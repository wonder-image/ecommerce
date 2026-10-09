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
            this.stripeBox(this.latest);
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

        if (this.frozen) {
            return;
        }

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
        this.stripeBox(payload);
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
        const inputs = [...group.querySelectorAll('[data-choice-input]')];
        // Con le stesse scelte si aggiorna sul posto: i campi della carta nel pannello non si ricaricano.
        const same = inputs.length === options.length && inputs.every((input, i) => input.value === String(options[i].value));
        const fill = (node, option) => {
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
                const card = pane.querySelector('[data-checkout-stripe-element]');

                [...pane.childNodes].filter((child) => child !== card).forEach((child) => child.remove());
                pane.prepend(panel);
                this.pane(pane);
            }

            return node;
        };

        if (same) {
            inputs.forEach((input, i) => fill(input.closest('label') || input.parentElement, options[i]));

            return;
        }

        group.replaceChildren(...options.map((option) => fill(template.content.cloneNode(true).firstElementChild, option)));
    }

    // Il pannello si vede se ha un testo o i campi della carta.
    pane(pane) {
        const card = pane.querySelector('[data-checkout-stripe-element]');

        pane.hidden = pane.textContent.trim() === '' && (!card || card.hidden);
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

        // Un campo obbligatorio solo in un caso: required e asterisco della label lo seguono.
        this.root.querySelectorAll('[data-checkout-required]').forEach((box) => {
            const required = box.dataset.checkoutRequired.split('|').some((part) => this.matches(part));

            box.querySelectorAll('input, select, textarea').forEach((field) => { field.required = required; });
            box.querySelectorAll('.wi-label').forEach((label) => {
                label.textContent = label.textContent.replace(/\*$/, '') + (required ? '*' : '');
            });
        });

        // La lib accende «Ordina» guardando i campi visibili: va ricontrollato dopo aver mosso i pannelli.
        if (typeof check === 'function') { check(); }
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

    // L'opzione scelta: il radio spuntato, o quella dell'anteprima prima del primo disegno.
    chosenPayment(payload = this.latest) {
        const checked = this.form?.querySelector('[name="payment_method_id"]:checked');
        const id = checked ? Number(checked.value) : Number(payload?.payment_methods?.selected);

        return (payload?.payment_methods?.options || []).find((o) => Number(o.id) === id) || null;
    }

    // Il Payment Element: si monta la prima volta che serve, poi segue importo e valuta del riepilogo.
    stripeBox(payload) {
        const box = document.querySelector('[data-checkout-stripe]');
        const keys = payload?.stripe;
        const chosen = this.chosenPayment(payload);
        const on = Boolean(box && keys?.publishable_key && keys.amount > 0 && chosen?.provider === 'stripe');

        box?.toggleAttribute('hidden', !on);
        this.stripeSlot(on);

        if (!on) {
            return;
        }

        this.stripeOptions = { mode: 'payment', amount: keys.amount, currency: keys.currency };

        if (chosen.payment_method_types?.length) {
            this.stripeOptions.paymentMethodTypes = chosen.payment_method_types;
        }

        if (!this.stripeReady) {
            this.stripeReady = loadStripe().then(() => {
                this.stripe = window.Stripe(keys.publishable_key, keys.account ? { stripeAccount: keys.account } : {});
                this.elements = this.stripe.elements(this.stripeOptions);
                this.paymentElement = this.elements.create('payment', STRIPE_PAYMENT_ELEMENT);
                this.stripeSlot(!box.hidden);
            }).catch(() => {
                this.stripeReady = null;
                this.say([this.labels.stripe_error]);
            });

            return;
        }

        this.stripeReady.then(() => {
            // Se Stripe.js non è partito, non c'è niente da aggiornare.
            if (this.elements) {
                this.elements.update(this.stripeOptions);
            }
        });
    }

    // I campi della carta vanno nel pannello della scelta Stripe. Spostare l'iframe lo ricarica:
    // si smonta e si rimonta solo se il pannello è cambiato, e scegliendo altro si nasconde e basta.
    stripeSlot(on) {
        this.stripeCard = this.stripeCard || document.querySelector('[data-checkout-stripe-element]');

        const card = this.stripeCard;
        const radio = this.form?.querySelector('[name="payment_method_id"]:checked');
        const target = on ? radio?.closest('label')?.querySelector('[data-choice-panel]') : null;
        const holder = card?.closest('[data-choice-panel]');

        if (!card) {
            return;
        }

        card.hidden = !target;

        if (holder && holder !== target) {
            this.pane(holder);
        }

        if (!target) {
            return;
        }

        if (holder !== target || !card.isConnected) {
            if (this.stripeMounted) {
                this.paymentElement.unmount();
                this.stripeMounted = false;
            }

            target.append(card);
        }

        if (this.paymentElement && !this.stripeMounted) {
            this.paymentElement.mount(card);
            this.stripeMounted = true;
        }

        this.pane(target);
    }

    // «Paga»: Stripe controlla la carta, il server fa nascere l'ordine e l'intento, poi Stripe incassa.
    async payOnline() {
        if (this.paying) {
            return;
        }

        if (!this.elements) {
            this.say([this.labels.stripe_error]);

            return;
        }

        // Una risposta del riepilogo ancora in viaggio non deve ridisegnare il modulo mentre si paga.
        this.sequence++;
        clearTimeout(this.timer);
        this.paying = true;
        this.lock(true);
        this.say([]);
        payAlert('');
        paySpinner(true, this.labels.processing);

        try {
            const checked = await this.elements.submit();

            if (checked.error) {
                this.say([checked.error.message || this.labels.pay_failed]);

                return;
            }

            if (!this.placed) {
                this.placed = await this.place();

                if (!this.placed) {
                    return;
                }

                this.freeze();
            }

            const { error } = await this.stripe.confirmPayment({
                elements: this.elements,
                clientSecret: this.placed.client_secret,
                confirmParams: {
                    return_url: new URL(this.placed.return_url, window.location.href).href,
                    payment_method_data: { billing_details: this.placed.billing_details },
                },
            });

            // Senza errore Stripe ha già portato il cliente al ritorno. Il rifiuto si legge
            // nell'alert e dentro il Payment Element: sotto il box non si ripete.
            if (error) {
                payAlert(error.message || this.labels.pay_failed);
                this.say([]);
                await this.reopen();
            }
        } catch (error) {
            this.say([this.labels.stripe_error]);
            await this.reopen([this.labels.stripe_error]);
        } finally {
            this.paying = false;
            this.lock(false);
            paySpinner(false);
        }
    }

    // L'ordine nasce qui: il server controlla che il totale sia quello che il cliente ha visto.
    async place() {
        const response = await fetch(this.form.action, {
            method: 'POST',
            body: this.body({ expected_total: String(this.latest?.order?.total ?? '') }),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });
        const payload = await response.json().catch(() => ({}));

        if (payload.client_secret) {
            return payload;
        }

        if (payload.redirect) {
            window.location.href = payload.redirect;

            return null;
        }

        if (payload.error === 'total_changed' && payload.summary) {
            this.render(payload.summary);
            this.say([payload.message]);
        } else {
            this.say(payload.errors || [payload.message || payload.error || this.labels.summary_error]);
        }

        this.resetRecaptcha();

        return null;
    }

    // Il token del reCAPTCHA vale una volta sola: il widget è Enterprise (lo monta la lib) e scrive il
    // token in due campi nascosti. Si rinnova il widget e si svuotano i campi, così l'ospite lo rifà
    // prima del prossimo «Paga» invece di rimandare un token già usato.
    resetRecaptcha() {
        try {
            if (typeof window.grecaptcha?.enterprise?.reset === 'function') {
                window.grecaptcha.enterprise.reset();
            }
        } catch (error) {
            // Il widget non è ancora montato: non c'è niente da rinnovare.
        }

        this.form.querySelectorAll('input[name="g-recaptcha-token"], input[name="g-recaptcha-action"]').forEach((field) => { field.value = ''; });
    }

    // L'ordine è nato: il modulo non cambia più, altrimenti si pagherebbe un ordine diverso da quello che si vede.
    // Si spengono solo i campi accesi, così la riapertura non accende quelli che il modulo tiene spenti.
    freeze() {
        this.frozen = true;
        clearTimeout(this.timer);
        this.frozenFields = [
            ...this.form.querySelectorAll('input, select, textarea'),
            ...document.querySelectorAll('[data-checkout-coupon] input, [data-checkout-coupon] button'),
        ].filter((field) => !field.disabled);
        this.frozenFields.forEach((field) => { field.disabled = true; });
    }

    // Pagamento rifiutato: il server annulla l'ordine e rimette righe e scelte nel carrello, il modulo
    // torna modificabile e il prossimo «Paga» fa nascere un ordine nuovo. I messaggi dati restano a vista.
    async reopen(keep = []) {
        if (!this.placed) {
            return;
        }

        this.placed = null;
        this.frozen = false;
        (this.frozenFields || []).forEach((field) => { field.disabled = false; });
        this.frozenFields = [];
        this.resetRecaptcha();

        const payload = await this.request(this.root.dataset.reopenUrl, this.body());

        if (payload && payload.success !== false) {
            this.render(payload);
            this.notices([...keep, ...(payload.notices || [])]);
        }
    }

    lock(on) {
        document.querySelectorAll('[data-checkout-submit]').forEach((button) => { button.disabled = on; });
        this.busy(on);
    }

    // Gli errori del pagamento si leggono vicino alla carta e nel riepilogo.
    say(messages) {
        const list = (messages || []).filter(Boolean);
        const box = document.querySelector('[data-checkout-stripe-notice]');

        if (box) {
            box.textContent = list.join(' ');
        }

        this.notices(list);
    }

    // Un clic solo: il bottone si spegne all'invio e torna acceso se il browser riapre la pagina dalla cache.
    // Con Stripe il modulo non parte: lo manda payOnline() in JSON.
    guardSubmit() {
        this.form.addEventListener('submit', (event) => {
            if (!this.validate(event)) {
                return;
            }

            if (this.chosenPayment()?.provider === 'stripe') {
                event.preventDefault();
                this.payOnline();

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

        // Le select disegnate dalla lib legano la label al bottone «-control».
        const label = field.id ? this.form.querySelector(`label[for="${CSS.escape(field.id)}"], label[for="${CSS.escape(field.id)}-control"]`) : null;
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

// −, + e «Rimuovi» del carrello possono durare qualche secondo: lo spinner resta almeno
// CART_SPINNER_MIN ms, per non sembrare un lampo. La conferma della lib ferma il clic prima.
const CART_SPINNER_MIN = 800;

function cartSpinner(on) {
    if (typeof loadingSpinner === 'function' && document.getElementById('loading-spinner')) {
        loadingSpinner(on);
    }
}

// Mentre si paga lo spinner dice cosa succede; spento torna al testo della lib. Se Stripe chiede il 3DS,
// la sua finestra sta sopra lo spinner. Riuscito il pagamento, lo spinner resta fino alla pagina di ritorno.
// Un rifiuto di Stripe (la carta, il 3DS) si legge in un alert della lib, che resta finché il cliente non lo chiude
// o non riprova. Restituisce il messaggio che non ha potuto mostrare, così torna sotto la carta.
function payAlert(message) {
    document.querySelectorAll('[data-pay-alert]').forEach((node) => node.remove());

    const alert = document.querySelector('[data-checkout-pay-alert]')?.content.firstElementChild?.cloneNode(true);

    if (!message || !alert || typeof alertContainer !== 'function') {
        return message;
    }

    alert.removeAttribute('id');
    alert.dataset.payAlert = '';
    alert.classList.remove('wi-show');
    alert.querySelector('.wi-alert-body').textContent = message;
    alertContainer().appendChild(alert);
    // Entra da destra come gli alert della lib.
    requestAnimationFrame(() => requestAnimationFrame(() => alert.classList.add('wi-show')));

    return '';
}

function paySpinner(on, text = '') {
    const message = document.querySelector('#loading-spinner .text');

    if (message) {
        message.dataset.text ??= message.textContent;
        message.textContent = on && text ? text : message.dataset.text;
    }

    cartSpinner(on);
}

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || !form.matches('form[data-cart-action]') || event.defaultPrevented) {
        return;
    }
    if (form.dataset.cartSending === 'go') {
        // Il secondo giro, dopo l'attesa: parte davvero.
        delete form.dataset.cartSending;
        return;
    }

    event.preventDefault();
    if (form.dataset.cartSending || typeof form.requestSubmit !== 'function') {
        return;
    }

    // Il bottone premuto porta quantità e formaction: requestSubmit(submitter) li tiene, form.submit() no.
    const submitter = event.submitter && event.submitter.form === form ? event.submitter : null;
    form.dataset.cartSending = 'wait';
    cartSpinner(true);
    setTimeout(() => {
        form.dataset.cartSending = 'go';
        submitter ? form.requestSubmit(submitter) : form.requestSubmit();
    }, CART_SPINNER_MIN);
});

// Tornando indietro il browser può rimettere la pagina dalla cache con lo spinner acceso.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) {
        return;
    }

    document.querySelectorAll('form[data-cart-action]').forEach((form) => { delete form.dataset.cartSending; });
    paySpinner(false);
});

let stripeScript = null;

// Solo numero, scadenza e CVC: Link e i wallet vanno nei bottoni rapidi, i dati del cliente li dà l'ordine alla conferma.
const STRIPE_PAYMENT_ELEMENT = { wallets: { applePay: 'never', googlePay: 'never', link: 'never' }, fields: { billingDetails: 'never' } };

// Stripe.js arriva da Stripe, come vuole Stripe per la sicurezza della carta, e solo nelle pagine che lo usano.
function loadStripe() {
    if (window.Stripe) {
        return Promise.resolve();
    }

    if (!stripeScript) {
        stripeScript = new Promise((resolve, reject) => {
            const script = document.createElement('script');

            script.src = 'https://js.stripe.com/v3/';
            script.onload = () => resolve();
            script.onerror = () => {
                stripeScript = null;
                script.remove();
                reject(new Error('Stripe.js'));
            };
            document.head.appendChild(script);
        });
    }

    return stripeScript;
}

// La pagina «Paga ora»: ordine e intento ci sono già, il Payment Element parte dal client_secret.
class CheckoutPay {
    constructor(root) {
        this.root = root;
        this.labels = JSON.parse(root.dataset.labels || '{}');
        this.button = root.querySelector('[data-checkout-pay-submit]');
        this.notice = root.querySelector('[data-checkout-pay-notice]');
        this.billing = JSON.parse(this.root.dataset.billingDetails || '{}');

        loadStripe().then(() => this.mount()).catch(() => this.say(this.labels.error));
    }

    mount() {
        const account = this.root.dataset.account;

        this.stripe = window.Stripe(this.root.dataset.publishableKey, account ? { stripeAccount: account } : {});
        this.elements = this.stripe.elements({ clientSecret: this.root.dataset.clientSecret });
        this.elements.create('payment', STRIPE_PAYMENT_ELEMENT).mount(this.root.querySelector('[data-checkout-pay-element]'));
        this.button.addEventListener('click', () => this.pay());
        this.button.disabled = false;
    }

    async pay() {
        this.button.disabled = true;
        this.say('');
        payAlert('');
        paySpinner(true, this.labels.processing);

        try {
            const { error } = await this.stripe.confirmPayment({
                elements: this.elements,
                confirmParams: {
                    return_url: new URL(this.root.dataset.returnUrl, window.location.href).href,
                    payment_method_data: { billing_details: this.billing },
                },
            });

            // Senza errore Stripe ha già portato il cliente al ritorno.
            if (error) {
                payAlert(error.message || this.labels.failed);
            }
        } catch (error) {
            this.say(this.labels.error);
        } finally {
            this.button.disabled = false;
            paySpinner(false);
        }
    }

    say(message) {
        if (this.notice) {
            this.notice.textContent = message || '';
        }
    }
}

document.querySelectorAll('[data-checkout-pay]').forEach((root) => new CheckoutPay(root));

window.Checkout = Checkout;
window.ecommerceCheckout = new Checkout();
