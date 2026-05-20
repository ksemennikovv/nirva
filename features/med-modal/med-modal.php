<link rel="stylesheet" href="/features/med-modal/med-modal.css">

<div id="med-modal" class="med-modal" hidden aria-modal="true" role="dialog">

    <div class="med-modal__overlay"></div>

    <div class="med-modal__sheet">

        <!-- Шапка -->
        <div class="med-modal__header">
            <button class="med-modal__nav med-modal__nav--prev" id="med-modal-prev" aria-label="Предыдущая">←</button>
            <div class="med-modal__counter" id="med-modal-counter">1 из 1</div>
            <button class="med-modal__nav med-modal__nav--next" id="med-modal-next" aria-label="Следующая">→</button>
            <button class="med-modal__close" id="med-modal-close" aria-label="Закрыть">✕</button>
        </div>

        <div class="med-modal__title" id="med-modal-title"></div>

        <!-- Изображение / градиент -->
        <div class="med-modal__cover" id="med-modal-cover">
            <div class="med-modal__cover-desc" id="med-modal-desc"></div>
        </div>

        <!-- Аудио-плеер -->
        <div class="med-modal__player" id="med-modal-player">
            <button class="med-modal__play" id="med-modal-play">▶</button>
            <span class="med-modal__play-label">Демо</span>
            <div class="med-modal__waveform" id="med-modal-waveform">
                <?php for ($i = 0; $i < 40; $i++): ?>
                <span class="med-modal__bar" style="height:<?php echo rand(20, 90); ?>%"></span>
                <?php endfor; ?>
            </div>
            <span class="med-modal__time" id="med-modal-time">0:00</span>
            <audio id="med-modal-audio" preload="none"></audio>
        </div>

        <!-- Прогресс-бар -->
        <div class="med-modal__progress-wrap">
            <div class="med-modal__progress-track">
                <div class="med-modal__progress-fill" id="med-modal-progress"></div>
            </div>
        </div>

        <!-- Покупка -->
        <div class="med-modal__purchase">
            <div class="med-modal__purchase-row" id="med-modal-single-row">
                <div class="med-modal__purchase-icon">🛍</div>
                <div class="med-modal__purchase-info">
                    <div class="med-modal__purchase-price" id="med-modal-price"></div>
                    <div class="med-modal__purchase-label">Полная медитация</div>
                </div>
                <button class="med-modal__btn med-modal__btn--outline" id="med-modal-cart-btn">В корзину</button>
            </div>

            <div class="med-modal__purchase-row" id="med-modal-set-row" hidden>
                <div class="med-modal__purchase-icon">🎁</div>
                <div class="med-modal__purchase-info">
                    <div class="med-modal__purchase-price" id="med-modal-set-price"></div>
                    <div class="med-modal__purchase-label">Комплект из <span id="med-modal-set-count"></span> медитаций</div>
                    <div class="med-modal__purchase-saving" id="med-modal-saving"></div>
                </div>
                <button class="med-modal__btn med-modal__btn--primary" id="med-modal-set-btn">Весь набор</button>
            </div>
        </div>

    </div>
</div>

<script>var MED_SET_DISCOUNT = <?php echo class_exists('BusinessConfig') ? BusinessConfig::MEDITATION_SET_DISCOUNT : 0.15; ?>;</script>
<script src="/features/med-modal/med-modal.js"></script>
