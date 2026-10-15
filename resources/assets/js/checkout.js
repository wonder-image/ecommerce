class Checkout {
    constructor() {
        this.root = document.querySelector('[data-checkout]');
        this.sequence = 0;
        this.timer = null;
        this.latest = null;
        // Un gruppo elements per ogni scelta Stripe (chiave → { elements, element, container, mounted }); le scelte
        // il cui Payment Element non si carica (loaderror) si tolgono per sempre dal modulo.
        this.groups = {};
        this.dropped = new Set();
        // Apple Pay, Google Pay e Link fra le scelte: nascosti finché il browser non dice quali sa usare (walletProbe).
        this.walletTypes = new Set();
        this.probed = false;
        this.walletToken = null;

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

            // Il gruppo della nuova scelta parte subito: «Paga» non deve trovare l'elemento della scelta di prima.
            if (event.target.name === 'payment_method_id') {
                this.stripeBox(this.latest);
                this.submit(this.latest);
            }

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

        // Mentre si paga il modulo non si ridisegna.
        if (this.paying) {
            return;
        }

        this.timer = setTimeout(() => this.refresh(), 300);
    }

    body(extra = {}) {
        const data = this.form ? new FormData(this.form) : new FormData();

        if (!this.form) {
            data.set('csrf_token', document.querySelector('[name="csrf_token"]')?.value || '');
        }

        // Mentre si paga i radio del pagamento sono spenti e il modulo non li manda: vale la scelta fissata all'inizio.
        if (this.payKey) {
            data.set('payment_method_id', this.payKey);
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

        // I pezzi di un multiprodotto, già scritti dal server («1 × Pane»).
        const parts = node.querySelector('[data-line-parts]');

        if (parts) {
            parts.hidden = !(item.parts || []).length;
            parts.replaceChildren(...(item.parts || []).map((part) => {
                const li = document.createElement('li');
                li.textContent = part;

                return li;
            }));
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
                const cards = [...pane.querySelectorAll('[data-checkout-stripe-element]')];

                [...pane.childNodes].filter((child) => !cards.includes(child)).forEach((child) => child.remove());
                pane.prepend(panel);
                this.pane(pane);
            }

            node.toggleAttribute('hidden', Boolean(option.hidden));

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
        const cards = [...pane.querySelectorAll('[data-checkout-stripe-element]')];

        pane.hidden = pane.textContent.trim() === '' && cards.every((card) => card.hidden);
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

        const options = (payload.payment_methods.options || []).filter((o) => !this.dropped.has(o.key))
            .map((o) => ({ ...o, value: o.key, hidden: isWallet(o) && !this.walletTypes.has(o.stripe_method_type) }));
        // Resta spuntata la scelta di prima, se c'è ancora; altrimenti la voce dell'anteprima (carta, per i metodi Stripe).
        const checked = this.form?.querySelector('[name="payment_method_id"]:checked')?.value;
        const selected = options.some((o) => o.value === checked)
            ? checked
            : options.find((o) => String(o.id) === String(payload.payment_methods.selected) && ['', 'card'].includes(o.stripe_method_type ?? ''))?.value;

        this.choices(box, 'payment_method_id', options, selected,
            (o) => [o.name, '', o.fee_display || '', o.icon_urls || [], o.panel || '']);
        this.syncPaymentRadios();
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

        const chosen = this.chosenPayment(payload);

        if (chosen) {
            // Con un wallet si paga dal suo bottone, che prende il posto di «Paga»: questo sparisce.
            document.querySelectorAll('[data-checkout-submit]').forEach((button) => {
                button.textContent = chosen.manual ? this.labels.submit_manual : this.labels.submit_online;
                button.hidden = isWallet(chosen);
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

    // L'opzione scelta: il radio spuntato (vale la chiave della scelta), o quella dell'anteprima prima del primo disegno.
    chosenPayment(payload = this.latest) {
        const options = payload?.payment_methods?.options || [];
        const checked = this.form?.querySelector('[name="payment_method_id"]:checked');

        if (checked) {
            return options.find((o) => String(o.key) === checked.value) || null;
        }

        return options.find((o) => String(o.id) === String(payload?.payment_methods?.selected) && ['', 'card'].includes(o.stripe_method_type ?? '')) || null;
    }

    // Il Payment Element: ogni scelta Stripe ha il suo gruppo, creato la prima volta che serve,
    // poi tutti seguono importo e valuta del riepilogo.
    stripeBox(payload) {
        const box = document.querySelector('[data-checkout-stripe]');
        const keys = payload?.stripe;
        const stripeChoices = (payload?.payment_methods?.options || []).filter((o) => o.provider === 'stripe');
        const chosen = this.chosenPayment(payload);
        const on = Boolean(box && stripeChoices.length && keys?.publishable_key && keys.amount > 0 && chosen?.provider === 'stripe');
        // I wallet fra le scelte si provano anche se è spuntato un pagamento manuale: per mostrarli serve Stripe.js.
        const probe = Boolean(stripeChoices.some((o) => isWallet(o) && !this.dropped.has(o.key)) && keys?.publishable_key && keys.amount > 0);

        this.stripeOn = on;
        box?.toggleAttribute('hidden', !on);
        this.stripeSlot(on);

        if (!on && (!probe || this.probed)) {
            return;
        }

        this.stripeOptions = { mode: 'payment', amount: keys.amount, currency: keys.currency };

        if (!this.stripeReady) {
            this.stripeReady = loadStripe().then(() => {
                this.stripe = window.Stripe(keys.publishable_key, keys.account ? { stripeAccount: keys.account } : {});
            }).catch(() => {
                this.stripeReady = null;

                if (this.stripeOn) {
                    this.say([this.labels.stripe_error]);
                }

                // Senza Stripe.js i wallet non si possono provare: spariscono.
                (this.latest?.payment_methods?.options || []).filter(isWallet).forEach((o) => this.dropChoice(o.key));
            });
        }

        this.stripeReady.then(() => {
            // Se Stripe.js non è partito, non c'è niente da fare. Intanto la scelta può essere cambiata: vale l'ultima.
            if (!this.stripe) {
                return;
            }

            this.walletProbe();

            const chosen = this.chosenPayment(this.latest);

            if (!this.stripeOn || chosen?.provider !== 'stripe') {
                return;
            }

            try {
                let group = this.groups[chosen.key];

                if (!group) {
                    const options = { ...this.stripeOptions, appearance: STRIPE_APPEARANCE, ...(chosen.payment_method_types?.length ? { paymentMethodTypes: chosen.payment_method_types } : {}) };
                    const elements = this.stripe.elements(options);

                    const element = isWallet(chosen)
                        ? elements.create('expressCheckout', { paymentMethods: walletMethods([chosen.stripe_method_type]) })
                        : elements.create('payment', STRIPE_PAYMENT_ELEMENT);

                    group = { elements, element, container: this.stripeContainer(chosen.key, chosen.stripe_method_type), mounted: false, wallet: isWallet(chosen) };
                    this.groups[chosen.key] = group;

                    if (isWallet(chosen)) {
                        this.walletEvents(group, chosen.key);
                    }

                    // Klarna, BLIK… fuori dai loro limiti (importo, paese) non si caricano: la scelta sparisce e torna la carta.
                    if (!['', 'card'].includes(chosen.stripe_method_type ?? '')) {
                        const key = chosen.key;

                        group.element.on('loaderror', () => this.dropChoice(key));
                    }
                }

                this.elements = group.elements;
                this.paymentElement = group.element;

                Object.entries(this.groups).forEach(([, other]) => {
                    if (other !== group) {
                        other.elements.update({ amount: this.stripeOptions.amount, currency: this.stripeOptions.currency });
                    }
                });
                this.elements.update(this.stripeOptions);
                this.stripeSlot(true);
            } catch (error) {
                if (['', 'card'].includes(chosen.stripe_method_type ?? '')) {
                    this.say([this.labels.stripe_error]);
                } else {
                    this.dropChoice(chosen.key);
                }
            }
        });
    }

    // Il contenitore del Payment Element di una scelta: la carta usa quello della vista, le altre ne hanno uno loro.
    stripeContainer(key, type) {
        const free = ['', 'card'].includes(type ?? '')
            ? [...document.querySelectorAll('[data-checkout-stripe-element]')].find((node) => !node.dataset.checkoutStripeElement)
            : null;
        const container = free || document.createElement('div');

        container.dataset.checkoutStripeElement = key;
        container.className = container.className || 'w-100';

        return container;
    }

    // Una volta sola, un Express Checkout Element invisibile prova tutti i wallet fra le scelte: quelli che il browser
    // sa usare si mostrano, gli altri spariscono come le scelte che non si caricano.
    walletProbe() {
        const wallets = (this.latest?.payment_methods?.options || []).filter((o) => isWallet(o) && !this.dropped.has(o.key));

        if (this.probed || !wallets.length || !this.form) {
            return;
        }

        this.probed = true;

        const holder = document.createElement('div');
        let element = null;
        let done = false;
        const finish = (available) => {
            if (done) {
                return;
            }

            done = true;
            Object.entries(WALLETS).forEach(([type, name]) => {
                if (available?.[name]) {
                    this.walletTypes.add(type);
                }
            });
            wallets.forEach((o) => {
                if (this.walletTypes.has(o.stripe_method_type)) {
                    [...this.form.querySelectorAll('[name="payment_method_id"]')].find((r) => r.value === o.key)?.closest('label')?.removeAttribute('hidden');
                } else {
                    this.dropChoice(o.key);
                }
            });

            try { element?.destroy(); } catch (error) { /* già smontato */ }
            holder.remove();
        };

        holder.style.cssText = 'height:0;margin:0;overflow:hidden;visibility:hidden';
        this.form.append(holder);

        try {
            element = this.stripe.elements({ ...this.stripeOptions, appearance: STRIPE_APPEARANCE })
                .create('expressCheckout', { paymentMethods: walletMethods(wallets.map((o) => o.stripe_method_type)) });
            element.on('ready', ({ availablePaymentMethods }) => finish(availablePaymentMethods));
            element.on('loaderror', () => finish(null));
            element.mount(holder);
        } catch (error) {
            finish(null);
        }
    }

    // I campi vanno nel pannello della scelta Stripe, il bottone di un wallet al posto di «Paga». Spostare l'iframe
    // lo ricarica: ogni gruppo si monta una volta sola, si rimonta solo se il posto è cambiato, e scegliendo altro
    // si nasconde e basta.
    stripeSlot(on) {
        const radio = this.form?.querySelector('[name="payment_method_id"]:checked');
        const slot = this.form?.querySelector('[data-checkout-wallet-slot]');

        Object.entries(this.groups).forEach(([key, other]) => {
            if (on && radio && key === radio.value) {
                return;
            }

            const holder = other.container.closest('[data-choice-panel]');

            other.container.hidden = true;

            if (holder) {
                this.pane(holder);
            }
        });

        const group = on && radio ? this.groups[radio.value] : null;

        // Vuoto, lo slot terrebbe comunque il suo spazio fra i campi della conferma.
        if (slot) {
            slot.hidden = !group?.wallet;
        }

        if (!group) {
            return;
        }

        this.elements = group.elements;
        this.paymentElement = group.element;

        const card = group.container;
        const target = group.wallet ? slot : radio.closest('label')?.querySelector('[data-choice-panel]');
        const holder = card.closest('[data-choice-panel]');

        card.hidden = !target;

        if (holder && holder !== target) {
            this.pane(holder);
        }

        if (!target) {
            return;
        }

        if (card.parentElement !== target) {
            if (group.mounted) {
                this.paymentElement.unmount();
                group.mounted = false;
            }

            target.append(card);
        }

        if (!group.mounted) {
            this.paymentElement.mount(card);
            group.mounted = true;
        }

        if (!group.wallet) {
            this.pane(target);
        }
    }

    // Il Payment Element di una scelta non si carica: la voce sparisce, torna spuntata la carta Stripe. Niente messaggi.
    dropChoice(key) {
        // Mentre si paga la scelta non cambia.
        if (this.paying) {
            return;
        }

        const radios = [...(this.form?.querySelectorAll('[name="payment_method_id"]') || [])];
        const radio = radios.find((r) => r.value === key);
        const group = this.groups[key];
        const wasChecked = Boolean(radio?.checked);

        this.dropped.add(key);
        radio?.closest('label')?.remove();

        if (group) {
            try { group.element.destroy(); } catch (error) { /* già smontato */ }
            group.container.remove();
            delete this.groups[key];
        }

        if (!wasChecked) {
            return;
        }

        const card = (this.latest?.payment_methods?.options || []).find((o) => o.provider === 'stripe' && ['', 'card'].includes(o.stripe_method_type ?? ''));
        const cardRadio = card ? radios.find((r) => r.value === card.key) : null;

        if (cardRadio) {
            cardRadio.checked = true;
        }

        this.stripeBox(this.latest);
        this.submit(this.latest);
        // Il totale può avere un'altra commissione: il riepilogo si rilegge con la carta.
        this.schedule();
    }

    // «Paga»: Stripe controlla la carta, il server fa nascere l'ordine e l'intento, poi Stripe incassa.
    async payOnline() {
        if (this.paying) {
            return;
        }

        // L'elemento è quello della scelta di adesso: se il suo gruppo non è ancora pronto non si paga.
        const group = this.groups[this.chosenPayment()?.key];

        if (!group) {
            this.say([this.labels.stripe_error]);

            return;
        }

        // Da qui al pagamento si usa questo elements, non quello che cambia con la scelta: Stripe riceve sempre
        // quello della scelta con cui è nato l'ordine.
        const { elements } = group;

        // Una risposta del riepilogo ancora in viaggio non deve ridisegnare il modulo mentre si paga.
        this.sequence++;
        clearTimeout(this.timer);
        this.payKey = this.chosenPayment().key;
        this.paying = true;
        this.lock(true);
        this.say([]);
        payAlert('');
        paySpinner(true, this.labels.processing);

        try {
            const checked = await elements.submit();

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
                elements,
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
            this.payKey = null;
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

        // Punteggio basso: la lib apre la casella nel riquadro del modulo; altrimenti il token usato si butta.
        if (payload.status === 618 && typeof window.wiRecaptchaChallenge === 'function') {
            window.wiRecaptchaChallenge(this.form);
        } else {
            this.resetRecaptcha();
        }

        return null;
    }

    // Il token del reCAPTCHA vale una volta sola. Con la lib nuova se ne occupa `wiRecaptchaReset`, che sa
    // se il riquadro è a casella o v3 e scrive anche il tipo. Altrimenti il widget è Enterprise (lo monta la
    // lib) e scrive il token in due campi nascosti: si rinnova il widget e si svuotano i campi, così
    // l'ospite lo rifà prima del prossimo «Paga» invece di rimandare un token già usato.
    resetRecaptcha() {
        if (typeof window.wiRecaptchaReset === 'function') {
            window.wiRecaptchaReset(this.form);
            return;
        }

        try {
            if (typeof window.grecaptcha?.enterprise?.reset === 'function') {
                window.grecaptcha.enterprise.reset();
            }
        } catch (error) {
            // Il widget non è ancora montato: non c'è niente da rinnovare.
        }

        this.form.querySelectorAll('input[name="g-recaptcha-token"], input[name="g-recaptcha-action"]').forEach((field) => { field.value = ''; });
    }

    // Clic sul bottone del wallet: il foglio si apre solo se il modulo è a posto. Stripe vuole la risposta entro
    // un secondo, quindi il controllo è sincrono; il token reCAPTCHA si chiede subito dopo e serve alla conferma.
    walletClick(event) {
        if (this.paying || this.frozen || !this.validate(null)) {
            return;
        }

        // Casella reCAPTCHA a vista e non spuntata: il foglio non si apre.
        const box = this.form.querySelector('.g-recaptcha');
        const checkbox = Boolean(box?.dataset.wiSiteKey) && (!box.dataset.wiScoreKey || box.dataset.wiChallenge === '1');

        if (checkbox && !this.form.querySelector('input[name="g-recaptcha-token"]')?.value) {
            this.say([this.labels.captcha]);

            return;
        }

        this.say([]);
        payAlert('');
        event.resolve({});
        this.walletToken = this.captchaToken();
    }

    // Chi è entrato non ha il reCAPTCHA: vale sì. La lib scrive il token nei campi nascosti del modulo.
    captchaToken() {
        if (!this.form.querySelector('.g-recaptcha') || typeof window.wiRecaptchaToken !== 'function') {
            return Promise.resolve(true);
        }

        return window.wiRecaptchaToken(this.form).then(Boolean, () => false);
    }

    // Il cliente ha confermato nel foglio del wallet: come «Paga», ma con l'elements del wallet.
    async walletConfirm(event, key) {
        const group = this.groups[key];

        if (this.paying || !group) {
            event.paymentFailed({ reason: 'fail' });

            return;
        }

        const { elements } = group;
        let sent = false;

        this.sequence++;
        clearTimeout(this.timer);
        this.payKey = key;
        this.paying = true;
        this.lock(true);
        paySpinner(true, this.labels.processing);

        try {
            const checked = await elements.submit();

            if (checked.error) {
                event.paymentFailed({ reason: 'fail' });
                this.say([checked.error.message || this.labels.pay_failed]);

                return;
            }

            if (!(await this.walletToken)) {
                event.paymentFailed({ reason: 'fail' });
                this.say([this.labels.captcha]);

                return;
            }

            if (!this.placed) {
                this.placed = await this.place();

                if (!this.placed) {
                    event.paymentFailed({ reason: 'fail' });

                    return;
                }

                this.freeze();
            }

            sent = true;

            const { error } = await this.stripe.confirmPayment({
                elements,
                clientSecret: this.placed.client_secret,
                confirmParams: {
                    return_url: new URL(this.placed.return_url, window.location.href).href,
                    payment_method_data: { billing_details: this.placed.billing_details },
                },
            });

            if (error) {
                payAlert(error.message || this.labels.pay_failed);
                this.say([]);
                await this.reopen();
            }
        } catch (error) {
            if (!sent) {
                event.paymentFailed({ reason: 'fail' });
            }

            this.say([this.labels.stripe_error]);
            await this.reopen([this.labels.stripe_error]);
        } finally {
            this.paying = false;
            this.payKey = null;
            this.walletToken = null;
            this.lock(false);
            paySpinner(false);
        }
    }

    walletEvents(group, key) {
        // Il wallet non c'è più (per esempio la carta è uscita da Google Pay): la scelta sparisce, torna la carta.
        group.element.on('ready', ({ availablePaymentMethods }) => {
            if (!availablePaymentMethods) {
                this.dropChoice(key);
            }
        });
        group.element.on('click', (e) => this.walletClick(e));
        group.element.on('confirm', (e) => this.walletConfirm(e, key));
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
        this.syncPaymentRadios();
        this.busy(on);
    }

    // Mentre si paga (e dopo la nascita dell'ordine) la scelta del pagamento non cambia.
    syncPaymentRadios() {
        this.form?.querySelectorAll('[name="payment_method_id"]').forEach((radio) => {
            radio.disabled = Boolean(this.paying) || Boolean(this.frozen);
        });
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

            // Con un wallet «Paga» non c'è: anche Invio non manda il modulo, si paga dal bottone del wallet.
            if (isWallet(this.chosenPayment())) {
                event.preventDefault();
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

        event?.preventDefault();
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

// Solo numero, scadenza e CVC: Link e i wallet hanno la loro scelta (e i bottoni rapidi), i dati del cliente li dà l'ordine alla conferma.
const STRIPE_PAYMENT_ELEMENT = { wallets: { applePay: 'never', googlePay: 'never', link: 'never' }, fields: { billingDetails: 'never' } };

// Il tipo Stripe del wallet → il suo nome nell'Express Checkout Element.
const WALLETS = { apple_pay: 'applePay', google_pay: 'googlePay', link: 'link' };
const isWallet = (o) => Boolean(o && WALLETS[o.stripe_method_type]);

// Nell'Express Checkout Element solo i wallet dati: gli altri metodi hanno la loro scelta.
function walletMethods(types) {
    return {
        applePay: types.includes('apple_pay') ? 'auto' : 'never',
        googlePay: types.includes('google_pay') ? 'auto' : 'never',
        link: types.includes('link') ? 'auto' : 'never',
        paypal: 'never',
        amazonPay: 'never',
        klarna: 'never',
    };
}

// Klarna, PayPal e gli altri a reindirizzamento: Stripe mostra il loro logo in un riquadro che non si può togliere, almeno senza bordo né sfondo.
const STRIPE_APPEARANCE = { rules: { '.Block': { border: 'none', boxShadow: 'none', padding: '0', backgroundColor: 'transparent' }, '.BlockDivider': { backgroundColor: 'transparent' } } };

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

    // Centesimi di un pezzo (della variante scelta, se la scheda l'ha cambiata) per la quantità della form.
    productAmount() {
        const unit = Number(this.root.dataset.expressUnit) || this.config.amount;
        const quantity = parseFloat(String(this.productForm?.querySelector('[name="quantity"]')?.value || '').replace(',', '.'));

        return Math.max(1, Math.round(unit * (quantity > 0 ? quantity : 1)));
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

// Carrello e checkout restano sotto lo spinner finché i bottoni rapidi non sono pronti, al massimo EXPRESS_WAIT_MAX ms:
// meglio la pagina un attimo dopo che i bottoni che compaiono e spingono giù il resto. Se in questa sessione
// il browser non aveva wallet, non si aspetta più.
const EXPRESS_WAIT_MAX = 2000;
const EXPRESS_NO_WALLET = 'wi-express-no-wallet';

function expressNoWallet(value) {
    try {
        if (value === undefined) {
            return sessionStorage.getItem(EXPRESS_NO_WALLET) === '1';
        }
        value ? sessionStorage.setItem(EXPRESS_NO_WALLET, '1') : sessionStorage.removeItem(EXPRESS_NO_WALLET);
    } catch (error) {
        // Senza sessionStorage (navigazione privata, dati bloccati) si aspetta ogni volta, sempre col tetto.
    }

    return false;
}

// Il check-out rapido: l'Express Checkout Element di Stripe (Apple Pay, Google Pay, Link) nel checkout,
// nel carrello e nella scheda prodotto. Il foglio del wallet chiede indirizzo e tariffa; il server compra
// da un carrello a parte e crea ordine e intento solo alla conferma.
class Express {
    constructor(root) {
        this.root = root;
        this.config = JSON.parse(root.dataset.express || '{}');
        this.labels = this.config.labels || {};
        this.notice = root.querySelector('[data-express-message]');
        this.started = null;
        this.total = null;
        this.ready = false;
        this.unavailable = false;
        this.invoice = root.closest('form')?.querySelector('[name="invoice"]') || null;

        // Chi vuole la fattura passa dal modulo: il wallet non dà codice fiscale e partita IVA.
        this.invoice?.addEventListener('change', () => this.toggle());
        this.toggle();

        // Il riquadro reCAPTCHA dell'ospite sta fuori dal modulo (riquadro autonomo); chi è entrato non ce l'ha.
        this.captcha = root.querySelector('[data-wi-recaptcha-standalone]');
        const box = this.captcha?.querySelector('.g-recaptcha');
        // Casella a vista (solo v2, o sfida già aperta dal server): i bottoni aspettano la spunta.
        this.challenge = Boolean(box?.dataset.wiSiteKey) && (!box.dataset.wiScoreKey || box.dataset.wiChallenge === '1');
        this.captcha?.addEventListener('wi-recaptcha-change', (event) => {
            if (this.challenge) {
                this.lockButtons(!event.detail?.token);
            }
        });
        if (this.challenge) {
            this.lockButtons(true);
        }

        // Sulla scheda il foglio si apre col totale di quantità e variante scelte: Stripe mostra l'importo prima di ogni evento.
        this.productForm = this.config.source === 'product' ? document.querySelector('form[data-ecommerce-add-to-cart]') : null;
        if (this.productForm) {
            const refresh = () => this.elements?.update({ amount: this.productAmount() });
            this.productForm.addEventListener('input', refresh);
            this.productForm.addEventListener('change', refresh);
            root.addEventListener('express:amount', refresh);
        }

        this.waiting = Boolean(root.dataset.expressWait) && !expressNoWallet();
        if (this.waiting) {
            paySpinner(true, root.dataset.expressWait);
            this.timer = setTimeout(() => this.done(), EXPRESS_WAIT_MAX);
        }

        loadStripe().then(() => this.mount()).catch(() => this.hide());
    }

    // Fine dell'attesa: bottoni pronti, nessun wallet, errore o tetto raggiunto.
    done() {
        if (!this.waiting) {
            return;
        }
        this.waiting = false;
        clearTimeout(this.timer);
        paySpinner(false);
    }

    mount() {
        const { publishable_key: key, account } = this.config.stripe || {};
        const types = this.config.types || [];

        this.stripe = window.Stripe(key, account ? { stripeAccount: account } : {});
        this.elements = this.stripe.elements({
            mode: 'payment',
            amount: this.productForm ? this.productAmount() : this.config.amount,
            currency: this.config.currency,
            appearance: STRIPE_APPEARANCE,
        });
        this.element = this.elements.create('expressCheckout', {
            paymentMethods: walletMethods(types),
            ...(this.config.layout ? { layout: this.config.layout } : {}),
            ...(this.config.buttonType ? { buttonType: this.config.buttonType } : {}),
        });

        this.element.on('ready', ({ availablePaymentMethods }) => {
            expressNoWallet(!availablePaymentMethods);
            if (!availablePaymentMethods) {
                this.hide();
                return;
            }
            // Titolo, condizioni e «OPPURE» compaiono solo coi bottoni.
            this.ready = true;
            this.toggle();
            this.done();
        });
        this.element.on('loaderror', () => this.hide());
        this.element.on('click', (event) => this.click(event));
        this.element.on('shippingaddresschange', (event) => this.address(event));
        this.element.on('shippingratechange', (event) => this.rate(event));
        this.element.on('confirm', (event) => this.confirm(event));
        this.element.on('cancel', () => this.cancel());
        this.element.mount(this.root.querySelector('[data-express-element]'));
    }

    // Stripe vuole la risposta entro un secondo: il foglio si apre subito, il carrello a parte nasce intanto.
    // L'ospite passa dal reCAPTCHA: il token v3 si chiede dopo `resolve`, la casella va spuntata prima.
    click(event) {
        if (this.root.classList.contains('wi-express-locked')) {
            // Senza `resolve` il foglio non si apre: prima la casella.
            this.say(this.labels.captcha);
            return;
        }

        this.say('');
        event.resolve({
            emailRequired: true,
            phoneNumberRequired: true,
            shippingAddressRequired: true,
            billingAddressRequired: true,
            shippingRates: [{ id: 'pending', displayName: this.labels.pending_rate, amount: 0 }],
        });

        const data = { source: this.config.source };

        if (this.config.source === 'product') {
            // Variante e quantità sono quelle scelte nel modulo «Aggiungi».
            const form = document.querySelector('form[data-ecommerce-add-to-cart]');

            data.product_id = form?.querySelector('[name="product_id"]')?.value || this.config.product_id;
            data.quantity = form?.querySelector('[name="quantity"]')?.value || '1';
        }

        this.started = this.verify(data)
            .then((values) => {
                if (!values) {
                    // Nessun token: la casella è aperta e non spuntata.
                    return { ok: false, data: { status: 618, message: this.labels.captcha } };
                }

                return this.post('start', values).then((reply) => {
                    // Il token della casella vale una volta: si rinnova per il prossimo avvio.
                    if (values['g-recaptcha-type'] === 'checkbox') {
                        window.wiRecaptchaReset?.(this.captcha);
                    }

                    return reply;
                });
            })
            .then((reply) => {
                this.total = reply.ok ? reply.data.total : null;
                this.startError = reply.ok ? '' : reply.data.message || '';

                // Punteggio basso: la casella si apre sotto i bottoni, che aspettano la spunta.
                if (reply.data?.status === 618) {
                    this.challenge = true;
                    window.wiRecaptchaChallenge?.(this.captcha);
                    this.lockButtons(true);
                    this.say(this.labels.captcha);
                }

                return reply;
            });
    }

    // Il token reCAPTCHA dell'ospite per `start`; null se la casella è aperta e non spuntata.
    async verify(data) {
        if (!this.captcha || typeof window.wiRecaptchaToken !== 'function') {
            return data;
        }

        const answer = await window.wiRecaptchaToken(this.captcha).catch(() => null);

        return answer ? { ...data, 'g-recaptcha-token': answer.token, 'g-recaptcha-action': answer.action, 'g-recaptcha-type': answer.type } : null;
    }

    // Bottoni spenti finché la casella non è spuntata (vedi express.css).
    lockButtons(on) {
        this.root.classList.toggle('wi-express-locked', on);
    }

    async address(event) {
        const reply = await this.shipping(event.address, '');

        if (!reply) {
            // Se il carrello a parte non è nato (prodotto finito, carrello vuoto) si dice perché.
            this.say(this.startError || this.labels.unavailable);
            event.reject();

            return;
        }

        event.resolve({ shippingRates: reply.rates });
    }

    async rate(event) {
        const reply = await this.shipping(this.lastAddress, event.shippingRate.id);

        if (!reply) {
            this.say(this.labels.error);
            event.reject();

            return;
        }

        event.resolve();
    }

    // Tariffe e totale per l'indirizzo (parziale) del foglio; null se lì non si spedisce o il carrello non è partito.
    async shipping(address, rate) {
        const started = this.started ? await this.started : null;

        if (!started?.ok) {
            return null;
        }

        this.lastAddress = address;
        const reply = await this.post('shipping', { address: JSON.stringify(address || {}), rate });

        if (!reply.ok) {
            return null;
        }

        this.total = reply.data.total;
        this.elements.update({ amount: reply.data.amount });

        return reply.data;
    }

    async confirm(event) {
        const { error: invalid } = await this.elements.submit();

        if (invalid) {
            event.paymentFailed({ reason: 'fail' });
            this.say(invalid.message || this.labels.error);

            return;
        }

        const wallet = {
            email: event.billingDetails?.email || '',
            phone: event.billingDetails?.phone || '',
            shipping: event.shippingAddress || {},
            billing: { name: event.billingDetails?.name || '', address: event.billingDetails?.address || {} },
            rate: event.shippingRate?.id || '',
        };
        const placed = await this.post('place', {
            wallet: JSON.stringify(wallet),
            type: event.expressPaymentType || '',
            expected_total: this.total ?? '',
        });

        if (!placed.ok) {
            event.paymentFailed({ reason: placed.data.error === 'no_shipping' ? 'address_unserviceable' : 'fail' });
            this.say(placed.data.message || this.labels.error);

            return;
        }

        paySpinner(true);

        try {
            const { error } = await this.stripe.confirmPayment({
                elements: this.elements,
                clientSecret: placed.data.client_secret,
                confirmParams: { return_url: new URL(placed.data.return_url, window.location.href).href },
            });

            // Senza errore Stripe ha già portato il cliente al ritorno.
            if (error) {
                this.say(payAlert(error.message || this.labels.error));
                await this.post('cancel', {});
            }
        } catch (error) {
            this.say(this.labels.error);
            await this.post('cancel', {});
        } finally {
            paySpinner(false);
        }
    }

    // Il foglio chiuso senza pagare: l'eventuale ordine rapido si annulla e il carrello a parte si butta.
    cancel() {
        if (!this.started) {
            return;
        }

        this.started = null;
        this.total = null;
        this.post('cancel', {});

        // Il cliente ritrova la casella aperta sotto i bottoni.
        if (this.challenge) {
            this.say(this.labels.captcha);
        }
    }

    async post(action, values) {
        const body = new URLSearchParams({ csrf_token: this.config.csrf || '', ...values });

        try {
            const response = await fetch(this.config.urls[action], {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            });
            const data = await response.json();

            if (data.redirect) {
                window.location.href = data.redirect;
            }

            return { ok: response.ok && data.success === true, data };
        } catch (error) {
            return { ok: false, data: {} };
        }
    }

    hide() {
        this.unavailable = true;
        this.toggle();
        this.done();
    }

    toggle() {
        this.root.toggleAttribute('data-express-loading', !this.ready && !this.unavailable);
        this.root.hidden = this.unavailable || !!this.invoice?.checked;
    }

    say(message) {
        if (this.notice) {
            this.notice.textContent = message || '';
            this.notice.hidden = !message;
        }
    }
}

document.querySelectorAll('[data-express]').forEach((root) => new Express(root));

document.querySelectorAll('[data-checkout-pay]').forEach((root) => new CheckoutPay(root));

window.Checkout = Checkout;
window.ecommerceCheckout = new Checkout();
