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

    // ── Чипсы выбора режима ───────────────────────────────────────────────────
    var selectedDiaryMode = null;

    var chipPrefixes = {
        vent:       'Хочу просто выговориться на тему ',
        reflection: 'Хочу провести мини-самоанализ на ситуацию, связанную с ',
    };
    var chipPlaceholders = {
        vent:       'Хочу просто выговориться на тему сегодняшнего события...',
        reflection: 'Хочу провести мини-самоанализ на ситуацию, связанную с ...',
    };
    var defaultPlaceholder = 'Что сегодня было важным? Как вы себя чувствуете?';

    var diaryInput = document.getElementById('diary-input');

    document.querySelectorAll('.diary-compose__chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var prev = document.querySelector('.diary-compose__chip.active');
            var prevMode = prev ? prev.dataset.mode : null;

            document.querySelectorAll('.diary-compose__chip').forEach(function (c) { c.classList.remove('active'); });
            this.classList.add('active');
            selectedDiaryMode = this.dataset.mode;

            if (diaryInput) {
                var currentVal = diaryInput.value;
                // Убираем префикс предыдущего режима если он есть
                if (prevMode && chipPrefixes[prevMode] && currentVal.startsWith(chipPrefixes[prevMode])) {
                    currentVal = currentVal.slice(chipPrefixes[prevMode].length);
                }
                // Подставляем префикс нового режима
                diaryInput.value = chipPrefixes[selectedDiaryMode] + currentVal;
                diaryInput.placeholder = chipPlaceholders[selectedDiaryMode];
                diaryInput.focus();
                // Курсор в конец
                var len = diaryInput.value.length;
                diaryInput.setSelectionRange(len, len);
            }
        });
    });

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
                    ChatRoller.openDiary(data.entry_id, text, false, selectedDiaryMode);
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
