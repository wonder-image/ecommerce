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

        // Anche la fatturazione: le tasse si calcolano sul suo indirizzo.
        const watch = 'select, [name^="shipping_"], [name^="billing_"], [name="same_as_shipping"], [name="invoice"], [name="fulfillment_type"], [name="location_id"], [name="payment_method_id"]';

        this.form.addEventListener('change', (event) => {
            if (event.target.hasAttribute('aria-invalid')) this.fieldError(event.target, '');
            this.toggles();

            if (!event.target.matches(watch)) {
                return;
            }

            this.announce(event.target.name);
            this.schedule();
        });
        this.form.addEventListener('input', (event) => {
            if (event.target.hasAttribute('aria-invalid')) this.fieldError(event.target, '');
            this.mirror();

            if (event.target.matches('input[type="text"], input:not([type])') && event.target.matches(watch)) {
                this.schedule();
            }
        });
        this.toggles();
        this.mirror();
        this.guardSubmit();
    }

    schedule() {
        // Una risposta già in viaggio non deve riportare indietro quello che il cliente ha appena cambiato.
        this.sequence++;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), 300);
    }

    body(extra = {}) {
        const data = this.form ? new FormData(this.form) : new FormData();

        if (!this.form) {
            data.set('csrf_token', document.querySelector('[name="csrf_token"]')?.value || '');
        }

        Object.entries(extra).forEach(([key, value]) => data.set(key, value));

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

        if (image && item.image) {
            image.src = item.image;
        } else {
            image?.remove();
        }

        this.text(node, '[data-line-quantity]', item.quantity_display);
        this.text(node, '[data-line-name]', item.name);
        this.text(node, '[data-line-total]', item.line_total_display);

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

        // Sul carrello la spedizione non c'è ancora; nel checkout, senza indirizzo, si chiede di inserirlo.
        if (this.form && this.root.dataset.shipping !== 'off' && payload.fulfillment.type === 'shipping') {
            rows.push([this.labels.shipping_total, d.shipping_pending ? this.labels.shipping_pending : d.shipping_total]);
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

        line.className = 'w-100 d-grid col-2 gap-3';
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

        // Senza sedi per il ritiro non c'è niente da scegliere.
        box.hidden = !pickup;

        box.querySelectorAll('[name="fulfillment_type"]').forEach((radio) => {
            radio.checked = radio.value === payload.fulfillment.type;

            if (radio.value === 'pickup') {
                radio.disabled = !pickup;
                radio.closest('label')?.toggleAttribute('hidden', !pickup);
            }
        });
        document.querySelectorAll('[data-checkout-shipping]').forEach((node) => node.toggleAttribute('hidden', payload.fulfillment.type === 'pickup'));
    }

    // Le scelte nascono dal template della Choice: titolo, testo, prezzo, loghi e pannello vanno nelle loro parti.
    choices(container, name, options, selected, parts) {
        const template = document.querySelector('template[data-checkout-choice]');

        if (!container || !template) {
            return;
        }

        const group = container.querySelector('[data-choice-list]') || container;

        group.replaceChildren(...options.map((option) => {
            const node = template.content.cloneNode(true).firstElementChild;
            const input = node.querySelector('[data-choice-input]');
            const [title, text, aside, icons = [], panel = ''] = parts(option);

            input.name = name;
            input.value = String(option.value);
            input.checked = option.value === selected;
            [['[data-choice-title]', title], ['[data-choice-text]', text], ['[data-choice-aside]', aside]].forEach(([selector, value]) => {
                const target = this.text(node, selector, value);

                if (target) {
                    target.hidden = !value;
                }
            });
            this.icons(node, icons);

            const pane = node.querySelector('[data-choice-panel]');

            if (pane) {
                pane.textContent = panel;
                pane.hidden = panel === '';
            }

            return node;
        }));
    }

    // Al massimo tre loghi, poi «+N».
    icons(node, icons) {
        const logos = node.querySelector('[data-choice-icons]');

        if (!logos) {
            return;
        }

        const all = icons.filter((icon) => icon && icon.src);
        const shown = all.slice(0, 3);

        logos.replaceChildren(...shown.map((icon) => {
            const img = document.createElement('img');

            img.src = icon.src;
            img.alt = icon.alt || '';
            img.width = 38;
            img.height = 24;
            img.loading = 'lazy';

            return img;
        }));

        if (all.length > shown.length) {
            const more = document.createElement('span');

            more.className = 'wi-choice__more';
            more.textContent = '+' + (all.length - shown.length);
            logos.append(more);
        }

        logos.hidden = shown.length === 0;
    }

    shippingMethods(payload) {
        const box = document.querySelector('[data-checkout-shipping-methods]');
        const options = payload.shipping_methods.options.map((o) => ({ ...o, value: o.method_id }));
        const notice = document.querySelector('[data-checkout-notice]');

        this.choices(box, 'shipping_method_id', options, payload.shipping_methods.selected, (o) => [o.name, o.description, o.price_display]);

        if (notice) {
            const key = options.length || this.root.dataset.shipping !== 'on'
                ? ''
                : (payload.shipping_methods.address_complete ? 'no_shipping' : 'shipping_methods_pending');

            notice.textContent = key ? (this.labels[key] || '') : '';
            notice.hidden = key === '';
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

        if (!box) {
            return;
        }

        const options = (payload.payment_methods.options || []).map((o) => ({ ...o, value: o.id }));

        this.choices(box, 'payment_method_id', options, payload.payment_methods.selected,
            (o) => [o.name, '', o.fee_display || '', o.icon_urls || [], o.panel || '']);
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
        if (!this.form) {
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

    // I pannelli si aprono e chiudono coi campi: «nome:on», «nome:off» o «nome:valore»; con «|» basta una condizione.
    toggles() {
        this.root.querySelectorAll('[data-checkout-toggle]').forEach((panel) => {
            const rule = panel.dataset.checkoutToggle || '';

            panel.hidden = rule !== '' && !rule.split('|').some((part) => this.matches(part));
        });
    }

    matches(part) {
        const [name, wanted] = part.split(':');
        const fields = [...this.root.querySelectorAll(`[name="${name}"]`)];

        if (!fields.length) {
            return false;
        }

        const field = fields.find((f) => (f.type === 'radio' ? f.checked : true));

        if (field && field.type === 'checkbox') {
            return wanted === 'on' ? field.checked : !field.checked;
        }

        const value = field ? field.value : '';

        if (wanted === 'on') {
            return value !== '' && value !== '0';
        }

        if (wanted === 'off') {
            return value === '' || value === '0';
        }

        return value === wanted;
    }

    // Col ritiro (o con «indirizzo diverso») nome e cognome partono da quelli della consegna.
    mirror() {
        if (!this.form) {
            return;
        }

        [['shipping_name', 'billing_name'], ['shipping_surname', 'billing_surname']].forEach(([from, to]) => {
            const source = this.form.querySelector(`[name="${from}"]`);
            const target = this.form.querySelector(`[name="${to}"]`);

            if (!source || !target) {
                return;
            }

            if (target.value === '' || target.dataset.mirrored === target.value) {
                target.value = source.value;
                target.dataset.mirrored = source.value;
            }
        });
    }

    // Un clic solo: il bottone si spegne all'invio e torna acceso se il browser riapre la pagina dalla cache.
    guardSubmit() {
        this.form.addEventListener('submit', (event) => {
            if (!this.validate(event)) {
                return;
            }

            setTimeout(() => document.querySelectorAll('[data-checkout-submit]').forEach((button) => { button.disabled = true; }), 0);
        });
        window.addEventListener('pageshow', () => {
            document.querySelectorAll('[data-checkout-submit]:not([data-checkout-locked])').forEach((button) => { button.disabled = false; });
        });
    }

    // Gli errori stanno sotto il campo, e la pagina scorre al primo.
    validate(event) {
        let first = null;

        this.form.querySelectorAll('input, select, textarea').forEach((field) => {
            if (field.disabled || field.type === 'hidden' || field.closest('[hidden]')) {
                return;
            }

            const ok = field.checkValidity();

            this.fieldError(field, ok ? '' : this.message(field));

            if (!ok && !first) {
                first = field;
            }
        });

        if (!first) {
            return true;
        }

        event.preventDefault();
        first.focus({ preventScroll: true });
        first.scrollIntoView({ block: 'center', behavior: 'smooth' });

        return false;
    }

    message(field) {
        if (!field.validity.valueMissing) {
            return field.validationMessage;
        }

        const label = field.id ? this.form.querySelector(`label[for="${CSS.escape(field.id)}"]`) : null;
        const text = (label ? label.textContent : field.name).replace('*', '').trim().toLowerCase();

        return (this.labels.field_required || '{{label}}').replace('{{label}}', text);
    }

    fieldError(field, text) {
        const holder = field.parentElement;
        let note = holder.querySelector(':scope > [data-checkout-field-error]');

        if (!text) {
            field.removeAttribute('aria-invalid');
            note?.remove();

            return;
        }

        if (!note) {
            note = document.createElement('p');
            note.className = 'text-small tx-danger mt-1';
            note.setAttribute('data-checkout-field-error', '');
            note.id = (field.id || field.name) + '-error';
            holder.append(note);
        }

        note.textContent = text;
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', note.id);
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
