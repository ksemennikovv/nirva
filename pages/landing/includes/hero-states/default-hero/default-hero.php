<!--
    default-hero.php — Self-contained feature "Новый пользователь".

    Self-contained: подключает свои CSS и JS самостоятельно.
    Скрыт атрибутом hidden; показывается через HeroStatesManager.showDefaultHero().
    Инициализируется через DefaultHero.init() из assets/js/main.js.
-->

<link rel="stylesheet" href="/pages/landing/includes/hero-states/default-hero/default-hero.css">

<section id="default-hero-state" hidden>

    <!-- Заголовок onboarding-блока; выделенная фраза задаётся динамически -->
    <div id="hero-title">
        Опиши своё состояние и получи персональную телесную практику,
        чтобы уйти <span class="hero-title__highlight">от панической атаки</span>
        и почувствовать себя легче прямо сейчас:
    </div>

    <!-- Блок ввода: textarea + вспомогательные кнопки -->
    <div class="default-hero__input-wrap">

        <!-- Поле ввода состояния пользователя -->
        <textarea
            id="hero-text-input"
            placeholder="Например: я чувствую тревогу, пустоту, нет сил, застряла, не понимаю что делать…"
        ></textarea>

        <div class="default-hero__input-actions">

            <!-- Голосовой ввод — управляется VoiceRecorder из main.js -->
            <button id="start-voice-recording" type="button">диктофон</button>

            <!-- Отправить — дублирует действие #start-analysis-button -->
            <button id="submit-analysis-input" type="button">отправить</button>

        </div>
    </div>

    <!-- Главная CTA-кнопка: валидирует и запускает анализ -->
    <button id="start-analysis-button" type="button">
        Разобрать состояние и получить практику бесплатно
    </button>

    <p class="default-hero__trust">Конфиденциально · никто не увидит запрос</p>

</section>

<script src="/pages/landing/includes/hero-states/default-hero/default-hero.js"></script>
