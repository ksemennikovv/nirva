/**
 * features/med-modal/med-modal.js
 *
 * MedModal.open(items, startIndex)
 *   items: [{ id, title, description, price, demo_audio_url, gradient, free }]
 *   startIndex: 0-based index of the item to show first
 *
 * Переиспользуется на странице медитаций и на странице разбора.
 */

const MedModal = {

    _items:   [],
    _index:   0,
    _cart:    [],

    _el:       null,
    _audio:    null,

    init() {
        this._el    = document.getElementById('med-modal');
        this._audio = document.getElementById('med-modal-audio');
        if (!this._el) return;

        this._cart = JSON.parse(localStorage.getItem('nirva_cart') || '[]');

        document.getElementById('med-modal-close').addEventListener('click', () => MedModal.close());
        document.querySelector('.med-modal__overlay').addEventListener('click', () => MedModal.close());
        document.getElementById('med-modal-prev').addEventListener('click', () => MedModal._go(-1));
        document.getElementById('med-modal-next').addEventListener('click', () => MedModal._go(1));
        document.getElementById('med-modal-play').addEventListener('click', () => MedModal._launchPlayer());
        document.getElementById('med-modal-play-full').addEventListener('click', () => MedModal._launchPlayer());
        document.getElementById('med-modal-cart-btn').addEventListener('click', () => {
            const item   = MedModal._items[MedModal._index];
            const srcBtn = document.getElementById('med-modal-cart-btn');
            if (typeof MedCart !== 'undefined') {
                MedCart.toggle(item, srcBtn);
                MedModal._syncCartBtn(item);
            }
        });
        document.getElementById('med-modal-set-btn').addEventListener('click', () => MedModal._addSetToCart());

        // Свайп влево/вправо на мобильных
        let _touchStartX = 0;
        const sheet = this._el.querySelector('.med-modal__sheet');
        if (sheet) {
            sheet.addEventListener('touchstart', function (e) {
                _touchStartX = e.touches[0].clientX;
            }, { passive: true });
            sheet.addEventListener('touchend', function (e) {
                const dx = e.changedTouches[0].clientX - _touchStartX;
                if (Math.abs(dx) > 50) MedModal._go(dx < 0 ? 1 : -1);
            }, { passive: true });
        }

        // Прогресс-бар — клик для перемотки
        document.querySelector('.med-modal__progress-track').addEventListener('click', function (e) {
            const audio = MedModal._audio;
            if (!audio.duration) return;
            const rect = this.getBoundingClientRect();
            audio.currentTime = ((e.clientX - rect.left) / rect.width) * audio.duration;
        });

        // Обновление прогресса (демо-превью в самом модале)
        this._audio.addEventListener('timeupdate', () => MedModal._onTimeUpdate());
        this._audio.addEventListener('ended', () => MedModal._onEnded());
    },

    // ── Открытие ──────────────────────────────────────────────────────────────

    open(items, startIndex = 0) {
        if (!this._el) return;
        this._items = items;
        this._index = startIndex;
        this._render();
        this._el.hidden = false;
        document.body.style.overflow = 'hidden';
    },

    close() {
        if (!this._el) return;
        this._audio.pause();
        this._audio.src = '';
        this._el.hidden = true;
        document.body.style.overflow = '';
    },

    // ── Навигация ─────────────────────────────────────────────────────────────

    _go(dir) {
        const next = this._index + dir;
        if (next < 0 || next >= this._items.length) return;
        this._audio.pause();
        this._index = next;
        this._render();
    },

    // ── Рендер текущего слайда ────────────────────────────────────────────────

    _render() {
        const item  = this._items[this._index];
        const total = this._items.length;

        document.getElementById('med-modal-counter').textContent = (this._index + 1) + ' из ' + total;
        document.getElementById('med-modal-title').textContent   = item.title || 'Медитация';
        document.getElementById('med-modal-desc').textContent    = item.description || '';
        const cover = document.getElementById('med-modal-cover');
        if (item.image_url) {
            cover.style.background = 'url(' + item.image_url + ') center/cover no-repeat';
        } else {
            cover.style.background = item.gradient || 'linear-gradient(145deg,#2d3436,#636e72)';
        }

        // Навигационные кнопки
        document.getElementById('med-modal-prev').disabled = this._index === 0;
        document.getElementById('med-modal-next').disabled = this._index === total - 1;

        // Аудио — полное если куплено, иначе демо
        const audio = this._audio;
        audio.pause();
        const isOwned   = !!item.free;
        const audioSrc  = isOwned && item.full_audio_url ? item.full_audio_url : (item.demo_audio_url || '');
        audio.src = audioSrc;
        document.getElementById('med-modal-play').textContent = '▶';
        document.getElementById('med-modal-time').textContent = '0:00';
        document.getElementById('med-modal-progress').style.width = '0%';
        this._resetWaveform();

        // Переключаем режим: для купленных — большая кнопка, для демо — встроенный плеер
        const playFullBtn  = document.getElementById('med-modal-play-full');
        const playerEl     = document.getElementById('med-modal-player');
        const progressWrap = document.getElementById('med-modal-progress-wrap');
        if (playFullBtn)  playFullBtn.hidden  = !isOwned;
        if (playerEl)     playerEl.hidden     = isOwned;
        if (progressWrap) progressWrap.hidden = isOwned;

        // Цена одной
        const priceEl = document.getElementById('med-modal-price');
        priceEl.textContent = item.price > 0 ? item.price + ' ₽' : 'Бесплатно';

        // Кнопка корзины
        this._syncCartBtn(item);

        if (isOwned) {
            document.getElementById('med-modal-single-row').hidden = true;
        } else {
            document.getElementById('med-modal-single-row').hidden = false;
        }

        // Комплект — только если >1 медитации и есть платные
        const paidItems = this._items.filter(m => !m.free);
        if (paidItems.length > 1) {
            const totalPrice   = paidItems.reduce((s, m) => s + m.price, 0);
            const discount     = Math.round(totalPrice * (typeof MED_SET_DISCOUNT !== 'undefined' ? MED_SET_DISCOUNT : 0.15));
            const setPrice     = totalPrice - discount;
            document.getElementById('med-modal-set-price').textContent   = setPrice + ' ₽';
            document.getElementById('med-modal-set-count').textContent   = paidItems.length;
            document.getElementById('med-modal-saving').textContent      = 'Выгода ' + discount + ' ₽';
            document.getElementById('med-modal-set-row').hidden = false;
        } else {
            document.getElementById('med-modal-set-row').hidden = true;
        }
    },

    // ── Запуск MedPlayer ──────────────────────────────────────────────────────

    _launchPlayer() {
        const item = MedModal._items[MedModal._index];
        if (item && item.free && typeof MedPlayer !== 'undefined') {
            MedModal.close();
            // Запускаем конкретный трек; плеер сам загрузит полный список из API
            MedPlayer.play([item], 0);
            return;
        }
        MedModal._togglePlay();
    },

    // ── Встроенный плеер (демо-превью / фолбэк) ──────────────────────────────

    _togglePlay() {
        const audio = this._audio;
        const btn   = document.getElementById('med-modal-play');
        if (!audio.src) return;

        if (audio.paused) {
            audio.play().then(() => { btn.textContent = '⏸'; }).catch(() => {});
        } else {
            audio.pause();
            btn.textContent = '▶';
        }
    },

    _onTimeUpdate() {
        const audio    = this._audio;
        const progress = audio.duration ? (audio.currentTime / audio.duration) : 0;
        document.getElementById('med-modal-progress').style.width = (progress * 100) + '%';
        document.getElementById('med-modal-time').textContent     = MedModal._fmtTime(audio.currentTime);

        // Подсветка баров
        const bars   = document.querySelectorAll('.med-modal__bar');
        const filled = Math.round(progress * bars.length);
        bars.forEach((b, i) => b.classList.toggle('played', i < filled));
    },

    _onEnded() {
        document.getElementById('med-modal-play').textContent = '▶';
        document.getElementById('med-modal-progress').style.width = '0%';
        this._resetWaveform();
    },

    _resetWaveform() {
        document.querySelectorAll('.med-modal__bar').forEach(b => b.classList.remove('played'));
    },

    _fmtTime(sec) {
        const m = Math.floor(sec / 60);
        const s = Math.floor(sec % 60);
        return m + ':' + String(s).padStart(2, '0');
    },

    // ── Корзина ───────────────────────────────────────────────────────────────

    _saveCart() {
        localStorage.setItem('nirva_cart', JSON.stringify(this._cart));
    },

    _toggleCart() {
        const item = this._items[this._index];
        const idx  = this._cart.indexOf(item.id);
        if (idx === -1) {
            this._cart.push(item.id);
        } else {
            this._cart.splice(idx, 1);
        }
        this._saveCart();
        this._syncCartBtn(item);
    },

    _syncCartBtn(item) {
        const btn    = document.getElementById('med-modal-cart-btn');
        const inCart = typeof MedCart !== 'undefined' ? MedCart.has(item.id) : false;
        btn.textContent = inCart ? '✓ В корзине' : 'В корзину';
        btn.classList.toggle('in-cart', inCart);
        btn.dataset.cartId = item.id;
    },

    _addSetToCart() {
        const paidItems = this._items.filter(m => !m.free);
        const srcBtn    = document.getElementById('med-modal-set-btn');
        paidItems.forEach(m => {
            if (typeof MedCart !== 'undefined') MedCart.add(m);
        });
        if (paidItems.length > 0 && typeof MedCart !== 'undefined') {
            MedCart._flyTo(srcBtn, paidItems[0]);
        }
        this._syncCartBtn(this._items[this._index]);
        srcBtn.textContent = '✓ Набор в корзине';
    },
};

document.addEventListener('DOMContentLoaded', () => MedModal.init());
