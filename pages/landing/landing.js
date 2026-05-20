/**
 * landing.js — Менеджер hero-states landing-страницы.
 *
 * Определяет глобальный объект HeroStatesManager.
 * Загружается первым среди JS-файлов (см. landing.php).
 *
 * init() вызывается из assets/js/main.js на DOMContentLoaded:
 *   1. Скрывает все states
 *   2. Запрашивает текущий state у сервера (get-current-state.php)
 *   3. Показывает нужный state через applyState()
 *
 * Управление видимостью через hidden-атрибут на section-элементах.
 */

const HeroStatesManager = {

    /** ID всех section-элементов hero-states */
    states: {
        defaultHero:        'default-hero-state',
        unfinishedAnalysis: 'unfinished-analysis-state',
        registrationGate:   'registration-gate-state',
    },

    /**
     * init() — вызывается из main.js на DOMContentLoaded.
     *
     * Скрывает все states, затем запрашивает текущий hero-state у сервера.
     * При ошибке сети или неожиданном ответе — показывает default-hero.
     */
    init() {
        this.hideAllStates();

        fetch('/pages/landing/api/get-current-state.php')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.data.topic) {
                        const headerSpan = document.getElementById('chat-roller-topic');
                        if (headerSpan) headerSpan.textContent = data.data.topic;
                    }
                    // Если сервер говорит default-hero — проверим LocalStorage
                    if (data.data.hero_state === 'default-hero') {
                        HeroStatesManager.tryRestoreFromLocalStorage();
                    } else {
                        HeroStatesManager.applyState(data.data.hero_state);
                    }
                } else {
                    HeroStatesManager.tryRestoreFromLocalStorage();
                }
            })
            .catch(() => {
                HeroStatesManager.showDefaultHero();
            });
    },

    /**
     * Проверяет localStorage на наличие незавершённого чата.
     * Если данные свежие (<24ч) — пытается восстановить сессию на сервере.
     */
    tryRestoreFromLocalStorage() {
        const raw = localStorage.getItem('nirva_session');
        if (!raw) { HeroStatesManager.showDefaultHero(); return; }

        let saved;
        try { saved = JSON.parse(raw); } catch (e) { HeroStatesManager.showDefaultHero(); return; }

        const age = Math.floor(Date.now() / 1000) - (saved.saved_at || 0);
        if (age > 86400 || !saved.analysis_session_id) {
            localStorage.removeItem('nirva_session');
            HeroStatesManager.showDefaultHero();
            return;
        }

        fetch('/pages/landing/api/restore-session.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ analysis_session_id: saved.analysis_session_id }),
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (data.topic) {
                        const headerSpan = document.getElementById('chat-roller-topic');
                        if (headerSpan) headerSpan.textContent = data.topic;
                        const unfinishedSpan = document.getElementById('unfinished-analysis-topic');
                        if (unfinishedSpan) unfinishedSpan.textContent = data.topic;
                    }
                    HeroStatesManager.applyState('unfinished-analysis');
                } else {
                    localStorage.removeItem('nirva_session');
                    HeroStatesManager.showDefaultHero();
                }
            })
            .catch(() => { HeroStatesManager.showDefaultHero(); });
    },

    /**
     * applyState(heroState) — показывает state по строковому имени из API.
     *
     * Используется в init() и может вызываться из других модулей
     * для переключения state без знания о внутренней структуре IDs.
     *
     * @param {string} heroState — 'default-hero' | 'unfinished-analysis' | 'registration-gate'
     */
    applyState(heroState) {
        const map = {
            'default-hero':        () => HeroStatesManager.showDefaultHero(),
            'unfinished-analysis': () => HeroStatesManager.showUnfinishedAnalysis(),
            'registration-gate':   () => HeroStatesManager.showRegistrationGate(),
        };

        const fn = map[heroState];

        if (fn) {
            fn();
        } else {
            HeroStatesManager.showDefaultHero();
        }
    },

    /** Скрывает все hero-state секции через атрибут hidden */
    hideAllStates() {
        Object.values(this.states).forEach(id => {
            const el = document.getElementById(id);
            if (el) el.hidden = true;
        });
    },

    /** Показывает default-hero-state */
    showDefaultHero() {
        this.setState(this.states.defaultHero);
    },

    /** Показывает unfinished-analysis-state */
    showUnfinishedAnalysis() {
        this.setState(this.states.unfinishedAnalysis);
    },

    /** Показывает registration-gate-state */
    showRegistrationGate() {
        this.setState(this.states.registrationGate);
    },

    /**
     * setState(stateId) — скрывает все states и показывает запрошенный.
     * @param {string} stateId — значение id атрибута нужного section
     */
    setState(stateId) {
        this.hideAllStates();
        const el = document.getElementById(stateId);
        if (el) el.hidden = false;
    },
};
