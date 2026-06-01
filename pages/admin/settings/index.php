<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/assets/php/helpers.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';

// Обновление версии ассетов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bump_assets') {
    $vf = $root . '/assets/version.txt';
    file_put_contents($vf, time());
    header('Location: /admin/settings/?asset_bumped=1');
    exit;
}

$flash = $_GET['saved'] ?? '';
$assetBumped = $_GET['asset_bumped'] ?? '';

// Схема всех настроек приложения
$schema = [
    'Доступ и режимы' => [
        'supervisor_mode' => [
            'label'   => 'Supervisor Mode — ручная проверка ИИ-ответов',
            'hint'    => 'Все ответы ИИ ждут одобрения администратора перед отправкой пользователю',
            'type'    => 'bool',
            'default' => BusinessConfig::SUPERVISOR_MODE,
        ],
        'subscription_required' => [
            'label'   => 'Требовать подписку',
            'hint'    => 'Отключите для тестового периода — все пользователи получат полный доступ без оплаты',
            'type'    => 'bool',
            'default' => BusinessConfig::SUBSCRIPTION_REQUIRED,
        ],
    ],
    'Разборы' => [
        'analysis_min_interval_days' => [
            'label'   => 'Минимальный интервал между разборами',
            'hint'    => 'Дней, которые должны пройти после предыдущего разбора. 0 — без ограничений',
            'type'    => 'int',
            'unit'    => 'дней',
            'default' => BusinessConfig::ANALYSIS_MIN_INTERVAL_DAYS,
        ],
        'burn_period_days' => [
            'label'   => 'Период сгорания слота',
            'hint'    => 'Расчётный период (в днях) для вычисления дедлайна сгорания разбора',
            'type'    => 'int',
            'unit'    => 'дней',
            'default' => BusinessConfig::BURN_PERIOD_DAYS,
        ],
        'burn_show_min_analyses' => [
            'label'   => 'Показывать дедлайн сгорания при разборах больше',
            'hint'    => 'При 1 разборе в месяц дедлайн не нужен. Обычно: 1',
            'type'    => 'int',
            'unit'    => 'шт',
            'default' => BusinessConfig::BURN_SHOW_MIN_ANALYSES,
        ],
    ],
    'Дашборд' => [
        'dashboard_diary_days_threshold' => [
            'label'   => 'Дней после разбора для показа блока дневника',
            'hint'    => 'Если прошло меньше N дней с последнего разбора — на главной показывается дневник, иначе — приглашение к разбору',
            'type'    => 'int',
            'unit'    => 'дней',
            'default' => BusinessConfig::DASHBOARD_DIARY_DAYS_THRESHOLD,
        ],
        'dashboard_diary_daily_show_limit' => [
            'label'   => 'Показов дневника в день с главной',
            'hint'    => 'После достижения лимита блок переключается на режим разбора',
            'type'    => 'int',
            'unit'    => 'раз',
            'default' => BusinessConfig::DASHBOARD_DIARY_DAILY_SHOW_LIMIT,
        ],
    ],
    'Дневник' => [
        'diary_free_entries_limit' => [
            'label'   => 'Бесплатных записей без подписки',
            'hint'    => 'Пользователь без активной подписки может создать не более N записей в дневнике',
            'type'    => 'int',
            'unit'    => 'записей',
            'default' => BusinessConfig::DIARY_FREE_ENTRIES_LIMIT,
        ],
    ],
    'Медитации' => [
        'meditation_auto_generate' => [
            'label'   => 'Автогенерация медитаций после разбора',
            'hint'    => 'Автоматически создавать персональные медитации после завершения разбора',
            'type'    => 'bool',
            'default' => BusinessConfig::MEDITATION_AUTO_GENERATE === 'yes',
        ],
        'meditation_generate_count' => [
            'label'   => 'Медитаций за одну генерацию',
            'hint'    => 'Сколько персональных медитаций создаётся за один цикл',
            'type'    => 'int',
            'unit'    => 'шт',
            'default' => BusinessConfig::MEDITATION_GENERATE_COUNT,
        ],
        'meditation_free_window_days' => [
            'label'   => 'Окно бесплатного доступа к медитациям',
            'hint'    => 'Новые пользователи без подписки имеют доступ к медитациям в течение N дней с регистрации',
            'type'    => 'int',
            'unit'    => 'дней',
            'default' => 30,
        ],
        'meditation_set_discount_pct' => [
            'label'   => 'Скидка на набор медитаций из разбора',
            'hint'    => 'Процент скидки при покупке всего набора медитаций из одного разбора',
            'type'    => 'int',
            'unit'    => '%',
            'default' => (int)(BusinessConfig::MEDITATION_SET_DISCOUNT * 100),
        ],
    ],
    // Секция генерации изображений рендерится отдельно ниже (динамические дропдауны)

    'Реферальная программа' => [
        'referral_bonus_months' => [
            'label'   => 'Бонус реферера',
            'hint'    => 'Дополнительных месяцев подписки за каждого приглашённого пользователя',
            'type'    => 'int',
            'unit'    => 'месяцев',
            'default' => BusinessConfig::REFERRAL_BONUS_MONTHS,
        ],
    ],
];

// Текущие значения из БД
$db  = Database::getConnection();
$all = $db->query('SELECT key_name, value FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);

function currentVal(array $all, string $key, $default) {
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

$pageTitle = 'Настройки';
$activeNav = 'settings';
require dirname(__DIR__) . '/_layout.php';
?>

<?php if ($flash === '1'): ?>
<div class="adm-alert adm-alert--success" style="margin-bottom:20px">Настройки сохранены.</div>
<?php endif; ?>
<?php if ($assetBumped === '1'): ?>
<div class="adm-alert adm-alert--success" style="margin-bottom:20px">Версия дизайна обновлена. Все пользователи получат свежие CSS/JS при следующем визите.</div>
<?php endif; ?>

<!-- Обновление дизайна -->
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head">
        <div class="adm-card__title">Обновление дизайна</div>
    </div>
    <div style="padding:20px">
        <div style="font-size:13px;color:var(--muted);margin-bottom:12px">
            Нажмите кнопку, чтобы сбросить кэш CSS/JS у всех пользователей.
            Браузеры загрузят свежие файлы при следующем переходе между страницами.
            Используйте только после изменений дизайна — не нужно делать это постоянно.
        </div>
        <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
            <form method="post" style="margin:0">
                <input type="hidden" name="action" value="bump_assets">
                <button type="submit" class="adm-btn adm-btn--primary">Обновить дизайн для всех пользователей</button>
            </form>
            <span style="font-size:12px;color:var(--muted)">
                Текущая версия: <strong><?= ASSET_VER ?></strong>
                (обновлено: <?= date('d.m.Y H:i', (int)ASSET_VER) ?>)
            </span>
        </div>
    </div>
</div>

<form method="post" action="/admin/settings/api/save.php">

<?php foreach ($schema as $groupName => $fields): ?>
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head">
        <div class="adm-card__title"><?php echo htmlspecialchars($groupName); ?></div>
    </div>
    <div style="padding:0">

    <?php foreach ($fields as $key => $field):
        $rawVal = currentVal($all, $key, $field['default']);
        $isWide = in_array($field['type'], ['select', 'textarea', 'password']);
    ?>
    <div style="display:grid;grid-template-columns:<?php echo $isWide ? '1fr 2fr' : '1fr auto'; ?>;gap:20px;align-items:<?php echo $isWide ? 'start' : 'center'; ?>;padding:16px 20px;border-bottom:1px solid var(--border)">
        <div>
            <div style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:3px"><?php echo htmlspecialchars($field['label']); ?></div>
            <div style="font-size:12px;color:var(--muted)"><?php echo htmlspecialchars($field['hint']); ?></div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;width:100%">

        <?php if ($field['type'] === 'bool'): ?>
            <?php $checked = (bool)(int)$rawVal || ($rawVal === 'yes' || $rawVal === true || $rawVal === 1); ?>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
                <input type="hidden" name="<?php echo $key; ?>" value="0">
                <input type="checkbox" name="<?php echo $key; ?>" value="1"
                    <?php echo $checked ? 'checked' : ''; ?>
                    style="width:18px;height:18px;cursor:pointer;accent-color:var(--accent)">
                <span style="color:var(--muted)"><?php echo $checked ? 'Включено' : 'Выключено'; ?></span>
            </label>

        <?php elseif ($field['type'] === 'select'): ?>
            <select name="<?php echo $key; ?>"
                    style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%">
                <?php foreach ($field['options'] as $optVal => $optLabel): ?>
                <option value="<?php echo htmlspecialchars($optVal); ?>" <?php echo $rawVal === $optVal ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($optLabel); ?>
                </option>
                <?php endforeach; ?>
            </select>

        <?php elseif ($field['type'] === 'textarea'): ?>
            <textarea name="<?php echo $key; ?>" rows="3"
                      style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;font-size:12px;font-family:monospace;resize:vertical"><?php echo htmlspecialchars((string)$rawVal); ?></textarea>

        <?php elseif ($field['type'] === 'password'): ?>
            <input type="text" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars((string)$rawVal); ?>"
                   style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;font-family:monospace">

        <?php else: ?>
            <input type="number" name="<?php echo $key; ?>" value="<?php echo (int)$rawVal; ?>"
                min="0" max="9999" required
                style="width:80px;padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:14px;font-weight:600;text-align:center">
            <?php if (!empty($field['unit'])): ?>
            <span style="font-size:12px;color:var(--muted)"><?php echo htmlspecialchars($field['unit']); ?></span>
            <?php endif; ?>

        <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    </div>
</div>
<?php endforeach; ?>

<?php
// Данные для JS
$fluxModels = [
    'fal-ai/flux-pro/v1.1'       => 'Flux Pro 1.1 — $0.04/img ⭐⭐⭐⭐⭐',
    'fal-ai/flux-pro/v1.1-ultra' => 'Flux Pro 1.1 Ultra — $0.06/img (4x разрешение)',
    'fal-ai/flux/dev'            => 'Flux Dev — ~$0.025/img ⭐⭐⭐⭐',
    'fal-ai/flux/schnell'        => 'Flux Schnell — ~$0.003/img ⭐⭐⭐ (быстрый/дешёвый)',
    'fal-ai/flux-kontext-pro'    => 'Flux Kontext Pro — $0.04/img',
    'fal-ai/ideogram/v3'         => 'Ideogram V3 — ~$0.08/img',
    'fal-ai/recraft-v3'          => 'Recraft V3 — ~$0.04/img',
];
$imagenModels = [
    'nano-banana-2'   => 'Nano Banana 2 — $0.04/img ⭐⭐⭐⭐⭐',
    'nano-banana-pro' => 'Nano Banana Pro — $0.09/img (студийный)',
    'nano-banana'     => 'Nano Banana (базовая) — $0.02/img',
];
$aspectRatios = ['9:16'=>'9:16 (портрет)', '2:3'=>'2:3 (портрет)', '1:1'=>'1:1 (квадрат)', '3:2'=>'3:2 (пейзаж)', '16:9'=>'16:9 (широкий)', 'auto'=>'auto'];
$resolutions  = ['1K'=>'1K — стандарт', '2K'=>'2K — высокое', '4K'=>'4K — максимум'];

$curProvPersonal = $all['image_provider_personal'] ?? 'none';
$curProvGeneral  = $all['image_provider_general']  ?? 'none';
$curFluxModel    = $all['flux_model']              ?? 'fal-ai/flux-pro/v1.1';
$curImagenModel  = $all['imagen_model']            ?? 'nano-banana-2';
$curAspect       = $all['imagen_aspect_ratio']     ?? '9:16';
$curResolution   = $all['imagen_resolution']       ?? '1K';
?>

<!-- ═══ ГЕНЕРАЦИЯ ИЗОБРАЖЕНИЙ ════════════════════════════════════════════ -->
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">🖼 Генерация изображений</div></div>
    <div style="padding:0">

    <?php foreach (['personal' => 'Персональные медитации', 'general' => 'Общие медитации'] as $type => $label):
        $curProv = ($type === 'personal') ? $curProvPersonal : $curProvGeneral;
    ?>
    <!-- Провайдер + модель для <?php echo $type; ?> -->
    <div style="padding:16px 20px;border-bottom:1px solid var(--border)" id="section-<?php echo $type; ?>">
        <div style="font-size:13px;font-weight:700;margin-bottom:12px"><?php echo $label; ?></div>
        <div style="display:grid;grid-template-columns:200px 1fr;gap:12px;align-items:start">

            <!-- Провайдер -->
            <div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Провайдер</div>
                <select name="image_provider_<?php echo $type; ?>" id="prov-<?php echo $type; ?>"
                        onchange="updateModels('<?php echo $type; ?>')"
                        style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                    <option value="none"   <?php echo $curProv === 'none'   ? 'selected' : ''; ?>>— Отключено</option>
                    <option value="flux"   <?php echo $curProv === 'flux'   ? 'selected' : ''; ?>>⚡ Fal.ai (Flux)</option>
                    <option value="imagen" <?php echo $curProv === 'imagen' ? 'selected' : ''; ?>>🍌 NanoBanana</option>
                </select>
            </div>

            <!-- Модели Flux -->
            <div id="flux-models-<?php echo $type; ?>" style="display:<?php echo $curProv === 'flux' ? 'block' : 'none'; ?>">
                <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Модель Fal.ai</div>
                <select name="flux_model_<?php echo $type; ?>"
                        style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                    <?php foreach ($fluxModels as $slug => $desc): ?>
                    <option value="<?php echo $slug; ?>" <?php echo $curFluxModel === $slug ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($desc); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Модели NanoBanana -->
            <div id="imagen-models-<?php echo $type; ?>" style="display:<?php echo $curProv === 'imagen' ? 'block' : 'none'; ?>">
                <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Модель NanoBanana</div>
                <div style="display:flex;gap:8px">
                    <select name="imagen_model_<?php echo $type; ?>"
                            style="flex:1;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                        <?php foreach ($imagenModels as $slug => $desc): ?>
                        <option value="<?php echo $slug; ?>" <?php echo $curImagenModel === $slug ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($desc); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="imagen_aspect_<?php echo $type; ?>"
                            style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                        <?php foreach ($aspectRatios as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>" <?php echo $curAspect === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="imagen_resolution_<?php echo $type; ?>"
                            style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                        <?php foreach ($resolutions as $val => $lbl): ?>
                        <option value="<?php echo $val; ?>" <?php echo $curResolution === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Заглушка когда none -->
            <div id="none-models-<?php echo $type; ?>" style="display:<?php echo $curProv === 'none' ? 'flex' : 'none'; ?>;align-items:center;color:var(--muted);font-size:12px">
                Выберите провайдер для настройки модели
            </div>

        </div>
    </div>
    <?php endforeach; ?>

    <!-- Промты -->
    <?php
    $promptFields = [
        'image_prompt_personal' => ['label' => 'Промт — персональные', 'default' => 'sacred feminine energy, {title}, spiritual healing meditation, {description}, soft divine golden light, ethereal atmosphere, dreamlike sacred space, woman silhouette, no text, no watermark, vertical portrait, cinematic quality, {style}'],
        'image_prompt_general'  => ['label' => 'Промт — общие', 'default' => 'meditation artwork, {title}, {category} theme, {description}, soft pastel light, peaceful sacred space, spiritual atmosphere, abstract divine, no text, no watermark, square format, high quality, {style}'],
        'image_style_flux'      => ['label' => 'Стиль-суффикс Fal.ai', 'default' => 'photorealistic, sharp details, 8K resolution'],
        'image_style_imagen'    => ['label' => 'Стиль-суффикс NanoBanana', 'default' => 'painterly, vivid colors, artistic quality'],
    ];
    foreach ($promptFields as $key => $f):
        $val = $all[$key] ?? $f['default'];
    ?>
    <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:grid;grid-template-columns:200px 1fr;gap:12px">
        <div>
            <div style="font-size:13px;font-weight:600"><?php echo $f['label']; ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px">Переменные: {title} {description} {topic} {category} {style}</div>
        </div>
        <textarea name="<?php echo $key; ?>" rows="2"
                  style="padding:7px 9px;border:1px solid var(--border);border-radius:6px;font-size:12px;font-family:monospace;resize:vertical"><?php echo htmlspecialchars($val); ?></textarea>
    </div>
    <?php endforeach; ?>

    </div>
</div>

<?php
// Текущие значения настроек ИИ-ассистента
$curAiProvider = $all['ai_provider'] ?? 'openai';
$curAiModel    = $all['ai_model']    ?? 'gpt-4o';
?>

<!-- ═══ ИИ-АССИСТЕНТ ════════════════════════════════════════════════════ -->
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">🤖 ИИ-ассистент</div></div>
    <div style="padding:0">

    <div style="padding:14px 20px;border-bottom:1px solid var(--border)">
        <div style="font-size:12px;color:var(--muted);margin-bottom:12px">
            Провайдер и модель, используемые для всех AI-промтов: разборы, самоисследования, дневник, тексты персональных медитаций.
        </div>
        <div style="display:grid;grid-template-columns:200px 1fr;gap:12px;align-items:start">

            <!-- Провайдер -->
            <div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Провайдер</div>
                <select name="ai_provider" id="ai-provider"
                        onchange="updateAiModels()"
                        style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                    <option value="openai"   <?php echo $curAiProvider === 'openai'   ? 'selected' : ''; ?>>💬 ChatGPT (OpenAI)</option>
                    <option value="claude"   <?php echo $curAiProvider === 'claude'   ? 'selected' : ''; ?>>🧠 Claude (Anthropic)</option>
                    <option value="deepseek" <?php echo $curAiProvider === 'deepseek' ? 'selected' : ''; ?>>🔍 DeepSeek</option>
                </select>
            </div>

            <!-- Модель -->
            <div>
                <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Модель</div>
                <select name="ai_model" id="ai-model"
                        style="width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
                    <!-- заполняется JS -->
                </select>
            </div>

        </div>
    </div>

    </div>
</div>

<div style="display:flex;gap:12px;align-items:center;padding:4px 0">
    <button type="submit" class="adm-btn adm-btn--primary">Сохранить настройки</button>
    <span style="font-size:12px;color:var(--muted)">Изменения вступают в силу немедленно, без перезапуска</span>
</div>

</form>

<script>
document.querySelectorAll('input[type=checkbox]').forEach(cb => {
    cb.addEventListener('change', () => {
        const label = cb.nextElementSibling;
        if (label) label.textContent = cb.checked ? 'Включено' : 'Выключено';
    });
});

function updateModels(type) {
    const prov = document.getElementById('prov-' + type).value;
    document.getElementById('flux-models-'  + type).style.display = prov === 'flux'   ? 'block' : 'none';
    document.getElementById('imagen-models-'+ type).style.display = prov === 'imagen' ? 'block' : 'none';
    document.getElementById('none-models-'  + type).style.display = prov === 'none'   ? 'flex'  : 'none';
}

// ИИ-ассистент — модели по провайдерам
const AI_MODELS = {
    openai: [
        { value: 'gpt-4o',       label: 'GPT-4o — основная ⭐⭐⭐⭐⭐' },
        { value: 'gpt-4o-mini',  label: 'GPT-4o mini — быстрая и дешёвая ⭐⭐⭐' },
        { value: 'gpt-4-turbo',  label: 'GPT-4 Turbo ⭐⭐⭐⭐' },
        { value: 'o3-mini',      label: 'o3-mini — с рассуждениями ⭐⭐⭐⭐' },
    ],
    claude: [
        { value: 'claude-opus-4-7',   label: 'Claude Opus 4.7 — топ ⭐⭐⭐⭐⭐' },
        { value: 'claude-sonnet-4-6', label: 'Claude Sonnet 4.6 ⭐⭐⭐⭐' },
        { value: 'claude-haiku-4-5',  label: 'Claude Haiku 4.5 — быстрая ⭐⭐⭐' },
    ],
    deepseek: [
        { value: 'deepseek-chat',     label: 'DeepSeek Chat ⭐⭐⭐⭐' },
        { value: 'deepseek-reasoner', label: 'DeepSeek Reasoner — с рассуждениями ⭐⭐⭐⭐' },
    ],
};

const currentAiModel = <?php echo json_encode($curAiModel); ?>;

function updateAiModels() {
    const prov   = document.getElementById('ai-provider').value;
    const select = document.getElementById('ai-model');
    const models = AI_MODELS[prov] || [];
    select.innerHTML = '';
    models.forEach(m => {
        const opt = document.createElement('option');
        opt.value = m.value;
        opt.textContent = m.label;
        if (m.value === currentAiModel) opt.selected = true;
        select.appendChild(opt);
    });
}

// Инициализация при загрузке страницы
updateAiModels();
</script>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
