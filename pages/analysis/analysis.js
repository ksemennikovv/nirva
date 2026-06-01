/**
 * analysis.js — Логика страницы разбора.
 */

document.addEventListener('DOMContentLoaded', function () {
    const page       = document.querySelector('.analysis-page');
    const analysisId = page ? parseInt(page.dataset.analysisId, 10) : 0;

    // ── Кнопка "Начать/Продолжить разбор" ────────────────────────────────────
    const btnOpenChat = document.getElementById('btn-open-chat');
    if (btnOpenChat) {
        btnOpenChat.addEventListener('click', function () {
            if (typeof ChatRoller !== 'undefined') ChatRoller.open();
        });
    }

    // ── Автозапуск чата при переходе с дашборда ──────────────────────────────
    const autoText = sessionStorage.getItem('nirva_analysis_autostart');
    if (autoText) {
        sessionStorage.removeItem('nirva_analysis_autostart');
        try {
            if (typeof ChatRoller !== 'undefined' && document.getElementById('chat-roller')) {
                ChatRoller.open(); // читает nirva_paywall из sessionStorage
                ChatRoller.sendMessage(autoText);
            }
        } catch (e) {
            console.error('ChatRoller autostart error:', e);
        }
    }

    // ── Скролл к Итогам если разбор завершён ─────────────────────────────────
    const blockResults = document.getElementById('block-results');
    if (blockResults) {
        setTimeout(function () {
            const scroller = document.querySelector('.analysis-page');
            if (!scroller) return;
            const targetTop = blockResults.offsetTop - scroller.offsetTop;
            scroller.scrollTo({ top: targetTop, behavior: 'smooth' });
        }, 150);
    }

    // ── Collapsible блоки ─────────────────────────────────────────────────────
    document.querySelectorAll('.analysis-block__header').forEach(header => {
        header.addEventListener('click', function () {
            const block     = this.closest('.analysis-block');
            const collapsed = block.dataset.collapsed === 'true';
            block.dataset.collapsed = collapsed ? 'false' : 'true';
            const btn = this.querySelector('.analysis-block__toggle');
            if (btn) btn.textContent = collapsed ? 'Свернуть' : 'Развернуть';
        });
    });

    // ── Показать диалог ───────────────────────────────────────────────────────
    const btnShowChat = document.getElementById('btn-show-chat');
    if (btnShowChat) {
        btnShowChat.addEventListener('click', function () {
            if (typeof ChatRoller !== 'undefined') {
                ChatRoller.openReadonly();
            }
        });
    }

    // ── Практика выполнена ────────────────────────────────────────────────────
    const btnPracticeDone = document.getElementById('btn-practice-done');
    if (btnPracticeDone) {
        btnPracticeDone.addEventListener('click', async function () {
            this.disabled = true;
            this.textContent = 'Сохраняем...';

            try {
                const res  = await fetch('/pages/analysis/api/complete-practice.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ analysis_id: analysisId }),
                });
                const data = await res.json();

                if (data.success) {
                    // Свернуть блоки разбора, задания, практики
                    ['block-summary', 'block-task', 'block-practice'].forEach(id => {
                        const block = document.getElementById(id);
                        if (block) block.dataset.collapsed = 'true';
                    });

                    // Показать CTA самоисследования (обновить страницу)
                    window.location.reload();
                } else {
                    this.disabled = false;
                    this.textContent = 'Практика выполнена ✓';
                    alert('Ошибка. Попробуйте снова.');
                }
            } catch (e) {
                this.disabled = false;
                this.textContent = 'Практика выполнена ✓';
                alert('Ошибка соединения.');
            }
        });
    }

    // ── Начать самоисследование ───────────────────────────────────────────────
    const btnReflection = document.getElementById('btn-start-reflection');
    if (btnReflection) {
        btnReflection.addEventListener('click', function () {
            if (typeof ChatRoller !== 'undefined') {
                ChatRoller.openReflection(analysisId);
            }
        });
    }

    // ── Polling медитаций ─────────────────────────────────────────────────────
    (function () {
        if (!analysisId) return;

        var medBlock = document.getElementById('block-meditations');
        if (!medBlock) return;

        // Polling нужен только если есть плейсхолдер (нет ready-медитаций)
        var placeholder = medBlock.querySelector('.muted');
        if (!placeholder) return;

        var knownIds     = [];
        var pollInterval = null;
        var pollCount    = 0;
        var maxPolls     = 100; // ~5 минут

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
            var body = medBlock.querySelector('.analysis-block__body');
            if (!body) return;

            // Первый раз — создаём обёртку med-row
            var existing = body.querySelector('.med-row-block');
            if (!existing) {
                body.innerHTML =
                    '<div class="med-row-block">' +
                    '<div class="med-row-block__head">' +
                    '<div><div class="med-row-block__title">Медитации для вас</div>' +
                    '<div class="med-row-block__sub">Создано специально по теме этого разбора</div></div>' +
                    '<a href="/meditations/?filter=personal" class="med-row-block__more">Все →</a>' +
                    '</div>' +
                    '<div class="med-row-scroll" data-med-row-slug="' + slug + '"></div>' +
                    '</div>';
            }

            var scroll = body.querySelector('.med-row-scroll');
            if (!scroll) return;

            // Добавляем только новые карточки
            readyMeds.forEach(function (med) {
                if (knownIds.indexOf(med.id) !== -1) return;
                knownIds.push(med.id);
                var idx = knownIds.length - 1;
                scroll.insertAdjacentHTML('beforeend', renderCard(med, idx, slug));

                // Регистрируем в MED_CATALOG для MedModal
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

            // Привязать обработчики к новым карточкам
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
            if (pollCount > maxPolls) {
                clearInterval(pollInterval);
                return;
            }

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

        // Начать polling через 2 сек после загрузки
        setTimeout(function () {
            poll();
            pollInterval = setInterval(poll, 3000);
        }, 2000);
    }());
});
