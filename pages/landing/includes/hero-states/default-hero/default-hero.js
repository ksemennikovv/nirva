/**
 * default-hero.js — Логика onboarding-блока "Новый пользователь".
 *
 * Определяет глобальный объект DefaultHero.
 * init() вызывается из assets/js/main.js на DOMContentLoaded.
 *
 * Flow:
 *   1. start-analysis.php — создаёт сессию (только ID, без сообщения)
 *   2. ChatRoller.open() — открывает чат (история пустая → ничего не загружает)
 *   3. ChatRoller.sendMessage(text) — отправляет первое сообщение в AI
 *      (сохраняет в БД + получает ответ AI, как любое последующее сообщение)
 */

const DefaultHero = {

    init() {
        document
            .getElementById('start-analysis-button')
            .addEventListener('click', () => DefaultHero.handleStartAnalysis());

        document
            .getElementById('submit-analysis-input')
            .addEventListener('click', () => DefaultHero.handleStartAnalysis());
    },

    handleStartAnalysis() {
        const input = document.getElementById('hero-text-input');
        const text  = DefaultHero.getInputValue();

        if (!DefaultHero.validateInput(text)) {
            input.classList.add('error');
            input.addEventListener('input', () => input.classList.remove('error'), { once: true });
            return;
        }

        const btn = document.getElementById('start-analysis-button');
        btn.disabled = true;

        // Шаг 1: создать сессию (сообщение пока не сохраняем в БД)
        fetch('/pages/landing/includes/hero-states/default-hero/api/start-analysis.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({}),
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    console.error('start-analysis error:', data.error);
                    return;
                }

                input.value = '';

                // Шаг 2: открыть чат (история пустая — appendMessage не вызывается)
                ChatRoller.open();

                // Шаг 3: отправить первое сообщение через тот же путь что и чат
                // send-message.php сохранит его и вернёт ответ AI
                ChatRoller.sendMessage(text);
            })
            .catch(err => {
                console.error('start-analysis fetch failed:', err);
            })
            .finally(() => {
                btn.disabled = false;
            });
    },

    validateInput(text) {
        return text.length > 0;
    },

    getInputValue() {
        return document.getElementById('hero-text-input').value.trim();
    },
};
