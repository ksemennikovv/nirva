<?php
/**
 * features/med-row/med-row.php — Горизонтальная карусель медитаций.
 *
 * Переменные, которые должны быть определены до подключения:
 *   $medRowItems   array   — массив медитаций (id, title, description, price,
 *                            demo_audio_url, image_url)
 *   $medRowSlug    string  — уникальный slug для MED_CATALOG (например 'analysis_34')
 *   $medRowTitle   string  — заголовок секции
 *   $medRowSub     string  — подзаголовок (необязательно)
 *   $medRowMoreUrl string  — URL кнопки «Все →» (необязательно)
 */

if (empty($medRowItems)) return;

// Формируем JS-объект для MedModal
$medRowJs = [];
foreach ($medRowItems as $m) {
    $isPurchased = !empty($m['is_purchased']);
    $medRowJs[] = [
        'id'              => (int)$m['id'],
        'title'           => $m['title'] ?? 'Медитация',
        'description'     => $m['description'] ?? '',
        'price'           => (int)($m['price'] ?? 0),
        'demo_audio_url'  => $m['demo_audio_url'] ?? '',
        'full_audio_url'  => $m['full_audio_url'] ?? '',
        'image_url'       => $m['image_url'] ?? '',
        'gradient'        => 'linear-gradient(145deg,#2d3436,#636e72)',
        'free'            => $isPurchased,
        'type'            => $m['type'] ?? 'personal',
        'last_listened_at'=> $m['last_listened_at'] ?? null,
    ];
}
$medRowSlug    = $medRowSlug    ?? 'meditations';
$medRowTitle   = $medRowTitle   ?? 'Медитации';
$medRowSub     = $medRowSub     ?? '';
$medRowMoreUrl = $medRowMoreUrl ?? '';
?>
<div class="med-row-block">
    <div class="med-row-block__head">
        <div>
            <div class="med-row-block__title"><?php echo htmlspecialchars($medRowTitle); ?></div>
            <?php if ($medRowSub): ?>
            <div class="med-row-block__sub"><?php echo htmlspecialchars($medRowSub); ?></div>
            <?php endif; ?>
        </div>
        <?php if ($medRowMoreUrl): ?>
        <a href="<?php echo htmlspecialchars($medRowMoreUrl); ?>" class="med-row-block__more">Все →</a>
        <?php endif; ?>
    </div>

    <div class="med-row-scroll" data-med-row-slug="<?php echo htmlspecialchars($medRowSlug); ?>">
        <?php foreach ($medRowItems as $idx => $med):
            $isPurchased = !empty($med['is_purchased']);
            $bg = !empty($med['image_url'])
                ? 'url(' . htmlspecialchars($med['image_url']) . ') center/cover no-repeat'
                : 'linear-gradient(145deg,#2d3436,#636e72)';
        ?>
        <div class="med-row-card <?php echo $isPurchased ? 'med-row-card--owned' : ''; ?>"
             data-slug="<?php echo htmlspecialchars($medRowSlug); ?>" data-idx="<?php echo $idx; ?>" style="cursor:pointer">
            <div class="med-row-card__bg" style="background:<?php echo $bg; ?>"></div>
            <?php if (!$isPurchased): ?>
            <button class="med-row-card__cart" data-cart-id="<?php echo (int)$med['id']; ?>" data-cart-icon="1" type="button" aria-label="В корзину">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                </svg>
            </button>
            <?php endif; ?>
            <div class="med-row-card__body">
                <div class="med-row-card__title"><?php echo htmlspecialchars($med['title'] ?? 'Медитация'); ?></div>
            </div>
            <div class="med-row-card__footer">
                <?php if ($isPurchased): ?>
                <span class="med-row-card__play-full">▶ Слушать</span>
                <?php else: ?>
                <span class="med-row-card__demo">▶ Демо</span>
                <span class="med-row-card__price"><?php echo (int)($med['price'] ?? 0) > 0 ? number_format((int)$med['price'], 0, '', ' ') . ' ₽' : ''; ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function () {
    var slug  = <?php echo json_encode($medRowSlug); ?>;
    var items = <?php echo json_encode($medRowJs, JSON_UNESCAPED_UNICODE); ?>;

    // Регистрируем в глобальном каталоге
    if (typeof window.MED_CATALOG === 'undefined') window.MED_CATALOG = [];
    var existing = window.MED_CATALOG.findIndex(function (c) { return c.slug === slug; });
    if (existing >= 0) { window.MED_CATALOG[existing].items = items; }
    else               { window.MED_CATALOG.push({ slug: slug, items: items }); }

    // Вешаем обработчики после загрузки DOM
    function bindRow() {
        document.querySelectorAll('.med-row-card[data-slug="' + slug + '"]').forEach(function (card) {
            if (card.dataset.medRowBound) return;
            card.dataset.medRowBound = '1';

            card.addEventListener('click', function (e) {
                if (e.target.closest('.med-row-card__cart')) return;
                if (typeof MedModal === 'undefined') return;
                var idx = parseInt(this.dataset.idx || '0', 10);
                var cat = window.MED_CATALOG.find(function (c) { return c.slug === slug; });
                if (cat) MedModal.open(cat.items, idx);
            });

            var cartBtn = card.querySelector('.med-row-card__cart[data-cart-id]');
            if (cartBtn) {
                cartBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (typeof MedCart === 'undefined') return;
                    var id  = parseInt(this.dataset.cartId, 10);
                    var cat = window.MED_CATALOG.find(function (c) { return c.slug === slug; });
                    var item = cat ? cat.items.find(function (m) { return m.id === id; }) : null;
                    if (!item) item = { id: id, title: '', price: 0, image_url: '', gradient: '' };
                    MedCart.toggle(item, this);
                    this.classList.toggle('in-cart', MedCart.has(id));
                });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindRow);
    } else {
        bindRow();
    }
})();
</script>
