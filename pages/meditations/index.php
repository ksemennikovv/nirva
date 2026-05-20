<?php
session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/middleware/auth.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/repositories/MeditationRepository.php';
require_once $root . '/src/repositories/SubscriptionRepository.php';

$medRepo = new MeditationRepository();
$subRepo = new SubscriptionRepository();

$subscription = $subRepo->getActive($currentUserId);
$isFirstMonth = false;
if (!$subscription) {
    $db   = Database::getConnection();
    $stmt = $db->prepare('SELECT created_at FROM users WHERE id = ?');
    $stmt->execute([$currentUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $isFirstMonth = $user && strtotime($user['created_at']) > (time() - BusinessConfig::MEDITATION_FREE_WINDOW_SECONDS);
}

$personalGroups = $medRepo->getPersonalGroupedByAnalysis($currentUserId);
$categories     = $medRepo->getGeneralByCategory($currentUserId);

// Темы для фильтра: общие категории + уникальные темы персональных
$topicFilters = [];
foreach ($categories as $cat) {
    $topicFilters[] = ['slug' => $cat['slug'], 'name' => $cat['name'], 'type' => 'general'];
}
foreach ($personalGroups as $group) {
    foreach ($group['items'] as $med) {
        if (!empty($med['topic'])) {
            $slug = 'personal_' . preg_replace('/\W+/u', '_', mb_strtolower($med['topic']));
            $exists = array_filter($topicFilters, fn($t) => $t['slug'] === $slug);
            if (!$exists) {
                $topicFilters[] = ['slug' => $slug, 'name' => $med['topic'], 'type' => 'personal'];
            }
        }
    }
}

$categoryIcons = [
    'confidence' => '⚡',
    'family'     => '🏠',
    'money'      => '💎',
    'health'     => '🌿',
    'children'   => '🤍',
];
$categoryColors = [
    'confidence' => ['#6c5ce7', '#a29bfe'],
    'family'     => ['#e17055', '#fab1a0'],
    'money'      => ['#00b894', '#55efc4'],
    'health'     => ['#0984e3', '#74b9ff'],
    'children'   => ['#fd79a8', '#fdcfe8'],
];

function cardGradient(string $slug, array $colors): string {
    return 'linear-gradient(145deg,' . $colors[0] . ',' . $colors[1] . ')';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Медитации — Nirva</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/pages/meditations/meditations.css">
</head>
<body>

<header class="app-header">
    <a href="/dashboard/" class="app-header__back">← Главная</a>
    <span class="app-header__logo">Nirva</span>
    <a href="/logout/" class="app-header__logout">Выйти</a>
</header>

<main class="meditations-page">

    <h1 class="meditations-page__title">Медитации</h1>

    <!-- ── Фильтры ────────────────────────────────────────────────────────── -->
    <div class="med-filters-wrap">
        <div class="med-filters" id="med-filters-type">
            <button class="med-filter active" data-type="all">Все</button>
            <button class="med-filter" data-type="accessible">Доступные</button>
            <button class="med-filter" data-type="locked">Недоступные</button>
            <button class="med-filter" data-type="general">Общие</button>
            <button class="med-filter" data-type="personal">Персональные</button>
        </div>

        <?php if (!empty($topicFilters)): ?>
        <div class="med-filters med-filters--topics" id="med-filters-topic">
            <span class="med-filters__label">Темы:</span>
            <button class="med-filter med-filter--topic active" data-topic="all">Все темы</button>
            <?php foreach ($topicFilters as $tf): ?>
            <button class="med-filter med-filter--topic <?php echo $tf['type'] === 'personal' ? 'med-filter--personal-topic' : ''; ?>"
                    data-topic="<?php echo htmlspecialchars($tf['slug']); ?>">
                <?php echo htmlspecialchars($tf['name']); ?>
                <?php if ($tf['type'] === 'personal'): ?><span class="med-filter__badge">AI</span><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Персональные медитации (по разборам) ─────────────────────────── -->
    <?php foreach ($personalGroups as $group):
        $groupSlug  = 'analysis_' . $group['analysis_id'];
        $groupLabel = $group['analysis_topic'] ? '«' . $group['analysis_topic'] . '»' : 'Разбор #' . $group['analysis_id'];
    ?>
    <section class="med-section" data-section-type="personal" data-section-slug="<?php echo $groupSlug; ?>">
        <div class="med-section__head">
            <span class="med-section__icon">✨</span>
            <div>
                <div class="med-section__name">Персональные — <?php echo htmlspecialchars($groupLabel); ?></div>
                <div class="med-section__meta"><?php echo count($group['items']); ?> медитации</div>
            </div>
        </div>
        <div class="med-row">
            <?php foreach ($group['items'] as $medIdx => $med):
                $isPurchased = (bool)$med['is_purchased'];
                $topicSlug   = 'personal_' . preg_replace('/\W+/u', '_', mb_strtolower($med['topic'] ?? ''));
                $imgUrl      = $med['image_url'] ?? '';
                $bgStyle     = $imgUrl ? 'url(' . htmlspecialchars($imgUrl) . ') center/cover no-repeat'
                                       : 'linear-gradient(145deg,#a29bfe,#6c5ce7)';
            ?>
            <div class="med-card <?php echo $isPurchased ? '' : 'med-card--locked'; ?>"
                 data-id="<?php echo $med['id']; ?>"
                 data-med-type="personal"
                 data-accessible="<?php echo $isPurchased ? '1' : '0'; ?>"
                 data-topic-slug="<?php echo htmlspecialchars($topicSlug); ?>"
                 data-category-slug="<?php echo htmlspecialchars($groupSlug); ?>"
                 data-item-index="<?php echo $medIdx; ?>"
                 style="cursor:pointer">
                <div class="med-card__bg" style="background:<?php echo $bgStyle; ?>"></div>

                <?php if (!$isPurchased): ?>
                <button class="med-card__cart" data-cart-id="<?php echo $med['id']; ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    В корзину
                </button>
                <?php endif; ?>

                <div class="med-card__body">
                    <div class="med-card__title"><?php echo htmlspecialchars($med['title'] ?? 'Медитация'); ?></div>
                    <?php if ($med['description']): ?>
                    <div class="med-card__desc"><?php echo htmlspecialchars($med['description']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="med-card__footer">
                    <?php if ($med['demo_audio_url']): ?>
                    <button class="med-card__demo" data-audio="<?php echo htmlspecialchars($med['demo_audio_url']); ?>">▶ Демо</button>
                    <?php else: ?>
                    <span class="med-card__demo med-card__demo--placeholder">▶ Демо</span>
                    <?php endif; ?>
                    <?php if (!$isPurchased): ?>
                    <span class="med-card__price"><?php echo number_format((int)$med['price'], 0, '', ' '); ?> ₽</span>
                    <?php else: ?>
                    <span class="med-card__badge">Доступна</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <!-- ── Каталог по категориям ──────────────────────────────────────────── -->
    <?php foreach ($categories as $cat):
        $slug   = $cat['slug'];
        $colors = $categoryColors[$slug] ?? ['#6c5ce7', '#a29bfe'];
        $icon   = $categoryIcons[$slug] ?? '🎵';
    ?>
    <section class="med-section" data-section-type="general" data-section-slug="<?php echo htmlspecialchars($slug); ?>">
        <div class="med-section__head">
            <span class="med-section__icon"><?php echo $icon; ?></span>
            <div>
                <div class="med-section__name"><?php echo htmlspecialchars($cat['name']); ?></div>
                <div class="med-section__meta med-section__count"><?php echo count($cat['meditations']); ?> медитации</div>
            </div>
        </div>

        <div class="med-row">
            <?php foreach ($cat['meditations'] as $medIdx => $med):
                $isFree      = $isFirstMonth && $med['is_free_first_month'];
                $isPurchased = (bool)$med['is_purchased'];
                $hasAccess   = $isFree || $isPurchased;
                $imgUrl      = $med['image_url'] ?? '';
                $bgStyle     = $imgUrl
                    ? 'url(' . htmlspecialchars($imgUrl) . ') center/cover no-repeat'
                    : cardGradient($slug, $colors);
            ?>
            <div class="med-card <?php echo $hasAccess ? '' : 'med-card--locked'; ?>"
                 data-id="<?php echo $med['id']; ?>"
                 data-med-type="general"
                 data-accessible="<?php echo $hasAccess ? '1' : '0'; ?>"
                 data-topic-slug="<?php echo htmlspecialchars($slug); ?>"
                 data-category-slug="<?php echo htmlspecialchars($slug); ?>"
                 data-item-index="<?php echo $medIdx; ?>"
                 style="cursor:pointer">

                <div class="med-card__bg" style="background:<?php echo $bgStyle; ?>"></div>

                <?php if (!$hasAccess): ?>
                <button class="med-card__cart" data-cart-id="<?php echo $med['id']; ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    В корзину
                </button>
                <?php endif; ?>

                <div class="med-card__body">
                    <div class="med-card__title"><?php echo htmlspecialchars($med['title'] ?? 'Медитация'); ?></div>
                    <?php if ($med['description']): ?>
                    <div class="med-card__desc"><?php echo htmlspecialchars($med['description']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="med-card__footer">
                    <?php if ($med['demo_audio_url']): ?>
                    <button class="med-card__demo" data-audio="<?php echo htmlspecialchars($med['demo_audio_url']); ?>">▶ Демо</button>
                    <?php else: ?>
                    <span class="med-card__demo med-card__demo--placeholder">▶ Демо</span>
                    <?php endif; ?>

                    <?php if (!$hasAccess): ?>
                    <span class="med-card__price"><?php echo number_format($med['price'], 0, '', ' '); ?> ₽</span>
                    <?php else: ?>
                    <span class="med-card__badge">Доступна</span>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <div class="med-empty" id="med-empty" hidden>Нет медитаций, соответствующих фильтру.</div>

</main>

<?php require_once $root . '/features/med-modal/med-modal.php'; ?>
<?php require_once $root . '/features/med-cart/med-cart.php'; ?>

<!-- Данные каталога для MedModal -->
<script>
var MED_CATALOG = <?php
    $jsCategories = [];

    // Персональные (по группам разборов)
    foreach ($personalGroups as $group) {
        $items = [];
        foreach ($group['items'] as $med) {
            $imgUrl     = $med['image_url'] ?? '';
            $isPurchased = (bool)$med['is_purchased'];
            $items[] = [
                'id'             => (int)$med['id'],
                'title'          => $med['title'] ?? '',
                'description'    => $med['description'] ?? '',
                'price'          => (int)$med['price'],
                'demo_audio_url' => $med['demo_audio_url'] ?? '',
                'image_url'      => $imgUrl,
                'gradient'       => 'linear-gradient(145deg,#a29bfe,#6c5ce7)',
                'free'           => $isPurchased,
            ];
        }
        $jsCategories[] = ['slug' => 'analysis_' . $group['analysis_id'], 'items' => $items];
    }

    // Общие по категориям
    foreach ($categories as $cat) {
        $slug   = $cat['slug'];
        $colors = $categoryColors[$slug] ?? ['#6c5ce7', '#a29bfe'];
        $items  = [];
        foreach ($cat['meditations'] as $med) {
            $isFree  = ($isFirstMonth && $med['is_free_first_month']) || $med['is_purchased'];
            $imgUrl  = $med['image_url'] ?? '';
            $items[] = [
                'id'             => (int)$med['id'],
                'title'          => $med['title'] ?? '',
                'description'    => $med['description'] ?? '',
                'price'          => (int)$med['price'],
                'demo_audio_url' => $med['demo_audio_url'] ?? '',
                'image_url'      => $imgUrl,
                'gradient'       => 'linear-gradient(145deg,' . $colors[0] . ',' . $colors[1] . ')',
                'free'           => $isFree,
            ];
        }
        $jsCategories[] = ['slug' => $slug, 'items' => $items];
    }
    echo json_encode($jsCategories, JSON_UNESCAPED_UNICODE);
?>;
</script>

<script src="/assets/js/main.js"></script>
<script src="/pages/meditations/meditations.js"></script>
</body>
</html>
