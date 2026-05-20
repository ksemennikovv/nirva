<!--
    registration-gate.php — Self-contained feature "Ворота регистрации".

    Подключает собственные assets самостоятельно:
      - registration-gate.css (стили scoped внутри #registration-gate-state)
      - registration-gate.js  (определяет объект RegistrationGate)

    Показывается после завершения анализа, когда пользователь ещё не зарегистрирован.
    Скрыт атрибутом hidden; показывается через HeroStatesManager.showRegistrationGate().

    Два шага:
      1. #registration-gate-step-email — ввод email + согласия
      2. #registration-gate-step-code  — ввод кода из письма
-->

<!-- Собственный CSS feature -->
<link rel="stylesheet" href="/pages/landing/includes/hero-states/registration-gate/registration-gate.css">

<section id="registration-gate-state" hidden>

    <!-- Результат анализа (заполняется из PHP-сессии и/или JS) -->
    <p class="registration-gate__intro">
        Вам назначена практика
        <span id="selected-practice-title" class="registration-gate__practice-title">
            <?php echo htmlspecialchars($_SESSION['recommended_practice'] ?? ''); ?>
        </span>
    </p>

    <p class="registration-gate__task" id="registration-gate-task"<?php if (empty($_SESSION['personal_task'])): ?> hidden<?php endif; ?>>
        Её нужно выполнить с персональным заданием:<br>
        Выполняйте практику и повторяйте про себя слово
        "<span id="personal-task-word" class="registration-gate__task-word"><?php echo htmlspecialchars($_SESSION['personal_task'] ?? ''); ?></span>"
    </p>

    <!-- ── Шаг 1: ввод email ─────────────────────────────────────────────── -->
    <div id="registration-gate-step-email">

        <p class="registration-gate__description">
            Она ждёт вас в личном кабинете. Введите email, чтобы получить бесплатный доступ:
        </p>

        <input
            type="email"
            id="registration-email-input"
            class="registration-gate__input"
            placeholder="Ваш email"
            autocomplete="email"
        >

        <label class="registration-gate__checkbox-label">
            <input type="checkbox" id="terms-checkbox">
            Я принимаю условия использования
        </label>

        <label class="registration-gate__checkbox-label">
            <input type="checkbox" id="privacy-checkbox">
            Я соглашаюсь на обработку персональных данных
        </label>

        <button id="submit-registration-email" type="button" class="registration-gate__btn">
            Получить доступ
        </button>

    </div>

    <!-- ── Шаг 2: ввод кода верификации ─────────────────────────────────── -->
    <div id="registration-gate-step-code" hidden>

        <p class="registration-gate__description">
            Мы отправили код на ваш email. Введите его ниже:
        </p>

        <input
            type="text"
            id="registration-code-input"
            class="registration-gate__input"
            placeholder="Код из письма"
            maxlength="6"
            inputmode="numeric"
            autocomplete="one-time-code"
        >

        <button id="verify-registration-code" type="button" class="registration-gate__btn">
            Подтвердить
        </button>

        <button id="resend-registration-code" type="button" class="registration-gate__btn registration-gate__btn--secondary">
            Отправить повторно
        </button>

    </div>

    <!-- ── Шаг 3: создание пароля ───────────────────────────────────────────── -->
    <div id="registration-gate-step-password" hidden>

        <p class="registration-gate__description">
            Придумайте пароль для входа в личный кабинет:
        </p>

        <input
            type="password"
            id="registration-password-input"
            class="registration-gate__input"
            placeholder="Пароль (минимум 8 символов)"
            autocomplete="new-password"
            minlength="8"
        >

        <input
            type="password"
            id="registration-password-confirm-input"
            class="registration-gate__input"
            placeholder="Повторите пароль"
            autocomplete="new-password"
            minlength="8"
        >

        <button id="submit-registration-password" type="button" class="registration-gate__btn">
            Войти в личный кабинет
        </button>

    </div>

    <!-- Блок ошибок (показывается через RegistrationGate.showError()) -->
    <div id="registration-error" class="registration-gate__error" hidden></div>

</section>

<!-- Собственный JS feature — определяет объект RegistrationGate -->
<script src="/pages/landing/includes/hero-states/registration-gate/registration-gate.js"></script>
