<!--
    features/plan-modal/plan-modal.php — Модальное окно выбора тарифа.

    Подключается через require_once на любой странице где нужна смена тарифа.
    Требует чтобы до подключения были определены:
      - $prices (array) из PaymentService::getPrices()

    Управление: PlanModal.open() / PlanModal.close() из plan-modal.js
-->

<link rel="stylesheet" href="/features/plan-modal/plan-modal.css">

<div id="plan-modal" hidden>

    <div class="plan-modal__backdrop"></div>

    <div class="plan-modal__window">

        <button class="plan-modal__close" id="plan-modal-close" type="button">✕</button>

        <div id="plan-modal-context" class="plan-modal__context" hidden></div>

        <h2 class="plan-modal__title">Выберите формат</h2>

        <!-- Переключатель периода -->
        <div class="plan-modal__periods">
            <button class="plan-modal__period plan-modal__period--active" data-period="monthly" type="button">
                1 месяц
            </button>
            <button class="plan-modal__period" data-period="6months" type="button">
                6 месяцев <span class="plan-modal__discount">−10%</span>
            </button>
            <button class="plan-modal__period" data-period="12months" type="button">
                12 месяцев <span class="plan-modal__discount">−20%</span>
            </button>
        </div>

        <!-- Карточки тарифов -->
        <div class="plan-modal__cards">

            <div class="plan-modal__card" data-plan="start">
                <div class="plan-modal__card-name">Старт</div>
                <div class="plan-modal__card-detail">1 разбор в месяц</div>
                <div class="plan-modal__card-prices">
                    <?php foreach ($prices['start'] as $period => $price): ?>
                    <div class="plan-modal__price" data-period="<?php echo $period; ?>"
                         <?php echo $period !== 'monthly' ? 'hidden' : ''; ?>>
                        <?php echo number_format($price, 0, '', ' '); ?> ₽
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="plan-modal__btn" data-plan="start" type="button">Выбрать</button>
            </div>

            <div class="plan-modal__card plan-modal__card--featured" data-plan="basic">
                <div class="plan-modal__card-badge">Популярный</div>
                <div class="plan-modal__card-name">Базовый</div>
                <div class="plan-modal__card-detail">2 разбора в месяц</div>
                <div class="plan-modal__card-prices">
                    <?php foreach ($prices['basic'] as $period => $price): ?>
                    <div class="plan-modal__price" data-period="<?php echo $period; ?>"
                         <?php echo $period !== 'monthly' ? 'hidden' : ''; ?>>
                        <?php echo number_format($price, 0, '', ' '); ?> ₽
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="plan-modal__btn" data-plan="basic" type="button">Выбрать</button>
            </div>

            <div class="plan-modal__card" data-plan="transformation">
                <div class="plan-modal__card-name">Трансформация</div>
                <div class="plan-modal__card-detail">4 разбора в месяц</div>
                <div class="plan-modal__card-prices">
                    <?php foreach ($prices['transformation'] as $period => $price): ?>
                    <div class="plan-modal__price" data-period="<?php echo $period; ?>"
                         <?php echo $period !== 'monthly' ? 'hidden' : ''; ?>>
                        <?php echo number_format($price, 0, '', ' '); ?> ₽
                    </div>
                    <?php endforeach; ?>
                </div>
                <button class="plan-modal__btn" data-plan="transformation" type="button">Выбрать</button>
            </div>

        </div>

        <div id="plan-modal-error" class="plan-modal__error" hidden></div>

    </div>

</div>

<script src="/features/plan-modal/plan-modal.js"></script>
