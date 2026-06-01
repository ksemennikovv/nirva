<?php
session_start();
$root = dirname(__DIR__, 2);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/assets/php/helpers.php';
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
    $isFirstMonth = $user && strtotime($user['created_at']) > (time() - BusinessConfig::meditationFreeWindowSeconds());
}

$personalGroups   = $medRepo->getPersonalGroupedByAnalysis($currentUserId);
$categories       = $medRepo->getGeneralByCategory($currentUserId);
$listenStats      = $medRepo->getListeningStats($currentUserId);
$lastListened     = $medRepo->getLastListenedPerMeditation($currentUserId);

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Медитации — Nirva</title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('/pages/meditations/meditations.css') ?>">
</head>
<body>

<div class="phone phone--full">

<header class="app-header">
    <a href="/dashboard/" class="app-header__back">
        <svg width="7" height="12" viewBox="0 0 7 12" fill="none"><path d="M6 1L1 6l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Назад
    </a>
    <a href="/dashboard/" class="app-header__logo-orb" style="width:36px;height:36px;font-size:14px">N</a>
</header>

<main class="meditations-page">

    <h1 class="meditations-page__title">Медитации</h1>

    <?php if ($listenStats['sessions_count'] > 0): ?>
    <div class="med-stats-card">
        <div class="med-stats-card__item">
            <span class="med-stats-card__val"><?php echo $listenStats['total_minutes']; ?></span>
            <span class="med-stats-card__lbl">минут</span>
        </div>
        <div class="med-stats-card__sep"></div>
        <div class="med-stats-card__item">
            <span class="med-stats-card__val"><?php echo $listenStats['sessions_count']; ?></span>
            <span class="med-stats-card__lbl">сессий</span>
        </div>
        <div class="med-stats-card__hint">за последние 30 дней</div>
    </div>
    <?php endif; ?>

    <!-- ── Фильтры ────────────────────────────────────────────────────────── -->
    <div class="med-filters-wrap">
        <div class="med-filter-row">

            <!-- Дропдаун по типу -->
            <div class="med-dd" id="med-dd-type">
                <button class="med-dd__trigger" id="med-dd-type-btn" aria-haspopup="true" aria-expanded="false">
                    <span class="med-dd__label" id="med-dd-type-label">Все</span>
                    <svg class="med-dd__arrow" width="10" height="6" viewBox="0 0 10 6" fill="none">
                        <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="med-dd__panel" id="med-dd-type-panel" hidden>
                    <label class="med-dd__item med-dd__item--all">
                        <input type="checkbox" class="med-dd__cb med-dd__cb--all" id="med-type-all" checked>
                        <span>Все</span>
                    </label>
                    <label class="med-dd__item">
                        <input type="checkbox" class="med-dd__cb" value="accessible" checked>
                        <span>Доступные</span>
                    </label>
                    <label class="med-dd__item">
                        <input type="checkbox" class="med-dd__cb" value="locked" checked>
                        <span>Недоступные</span>
                    </label>
                    <label class="med-dd__item">
                        <input type="checkbox" class="med-dd__cb" value="general" checked>
                        <span>Общие</span>
                    </label>
                    <label class="med-dd__item">
                        <input type="checkbox" class="med-dd__cb" value="personal" checked>
                        <span>Персональные</span>
                    </label>
                </div>
            </div>

            <!-- Дропдаун по теме -->
            <?php if (!empty($topicFilters)): ?>
            <div class="med-dd" id="med-dd-topic">
                <button class="med-dd__trigger" id="med-dd-topic-btn" aria-haspopup="true" aria-expanded="false">
                    <span class="med-dd__label" id="med-dd-topic-label">Все темы</span>
                    <svg class="med-dd__arrow" width="10" height="6" viewBox="0 0 10 6" fill="none">
                        <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div class="med-dd__panel med-dd__panel--topics" id="med-dd-topic-panel" hidden>
                    <div class="med-dd__search-wrap">
                        <input type="text" class="med-dd__search" id="med-dd-topic-search" placeholder="Поиск темы…">
                    </div>
                    <div class="med-dd__list" id="med-dd-topic-list">
                        <label class="med-dd__item med-dd__item--all">
                            <input type="checkbox" class="med-dd__cb med-dd__cb--all" id="med-topic-all" checked>
                            <span>Все темы</span>
                        </label>
                        <?php foreach ($topicFilters as $tf): ?>
                        <label class="med-dd__item <?php echo $tf['type'] === 'personal' ? 'med-dd__item--personal' : ''; ?>">
                            <input type="checkbox" class="med-dd__cb med-dd__cb--topic" value="<?php echo htmlspecialchars($tf['slug']); ?>"
                                   data-name="<?php echo htmlspecialchars($tf['name']); ?>" checked>
                            <span><?php echo htmlspecialchars($tf['name']); ?><?php if ($tf['type'] === 'personal'): ?> <span class="med-filter__badge">AI</span><?php endif; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
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
                <button class="med-card__cart" data-cart-id="<?php echo $med['id']; ?>" aria-label="В корзину">
                    <svg class="med-card__cart-icon-add" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    <svg class="med-card__cart-icon-done" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="med-card__cart-text">В корзину</span>
                </button>
                <?php else: ?>
                <span class="med-card__owned-badge">✓ Доступна</span>
                <?php endif; ?>

                <div class="med-card__body">
                    <div class="med-card__title"><?php echo htmlspecialchars($med['title'] ?? 'Медитация'); ?></div>
                    <?php if ($med['description']): ?>
                    <div class="med-card__desc"><?php echo htmlspecialchars($med['description']); ?></div>
                    <?php endif; ?>
                </div>
                <div class="med-card__footer">
                    <?php if ($isPurchased): ?>
                    <button class="med-card__play-btn">
                        <svg width="11" height="13" viewBox="0 0 11 13" fill="currentColor"><path d="M0 0l11 6.5L0 13V0z"/></svg>
                        Слушать
                    </button>
                    <?php else: ?>
                    <?php if ($med['demo_audio_url']): ?>
                    <button class="med-card__demo" data-audio="<?php echo htmlspecialchars($med['demo_audio_url']); ?>">▶ Демо</button>
                    <?php else: ?>
                    <span class="med-card__demo med-card__demo--placeholder">▶ Демо</span>
                    <?php endif; ?>
                    <span class="med-card__price"><?php echo number_format((int)$med['price'], 0, '', ' '); ?> ₽</span>
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
                <button class="med-card__cart" data-cart-id="<?php echo $med['id']; ?>" aria-label="В корзину">
                    <svg class="med-card__cart-icon-add" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                        <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                    </svg>
                    <svg class="med-card__cart-icon-done" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="med-card__cart-text">В корзину</span>
                </button>
                <?php else: ?>
                <span class="med-card__owned-badge">✓ Доступна</span>
                <?php endif; ?>

                <div class="med-card__body">
                    <div class="med-card__title"><?php echo htmlspecialchars($med['title'] ?? 'Медитация'); ?></div>
                    <?php if ($med['description']): ?>
                    <div class="med-card__desc"><?php echo htmlspecialchars($med['description']); ?></div>
                    <?php endif; ?>
                </div>

                <div class="med-card__footer">
                    <?php if ($hasAccess): ?>
                    <button class="med-card__play-btn">
                        <svg width="11" height="13" viewBox="0 0 11 13" fill="currentColor"><path d="M0 0l11 6.5L0 13V0z"/></svg>
                        Слушать
                    </button>
                    <?php else: ?>
                    <?php if ($med['demo_audio_url']): ?>
                    <button class="med-card__demo" data-audio="<?php echo htmlspecialchars($med['demo_audio_url']); ?>">▶ Демо</button>
                    <?php else: ?>
                    <span class="med-card__demo med-card__demo--placeholder">▶ Демо</span>
                    <?php endif; ?>
                    <span class="med-card__price"><?php echo number_format($med['price'], 0, '', ' '); ?> ₽</span>
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
<?php require_once $root . '/features/med-player/med-player.php'; ?>

</div><!-- /.phone -->

<!-- Данные каталога для MedModal -->
<script>
var MED_CATALOG = <?php
    $jsCategories = [];

    // Персональные (по группам разборов)
    foreach ($personalGroups as $group) {
        $items = [];
        foreach ($group['items'] as $med) {
            $medId       = (int)$med['id'];
            $isPurchased = (bool)$med['is_purchased'];
            $items[] = [
                'id'              => $medId,
                'title'           => $med['title'] ?? '',
                'description'     => $med['description'] ?? '',
                'price'           => (int)$med['price'],
                'demo_audio_url'  => $med['demo_audio_url'] ?? '',
                'full_audio_url'  => $isPurchased ? ($med['full_audio_url'] ?? '') : '',
                'image_url'       => $med['image_url'] ?? '',
                'gradient'        => 'linear-gradient(145deg,#a29bfe,#6c5ce7)',
                'free'            => $isPurchased,
                'type'            => 'personal',
                'last_listened_at'=> $lastListened[$medId] ?? null,
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
            $medId  = (int)$med['id'];
            $isFree = ($isFirstMonth && $med['is_free_first_month']) || $med['is_purchased'];
            $items[] = [
                'id'              => $medId,
                'title'           => $med['title'] ?? '',
                'description'     => $med['description'] ?? '',
                'price'           => (int)$med['price'],
                'demo_audio_url'  => $med['demo_audio_url'] ?? '',
                'full_audio_url'  => $isFree ? ($med['full_audio_url'] ?? '') : '',
                'image_url'       => $med['image_url'] ?? '',
                'gradient'        => 'linear-gradient(145deg,' . $colors[0] . ',' . $colors[1] . ')',
                'free'            => $isFree,
                'type'            => 'general',
                'last_listened_at'=> $lastListened[$medId] ?? null,
            ];
        }
        $jsCategories[] = ['slug' => $slug, 'items' => $items];
    }
    echo json_encode($jsCategories, JSON_UNESCAPED_UNICODE);
?>;
</script>

<script src="<?= asset_url('/assets/js/main.js') ?>"></script>
<script src="<?= asset_url('/pages/meditations/meditations.js') ?>"></script>
</body>
</html>
