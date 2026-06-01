/**
 * features/med-player/med-player.js
 *
 * MedPlayer.play(item) — запустить воспроизведение одного трека.
 * Полный список доступных медитаций загружается из API асинхронно.
 *
 * Архитектура очереди:
 *   _playlist   — полный список доступных из API (стабильный порядок)
 *   _currentId  — id текущего трека
 *   Вкладка "Все доступные" — _playlist в порядке из API
 *   Вкладка "Последние"     — _playlist отфильтрованный/отсортированный по last_listened_at
 *
 * История обновляется в памяти после ≥10 сек прослушивания (Spotify-паттерн).
 */

const MedPlayer = (() => {

    /* ── Состояние ─────────────────────────────────────────────────────── */
    let _playlist    = [];      // все доступные медитации из API
    let _currentId   = null;    // id воспроизводимого трека
    let _loop        = false;
    let _sleepEndMs  = 0;
    let _sleepMode   = '';
    let _sleepTimer  = null;
    let _audio       = null;
    let _listenStart = 0;
    let _listenAcc   = 0;

    let _playerState     = 'hidden';
    let _isStartingTrack = false;
    let _queueTab        = 'all';   // 'recent' | 'all'

    /* ── DOM-ссылки ────────────────────────────────────────────────────── */
    const $ = id => document.getElementById(id);
    let _bar, _full, _barBody, _barFill, _barPlay, _barTitle, _barTime,
        _barSleep, _barCover, _fullFill, _fullThumb, _fullCur, _fullDur,
        _fullTitle, _fullDesc, _fullCover, _fullBg, _fullPlay, _fullPrev,
        _fullNext, _fullBack, _fullFwd, _fullLoop, _fullSleep,
        _fullSleepLabel, _fullClose, _fullTrack, _sleepPicker,
        _queueList, _tabRecent, _tabAll;

    /* ── Инициализация ─────────────────────────────────────────────────── */
    function _init() {
        _bar  = $('mp-bar');
        _full = $('mp-full');
        if (!_bar || !_full) return;

        _audio = new Audio();
        _audio.preload = 'auto';

        _barBody        = $('mp-bar-body');
        _barFill        = $('mp-bar-fill');
        _barPlay        = $('mp-bar-play');
        _barTitle       = $('mp-bar-title');
        _barTime        = $('mp-bar-time');
        _barSleep       = $('mp-bar-sleep');
        _barCover       = $('mp-bar-cover');
        _fullFill       = $('mp-full-fill');
        _fullThumb      = $('mp-full-thumb');
        _fullCur        = $('mp-full-cur');
        _fullDur        = $('mp-full-dur');
        _fullTitle      = $('mp-full-title');
        _fullDesc       = $('mp-full-desc');
        _fullCover      = $('mp-full-cover');
        _fullBg         = $('mp-full-bg');
        _fullPlay       = $('mp-full-play');
        _fullPrev       = $('mp-full-prev');
        _fullNext       = $('mp-full-next');
        _fullBack       = $('mp-full-back');
        _fullFwd        = $('mp-full-fwd');
        _fullLoop       = $('mp-full-loop');
        _fullSleep      = $('mp-full-sleep');
        _fullSleepLabel = $('mp-sleep-label');
        _fullClose      = $('mp-full-close');
        _fullTrack      = $('mp-full-track');
        _sleepPicker    = $('mp-sleep-picker');
        _queueList      = $('mp-queue-list');
        _tabRecent      = $('mp-tab-recent');
        _tabAll         = $('mp-tab-all');

        if (_tabRecent) _tabRecent.addEventListener('click', () => _switchTab('recent'));
        if (_tabAll)    _tabAll.addEventListener('click',    () => _switchTab('all'));

        _barBody.addEventListener('click', e => {
            if (e.target.closest('#mp-bar-play') || e.target.closest('#mp-bar-expand')) return;
            _toFull();
        });
        _bar.querySelector('.mp-bar__progress-line').addEventListener('click', _onBarProgressClick);
        _barPlay.addEventListener('click', _togglePlay);
        $('mp-bar-expand').addEventListener('click', _toFull);

        _fullClose.addEventListener('click', _toMini);
        _fullPlay.addEventListener('click',  _togglePlay);
        _fullPrev.addEventListener('click',  () => _skip(-1));
        _fullNext.addEventListener('click',  () => _skip(1));
        _fullBack.addEventListener('click',  () => { _audio.currentTime = Math.max(0, _audio.currentTime - 10); });
        _fullFwd.addEventListener('click',   () => { _audio.currentTime = Math.min(_audio.duration || 0, _audio.currentTime + 10); });
        _fullLoop.addEventListener('click',  _toggleLoop);
        _fullSleep.addEventListener('click', _toggleSleepPicker);

        _fullTrack.addEventListener('mousedown',  _seekStart);
        _fullTrack.addEventListener('touchstart', _seekStart, { passive: false });
        document.addEventListener('mousemove',    _seekMove);
        document.addEventListener('touchmove',    _seekMove, { passive: false });
        document.addEventListener('mouseup',      _seekEnd);
        document.addEventListener('touchend',     _seekEnd);

        _sleepPicker.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => _setSleep(+btn.dataset.min));
        });

        _audio.addEventListener('timeupdate', _onTimeUpdate);
        _audio.addEventListener('ended',      _onEnded);
        _audio.addEventListener('play',  () => { _isStartingTrack = false; _listenStart = Date.now(); _syncPlayIcons(true); });
        _audio.addEventListener('pause', () => { if (_isStartingTrack) return; _accumulateListen(); _syncPlayIcons(false); });

        _setupDrag();
    }

    /* ── Публичный API ─────────────────────────────────────────────────── */

    function play(item) {
        if (!item) return;
        _currentId = item.id;
        // Если трека ещё нет в плейлисте — добавляем временно чтобы сразу воспроизвести
        if (!_playlist.find(t => t.id === item.id)) _playlist = [item];
        _loadTrack(item);
        _bar.hidden = false;
        _syncPlayIcons(true);
        _audio.play().catch(() => { _syncPlayIcons(false); });
        _toFull();
        _loadPlaylist();
    }

    /* ── Загрузка полного плейлиста из API ─────────────────────────────── */

    function _loadPlaylist() {
        fetch('/meditations/api/available.php')
            .then(r => r.json())
            .then(data => {
                if (!data.success || !Array.isArray(data.items)) return;

                // Если текущего трека нет в API-ответе — сохраняем его из памяти
                const currentItem = _playlist.find(t => t.id === _currentId);
                _playlist = data.items;
                if (_currentId && !_playlist.find(t => t.id === _currentId) && currentItem) {
                    _playlist.unshift(currentItem);
                }

                // Умный дефолт вкладки
                _queueTab = _playlist.some(t => t.last_listened_at) ? 'recent' : 'all';
                _updateNavButtons();
                _renderQueue();
            })
            .catch(() => {
                _queueTab = _playlist.some(t => t.last_listened_at) ? 'recent' : 'all';
                _renderQueue();
            });
    }

    /* ── Состояния шторки ──────────────────────────────────────────────── */

    function _toMini() {
        if (_sleepPicker) _sleepPicker.hidden = true;
        _full.classList.remove('open');
        _playerState = 'mini';
        _full.addEventListener('transitionend', () => {
            if (_playerState !== 'full') _full.hidden = true;
        }, { once: true });
    }

    function _toFull() {
        if (_sleepPicker) _sleepPicker.hidden = true;
        _full.hidden = false;
        requestAnimationFrame(() => requestAnimationFrame(() => {
            _full.classList.add('open');
        }));
        _playerState = 'full';
    }

    /* ── Загрузка трека ────────────────────────────────────────────────── */

    function _loadTrack(item) {
        item = item || _playlist.find(t => t.id === _currentId);
        if (!item) return;

        _isStartingTrack = true;
        const src = item.free && item.full_audio_url ? item.full_audio_url : (item.demo_audio_url || '');
        if (_audio.src !== src) {
            _audio.src = src;
        }

        _barTitle.textContent = item.title || 'Медитация';
        _setElementBg(_barCover, item);

        _fullTitle.textContent = item.title || 'Медитация';
        if (_fullDesc) {
            _fullDesc.textContent = item.description || '';
            _fullDesc.hidden = !item.description;
        }
        _setElementBg(_fullCover, item);
        _applyBg(item);

        _updateNavButtons();
        _setProgress(0);
        _barTime.textContent = '0:00 / 0:00';
        _fullCur.textContent = '0:00';
        _fullDur.textContent = '0:00';

        _listenAcc   = 0;
        _listenStart = 0;

        _renderQueue();
    }

    function _updateNavButtons() {
        const idx = _playlist.findIndex(t => t.id === _currentId);
        if (_fullPrev) _fullPrev.disabled = idx <= 0;
        if (_fullNext) _fullNext.disabled = idx < 0 || idx >= _playlist.length - 1;
    }

    function _setElementBg(el, item) {
        el.style.background = item.image_url
            ? 'url(' + item.image_url + ') center/cover no-repeat'
            : (item.gradient || 'linear-gradient(135deg,#a29bfe,#6c5ce7)');
    }

    function _applyBg(item) {
        if (!_fullBg) return;
        // Без картинки используем градиент + solid цвет чтобы blur давал непрозрачный фон
        if (item.image_url) {
            _fullBg.style.background = 'url(' + item.image_url + ') center/cover no-repeat';
        } else {
            const grad = item.gradient || 'linear-gradient(135deg,#6c5ce7,#a29bfe)';
            _fullBg.style.background = grad;
        }
    }

    /* ── Play / Pause ──────────────────────────────────────────────────── */

    function _togglePlay() {
        if (!_audio.src) return;
        if (_audio.paused) {
            _audio.play().catch(() => {});
            _syncPlayIcons(true);
        } else {
            _audio.pause();
            _syncPlayIcons(false);
        }
    }

    function _syncPlayIcons(playing) {
        _fullPlay.classList.toggle('playing', playing);
        _barPlay.classList.toggle('playing', playing);
    }

    /* ── Прогресс и seek ───────────────────────────────────────────────── */

    function _onTimeUpdate() {
        const cur = _audio.currentTime;
        const dur = _audio.duration || 0;
        const pct = dur ? (cur / dur) : 0;

        _setProgress(pct);
        _barTime.textContent = _fmt(cur) + ' / ' + _fmt(dur);
        _fullCur.textContent = _fmt(cur);
        _fullDur.textContent = _fmt(dur);

        if (_sleepMode === 'track' && dur) {
            const remaining = dur - cur;
            if (remaining <= 10 && !_audio.paused) {
                _audio.volume = Math.max(0, remaining / 10);
            }
        }
    }

    function _setProgress(pct) {
        const p = (pct * 100).toFixed(2) + '%';
        _barFill.style.width  = p;
        _fullFill.style.width = p;
        _fullThumb.style.left = p;
    }

    function _onBarProgressClick(e) {
        if (!_audio.duration) return;
        const rect = e.currentTarget.getBoundingClientRect();
        const pct  = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        _audio.currentTime = pct * _audio.duration;
    }

    let _seekDragging = false;
    function _seekStart(e) {
        if (_playerState === 'mini') return;
        _seekDragging = true;
        _doSeek(e);
        e.preventDefault();
    }
    function _seekMove(e) {
        if (!_seekDragging) return;
        _doSeek(e);
        e.preventDefault();
    }
    function _seekEnd(e) {
        if (!_seekDragging) return;
        _seekDragging = false;
        _doSeek(e);
    }
    function _doSeek(e) {
        if (!_audio.duration) return;
        const rect    = _fullTrack.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : (e.changedTouches ? e.changedTouches[0].clientX : e.clientX);
        const pct     = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
        _audio.currentTime = pct * _audio.duration;
        _setProgress(pct);
    }

    /* ── Skip (prev / next) ────────────────────────────────────────────── */

    function _skip(dir) {
        _saveListenRecord(false);
        const idx  = _playlist.findIndex(t => t.id === _currentId);
        const next = _playlist[idx + dir];
        if (!next) return;
        _currentId = next.id;
        _loadTrack(next);
        _audio.play().catch(() => {});
    }

    /* ── Track ended ───────────────────────────────────────────────────── */

    function _onEnded() {
        _audio.volume = 1;
        _saveListenRecord(true);

        if (_sleepMode === 'track') {
            _clearSleep();
            _syncPlayIcons(false);
            return;
        }

        if (_loop) {
            _audio.currentTime = 0;
            _audio.play().catch(() => {});
            return;
        }

        const idx  = _playlist.findIndex(t => t.id === _currentId);
        const next = _playlist[idx + 1];
        if (next) {
            _currentId = next.id;
            _loadTrack(next);
            _audio.play().catch(() => {});
        } else {
            _syncPlayIcons(false);
            _setProgress(0);
        }
    }

    /* ── Loop ───────────────────────────────────────────────────────────── */

    function _toggleLoop() {
        _loop = !_loop;
        _fullLoop.classList.toggle('active', _loop);
    }

    /* ── Sleep timer ────────────────────────────────────────────────────── */

    function _toggleSleepPicker() {
        _sleepPicker.hidden = !_sleepPicker.hidden;
        if (!_sleepPicker.hidden) {
            setTimeout(() => {
                document.addEventListener('click', _closeSleepPicker, { once: true, capture: true });
            }, 10);
        }
    }

    function _closeSleepPicker(e) {
        if (_sleepPicker && !_sleepPicker.contains(e.target) && e.target !== _fullSleep) {
            _sleepPicker.hidden = true;
        }
    }

    function _setSleep(min) {
        _sleepPicker.hidden = true;
        _sleepPicker.querySelectorAll('button').forEach(b => b.classList.remove('active'));

        if (min === -1) { _clearSleep(); return; }

        if (min === 0) {
            _sleepMode = 'track';
            _sleepEndMs = 0;
            _fullSleep.classList.add('active');
            _fullSleepLabel.textContent = 'До конца';
            _barSleep.hidden = false;
            _barSleep.textContent = '💤 ∞';
            _sleepPicker.querySelector('[data-min="0"]').classList.add('active');
            return;
        }

        _sleepMode  = 'time';
        _sleepEndMs = Date.now() + min * 60 * 1000;
        _fullSleep.classList.add('active');
        _sleepPicker.querySelector('[data-min="' + min + '"]').classList.add('active');
        _barSleep.hidden = false;

        clearInterval(_sleepTimer);
        _sleepTimer = setInterval(() => {
            const remaining = _sleepEndMs - Date.now();
            if (remaining <= 0) { _clearSleep(); _audio.pause(); return; }
            if (remaining <= 10000) _audio.volume = Math.max(0, remaining / 10000);
            const m = Math.floor(remaining / 60000);
            const s = Math.floor((remaining % 60000) / 1000);
            _barSleep.textContent       = '💤 ' + m + ':' + String(s).padStart(2, '0');
            _fullSleepLabel.textContent = m + ':' + String(s).padStart(2, '0');
        }, 1000);
    }

    function _clearSleep() {
        clearInterval(_sleepTimer);
        _sleepTimer  = null;
        _sleepMode   = '';
        _sleepEndMs  = 0;
        _audio.volume = 1;
        _barSleep.hidden = true;
        _fullSleep.classList.remove('active');
        _fullSleepLabel.textContent = 'Таймер';
        _sleepPicker.querySelectorAll('button').forEach(b => b.classList.remove('active'));
    }

    /* ── Queue panel ────────────────────────────────────────────────────── */

    function _switchTab(tab) {
        _queueTab = tab;
        _renderQueue();
    }

    function _renderQueue() {
        if (!_queueList) return;

        if (_tabRecent) _tabRecent.classList.toggle('active', _queueTab === 'recent');
        if (_tabAll)    _tabAll.classList.toggle('active',    _queueTab === 'all');

        let displayItems;
        if (_queueTab === 'recent') {
            // Только прослушанные, от новых к старым
            displayItems = _playlist
                .filter(t => t.last_listened_at)
                .slice()
                .sort((a, b) => new Date(b.last_listened_at) - new Date(a.last_listened_at));
        } else {
            // Все доступные в стабильном порядке из API (personal → general)
            displayItems = _playlist.slice();
        }

        _queueList.innerHTML = '';

        if (displayItems.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'mp-queue-empty';
            empty.textContent = _queueTab === 'recent'
                ? 'Вы ещё не слушали медитации'
                : 'Нет доступных медитаций';
            _queueList.appendChild(empty);
            return;
        }

        displayItems.forEach(item => {
            const isActive = item.id === _currentId;
            const div = document.createElement('div');
            div.className = 'mp-queue-item' + (isActive ? ' active' : '');
            const bg = item.image_url
                ? 'url(' + item.image_url + ') center/cover no-repeat'
                : (item.gradient || 'linear-gradient(135deg,#a29bfe,#6c5ce7)');
            div.innerHTML =
                '<div class="mp-queue-item__cover" style="background:' + bg + '"></div>' +
                '<div class="mp-queue-item__info">' +
                  '<div class="mp-queue-item__title">' + _esc(item.title || 'Медитация') + '</div>' +
                  (isActive ? '<div class="mp-queue-item__playing">▶ сейчас играет</div>' : '') +
                '</div>';
            div.addEventListener('click', () => {
                _saveListenRecord(false);
                _currentId = item.id;
                _loadTrack(item);
                _audio.play().catch(() => {});
            });
            _queueList.appendChild(div);
        });
    }

    /* ── Свайп-жесты ───────────────────────────────────────────────────── */

    function _setupDrag() {
        let startY = 0, startState = 'hidden', curDy = 0, isDragging = false;

        function dragStart(e, forceState) {
            startY     = (e.touches ? e.touches[0] : e).clientY;
            startState = forceState !== undefined ? forceState : _playerState;
            curDy      = 0;
            isDragging = true;
            _full.style.transition = 'none';
        }
        function dragMove(e) {
            if (!isDragging) return;
            curDy = (e.touches ? e.touches[0] : e).clientY - startY;
            if (startState === 'full' && curDy > 0) _full.style.transform = 'translateY(' + curDy + 'px)';
            if (e.cancelable) e.preventDefault();
        }
        function dragEnd() {
            if (!isDragging) return;
            isDragging = false;
            _full.style.transition = '';
            _full.style.transform  = '';
            if (startState === 'full' && curDy > 100) _toMini();
        }

        const topHandle = $('mp-full-drag');
        topHandle.addEventListener('touchstart', e => dragStart(e, 'full'), { passive: true });
        topHandle.addEventListener('touchmove',  dragMove, { passive: false });
        topHandle.addEventListener('touchend',   dragEnd);
    }

    /* ── История прослушиваний ─────────────────────────────────────────── */

    function _accumulateListen() {
        if (_listenStart > 0) {
            _listenAcc  += (Date.now() - _listenStart) / 1000;
            _listenStart = 0;
        }
    }

    function _saveListenRecord(completed) {
        _accumulateListen();
        const dur = Math.round(_listenAcc);
        // Порог: 10 секунд (Spotify-паттерн)
        if (dur < 10 || !_currentId) return;

        const item = _playlist.find(t => t.id === _currentId);
        if (!item) return;

        fetch('/meditations/api/record-listen.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ meditation_id: item.id, duration_sec: dur, completed: !!completed }),
        }).catch(() => {});

        // Обновляем last_listened_at в памяти — без нового запроса к API
        item.last_listened_at = new Date().toISOString();
        if (_queueTab === 'recent') _renderQueue();

        _listenAcc = 0;
    }

    /* ── Helpers ────────────────────────────────────────────────────────── */

    function _fmt(sec) {
        if (!sec || isNaN(sec)) return '0:00';
        const m = Math.floor(sec / 60);
        const s = Math.floor(sec % 60);
        return m + ':' + String(s).padStart(2, '0');
    }

    function _esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    document.addEventListener('DOMContentLoaded', _init);

    return { play };

})();
