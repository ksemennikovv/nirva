document.addEventListener('DOMContentLoaded', function () {

    var ALL_TYPES  = ['accessible', 'locked', 'general', 'personal'];
    var TYPE_NAMES = { accessible: 'Доступные', locked: 'Недоступные', general: 'Общие', personal: 'Персональные' };

    // activeTypes / activeTopics: хранят выбранные значения; пустой Set = ничего не выбрано
    var activeTypes  = new Set(ALL_TYPES); // по умолчанию все
    var activeTopics = new Set();          // пусто = все темы (особый случай: инициализируется после рендера)
    var allTopicsSelected = true;          // true пока "Все темы" отмечено

    // ── Применить фильтры ─────────────────────────────────────────────────────
    function applyFilters() {
        var anyVisible = false;

        document.querySelectorAll('.med-card').forEach(function (card) {
            var type       = card.dataset.medType;
            var accessible = card.dataset.accessible;
            var topicSlug  = card.dataset.topicSlug;

            var passType = false;
            if (activeTypes.has('accessible') && accessible === '1') passType = true;
            if (activeTypes.has('locked')     && accessible === '0') passType = true;
            if (activeTypes.has('general')    && type === 'general')  passType = true;
            if (activeTypes.has('personal')   && type === 'personal') passType = true;

            var passTopic = allTopicsSelected || activeTopics.has(topicSlug);
            var show = passType && passTopic;
            card.style.display = show ? '' : 'none';
            if (show) anyVisible = true;
        });

        document.querySelectorAll('.med-section').forEach(function (section) {
            var visible = Array.from(section.querySelectorAll('.med-card'))
                .filter(function (c) { return c.style.display !== 'none'; });
            section.style.display = visible.length > 0 ? '' : 'none';
            var counter = section.querySelector('.med-section__count');
            if (counter) counter.textContent = visible.length + ' медитации';
        });

        var emptyEl = document.getElementById('med-empty');
        if (emptyEl) emptyEl.hidden = anyVisible;
    }

    // ── Утилита: обновить чекбокс "Все" по состоянию дочерних ───────────────
    function syncAllCheckbox(allCb, childCbs) {
        var allChecked = Array.from(childCbs).every(function (cb) { return cb.checked; });
        allCb.checked       = allChecked;
        allCb.indeterminate = !allChecked && Array.from(childCbs).some(function (cb) { return cb.checked; });
    }

    // ── Дропдаун ТИПОВ ───────────────────────────────────────────────────────
    var typeBtn   = document.getElementById('med-dd-type-btn');
    var typePanel = document.getElementById('med-dd-type-panel');
    var typeLabel = document.getElementById('med-dd-type-label');
    var typeDd    = document.getElementById('med-dd-type');
    var typeAllCb = document.getElementById('med-type-all');
    var typeCbs   = typePanel ? typePanel.querySelectorAll('.med-dd__cb:not(.med-dd__cb--all)') : [];

    function updateTypeState() {
        var checked = Array.from(typeCbs).filter(function (cb) { return cb.checked; });
        activeTypes = new Set(checked.map(function (cb) { return cb.value; }));

        syncAllCheckbox(typeAllCb, typeCbs);

        if (activeTypes.size === ALL_TYPES.length) {
            typeLabel.textContent = 'Все';
            typeBtn.classList.remove('med-dd__trigger--active');
        } else if (activeTypes.size === 0) {
            typeLabel.textContent = 'Не выбрано';
            typeBtn.classList.add('med-dd__trigger--active');
        } else {
            typeLabel.textContent = ALL_TYPES
                .filter(function (t) { return activeTypes.has(t); })
                .map(function (t) { return TYPE_NAMES[t]; })
                .join(', ');
            typeBtn.classList.add('med-dd__trigger--active');
        }
        applyFilters();
    }

    if (typeBtn && typePanel) {
        typeBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !typePanel.hidden;
            closeAllDropdowns();
            if (!open) openDropdown(typeDd, typePanel, typeBtn);
        });

        typeAllCb.addEventListener('change', function () {
            typeCbs.forEach(function (cb) { cb.checked = typeAllCb.checked; });
            updateTypeState();
        });

        typeCbs.forEach(function (cb) {
            cb.addEventListener('change', updateTypeState);
        });
    }

    // ── Дропдаун ТЕМ ─────────────────────────────────────────────────────────
    var topicBtn    = document.getElementById('med-dd-topic-btn');
    var topicPanel  = document.getElementById('med-dd-topic-panel');
    var topicLabel  = document.getElementById('med-dd-topic-label');
    var topicDd     = document.getElementById('med-dd-topic');
    var topicSearch = document.getElementById('med-dd-topic-search');
    var topicAllCb  = document.getElementById('med-topic-all');
    var topicCbs    = topicPanel ? topicPanel.querySelectorAll('.med-dd__cb--topic') : [];
    // Инициализируем activeTopics всеми темами (все чекнуты по умолчанию)
    activeTopics = new Set(Array.from(topicCbs).map(function (cb) { return cb.value; }));
    allTopicsSelected = true;

    function updateTopicState() {
        var checked = Array.from(topicCbs).filter(function (cb) { return cb.checked; });
        activeTopics = new Set(checked.map(function (cb) { return cb.value; }));
        allTopicsSelected = (activeTopics.size === topicCbs.length);

        syncAllCheckbox(topicAllCb, topicCbs);

        if (allTopicsSelected) {
            topicLabel.textContent = 'Все темы';
            topicBtn.classList.remove('med-dd__trigger--active');
        } else if (activeTopics.size === 0) {
            topicLabel.textContent = 'Не выбрано';
            topicBtn.classList.add('med-dd__trigger--active');
        } else if (activeTopics.size === 1) {
            var single = checked[0].dataset.name || checked[0].value;
            topicLabel.textContent = single;
            topicBtn.classList.add('med-dd__trigger--active');
        } else {
            topicLabel.textContent = activeTopics.size + ' темы';
            topicBtn.classList.add('med-dd__trigger--active');
        }
        applyFilters();
    }

    if (topicBtn && topicPanel) {
        topicBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !topicPanel.hidden;
            closeAllDropdowns();
            if (!open) {
                openDropdown(topicDd, topicPanel, topicBtn);
                if (topicSearch) topicSearch.focus();
            }
        });

        topicAllCb.addEventListener('change', function () {
            // показываем только видимые (не скрытые поиском)
            Array.from(topicCbs).forEach(function (cb) {
                if (cb.closest('.med-dd__item').style.display !== 'none') {
                    cb.checked = topicAllCb.checked;
                }
            });
            updateTopicState();
        });

        topicCbs.forEach(function (cb) {
            cb.addEventListener('change', updateTopicState);
        });

        if (topicSearch) {
            topicSearch.addEventListener('input', function () {
                var q = this.value.trim().toLowerCase();
                Array.from(topicCbs).forEach(function (cb) {
                    var item = cb.closest('.med-dd__item');
                    var name = (cb.dataset.name || '').toLowerCase();
                    item.style.display = (!q || name.includes(q)) ? '' : 'none';
                });
                // "Все темы" всегда видна
                topicAllCb.closest('.med-dd__item').style.display = '';
            });
        }
    }

    // ── Открыть / закрыть ────────────────────────────────────────────────────
    function openDropdown(dd, panel, btn) {
        panel.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        dd.classList.add('med-dd--open');
    }

    function closeAllDropdowns() {
        [[typeDd, typePanel, typeBtn], [topicDd, topicPanel, topicBtn]].forEach(function (set) {
            var dd = set[0], panel = set[1], btn = set[2];
            if (panel) panel.hidden = true;
            if (btn)   btn.setAttribute('aria-expanded', 'false');
            if (dd)    dd.classList.remove('med-dd--open');
        });
        if (topicSearch) { topicSearch.value = ''; topicSearch.dispatchEvent(new Event('input')); }
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.med-dd')) closeAllDropdowns();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAllDropdowns();
    });

    // ── Кнопки "В корзину" ────────────────────────────────────────────────────
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

    // ── Кнопка Play на доступных карточках → сразу в плеер ──────────────────
    document.querySelectorAll('.med-card__play-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (typeof MedPlayer === 'undefined' || typeof MED_CATALOG === 'undefined') return;
            var card = this.closest('.med-card');
            if (!card) return;
            var slug = card.dataset.categorySlug;
            var idx  = parseInt(card.dataset.itemIndex || '0', 10);
            var cat  = MED_CATALOG.find(function (c) { return c.slug === slug; });
            if (!cat || !cat.items[idx]) return;
            MedPlayer.play(cat.items[idx]);
        });
    });

    // ── Открытие MedModal ─────────────────────────────────────────────────────
    document.querySelectorAll('.med-card').forEach(function (card) {
        card.addEventListener('click', function (e) {
            if (e.target.closest('.med-card__demo') || e.target.closest('.med-card__cart') || e.target.closest('.med-card__play-btn')) return;
            if (typeof MedModal === 'undefined' || typeof MED_CATALOG === 'undefined') return;
            var categorySlug = card.dataset.categorySlug;
            var itemIndex    = parseInt(card.dataset.itemIndex || '0', 10);
            var category = MED_CATALOG.find(function (c) { return c.slug === categorySlug; });
            if (!category) return;
            MedModal.open(category.items, itemIndex);
        });
    });

});
