document.addEventListener('DOMContentLoaded', function () {

    var activeType  = 'all';
    var activeTopic = 'all';

    // ── Фильтрация ────────────────────────────────────────────────────────────

    function applyFilters() {
        var allCards    = document.querySelectorAll('.med-card');
        var allSections = document.querySelectorAll('.med-section');
        var anyVisible  = false;

        allCards.forEach(function (card) {
            var type       = card.dataset.medType;       // 'general' | 'personal'
            var accessible = card.dataset.accessible;    // '1' | '0'
            var topicSlug  = card.dataset.topicSlug;

            var passType = true;
            if (activeType === 'accessible') passType = accessible === '1';
            else if (activeType === 'locked')   passType = accessible === '0';
            else if (activeType === 'general')  passType = type === 'general';
            else if (activeType === 'personal') passType = type === 'personal';

            var passTopic = activeTopic === 'all' || topicSlug === activeTopic;

            var show = passType && passTopic;
            card.style.display = show ? '' : 'none';
            if (show) anyVisible = true;
        });

        // Скрываем секции, в которых не осталось видимых карточек
        allSections.forEach(function (section) {
            var visibleCards = Array.from(section.querySelectorAll('.med-card'))
                .filter(function (c) { return c.style.display !== 'none'; });
            section.style.display = visibleCards.length > 0 ? '' : 'none';

            // Обновляем счётчик
            var counter = section.querySelector('.med-section__count');
            if (counter) counter.textContent = visibleCards.length + ' медитации';
        });

        var emptyEl = document.getElementById('med-empty');
        if (emptyEl) emptyEl.hidden = anyVisible;
    }

    // ── Пересчёт доступных тем при смене типа ────────────────────────────────

    function syncTopicChips() {
        var topicWrap = document.getElementById('med-filters-topic');
        if (!topicWrap) return;

        // Собираем topic-slug карточек, которые проходят по типу (без учёта темы)
        var visibleTopics = new Set();
        document.querySelectorAll('.med-card').forEach(function (card) {
            var type = card.dataset.medType;
            var passType = true;
            if (activeType === 'accessible') passType = card.dataset.accessible === '1';
            else if (activeType === 'locked')   passType = card.dataset.accessible === '0';
            else if (activeType === 'general')  passType = type === 'general';
            else if (activeType === 'personal') passType = type === 'personal';
            if (passType) visibleTopics.add(card.dataset.topicSlug);
        });

        // Показываем/скрываем чипсы тем
        topicWrap.querySelectorAll('.med-filter--topic').forEach(function (btn) {
            var topic = btn.dataset.topic;
            if (topic === 'all') { btn.style.display = ''; return; }
            var show = visibleTopics.has(topic);
            btn.style.display = show ? '' : 'none';
            // Если активная тема пропала — сбросить на "все темы"
            if (!show && btn.classList.contains('active')) {
                btn.classList.remove('active');
                topicWrap.querySelector('[data-topic="all"]').classList.add('active');
                activeTopic = 'all';
            }
        });

        // Скрыть весь блок тем если видна только кнопка "Все темы"
        var visibleChips = topicWrap.querySelectorAll('.med-filter--topic:not([style*="none"])');
        topicWrap.style.display = visibleChips.length > 1 ? '' : 'none';
    }

    // ── Фильтр по типу ────────────────────────────────────────────────────────

    document.querySelectorAll('#med-filters-type .med-filter').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#med-filters-type .med-filter')
                .forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            activeType = this.dataset.type;
            syncTopicChips();
            applyFilters();
        });
    });

    // ── Фильтр по теме ────────────────────────────────────────────────────────

    document.querySelectorAll('#med-filters-topic .med-filter--topic').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('#med-filters-topic .med-filter--topic')
                .forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            activeTopic = this.dataset.topic;
            applyFilters();
        });
    });

    // Начальная синхронизация
    syncTopicChips();

    // ── Кнопки "В корзину" на карточках ──────────────────────────────────────

    document.querySelectorAll('.med-card__cart[data-cart-id]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (typeof MedCart === 'undefined' || typeof MED_CATALOG === 'undefined') return;

            var id   = parseInt(this.dataset.cartId, 10);
            var card = this.closest('.med-card');
            var slug = card ? card.dataset.categorySlug : null;
            var idx  = card ? parseInt(card.dataset.itemIndex || '0', 10) : 0;

            var item = null;
            if (slug) {
                var cat = MED_CATALOG.find(function (c) { return c.slug === slug; });
                if (cat) item = cat.items[idx];
            }
            if (!item) item = { id: id, title: '', price: 0, image_url: '', gradient: '' };

            MedCart.toggle(item, btn);
        });
    });

    // ── Открытие MedModal по клику на карточку ────────────────────────────────

    document.querySelectorAll('.med-card').forEach(function (card) {
        card.addEventListener('click', function (e) {
            if (e.target.closest('.med-card__demo') || e.target.closest('.med-card__cart')) return;

            if (typeof MedModal === 'undefined' || typeof MED_CATALOG === 'undefined') return;

            var categorySlug = card.dataset.categorySlug;
            var itemIndex    = parseInt(card.dataset.itemIndex || '0', 10);

            var category = MED_CATALOG.find(function (c) { return c.slug === categorySlug; });
            if (!category) return;

            // Открываем с учётом текущей позиции карточки среди видимых
            MedModal.open(category.items, itemIndex);
        });
    });

});
