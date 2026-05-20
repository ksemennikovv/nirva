document.addEventListener('DOMContentLoaded', function () {

    // ── Фильтры истории ───────────────────────────────────────────────────
    var activeFilter = 'all';
    var dateFrom = null;
    var dateTo   = null;

    function applyFilter() {
        var entries = document.querySelectorAll('#diary-entries-list .diary-entry');
        entries.forEach(function (el) {
            var completed = el.dataset.completed === '1';
            var date      = el.dataset.date; // 'YYYY-MM-DD'
            var show = true;

            if (activeFilter === 'completed')  show = completed;
            if (activeFilter === 'incomplete') show = !completed;
            if (activeFilter === 'date') {
                if (dateFrom && date < dateFrom) show = false;
                if (dateTo   && date > dateTo)   show = false;
            }

            el.style.display = show ? '' : 'none';
        });
    }

    document.querySelectorAll('.diary-filter').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.diary-filter').forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            activeFilter = this.dataset.filter;

            var dateRange = document.getElementById('diary-date-range');
            if (dateRange) dateRange.hidden = (activeFilter !== 'date');

            applyFilter();
        });
    });

    var inputFrom = document.getElementById('diary-date-from');
    var inputTo   = document.getElementById('diary-date-to');
    if (inputFrom) inputFrom.addEventListener('change', function () { dateFrom = this.value || null; applyFilter(); });
    if (inputTo)   inputTo.addEventListener('change',   function () { dateTo   = this.value || null; applyFilter(); });

    // ── Открытие существующей записи дневника ─────────────────────────────
    document.querySelectorAll('.diary-entry[data-entry-id]').forEach(function (el) {
        el.style.cursor = 'pointer';
        el.addEventListener('click', function () {
            const entryId = parseInt(this.dataset.entryId, 10);
            if (entryId && typeof ChatRoller !== 'undefined') {
                ChatRoller.openDiary(entryId, null, true);
            }
        });
    });

    // ── Автозапуск из дашборда ────────────────────────────────────────────────
    var autoText = sessionStorage.getItem('nirva_diary_autostart');
    if (autoText) {
        sessionStorage.removeItem('nirva_diary_autostart');
        fetch('/pages/diary/api/start-entry.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ initial_text: autoText }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success && typeof ChatRoller !== 'undefined') {
                ChatRoller.openDiary(data.entry_id, autoText);
            }
        });
    }

    const btnStart = document.getElementById('btn-start-diary');
    if (!btnStart) return;

    btnStart.addEventListener('click', async function () {
        const input = document.getElementById('diary-input');
        const text  = input ? input.value.trim() : '';

        this.disabled = true;
        this.textContent = 'Открываем...';

        try {
            const res  = await fetch('/pages/diary/api/start-entry.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ initial_text: text }),
            });
            const data = await res.json();

            if (data.success) {
                if (typeof ChatRoller !== 'undefined') {
                    ChatRoller.openDiary(data.entry_id, text);
                }
            } else if (data.error === 'limit_reached') {
                window.location.href = '/billing/';
            } else {
                alert('Ошибка. Попробуйте снова.');
            }
        } catch (e) {
            alert('Нет соединения с сервером.');
        } finally {
            this.disabled = false;
            this.textContent = 'Начать запись';
        }
    });
});
