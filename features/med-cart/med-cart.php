<link rel="stylesheet" href="/features/med-cart/med-cart.css">

<!-- Кнопка-триггер корзины (вставляется в хедер через JS) -->
<div id="med-cart-trigger" class="med-cart-trigger" hidden>
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
    </svg>
    <span class="med-cart-trigger__badge" id="med-cart-badge">0</span>
</div>

<!-- Модальное окно корзины -->
<div id="med-cart-modal" class="med-cart-modal" hidden>
    <div class="med-cart-modal__overlay"></div>
    <div class="med-cart-modal__sheet">

        <div class="med-cart-modal__header">
            <div class="med-cart-modal__title">Корзина медитаций</div>
            <button class="med-cart-modal__close" id="med-cart-close">✕</button>
        </div>

        <!-- Список медитаций в корзине -->
        <div class="med-cart-modal__items" id="med-cart-items"></div>

        <!-- Пустая корзина -->
        <div class="med-cart-modal__empty" id="med-cart-empty" hidden>
            <div class="med-cart-modal__empty-icon">🛒</div>
            <div>Корзина пуста</div>
        </div>

        <!-- Итог -->
        <div class="med-cart-modal__footer" id="med-cart-footer">
            <div class="med-cart-modal__total" id="med-cart-total"></div>
            <button class="med-cart-modal__buy" id="med-cart-buy"></button>
            <div class="med-cart-modal__installment">*Можно купить в рассрочку</div>
        </div>

    </div>
</div>

<!-- Летящий элемент (клон для анимации) -->
<div id="med-cart-fly" class="med-cart-fly" hidden></div>

<script src="/features/med-cart/med-cart.js"></script>
