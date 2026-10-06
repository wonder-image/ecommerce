class Cart {
    constructor(selector = '#cart-offpage') {
        this.element = document.querySelector(selector);
        this.lastTrigger = null;

        if (!this.element) {
            return;
        }

        this.backdrop = this.element.querySelector('.background-blur');
        this.panels = Array.from(this.element.querySelectorAll('.background-white, .cart-content'));
        this.content = this.element.querySelector('[data-ecommerce-mini-cart-content]');

        this.prepareAnimation();
        this.reflect(false);
        this.bind();
    }

    prepareAnimation() {
        if (this.backdrop) {
            this.backdrop.style.opacity = '0';
            this.backdrop.style.transitionProperty = 'opacity';
            this.backdrop.style.transitionDuration = '.3s';
        }

        this.panels.forEach(function (panel) {
            panel.style.right = '-150%';
            panel.style.transitionProperty = 'right';
            panel.style.transitionDuration = '.3s';
            panel.style.transitionDelay = '.3s';
        });
    }

    reflect(open) {
        this.element?.setAttribute('aria-hidden', String(!open));
        document.querySelectorAll('[data-ecommerce-cart-trigger]').forEach(function (trigger) {
            trigger.setAttribute('aria-expanded', String(open));
        });
    }

    async open(trigger = null, refresh = true) {
        if (!this.element) {
            return false;
        }

        this.lastTrigger = trigger || this.lastTrigger;
        window.siteMenuToggle?.(false);
        this.element.classList.add('show');
        this.element.classList.remove('no-interaction');
        this.reflect(true);

        window.requestAnimationFrame(() => {
            if (this.backdrop) {
                this.backdrop.style.transitionDelay = '.3s';
                this.backdrop.style.opacity = '1';
            }

            this.panels.forEach(function (panel) {
                panel.style.right = '0';
            });
        });

        window.disableScroll?.();

        if (refresh && !await this.refresh()) {
            return false;
        }

        this.element.querySelector('[data-cart-close].btn')?.focus();

        return true;
    }

    close(restoreFocus = true) {
        if (!this.element) {
            return;
        }

        if (this.backdrop) {
            this.backdrop.style.transitionDelay = '0s';
            this.backdrop.style.opacity = '0';
        }

        this.panels.forEach(function (panel) {
            panel.style.right = '-150%';
        });

        this.element.classList.remove('show');
        this.element.classList.add('no-interaction');
        this.reflect(false);
        window.enableScroll?.();

        if (restoreFocus) {
            this.lastTrigger?.focus();
        }
    }

    toggle(trigger = null) {
        return this.element?.classList.contains('show')
            ? (this.close(), Promise.resolve(true))
            : this.open(trigger);
    }

    async request(url, options = {}) {
        const response = await fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }, options));
        const payload = await response.json();

        if (!response.ok || typeof payload.html !== 'string') {
            throw new Error('Cart request failed');
        }

        return payload;
    }

    render(payload) {
        if (!this.content) {
            return;
        }

        this.content.innerHTML = payload.html;
        document.dispatchEvent(new CustomEvent('ecommerce:cart:updated', {
            detail: {
                count: payload.count,
                empty: payload.empty,
            },
        }));
    }

    async refresh() {
        try {
            this.render(await this.request(this.element.dataset.previewUrl));
            return true;
        } catch (error) {
            return false;
        }
    }

    async submit(form, openAfter) {
        const submitter = form.querySelector('[type="submit"]');

        if (submitter) {
            submitter.disabled = true;
        }

        try {
            const payload = await this.request(form.action, {
                method: 'POST',
                body: new FormData(form),
            });
            this.render(payload);

            if (openAfter) {
                await this.open(null, false);
            }

            return true;
        } catch (error) {
            return false;
        } finally {
            if (submitter) {
                submitter.disabled = false;
            }
        }
    }

    bind() {
        document.addEventListener('click', async (event) => {
            const trigger = event.target.closest('[data-ecommerce-cart-trigger]');

            if (trigger) {
                event.preventDefault();

                if (!await this.open(trigger)) {
                    window.location.assign(trigger.href);
                }

                return;
            }

            if (event.target.closest('#cart-offpage [data-cart-close]')) {
                this.close();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.element.classList.contains('show')) {
                this.close();
            }
        });

        document.addEventListener('submit', async (event) => {
            const form = event.target;

            if (!(form instanceof HTMLFormElement)) {
                return;
            }

            const isAdd = form.matches('[data-ecommerce-add-to-cart]');
            const isCartMutation = form.matches('[data-ecommerce-mini-cart-form]');

            if (!isAdd && !isCartMutation) {
                return;
            }

            event.preventDefault();

            if (!await this.submit(form, isAdd)) {
                HTMLFormElement.prototype.submit.call(form);
            }
        });
    }
}

window.Cart = Cart;
window.ecommerceCart = new Cart();
