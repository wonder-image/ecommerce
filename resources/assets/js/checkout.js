class Checkout {
    constructor() {
        this.root = document.querySelector('[data-checkout]');
        this.sequence = 0;
        this.timer = null;
        this.latest = null;

        if (!this.root || !this.root.dataset.summaryUrl) {
            return;
        }

        this.form = this.root.tagName === 'FORM' ? this.root : null;
        this.step = this.root.dataset.step;
        this.labels = JSON.parse(this.root.dataset.labels || '{}');
        this.latest = JSON.parse(this.root.dataset.initial || 'null');
        this.bind();

        if (this.latest) {
            this.submit(this.latest);
        } else if (this.form) {
            // Sul Carrello non si chiede il riepilogo: per l'ospite porterebbe al login.
            this.refresh();
        }
    }

    bind() {
        document.querySelectorAll('[data-checkout-coupon]').forEach((form) => form.addEventListener('submit', (event) => this.coupon(event)));

        if (!this.form) {
            return;
        }

        // Sul Pagamento l'anteprima tiene già contatto e consegna: conta solo il metodo scelto.
        const watch = this.step === 'payment'
            ? '[name="payment_method_id"]'
            : 'select, [name^="shipping_"], [name="fulfillment_type"], [name="location_id"]';

        this.form.addEventListener('change', (event) => {
            this.toggles();

            if (!event.target.matches(watch)) {
                return;
            }

            this.announce(event.target.name);
            this.schedule();
        });
        this.form.addEventListener('input', (event) => {
            if (event.target.matches('input[type="text"], input:not([type])') && event.target.matches(watch)) {
                this.schedule();
            }
        });
        this.toggles();
        this.guardSubmit();
    }

    schedule() {
        // Una risposta già in viaggio non deve riportare indietro quello che il cliente ha appena cambiato.
        this.sequence++;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), 300);
    }

    body(extra = null) {
        let data;

        if (this.form && this.step !== 'payment') {
            data = new FormData(this.form);
        } else {
            const method = this.form?.querySelector('[name="payment_method_id"]:checked');

            data = new FormData();
            data.set('csrf_token', document.querySelector('[name="csrf_token"]')?.value || '');

            if (method) {
                data.set('payment_method_id', method.value);
            }
        }

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
        const payload = await this.request(this.root.dataset.summaryUrl, this.body());

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
            return: couponForm.querySelector('[name="return"]')?.value || '',
        });
        const payload = await this.request(this.root.dataset.couponUrl, data);

        if (payload) {
            this.render(payload);

            if (payload.error) {
                this.notices([payload.error]);
            }
        }
    }

    busy(on) {
        this.root.setAttribute('aria-busy', String(on));
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
        const template = document.querySelector('template[data-checkout-line]');

        if (!template) {
            return;
        }

        document.querySelectorAll('[data-checkout-lines]').forEach((box) => {
            box.replaceChildren(...payload.items.map((item) => this.line(template, item)));
        });
    }

    line(template, item) {
        const node = template.content.cloneNode(true).firstElementChild;
        const image = node.querySelector('[data-line-image]');
        const sku = node.querySelector('[data-line-sku]');

        if (image && item.image) {
            image.src = item.image;
        } else {
            image?.remove();
        }

        this.text(node, '[data-line-quantity]', item.quantity_display);
        this.text(node, '[data-line-name]', item.name);
        this.text(node, '[data-line-total]', item.line_total_display);

        if (sku) {
            sku.textContent = item.sku ? (this.labels.sku || ':sku').replace(':sku', item.sku) : '';
            sku.hidden = !item.sku;
        }

        return node;
    }

    text(node, selector, value) {
        const target = node.querySelector(selector);

        if (target) {
            target.textContent = value ?? '';
        }

        return target;
    }

    totals(payload) {
        const d = payload.display;
        const rows = [[this.labels.products_total, d.products_total]];

        if (parseFloat(payload.order.discount_total) !== 0) {
            rows.push([this.labels.discount, d.discount_total]);
        }

        if (this.step !== 'cart' && this.root.dataset.shipping !== 'off' && payload.fulfillment.type === 'shipping') {
            rows.push([this.labels.shipping_total, d.shipping_total]);
        }

        if (parseFloat(payload.order.fees_total) !== 0) {
            rows.push([this.labels.fees_total, d.fees_total]);
        }

        rows.push([this.labels.total, d.total, true]);
        document.querySelectorAll('[data-checkout-totals]').forEach((box) => {
            box.replaceChildren(...rows.map(([label, value, strong]) => this.row(label, value, strong)));
        });
        document.querySelectorAll('[data-checkout-total]').forEach((total) => {
            total.textContent = d.total;
        });
    }

    row(label, value, strong = false) {
        const line = document.createElement('div');
        const name = document.createElement('span');
        const amount = document.createElement(strong ? 'strong' : 'span');

        line.className = 'd-grid col-2 gap-3';
        name.className = strong ? 'fw-700' : '';
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
                radio.disabled = !pickup;
                radio.closest('label')?.toggleAttribute('hidden', !pickup);
            }
        });
        document.querySelector('[data-checkout-shipping]')?.toggleAttribute('hidden', payload.fulfillment.type === 'pickup');
    }

    // Le scelte nascono dal template della Choice: titolo, testo e prezzo vanno nelle loro parti.
    choices(container, name, options, selected, parts) {
        const template = document.querySelector('template[data-checkout-choice]');

        if (!container || !template) {
            return;
        }

        const group = container.querySelector('[data-choice-list]') || container;

        group.replaceChildren(...options.map((option) => {
            const node = template.content.cloneNode(true).firstElementChild;
            const input = node.querySelector('[data-choice-input]');
            const [title, text, aside] = parts(option);

            input.name = name;
            input.value = String(option.value);
            input.checked = option.value === selected;
            [['[data-choice-title]', title], ['[data-choice-text]', text], ['[data-choice-aside]', aside]].forEach(([selector, value]) => {
                const target = this.text(node, selector, value);

                if (target) {
                    target.hidden = !value;
                }
            });

            return node;
        }));
    }

    shippingMethods(payload) {
        const box = document.querySelector('[data-checkout-shipping-methods]');
        const options = payload.shipping_methods.options.map((o) => ({ ...o, value: o.method_id }));
        const warning = document.querySelector('[data-checkout-notice]');

        this.choices(box, 'shipping_method_id', options, payload.shipping_methods.selected, (o) => [o.name, o.description, o.price_display]);

        if (warning) {
            const missing = options.length === 0 && this.root.dataset.shipping === 'on';

            warning.textContent = missing ? this.labels.no_shipping : '';
            warning.toggleAttribute('hidden', !missing);
        }
    }

    pickup(payload) {
        const section = document.querySelector('[data-checkout-pickup]');
        const options = payload.pickup_locations.options.map((o) => ({ ...o, value: o.id }));

        section?.toggleAttribute('hidden', payload.fulfillment.type !== 'pickup');
        this.choices(document.querySelector('[data-checkout-pickup-locations]'), 'location_id', options, payload.pickup_locations.selected, (o) => [o.name, o.address, '']);
    }

    payments(payload) {
        const box = document.querySelector('[data-checkout-payments]');

        if (this.step !== 'payment' || !box || payload.payment_methods.options.length === 0) {
            return;
        }

        const options = payload.payment_methods.options.map((o) => ({ ...o, value: o.id }));

        this.choices(box, 'payment_method_id', options, payload.payment_methods.selected, (o) => [o.name, o.instructions, '']);
    }

    couponBox(payload) {
        const code = payload.coupon?.code || '';

        document.querySelectorAll('[data-checkout-coupon]').forEach((form) => {
            const input = form.querySelector('[name="code"]');

            if (input && !(payload.error && code === '')) {
                input.value = code;
            }

            form.querySelector('[data-checkout-coupon-remove]')?.toggleAttribute('hidden', code === '');
        });
    }

    submit(payload) {
        if (this.step !== 'payment') {
            return;
        }

        const chosen = payload.payment_methods.options.find((o) => o.id === payload.payment_methods.selected);

        if (chosen) {
            document.querySelectorAll('[data-checkout-submit]').forEach((button) => {
                button.textContent = chosen.manual ? this.labels.submit_manual : this.labels.submit_online;
            });
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

    // I pannelli si aprono e chiudono con le caselle: «nome:on», «nome:off» o «nome:valore».
    toggles() {
        this.root.querySelectorAll('[data-checkout-toggle]').forEach((panel) => {
            const [name, want] = (panel.dataset.checkoutToggle || '').split(':');
            if (!name) {
                panel.hidden = false;
                return;
            }
            const inputs = [...this.root.querySelectorAll(`[name="${name}"]`)];
            const on = inputs.some((input) => input.checked && (want === 'on' || want === 'off' || input.value === want));
            panel.hidden = want === 'off' ? on : !on;
        });
    }

    // Un clic solo: il bottone si spegne all'invio e torna acceso se il browser riapre la pagina dalla cache.
    guardSubmit() {
        this.form.addEventListener('submit', () => {
            setTimeout(() => document.querySelectorAll('[data-checkout-submit]').forEach((button) => { button.disabled = true; }), 0);
        });
        window.addEventListener('pageshow', () => {
            document.querySelectorAll('[data-checkout-submit]:not([data-checkout-locked])').forEach((button) => { button.disabled = false; });
        });
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
