/**
 * unfinished-analysis.js — Обработчики состояния "Незавершённый анализ".
 *
 * Используется DOMContentLoaded-обёртка (main.js не вызывает .init() для этого модуля).
 *
 * "Продолжить разбор" — открывает ChatRoller который сам загружает историю из БД.
 * "Начать новый"     — POST к reset-analysis.php, затем showDefaultHero().
 */

document.addEventListener('DOMContentLoaded', () => {
    const continueBtn = document.getElementById('unfinished-analysis-continue');
    const resetBtn    = document.getElementById('unfinished-analysis-reset');

    continueBtn.addEventListener('click', () => {
        handleContinueAnalysis();
    });

    resetBtn.addEventListener('click', () => {
        handleResetAnalysis();
    });

    function handleContinueAnalysis() {
        ChatRoller.open();
    }

    function handleResetAnalysis() {
        fetch('/pages/landing/includes/hero-states/unfinished-analysis/api/reset-analysis.php', {
            method: 'POST',
        })
            .then(res => res.json())
            .then(() => {
                HeroStatesManager.showDefaultHero();
            })
            .catch(() => {
                HeroStatesManager.showDefaultHero();
            });
    }
});
