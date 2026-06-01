<link rel="stylesheet" href="<?= asset_url('/features/med-player/med-player.css') ?>">

<!-- ══ Mini-bar (всегда внизу пока играет) ═══════════════════════════════ -->
<div id="mp-bar" class="mp-bar" hidden>
    <div class="mp-bar__progress-line">
        <div class="mp-bar__progress-fill" id="mp-bar-fill"></div>
    </div>
    <div class="mp-bar__body" id="mp-bar-body">
        <div class="mp-bar__cover" id="mp-bar-cover"></div>
        <div class="mp-bar__info">
            <div class="mp-bar__title" id="mp-bar-title">—</div>
            <div class="mp-bar__time"  id="mp-bar-time">0:00 / 0:00</div>
        </div>
        <div class="mp-bar__controls">
            <span class="mp-bar__sleep" id="mp-bar-sleep" hidden></span>
            <button class="mp-bar__btn" id="mp-bar-play" aria-label="Play/Pause">
                <svg class="mp-icon-play" width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M5 3.5l12 6.5-12 6.5V3.5z" fill="currentColor"/></svg>
                <svg class="mp-icon-pause" width="20" height="20" viewBox="0 0 20 20" fill="none"><rect x="4" y="3" width="4" height="14" rx="1.5" fill="currentColor"/><rect x="12" y="3" width="4" height="14" rx="1.5" fill="currentColor"/></svg>
            </button>
            <button class="mp-bar__btn mp-bar__btn--expand" id="mp-bar-expand" aria-label="Открыть плеер">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M3 10l5-5 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </div>
    </div>
</div>

<!-- ══ Full-screen player ════════════════════════════════════════════════ -->
<div id="mp-full" class="mp-full" hidden>

    <!-- Фон — размытая обложка -->
    <div class="mp-full__bg" id="mp-full-bg"></div>
    <div class="mp-full__overlay"></div>

    <!-- Шапка -->
    <div class="mp-full__topbar" id="mp-full-drag">
        <div class="mp-full__handle"></div>
        <div class="mp-full__topbar-row">
            <button class="mp-full__close" id="mp-full-close" aria-label="Свернуть">
                <svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M5 8 L10 13 L15 8" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <span class="mp-full__topbar-label">Медитация</span>
            <div style="width:36px"></div>
        </div>
    </div>

    <!-- Скролл: обложка + мета + прогресс + контролы + очередь -->
    <div class="mp-full__scroll">

        <div class="mp-full__cover-wrap">
            <div class="mp-full__cover" id="mp-full-cover"></div>
        </div>

        <div class="mp-full__meta">
            <div class="mp-full__title" id="mp-full-title">—</div>
            <div class="mp-full__desc" id="mp-full-desc" hidden></div>
        </div>

        <div class="mp-full__seek-wrap">
            <div class="mp-full__seek-track" id="mp-full-track">
                <div class="mp-full__seek-fill"  id="mp-full-fill"></div>
                <div class="mp-full__seek-thumb" id="mp-full-thumb"></div>
            </div>
            <div class="mp-full__seek-times">
                <span id="mp-full-cur">0:00</span>
                <span id="mp-full-dur">0:00</span>
            </div>
        </div>

        <div class="mp-full__controls">
            <button class="mp-full__ctrl mp-full__ctrl--side" id="mp-full-prev" aria-label="Предыдущая">
                <svg width="26" height="26" viewBox="0 0 26 26" fill="none"><path d="M21 5v16M5 13l12-8v16L5 13z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button class="mp-full__ctrl mp-full__ctrl--skip" id="mp-full-back" aria-label="-10s">
                <!-- center=(20,20) r=14; bottom(20,34)→CCW 270°→left(6,20); arrowhead at left pointing UP -->
                <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                    <path d="M 20 34 A 14 14 0 1 0 6 20" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
                    <path d="M 3 24 L 6 20 L 9 24" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                    <text x="20" y="20" font-size="10" fill="currentColor" text-anchor="middle" dominant-baseline="middle" font-family="Arial,sans-serif" font-weight="800">10</text>
                </svg>
            </button>
            <button class="mp-full__ctrl mp-full__ctrl--play" id="mp-full-play" aria-label="Play/Pause">
                <svg class="mp-full__icon-play" width="28" height="28" viewBox="0 0 28 28" fill="none"><path d="M8 4.5l18 9.5-18 9.5V4.5z" fill="currentColor"/></svg>
                <svg class="mp-full__icon-pause" width="28" height="28" viewBox="0 0 28 28" fill="none"><rect x="5" y="3" width="6" height="22" rx="2.5" fill="currentColor"/><rect x="17" y="3" width="6" height="22" rx="2.5" fill="currentColor"/></svg>
            </button>
            <button class="mp-full__ctrl mp-full__ctrl--skip" id="mp-full-fwd" aria-label="+10s">
                <!-- center=(20,20) r=14; bottom(20,34)→CW 270°→right(34,20); arrowhead at right pointing DOWN -->
                <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
                    <path d="M 20 34 A 14 14 0 1 1 34 20" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>
                    <path d="M 31 16 L 34 20 L 37 16" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                    <text x="20" y="20" font-size="10" fill="currentColor" text-anchor="middle" dominant-baseline="middle" font-family="Arial,sans-serif" font-weight="800">10</text>
                </svg>
            </button>
            <button class="mp-full__ctrl mp-full__ctrl--side" id="mp-full-next" aria-label="Следующая">
                <svg width="26" height="26" viewBox="0 0 26 26" fill="none"><path d="M5 5v16M21 13L9 5v16l12-8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </div>

        <div class="mp-full__extras">
            <button class="mp-full__extra" id="mp-full-loop">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M2 9a7 7 0 0 1 7-7h5M14 2l2 2-2 2M16 9a7 7 0 0 1-7 7H4M4 16l-2-2 2-2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>Повтор</span>
            </button>
            <button class="mp-full__extra" id="mp-full-sleep">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M16 11.5A7 7 0 0 1 7 2.5a7 7 0 1 0 9 9z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span id="mp-sleep-label">Таймер</span>
            </button>
        </div>

        <div class="mp-full__queue" id="mp-queue-panel">
            <div class="mp-queue-tabs">
                <button class="mp-queue-tab" id="mp-tab-recent" data-tab="recent">Последние</button>
                <button class="mp-queue-tab" id="mp-tab-all"    data-tab="all">Все доступные</button>
            </div>
            <div class="mp-full__queue-list" id="mp-queue-list"></div>
        </div>

    </div><!-- /.mp-full__scroll -->

    <!-- Sleep timer -->
    <div class="mp-sleep-picker" id="mp-sleep-picker" hidden>
        <div class="mp-sleep-picker__title">Таймер сна</div>
        <div class="mp-sleep-picker__opts">
            <button data-min="5">5 мин</button>
            <button data-min="10">10 мин</button>
            <button data-min="20">20 мин</button>
            <button data-min="30">30 мин</button>
            <button data-min="45">45 мин</button>
            <button data-min="0">До конца</button>
            <button data-min="-1">Отключить</button>
        </div>
    </div>

</div><!-- /#mp-full -->

<script src="<?= asset_url('/features/med-player/med-player.js') ?>"></script>
