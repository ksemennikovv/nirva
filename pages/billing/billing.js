/**
 * pages/billing/billing.js — Логика страницы биллинга.
 */

document.addEventListener('DOMContentLoaded', () => {

    // ── Открытие модали смены тарифа ─────────────────────────────────────
    const btnChange = document.getElementById('btn-change-plan');
    if (btnChange) {
        btnChange.addEventListener('click', () => {
            if (typeof PlanModal !== 'undefined') PlanModal.open();
        });
    }

    // ── Копирование реферальной ссылки ───────────────────────────────────
    const btnCopy = document.getElementById('btn-copy-ref');
    if (btnCopy) {
        btnCopy.addEventListener('click', () => {
            const linkText = document.getElementById('referral-link-text')?.textContent?.trim();
            if (!linkText) return;

            navigator.clipboard.writeText(linkText).then(() => {
                const original = btnCopy.textContent;
                btnCopy.textContent = 'скопировано ✓';
                setTimeout(() => { btnCopy.textContent = original; }, 2000);
            }).catch(() => {
                // Fallback для браузеров без clipboard API
                const ta = document.createElement('textarea');
                ta.value = linkText;
                ta.style.position = 'fixed';
                ta.style.opacity  = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);

                const original = btnCopy.textContent;
                btnCopy.textContent = 'скопировано ✓';
                setTimeout(() => { btnCopy.textContent = original; }, 2000);
            });
        });
    }

});
