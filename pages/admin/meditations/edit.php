<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/services/ImageGeneration/ImageHistoryService.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Bad request'; exit; }

$db   = Database::getConnection();
$stmt = $db->prepare("SELECT m.*, u.email, c.name AS category_name
    FROM meditations m
    LEFT JOIN users u ON u.id = m.user_id
    LEFT JOIN meditation_categories c ON c.id = m.category_id
    WHERE m.id = ?");
$stmt->execute([$id]);
$med = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$med) { http_response_code(404); echo 'Медитация не найдена'; exit; }

$cats = $db->query("SELECT id, name FROM meditation_categories WHERE user_id IS NULL ORDER BY sort_order, name")
           ->fetchAll(PDO::FETCH_ASSOC);

$flash    = $_GET['saved']   ?? '';
$imgFlash = $_GET['img']     ?? '';
$genFlash = $_GET['gen']     ?? '';

// История картинок
$imageHistory = [];
try {
    $histSvc      = new ImageHistoryService($db);
    $imageHistory = $histSvc->getHistory($id, 20);
} catch (\Throwable $e) {} // таблица ещё не создана

// Провайдеры для генерации картинки
$providerPersonal = BusinessConfig::imageProviderPersonal();
$providerGeneral  = BusinessConfig::imageProviderGeneral();
$activeProvider   = $med['type'] === 'personal' ? $providerPersonal : $providerGeneral;
$providerLabels   = ['flux' => 'Flux Pro (Fal.ai)', 'imagen' => 'Imagen 3 (NanoBanana)', 'none' => 'не задан'];

$pageTitle = 'Медитация #' . $id;
$activeNav = 'meditations';
require dirname(__DIR__) . '/_layout.php';
?>

<?php if ($flash === '1'): ?>
<div class="adm-alert adm-alert--success">✅ Изменения сохранены — название, описание, аудио и другие поля обновлены.</div>
<?php endif; ?>
<?php if ($imgFlash === '1'): ?>
<div class="adm-alert adm-alert--success">🖼 Картинка обновлена — новое изображение сохранено и привязано к медитации.</div>
<?php endif; ?>
<?php if ($imgFlash === 'deleted'): ?>
<div class="adm-alert adm-alert--success">🗑 Картинка удалена — медитация теперь использует цветовой градиент.</div>
<?php endif; ?>
<?php if ($imgFlash === 'error'): ?>
<div class="adm-alert adm-alert--error">❌ Ошибка загрузки — проверьте формат файла (JPG/PNG/WebP) и размер (до 20 МБ).</div>
<?php endif; ?>
<?php if ($genFlash === '1'): ?>
<div class="adm-alert adm-alert--success">🎨 Картинка сгенерирована через AI и сохранена. Предыдущая версия помещена в историю.</div>
<?php endif; ?>
<?php if ($genFlash === 'fail'): ?>
<div class="adm-alert adm-alert--error">❌ Генерация картинки не удалась — проверьте API ключ и выбранный провайдер в <a href="/admin/settings/" style="color:inherit;text-decoration:underline">настройках</a>.</div>
<?php endif; ?>

<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap">
    <a href="/admin/meditations/" class="adm-btn adm-btn--ghost adm-btn--sm">← Медитации</a>
    <?php if ($med['analysis_id']): ?>
    <a href="/admin/analyses/view.php?id=<?php echo $med['analysis_id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">📋 Разбор #<?php echo $med['analysis_id']; ?></a>
    <?php endif; ?>
    <?php if ($med['user_id']): ?>
    <a href="/admin/users/view.php?id=<?php echo $med['user_id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">👤 <?php echo htmlspecialchars($med['email'] ?? ''); ?></a>
    <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start">

<!-- Левая колонка: основные данные -->
<div>
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">Медитация #<?php echo $id; ?></div></div>
    <div style="padding:20px">
        <form class="adm-form" method="post" action="/admin/meditations/api/save-edit.php"
              enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?php echo $id; ?>">

            <div class="adm-form-row">
                <div class="adm-field">
                    <label>Название</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($med['title'] ?? ''); ?>" placeholder="Название медитации">
                </div>
                <div class="adm-field">
                    <label>Тема</label>
                    <input type="text" name="topic" value="<?php echo htmlspecialchars($med['topic'] ?? ''); ?>">
                </div>
            </div>

            <div class="adm-field">
                <label>Описание</label>
                <textarea name="description" rows="2" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:13px;resize:vertical"><?php echo htmlspecialchars($med['description'] ?? ''); ?></textarea>
            </div>

            <div class="adm-field">
                <label>Текст медитации (personal_context)</label>
                <textarea name="personal_context" rows="4" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:13px;resize:vertical"><?php echo htmlspecialchars($med['personal_context'] ?? ''); ?></textarea>
            </div>

            <!-- Аудио -->
            <div style="background:var(--bg);border-radius:8px;padding:14px;margin-bottom:12px">
                <div style="font-weight:700;margin-bottom:10px;font-size:13px">🎧 Аудио</div>
                <?php if ($med['full_audio_url']): ?>
                <audio controls src="<?php echo htmlspecialchars($med['full_audio_url']); ?>" style="width:100%;margin-bottom:10px"></audio>
                <?php endif; ?>
                <div class="adm-form-row">
                    <div class="adm-field">
                        <label style="font-size:12px">Загрузить MP3 (полное)</label>
                        <input type="file" name="full_audio_file" accept="audio/mpeg,.mp3"
                               style="border:1px solid var(--border);border-radius:6px;padding:5px;width:100%;font-size:12px">
                    </div>
                    <div class="adm-field">
                        <label style="font-size:12px">Загрузить MP3 (демо)</label>
                        <input type="file" name="demo_audio_file" accept="audio/mpeg,.mp3"
                               style="border:1px solid var(--border);border-radius:6px;padding:5px;width:100%;font-size:12px">
                    </div>
                </div>
                <div class="adm-form-row">
                    <div class="adm-field">
                        <label style="font-size:12px">URL полного аудио</label>
                        <input type="text" name="full_audio_url" value="<?php echo htmlspecialchars($med['full_audio_url'] ?? ''); ?>" placeholder="/assets/audio/meditations/...">
                    </div>
                    <div class="adm-field">
                        <label style="font-size:12px">URL демо аудио</label>
                        <input type="text" name="demo_audio_url" value="<?php echo htmlspecialchars($med['demo_audio_url'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="adm-form-row">
                <div class="adm-field">
                    <label>Статус</label>
                    <select name="generation_status">
                        <?php foreach (['ready','pending','generating','failed'] as $st): ?>
                        <option value="<?php echo $st; ?>" <?php echo $med['generation_status'] === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-field">
                    <label>Тип</label>
                    <select name="type">
                        <option value="personal" <?php echo $med['type'] === 'personal' ? 'selected' : ''; ?>>personal</option>
                        <option value="general"  <?php echo $med['type'] === 'general'  ? 'selected' : ''; ?>>general</option>
                    </select>
                </div>
                <?php if ($med['type'] === 'general'): ?>
                <div class="adm-field">
                    <label>Категория</label>
                    <select name="category_id">
                        <option value="">— без категории —</option>
                        <?php foreach ($cats as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $med['category_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="adm-field" style="flex:0 0 110px">
                    <label>Цена (₽)</label>
                    <input type="number" name="price" value="<?php echo $med['price'] ?? 0; ?>" min="0">
                </div>
                <?php endif; ?>
            </div>

            <div style="display:flex;gap:10px;align-items:center;margin-top:4px">
                <button type="submit" class="adm-btn adm-btn--primary">Сохранить изменения</button>
                <?php if ($med['generation_status'] !== 'ready'): ?>
                <button type="submit" form="form-regenerate" class="adm-btn adm-btn--ghost">↺ Перегенерировать аудио</button>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($med['generation_status'] !== 'ready'): ?>
        <form id="form-regenerate" method="post" action="/admin/meditations/api/regenerate.php" style="display:none">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Удаление — отдельная форма СНАРУЖИ главной формы -->
<div style="margin-top:12px;padding:14px 16px;background:#fff5f5;border:1px solid #fecaca;border-radius:8px;display:flex;gap:12px;align-items:center">
    <div style="flex:1">
        <div style="font-size:13px;font-weight:600;color:var(--danger);margin-bottom:2px">Удалить медитацию</div>
        <div style="font-size:12px;color:var(--muted)">Удалит медитацию, аудио файл, картинку и историю. Необратимо.</div>
    </div>
    <form method="post" action="/admin/meditations/api/delete-one.php"
          onsubmit="return confirm('Удалить медитацию «<?php echo htmlspecialchars($med['title'] ?? '#'.$id); ?>»? Это действие нельзя отменить.')">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="redirect" value="/admin/meditations/">
        <button type="submit" class="adm-btn adm-btn--danger">🗑 Удалить медитацию</button>
    </form>
</div>

</div>

<!-- Правая колонка: картинка -->
<div>
<div class="adm-card" style="position:sticky;top:20px">
    <div class="adm-card__head"><div class="adm-card__title">🖼 Картинка</div></div>
    <div style="padding:16px">

        <!-- Текущая картинка -->
        <?php if (!empty($med['image_url'])): ?>
        <div style="margin-bottom:14px">
            <img src="<?php echo htmlspecialchars($med['image_url']); ?>"
                 style="width:100%;border-radius:8px;display:block;box-shadow:0 2px 8px rgba(0,0,0,.12)"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
            <div style="display:none;padding:20px;text-align:center;color:var(--muted);font-size:12px;background:var(--bg);border-radius:8px">Файл не найден</div>
            <div style="font-size:11px;color:var(--muted);margin-top:6px;word-break:break-all"><?php echo htmlspecialchars($med['image_url']); ?></div>
        </div>
        <?php else: ?>
        <div style="width:100%;aspect-ratio:3/4;background:var(--bg);border-radius:8px;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px;margin-bottom:14px">
            Нет картинки
        </div>
        <?php endif; ?>

        <!-- Генерация через API -->
        <div style="margin-bottom:12px;padding:12px;background:var(--bg);border-radius:8px">
            <div style="font-size:12px;font-weight:700;margin-bottom:8px">Сгенерировать через AI</div>
            <div style="font-size:11px;color:var(--muted);margin-bottom:10px">
                Провайдер: <strong><?php echo htmlspecialchars($providerLabels[$activeProvider] ?? $activeProvider); ?></strong>
                <?php if ($activeProvider === 'none'): ?>
                <br><a href="/admin/settings/" style="color:var(--accent)">Выбрать провайдер в настройках →</a>
                <?php endif; ?>
            </div>
            <?php if ($activeProvider !== 'none'): ?>
            <form method="post" action="/admin/meditations/api/generate-image.php"
                  onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Генерируем...'">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="hidden" name="provider" value="<?php echo htmlspecialchars($activeProvider); ?>">
                <div class="adm-field" style="margin-bottom:8px">
                    <label style="font-size:11px">Промт (можно изменить)</label>
                    <textarea name="custom_prompt" rows="3"
                              style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:11px;resize:vertical;font-family:monospace"><?php
                        // Строим дефолтный промт
                        $isPersonal = $med['type'] === 'personal';
                        $defaultTemplate = $isPersonal
                            ? 'sacred feminine energy, {title}, spiritual healing, soft divine light, ethereal, dreamlike, no text, no watermark, cinematic'
                            : 'meditation artwork, {title}, soft light, peaceful sacred space, spiritual, no text, no watermark';
                        echo htmlspecialchars(strtr($defaultTemplate, [
                            '{title}' => $med['title'] ?? '',
                        ]));
                    ?></textarea>
                </div>
                <button type="submit" class="adm-btn adm-btn--primary" style="width:100%">
                    🎨 Сгенерировать картинку
                </button>
            </form>
            <?php else: ?>
            <div style="font-size:12px;color:var(--muted)">Настройте провайдер в /admin/settings/</div>
            <?php endif; ?>
        </div>

        <!-- Загрузить файл -->
        <div style="margin-bottom:12px;padding:12px;background:var(--bg);border-radius:8px">
            <div style="font-size:12px;font-weight:700;margin-bottom:8px">Загрузить файл</div>
            <form method="post" action="/admin/meditations/api/upload-image.php"
                  enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="file" name="image_file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
                       style="border:1px solid var(--border);border-radius:6px;padding:5px;width:100%;font-size:12px;margin-bottom:8px">
                <button type="submit" class="adm-btn adm-btn--ghost" style="width:100%;font-size:12px">
                    ⬆ Загрузить изображение
                </button>
            </form>
        </div>

        <!-- URL вручную -->
        <div style="padding:12px;background:var(--bg);border-radius:8px">
            <div style="font-size:12px;font-weight:700;margin-bottom:8px">Вставить URL вручную</div>
            <form method="post" action="/admin/meditations/api/upload-image.php">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="text" name="image_url" value=""
                       placeholder="https://... или /assets/images/..."
                       style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:6px;font-size:12px;margin-bottom:8px">
                <button type="submit" class="adm-btn adm-btn--ghost" style="width:100%;font-size:12px">
                    💾 Сохранить URL
                </button>
            </form>
        </div>

        <!-- Удалить картинку -->
        <?php if (!empty($med['image_url'])): ?>
        <form method="post" action="/admin/meditations/api/upload-image.php" style="margin-top:10px">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="delete" value="1">
            <button type="submit" class="adm-btn adm-btn--danger" style="width:100%;font-size:12px"
                    onclick="return confirm('Удалить картинку?')">
                🗑 Удалить картинку
            </button>
        </form>
        <?php endif; ?>

    </div>
</div>
</div><!-- закрывает правую колонку -->

</div><!-- /grid -->

<!-- История картинок — на всю ширину под гридом -->
<?php if (!empty($imageHistory)): ?>
<div class="adm-card" style="margin-top:20px">
    <div class="adm-card__head">
        <div class="adm-card__title">🕑 История картинок (<?php echo count($imageHistory); ?>)</div>
    </div>
    <div style="padding:16px;display:flex;flex-wrap:wrap;gap:12px">
    <?php foreach ($imageHistory as $h):
        $isLocal  = str_starts_with($h['image_url'], '/assets/');
        $provider = $h['provider'] ?? '';
        $source   = $h['source']   ?? 'generated';

        // Составляем красивый бейдж
        if ($provider) {
            // Нормализуем имя модели для отображения
            $badgeMap = [
                'flux-pro/v1.1'       => 'Flux Pro 1.1',
                'v1.1'                => 'Flux Pro 1.1',
                'flux-pro/v1.1-ultra' => 'Flux Ultra',
                'v1.1-ultra'          => 'Flux Ultra',
                'flux/dev'            => 'Flux Dev',
                'dev'                 => 'Flux Dev',
                'flux/schnell'        => 'Flux Schnell',
                'schnell'             => 'Flux Schnell',
                'flux-kontext-pro'    => 'Flux Kontext',
                'nano-banana-2'       => 'NB2',
                'nano-banana-2 1K'    => 'NB2 1K',
                'nano-banana-2 2K'    => 'NB2 2K',
                'nano-banana-2 4K'    => 'NB2 4K',
                'nano-banana-pro'     => 'NB Pro',
                'nano-banana'         => 'NB Base',
                'flux'                => 'Flux',
                'imagen'              => 'Imagen',
            ];
            $badgeText = $badgeMap[$provider] ?? $provider;
            // Цвет бейджа по провайдеру
            if (str_contains($provider, 'flux') || str_contains($provider, 'Flux') || str_contains($provider, 'v1.1') || str_contains($provider, 'schnell') || str_contains($provider, 'dev')) {
                $badgeBg = 'rgba(29,78,216,.8)'; // синий — Flux
            } elseif (str_contains($provider, 'nano') || str_contains($provider, 'NB') || str_contains($provider, 'imagen')) {
                $badgeBg = 'rgba(161,68,17,.8)'; // оранжевый — NanoBanana
            } else {
                $badgeBg = 'rgba(0,0,0,.7)';
            }
        } elseif ($source === 'uploaded') {
            $badgeText = '⬆ файл';
            $badgeBg   = 'rgba(5,150,105,.8)'; // зелёный
        } elseif ($source === 'url') {
            $badgeText = '🔗 URL';
            $badgeBg   = 'rgba(100,100,100,.8)';
        } else {
            $badgeText = '🎨 AI';
            $badgeBg   = 'rgba(0,0,0,.7)';
        }
    ?>
    <div style="width:160px;flex-shrink:0;border:1px solid var(--border);border-radius:8px;overflow:hidden;font-size:11px;background:#fff">
        <div style="position:relative;background:#f8fafc;height:200px">
            <img src="<?php echo htmlspecialchars($h['image_url']); ?>"
                 style="width:100%;height:100%;object-fit:cover;display:block"
                 onerror="this.style.opacity='.15'">
            <div style="position:absolute;top:6px;left:6px;background:<?php echo $badgeBg; ?>;color:#fff;font-size:9px;font-weight:700;padding:3px 7px;border-radius:10px;white-space:nowrap;letter-spacing:.02em">
                <?php echo htmlspecialchars($badgeText); ?>
            </div>
        </div>
        <div style="padding:6px 8px;color:var(--muted);border-bottom:1px solid var(--border)">
            <?php echo date('d.m.Y H:i', strtotime($h['created_at'])); ?>
        </div>
        <div style="padding:8px;display:flex;gap:6px">
            <form method="post" action="/admin/meditations/api/image-history.php" style="flex:1">
                <input type="hidden" name="action"        value="rollback">
                <input type="hidden" name="meditation_id" value="<?php echo $id; ?>">
                <input type="hidden" name="history_id"    value="<?php echo $h['id']; ?>">
                <button type="submit" class="adm-btn adm-btn--primary adm-btn--sm" style="width:100%"
                        onclick="return confirm('Откатить к этой картинке?')">↺ Откат</button>
            </form>
            <form method="post" action="/admin/meditations/api/image-history.php">
                <input type="hidden" name="action"        value="delete">
                <input type="hidden" name="meditation_id" value="<?php echo $id; ?>">
                <input type="hidden" name="history_id"    value="<?php echo $h['id']; ?>">
                <button type="submit" class="adm-btn adm-btn--danger adm-btn--sm"
                        onclick="return confirm('Удалить из истории?<?php echo $isLocal ? ' Файл тоже удалится.' : ''; ?>')">✕</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
