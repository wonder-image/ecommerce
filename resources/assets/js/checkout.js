class Checkout {
    constructor(selector = '#checkout') {
        this.form = document.querySelector(selector);
        this.sequence = 0;
        this.timer = null;
        this.latest = null;

        if (!this.form || !this.form.dataset.summaryUrl) {
            return;
        }

        this.labels = JSON.parse(this.form.dataset.labels || '{}');
        this.latest = JSON.parse(this.form.dataset.initial || 'null');
        this.bind();

        if (this.latest) {
            this.submit(this.latest);
        } else {
            this.refresh();
        }
    }

    bind() {
        const watch = 'select, input[name^="billing_"], input[name^="shipping_"], [name="fulfillment_type"], [name="location_id"], [name="payment_method_id"]';

        this.form.addEventListener('change', (event) => {
            if (!event.target.matches(watch)) {
                return;
            }

            this.announce(event.target.name);
            this.schedule();
        });
        this.form.addEventListener('input', (event) => {
            if (event.target.matches('input[name^="billing_"], input[name^="shipping_"]')) {
                this.schedule();
            }
        });
        document.querySelector('#checkout-coupon')?.addEventListener('submit', (event) => this.coupon(event));
    }

    schedule() {
        // Una risposta già in viaggio non deve riportare indietro quello che il cliente ha appena cambiato.
        this.sequence++;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), 300);
    }

    body(extra = null) {
        const data = new FormData(this.form);

        if (extra) {
            Object.entries(extra).forEach(([key, value]) => data.set(key, value));
        }

        return data;
    }

    async request(url, data) {
        const sequence = ++this.sequence;
        this.busy(true);

        try {
            const response = await fetch(url, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });
            const payload = await response.json();

            if (sequence !== this.sequence) {
                return null;
            }

            if (payload.redirect) {
                window.location.href = payload.redirect;

                return null;
            }

            return payload;
        } catch (error) {
            if (sequence === this.sequence) {
                this.notices([this.labels.summary_error]);
            }

            return null;
        } finally {
            if (sequence === this.sequence) {
                this.busy(false);
            }
        }
    }

    async refresh() {
        const payload = await this.request(this.form.dataset.summaryUrl, this.body());

        if (payload && payload.success !== false) {
            this.render(payload);
        } else if (payload) {
            this.notices([payload.error || this.labels.summary_error]);
        }
    }

    async coupon(event) {
        event.preventDefault();

        const couponForm = event.currentTarget;
        const data = this.body({
            action: event.submitter?.value || 'apply',
            code: couponForm.querySelector('[name="code"]')?.value || '',
        });
        const payload = await this.request(this.form.dataset.couponUrl, data);

        if (payload) {
            this.render(payload);

            if (payload.error) {
                this.notices([payload.error]);
            }
        }
    }

    busy(on) {
        this.form.setAttribute('aria-busy', String(on));
    }

    render(payload) {
        this.latest = payload;
        this.lines(payload);
        this.totals(payload);
        this.fulfillment(payload);
        this.shippingMethods(payload);
        this.pickup(payload);
        this.payments(payload);
        this.couponBox(payload);
        this.submit(payload);
        this.notices(payload.notices);
    }

    lines(payload) {
        const box = document.querySelector('[data-checkout-lines]');

        if (!box) {
            return;
        }

        box.replaceChildren(...payload.items.map((item) => this.row(`${item.quantity_display} × ${item.name}`, item.line_total_display)));
    }

    totals(payload) {
        const box = document.querySelector('[data-checkout-totals]');

        if (!box) {
            return;
        }

        const d = payload.display;
        const rows = [[this.labels.products_total, d.products_total]];

        if (parseFloat(payload.order.discount_total) !== 0) {
            rows.push([this.labels.discount, d.discount_total]);
        }

        if (this.form.dataset.shipping === 'on' && payload.fulfillment.type === 'shipping') {
            rows.push([this.labels.shipping_total, d.shipping_total]);
        }

        if (parseFloat(payload.order.fees_total) !== 0) {
            rows.push([this.labels.fees_total, d.fees_total]);
        }

        rows.push([this.labels.total, d.total, true]);
        box.replaceChildren(...rows.map(([label, value, strong]) => this.row(label, value, strong)));
    }

    row(label, value, strong = false) {
        const line = document.createElement('div');
        const name = document.createElement('span');
        const amount = document.createElement(strong ? 'strong' : 'span');

        line.className = 'd-grid col-2 gap-3';
        amount.className = 'a-r';
        name.textContent = label;
        amount.textContent = value;
        line.append(name, amount);

        return line;
    }

    fulfillment(payload) {
        const box = document.querySelector('[data-checkout-fulfillment]');

        if (!box) {
            return;
        }

        const pickup = payload.fulfillment.choices.includes('pickup');

        box.querySelectorAll('[name="fulfillment_type"]').forEach((radio) => {
            radio.checked = radio.value === payload.fulfillment.type;

            if (radio.value === 'pickup') {
                radio.closest('label')?.toggleAttribute('hidden', !pickup);
            }
        });
        document.querySelector('[data-checkout-shipping]')?.toggleAttribute('hidden', payload.fulfillment.type === 'pickup');
    }

    choices(container, name, options, selected, build) {
        if (!container) {
            return;
        }

        container.replaceChildren(...options.map((option) => {
            const label = document.createElement('label');
            const input = document.createElement('input');
            const text = document.createElement('span');

            input.type = 'radio';
            input.name = name;
            input.value = String(option.value);
            input.checked = option.value === selected;
            text.textContent = build(option);
            label.className = 'd-flex gap-3';
            label.append(input, text);

            return label;
        }));
    }

    shippingMethods(payload) {
        const box = document.querySelector('[data-checkout-shipping-methods]');
        const options = payload.shipping_methods.options.map((o) => ({ ...o, value: o.method_id }));
        const warning = document.querySelector('[data-checkout-notice]');

        this.choices(box, 'shipping_method_id', options, payload.shipping_methods.selected, (o) => [o.name, o.description, o.price_display].filter(Boolean).join(' — '));

        if (warning) {
            const missing = options.length === 0 && this.form.dataset.shipping === 'on';

            warning.textContent = missing ? this.labels.no_shipping : '';
            warning.toggleAttribute('hidden', !missing);
        }
    }

    pickup(payload) {
        const section = document.querySelector('[data-checkout-pickup]');
        const options = payload.pickup_locations.options.map((o) => ({ ...o, value: o.id }));

        section?.toggleAttribute('hidden', payload.fulfillment.type !== 'pickup');
        this.choices(document.querySelector('[data-checkout-pickup-locations]'), 'location_id', options, payload.pickup_locations.selected, (o) => `${o.name} — ${o.address}`);
    }

    payments(payload) {
        const box = document.querySelector('[data-checkout-payments]');

        if (!box || payload.payment_methods.options.length === 0) {
            return;
        }

        const options = payload.payment_methods.options.map((o) => ({ ...o, value: o.id }));

        this.choices(box, 'payment_method_id', options, payload.payment_methods.selected, (o) => (o.instructions ? `${o.name} — ${o.instructions}` : o.name));
    }

    couponBox(payload) {
        const form = document.querySelector('#checkout-coupon');

        if (!form) {
            return;
        }

        const code = payload.coupon?.code || '';
        const input = form.querySelector('[name="code"]');

        if (input && !(payload.error && code === '')) {
            input.value = code;
        }

        form.querySelector('[data-checkout-coupon-remove]')?.toggleAttribute('hidden', code === '');
    }

    submit(payload) {
        const button = document.querySelector('[data-checkout-submit]');
        const chosen = payload.payment_methods.options.find((o) => o.id === payload.payment_methods.selected);

        if (button && chosen) {
            button.textContent = chosen.manual ? this.labels.submit_manual : this.labels.submit_online;
        }
    }

    notices(messages) {
        const box = document.querySelector('[data-checkout-notices]');

        if (!box) {
            return;
        }

        box.replaceChildren(...(messages || []).filter(Boolean).map((message) => {
            const p = document.createElement('p');

            p.className = 'text-small';
            p.setAttribute('role', 'status');
            p.textContent = message;

            return p;
        }));
    }

    announce(name) {
        if (!this.latest || !['shipping_method_id', 'location_id', 'fulfillment_type', 'payment_method_id'].includes(name)) {
            return;
        }

        const event = name === 'payment_method_id' ? 'add_payment_info' : 'add_shipping_info';
        const order = this.latest.order;

        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push({ ecommerce: null });
        window.dataLayer.push({
            event,
            ecommerce: {
                currency: order.currency,
                value: parseFloat(order.total),
                items: this.latest.items.map((item) => ({
                    item_id: String(item.sku || item.product_id || ''),
                    item_name: item.name,
                    price: parseFloat(item.line_total) / (parseFloat(item.quantity) || 1),
                    quantity: parseFloat(item.quantity),
                })),
            },
        });
    }
}

window.Checkout = Checkout;
window.ecommerceCheckout = new Checkout();
