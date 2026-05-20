/**
 * registration-gate.js — Менеджер состояния "Ворота регистрации".
 *
 * Определяет глобальный объект RegistrationGate.
 * init() вызывается из assets/js/main.js на DOMContentLoaded.
 *
 * Двухшаговый flow:
 *   Шаг 1: email + согласия → submit-email.php → показать шаг 2
 *   Шаг 2: код из письма → verify-code.php → redirect в личный кабинет
 */

const RegistrationGate = {

    /**
     * init() — вешает обработчики на кнопки.
     * Вызывается один раз из main.js.
     */
    init() {
        document
            .getElementById('submit-registration-email')
            .addEventListener('click', () => RegistrationGate.handleSubmitRegistrationEmail());

        document
            .getElementById('verify-registration-code')
            .addEventListener('click', () => RegistrationGate.handleVerifyRegistrationCode());

        document
            .getElementById('resend-registration-code')
            .addEventListener('click', () => RegistrationGate.handleResendRegistrationCode());

        document
            .getElementById('submit-registration-password')
            .addEventListener('click', () => RegistrationGate.handleSubmitPassword());
    },

    /**
     * handleSubmitRegistrationEmail() — отправляет email на backend.
     *
     * Валидирует email и оба согласия.
     * При успехе — скрывает шаг 1, показывает шаг 2.
     */
    handleSubmitRegistrationEmail() {
        const email   = document.getElementById('registration-email-input').value.trim();
        const terms   = document.getElementById('terms-checkbox').checked;
        const privacy = document.getElementById('privacy-checkbox').checked;

        if (!email) {
            RegistrationGate.showError('Введите email');
            return;
        }

        if (!terms || !privacy) {
            RegistrationGate.showError('Необходимо принять условия и согласие');
            return;
        }

        RegistrationGate.clearError();

        fetch('/pages/landing/includes/hero-states/registration-gate/api/submit-email.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ email }),
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    RegistrationGate.showStepCode();
                } else {
                    RegistrationGate.showError(data.message || 'Ошибка отправки. Попробуйте снова.');
                }
            })
            .catch(() => {
                RegistrationGate.showError('Нет соединения с сервером. Попробуйте снова.');
            });
    },

    /**
     * handleVerifyRegistrationCode() — отправляет код верификации на backend.
     * При успехе: если need_password — показывает шаг 3, иначе — редирект.
     */
    handleVerifyRegistrationCode() {
        const code = document.getElementById('registration-code-input').value.trim();

        if (!code) {
            RegistrationGate.showError('Введите код из письма');
            return;
        }

        RegistrationGate.clearError();

        fetch('/pages/landing/includes/hero-states/registration-gate/api/verify-code.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ code }),
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.data.need_password) {
                        RegistrationGate.showStepPassword();
                    } else {
                        // Существующий пользователь с паролем — сразу в кабинет
                        localStorage.removeItem('nirva_session');
                        window.location.href = data.data.redirect;
                    }
                } else {
                    RegistrationGate.showError(data.message || 'Неверный код. Попробуйте снова.');
                }
            })
            .catch(() => {
                RegistrationGate.showError('Нет соединения с сервером. Попробуйте снова.');
            });
    },

    /** handleSubmitPassword() — устанавливает пароль (шаг 3). */
    handleSubmitPassword() {
        const password = document.getElementById('registration-password-input').value;
        const confirm  = document.getElementById('registration-password-confirm-input').value;

        if (password.length < 8) {
            RegistrationGate.showError('Пароль должен быть не менее 8 символов');
            return;
        }
        if (password !== confirm) {
            RegistrationGate.showError('Пароли не совпадают');
            return;
        }

        RegistrationGate.clearError();

        fetch('/pages/landing/includes/hero-states/registration-gate/api/set-password.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ password, password_confirm: confirm }),
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    localStorage.removeItem('nirva_session');
                    window.location.href = data.data.redirect;
                } else {
                    RegistrationGate.showError(data.message || 'Ошибка. Попробуйте снова.');
                }
            })
            .catch(() => {
                RegistrationGate.showError('Нет соединения с сервером.');
            });
    },

    /**
     * handleResendRegistrationCode() — запрашивает повторную отправку кода.
     *
     * Сервер соблюдает rate limit — не чаще одного раза в 60 секунд.
     */
    handleResendRegistrationCode() {
        RegistrationGate.clearError();

        fetch('/pages/landing/includes/hero-states/registration-gate/api/resend-code.php', {
            method: 'POST',
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    RegistrationGate.showError('Код отправлен повторно. Проверьте почту.');
                } else if (data.error === 'rate_limit') {
                    RegistrationGate.showError('Подождите 60 секунд перед повторным запросом.');
                } else {
                    RegistrationGate.showError(data.message || 'Ошибка отправки.');
                }
            })
            .catch(() => {
                RegistrationGate.showError('Нет соединения с сервером.');
            });
    },

    /**
     * setPracticeTitle(title) — вставляет название практики в #selected-practice-title.
     * Вызывается из ChatRoller.handleAnalysisCompleted() когда AI завершает анализ.
     *
     * @param {string} title
     */
    setPracticeTitle(title) {
        const el = document.getElementById('selected-practice-title');
        if (el) el.textContent = title;
    },

    setPersonalTask(word) {
        const wordEl = document.getElementById('personal-task-word');
        if (wordEl) wordEl.textContent = word;

        const blockEl = document.getElementById('registration-gate-task');
        if (blockEl) blockEl.hidden = false;
    },

    /**
     * showError(message) — показывает ошибку в #registration-error.
     * @param {string} message
     */
    showError(message) {
        const el = document.getElementById('registration-error');
        if (!el) return;
        el.textContent = message;
        el.hidden = false;
    },

    /** clearError() — скрывает блок ошибки. */
    clearError() {
        const el = document.getElementById('registration-error');
        if (el) el.hidden = true;
    },

    /** showStepCode() — переключает с шага 1 на шаг 2. */
    showStepCode() {
        document.getElementById('registration-gate-step-email').hidden    = true;
        document.getElementById('registration-gate-step-code').hidden     = false;
        document.getElementById('registration-gate-step-password').hidden = true;
    },

    /** showStepPassword() — переключает с шага 2 на шаг 3. */
    showStepPassword() {
        document.getElementById('registration-gate-step-email').hidden    = true;
        document.getElementById('registration-gate-step-code').hidden     = true;
        document.getElementById('registration-gate-step-password').hidden = false;
    },
};
