/**
 * features/med-cart/med-cart.js
 *
 * MedCart.add(item)        — добавить { id, title, price, image_url, gradient }
 * MedCart.remove(id)       — удалить
 * MedCart.toggle(item, sourceEl) — добавить/убрать + анимация полёта
 * MedCart.has(id)          — boolean
 * MedCart.open()           — открыть модаль
 * MedCart.close()          — закрыть
 */

const MedCart = {

    _KEY: 'nirva_cart_v2',

    // ── Хранилище ─────────────────────────────────────────────────────────────

    getItems() {
        try { return JSON.parse(localStorage.getItem(this._KEY) || '[]'); }
        catch { return []; }
    },

    _save(items) {
        localStorage.setItem(this._KEY, JSON.stringify(items));
        this._updateBadge();
        this._syncButtons();
    },

    has(id) {
        return this.getItems().some(i => i.id === id);
    },

    add(item) {
        const items = this.getItems();
        if (!items.some(i => i.id === item.id)) {
            items.push({ id: item.id, title: item.title, price: item.price,
                         image_url: item.image_url || '', gradient: item.gradient || '' });
            this._save(items);
        }
    },

    remove(id) {
        this._save(this.getItems().filter(i => i.id !== id));
        this._renderModal();
    },

    toggle(item, sourceEl) {
        if (this.has(item.id)) {
            this.remove(item.id);
        } else {
            this.add(item);
            if (sourceEl) this._flyTo(sourceEl, item);
        }
    },

    // ── Инициализация ─────────────────────────────────────────────────────────

    init() {
        // Встраиваем триггер в хедер
        const trigger = document.getElementById('med-cart-trigger');
        const header  = document.querySelector('.app-header');
        if (trigger && header) {
            trigger.hidden = false;
            // Вставляем перед "Выйти" — через его реального родителя (nav или header)
            const logout = header.querySelector('.app-header__logout');
            if (logout) logout.parentNode.insertBefore(trigger, logout);
            else header.appendChild(trigger);
            trigger.addEventListener('click', () => MedCart.open());
        }

        // Закрытие
        const closeBtn = document.getElementById('med-cart-close');
        const overlay  = document.querySelector('.med-cart-modal__overlay');
        if (closeBtn) closeBtn.addEventListener('click', () => MedCart.close());
        if (overlay)  overlay.addEventListener('click',  () => MedCart.close());

        this._updateBadge();
        this._syncButtons();
    },

    // ── Модальное окно ────────────────────────────────────────────────────────

    open() {
        const modal = document.getElementById('med-cart-modal');
        if (!modal) return;
        this._renderModal();
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
    },

    close() {
        const modal = document.getElementById('med-cart-modal');
        if (modal) modal.hidden = true;
        document.body.style.overflow = '';
    },

    _renderModal() {
        const items     = this.getItems();
        const listEl    = document.getElementById('med-cart-items');
        const emptyEl   = document.getElementById('med-cart-empty');
        const footerEl  = document.getElementById('med-cart-footer');
        const totalEl   = document.getElementById('med-cart-total');
        const buyBtn    = document.getElementById('med-cart-buy');

        if (!listEl) return;

        listEl.innerHTML = '';

        if (items.length === 0) {
            emptyEl.hidden  = false;
            footerEl.hidden = true;
            return;
        }

        emptyEl.hidden  = true;
        footerEl.hidden = false;

        items.forEach(item => {
            const bg = item.image_url
                ? 'url(' + item.image_url + ') center/cover no-repeat'
                : (item.gradient || 'linear-gradient(145deg,#2d3436,#636e72)');

            const el = document.createElement('div');
            el.className = 'med-cart-item';
            el.innerHTML =
                '<div class="med-cart-item__bg" style="background:' + bg + '">' +
                    '<div class="med-cart-item__title">' + this._esc(item.title) + '</div>' +
                    '<button class="med-cart-item__remove" data-id="' + item.id + '">✕</button>' +
                '</div>';
            listEl.appendChild(el);

            el.querySelector('.med-cart-item__remove').addEventListener('click', e => {
                e.stopPropagation();
                MedCart.remove(item.id);
            });
        });

        const total = items.reduce((s, i) => s + (i.price || 0), 0);
        totalEl.textContent = 'Купить ' + items.length + ' шт. за ' + total.toLocaleString('ru') + ' ₽';
        buyBtn.textContent  = total > 0
            ? 'Купить за ' + total.toLocaleString('ru') + ' ₽'
            : 'Получить бесплатно';
        buyBtn.disabled = false;
    },

    // ── Бейдж ────────────────────────────────────────────────────────────────

    _updateBadge() {
        const badge = document.getElementById('med-cart-badge');
        if (!badge) return;
        const count = this.getItems().length;
        badge.textContent = count;
        badge.style.display = count > 0 ? '' : 'none';
    },

    // ── Синхронизация кнопок "В корзину" по всей странице ────────────────────

    _syncButtons() {
        document.querySelectorAll('[data-cart-id]').forEach(btn => {
            const id     = parseInt(btn.dataset.cartId, 10);
            const inCart = MedCart.has(id);
            btn.textContent    = inCart ? '✓ В корзине' : 'В корзину';
            btn.dataset.inCart = inCart ? '1' : '0';
            btn.classList.toggle('in-cart', inCart);
        });
        // Синхронизация кнопки в MedModal если открыт
        const modalCartBtn = document.getElementById('med-modal-cart-btn');
        if (modalCartBtn && modalCartBtn.dataset.cartId) {
            const id = parseInt(modalCartBtn.dataset.cartId, 10);
            const inCart = MedCart.has(id);
            modalCartBtn.textContent = inCart ? '✓ В корзине' : 'В корзину';
            modalCartBtn.classList.toggle('in-cart', inCart);
        }
    },

    // ── Анимация полёта ───────────────────────────────────────────────────────

    _flyTo(sourceEl, item) {
        const trigger = document.getElementById('med-cart-trigger');
        if (!trigger) return;

        const from   = sourceEl.getBoundingClientRect();
        const to     = trigger.getBoundingClientRect();
        const flyEl  = document.getElementById('med-cart-fly');
        if (!flyEl) return;

        const bg = item.image_url
            ? 'url(' + item.image_url + ') center/cover no-repeat'
            : (item.gradient || 'linear-gradient(145deg,#2d3436,#636e72)');

        flyEl.style.background = bg;
        flyEl.style.left    = (from.left + from.width / 2 - 30) + 'px';
        flyEl.style.top     = (from.top  + from.height / 2 - 30) + 'px';
        flyEl.style.opacity = '1';
        flyEl.style.transform = 'scale(1)';
        flyEl.style.transition = 'none';
        flyEl.hidden = false;

        // Старт анимации на следующем фрейме
        requestAnimationFrame(() => requestAnimationFrame(() => {
            flyEl.style.transition = 'left .55s cubic-bezier(.25,.46,.45,.94),' +
                                     'top .55s cubic-bezier(.25,.46,.45,.94),' +
                                     'transform .55s ease,' +
                                     'opacity .4s ease .25s';
            flyEl.style.left      = (to.left + to.width  / 2 - 15) + 'px';
            flyEl.style.top       = (to.top  + to.height / 2 - 15) + 'px';
            flyEl.style.transform = 'scale(0.3)';
            flyEl.style.opacity   = '0';
        }));

        setTimeout(() => {
            flyEl.hidden = true;
            // Пульс бейджа
            const badge = document.getElementById('med-cart-badge');
            if (badge) {
                badge.classList.remove('pulse');
                void badge.offsetWidth;
                badge.classList.add('pulse');
            }
        }, 650);
    },

    _esc(str) {
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    },
};

document.addEventListener('DOMContentLoaded', () => MedCart.init());
