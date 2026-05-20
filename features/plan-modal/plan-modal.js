/**
 * features/plan-modal/plan-modal.js — Модальное окно выбора тарифа.
 *
 * Глобальный объект PlanModal с методами open() и close().
 * Инициализируется автоматически на DOMContentLoaded.
 */

const PlanModal = {

    _currentPeriod: 'monthly',

    init() {
        const modal    = document.getElementById('plan-modal');
        if (!modal) return;

        // Закрытие по крестику
        document.getElementById('plan-modal-close')
            .addEventListener('click', () => PlanModal.close());

        // Закрытие по backdrop
        modal.querySelector('.plan-modal__backdrop')
            .addEventListener('click', () => PlanModal.close());

        // Переключение периода
        modal.querySelectorAll('.plan-modal__period').forEach(btn => {
            btn.addEventListener('click', () => {
                PlanModal._setPeriod(btn.dataset.period);
            });
        });

        // Кнопки «Выбрать»
        modal.querySelectorAll('.plan-modal__btn').forEach(btn => {
            btn.addEventListener('click', () => {
                PlanModal._handleSelect(btn.dataset.plan);
            });
        });
    },

    open(context) {
        const modal = document.getElementById('plan-modal');
        if (!modal) return;

        const ctx = document.getElementById('plan-modal-context');
        if (ctx) {
            if (context) {
                ctx.textContent = context;
                ctx.hidden = false;
            } else {
                ctx.hidden = true;
            }
        }

        modal.hidden = false;
        // Запускаем анимацию въезда снизу
        modal.classList.remove('plan-modal--entering');
        void modal.offsetWidth; // reflow чтобы анимация перезапустилась
        modal.classList.add('plan-modal--entering');

        this._clearError();
    },

    close() {
        const modal = document.getElementById('plan-modal');
        if (modal) modal.hidden = true;
        this._clearError();
    },

    _setPeriod(period) {
        this._currentPeriod = period;
        const modal = document.getElementById('plan-modal');

        // Активная кнопка периода
        modal.querySelectorAll('.plan-modal__period').forEach(btn => {
            btn.classList.toggle('plan-modal__period--active', btn.dataset.period === period);
        });

        // Показываем нужную цену в каждой карточке
        modal.querySelectorAll('.plan-modal__price').forEach(el => {
            el.hidden = el.dataset.period !== period;
        });
    },

    _handleSelect(plan) {
        const modal = document.getElementById('plan-modal');
        const btn   = modal.querySelector(`.plan-modal__btn[data-plan="${plan}"]`);
        if (!btn) return;

        btn.disabled    = true;
        btn.textContent = 'Подождите...';
        this._clearError();

        fetch('/pages/billing/api/create-payment.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ plan, period: this._currentPeriod }),
        })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data?.payment_url) {
                    window.location.href = data.data.payment_url;
                } else {
                    this._showError(data.message || 'Ошибка оплаты. Попробуйте снова.');
                    btn.disabled    = false;
                    btn.textContent = 'Выбрать';
                }
            })
            .catch(() => {
                this._showError('Нет соединения с сервером.');
                btn.disabled    = false;
                btn.textContent = 'Выбрать';
            });
    },

    _showError(message) {
        const el = document.getElementById('plan-modal-error');
        if (!el) return;
        el.textContent = message;
        el.hidden = false;
    },

    _clearError() {
        const el = document.getElementById('plan-modal-error');
        if (el) el.hidden = true;
    },
};

document.addEventListener('DOMContentLoaded', () => PlanModal.init());
