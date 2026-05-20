document.addEventListener('DOMContentLoaded', function () {

    // ── Чипсы тем → поле ввода разбора ──────────────────────────────────────
    document.querySelectorAll('.db-topic-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var input = document.getElementById('db-input');
            if (input) { input.value = this.textContent.trim(); input.focus(); }
        });
    });

    // ── Создание нового разбора ───────────────────────────────────────────────
    var isPaywall = !!document.querySelector('[data-paywall="1"]');

    function startAnalysis(initialText) {
        if (!initialText || !initialText.trim()) {
            var input = document.getElementById('db-input');
            if (input) {
                input.focus();
                input.classList.add('db-input--error');
                setTimeout(function () { input.classList.remove('db-input--error'); }, 1500);
            }
            return;
        }

        var sendBtn  = document.getElementById('db-send-btn');
        var startBtn = document.getElementById('db-start-analysis');
        if (sendBtn)  sendBtn.disabled  = true;
        if (startBtn) startBtn.disabled = true;

        fetch('/pages/analysis/api/create.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ initial_text: initialText, paywall: isPaywall }),
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    if (sendBtn)  sendBtn.disabled  = false;
                    if (startBtn) startBtn.disabled = false;
                    return;
                }
                sessionStorage.setItem('nirva_analysis_autostart', initialText);
                if (isPaywall) sessionStorage.setItem('nirva_paywall', '1');
                window.location.href = '/analysis/' + data.id + '/';
            })
            .catch(function () {
                if (sendBtn)  sendBtn.disabled  = false;
                if (startBtn) startBtn.disabled = false;
            });
    }

    // ── Кнопка "отправить" (состояние 1) ─────────────────────────────────────
    var sendBtn = document.getElementById('db-send-btn');
    if (sendBtn) {
        sendBtn.addEventListener('click', function () {
            var input = document.getElementById('db-input');
            startAnalysis(input ? input.value.trim() : '');
        });
    }

    // ── Enter в поле ввода ────────────────────────────────────────────────────
    var dbInput = document.getElementById('db-input');
    if (dbInput) {
        dbInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                startAnalysis(dbInput.value.trim());
            }
        });
    }

    // ── Кнопка "Начать новый разбор" ─────────────────────────────────────────
    var startBtn = document.getElementById('db-start-analysis');
    if (startBtn) {
        startBtn.addEventListener('click', function () {
            var input = document.getElementById('db-input');
            startAnalysis(input ? input.value.trim() : '');
        });
    }

    // ── Кнопка "Обновить подписку" → plan-modal ───────────────────────────────
    var upgradeBtn = document.getElementById('db-upgrade-plan');
    if (upgradeBtn) {
        upgradeBtn.addEventListener('click', function () {
            if (typeof PlanModal !== 'undefined') PlanModal.open();
        });
    }

    // ── Дневник (состояния 2 и 3) ────────────────────────────────────────────
    var diarySend = document.getElementById('db-diary-send');
    if (diarySend) {
        diarySend.addEventListener('click', function () {
            var input = document.getElementById('db-diary-input');
            var text  = input ? input.value.trim() : '';
            if (!text) return;
            diarySend.disabled = true;
            sessionStorage.setItem('nirva_diary_autostart', text);
            window.location.href = '/diary/';
        });
    }

    // ── Polling медитаций (когда идёт генерация) ─────────────────────────────
    (function () {
        var medBlock = document.querySelector('[data-med-pending="1"]');
        if (!medBlock) return;

        var analysisId = parseInt(medBlock.dataset.lastAnalysisId || '0', 10);
        if (!analysisId) return;

        var knownIds     = [];
        var pollInterval = null;
        var pollCount    = 0;
        var maxPolls     = 100;

        function renderCard(med, idx, slug) {
            var bg = med.image_url
                ? 'url(' + med.image_url + ') center/cover no-repeat'
                : 'linear-gradient(145deg,#2d3436,#636e72)';
            var cartBtn = med.is_purchased ? '' :
                '<button class="med-row-card__cart" data-cart-id="' + med.id + '" type="button">' +
                '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
                '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>' +
                '<path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>' +
                '</svg></button>';
            var footer = med.is_purchased
                ? '<span class="med-row-card__play-full">▶ Слушать</span>'
                : '<span class="med-row-card__demo">▶ Демо</span>' +
                  (med.price > 0 ? '<span class="med-row-card__price">' + med.price + ' ₽</span>' : '');
            return '<div class="med-row-card' + (med.is_purchased ? ' med-row-card--owned' : '') + '"' +
                   ' data-slug="' + slug + '" data-idx="' + idx + '" style="cursor:pointer">' +
                   '<div class="med-row-card__bg" style="background:' + bg + '"></div>' +
                   cartBtn +
                   '<div class="med-row-card__body"><div class="med-row-card__title">' + (med.title || 'Медитация') + '</div></div>' +
                   '<div class="med-row-card__footer">' + footer + '</div>' +
                   '</div>';
        }

        function injectMeditations(readyMeds) {
            var slug = 'analysis_' + analysisId;
            var placeholder = document.getElementById('db-med-placeholder');

            var existing = medBlock.querySelector('.med-row-block');
            if (!existing) {
                var topic = medBlock.dataset.topic || 'разбора';
                if (placeholder) placeholder.remove();
                medBlock.insertAdjacentHTML('beforeend',
                    '<div class="med-row-block">' +
                    '<div class="med-row-block__head">' +
                    '<div><div class="med-row-block__title">Медитации для вас</div>' +
                    '<div class="med-row-block__sub">Персонально по теме разбора</div></div>' +
                    '<a href="/meditations/?filter=personal" class="med-row-block__more">Все →</a>' +
                    '</div>' +
                    '<div class="med-row-scroll" data-med-row-slug="' + slug + '"></div>' +
                    '</div>');
            }

            var scroll = medBlock.querySelector('.med-row-scroll');
            if (!scroll) return;

            readyMeds.forEach(function (med) {
                if (knownIds.indexOf(med.id) !== -1) return;
                knownIds.push(med.id);
                var idx = knownIds.length - 1;
                scroll.insertAdjacentHTML('beforeend', renderCard(med, idx, slug));

                if (typeof window.MED_CATALOG === 'undefined') window.MED_CATALOG = [];
                var cat = window.MED_CATALOG.find(function (c) { return c.slug === slug; });
                var item = {
                    id: med.id, title: med.title || 'Медитация',
                    description: med.description || '', price: med.price || 0,
                    demo_audio_url: med.demo_audio_url || '',
                    full_audio_url: med.full_audio_url || '',
                    image_url: med.image_url || '',
                    gradient: 'linear-gradient(145deg,#2d3436,#636e72)',
                    free: med.is_purchased,
                };
                if (cat) { cat.items.push(item); }
                else { window.MED_CATALOG.push({ slug: slug, items: [item] }); }
            });

            scroll.querySelectorAll('.med-row-card[data-slug="' + slug + '"]').forEach(function (card) {
                if (card.dataset.medRowBound) return;
                card.dataset.medRowBound = '1';
                card.addEventListener('click', function (e) {
                    if (e.target.closest('.med-row-card__cart')) return;
                    if (typeof MedModal === 'undefined') return;
                    var idx  = parseInt(this.dataset.idx || '0', 10);
                    var cat2 = window.MED_CATALOG.find(function (c) { return c.slug === slug; });
                    if (cat2) MedModal.open(cat2.items, idx);
                });
                var cartBtn = card.querySelector('.med-row-card__cart[data-cart-id]');
                if (cartBtn) {
                    cartBtn.addEventListener('click', function (e) {
                        e.stopPropagation();
                        if (typeof MedCart === 'undefined') return;
                        var id   = parseInt(this.dataset.cartId, 10);
                        var cat2 = window.MED_CATALOG.find(function (c) { return c.slug === slug; });
                        var itm  = cat2 ? cat2.items.find(function (m) { return m.id === id; }) : null;
                        if (!itm) itm = { id: id, title: '', price: 0, image_url: '', gradient: '' };
                        MedCart.toggle(itm, this);
                        this.classList.toggle('in-cart', MedCart.has(id));
                    });
                }
            });
        }

        function poll() {
            pollCount++;
            if (pollCount > maxPolls) { clearInterval(pollInterval); return; }

            fetch('/pages/analysis/api/get-meditations.php?id=' + analysisId)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) return;
                    var ready = data.data.ready || [];
                    if (ready.length > 0) injectMeditations(ready);
                    if (data.data.all_ready) clearInterval(pollInterval);
                })
                .catch(function () {});
        }

        setTimeout(function () {
            poll();
            pollInterval = setInterval(poll, 3000);
        }, 1500);
    }());

});
