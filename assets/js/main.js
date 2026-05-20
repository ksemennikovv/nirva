/**
 * assets/js/main.js — Глобальная точка инициализации приложения Nirva.
 *
 * Загружается последним среди всех JS-файлов страницы (см. landing.php).
 * К этому моменту все объекты уже определены в своих файлах:
 *   - HeroStatesManager  → pages/landing/landing.js
 *   - DefaultHero        → pages/landing/includes/hero-states/default-hero/default-hero.js
 *   - RegistrationGate   → pages/landing/includes/hero-states/registration-gate/registration-gate.js
 *   - ChatRoller         → (будет добавлен)
 *   - VoiceRecorder      → (будет добавлен)
 *
 * Единственная задача — вызвать init() каждого модуля в нужном порядке.
 * Бизнес-логика внутри main.js запрещена — она принадлежит модулям.
 */

document.addEventListener('DOMContentLoaded', () => {

    if (typeof HeroStatesManager !== 'undefined') HeroStatesManager.init();
    if (typeof DefaultHero       !== 'undefined') DefaultHero.init();
    if (typeof RegistrationGate  !== 'undefined') RegistrationGate.init();
    if (typeof ChatRoller        !== 'undefined') ChatRoller.init();
    if (typeof VoiceRecorder     !== 'undefined') VoiceRecorder.init();

});
