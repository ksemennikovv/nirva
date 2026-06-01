<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/UserRepository.php';
require_once $root . '/src/repositories/AnalysisRepository.php';
require_once $root . '/src/repositories/ProfileParameterRepository.php';

$userId   = (int)($_GET['id'] ?? 0);
$userRepo = new UserRepository();
$user     = $userRepo->getWithDetails($userId);

if (!$user) {
    http_response_code(404);
    echo 'Пользователь не найден'; exit;
}

$db = Database::getConnection();

// ── Подписки ──────────────────────────────────────────────────────────────────
$subs = $db->prepare("SELECT * FROM subscriptions WHERE user_id = ? ORDER BY id DESC");
$subs->execute([$userId]);
$subs = $subs->fetchAll(PDO::FETCH_ASSOC);

// ── История платежей ──────────────────────────────────────────────────────────
$payments = $db->prepare("SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 20");
$payments->execute([$userId]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

// ── Разборы (полные данные) ───────────────────────────────────────────────────
$analysesStmt = $db->prepare(
    "SELECT * FROM analysis_sessions WHERE user_id = ? ORDER BY id DESC"
);
$analysesStmt->execute([$userId]);
$analyses = $analysesStmt->fetchAll(PDO::FETCH_ASSOC);

// Собрать ID разборов
$analysisIds = array_column($analyses, 'id');

// Стартовые сообщения (первое user-сообщение каждого разбора)
$firstMsgs = [];
if ($analysisIds) {
    $placeholders = implode(',', array_fill(0, count($analysisIds), '?'));
    $fmStmt = $db->prepare(
        "SELECT analysis_session_id, content FROM messages
         WHERE analysis_session_id IN ($placeholders) AND role='user' AND phase='analysis'
         GROUP BY analysis_session_id
         HAVING MIN(id)"
    );
    // Alternative: use MIN(id) approach
    $fmStmt2 = $db->prepare(
        "SELECT m.analysis_session_id, m.content
         FROM messages m
         INNER JOIN (
             SELECT analysis_session_id, MIN(id) as min_id
             FROM messages WHERE role='user' AND phase='analysis' AND analysis_session_id IN ($placeholders)
             GROUP BY analysis_session_id
         ) first ON m.id = first.min_id"
    );
    $fmStmt2->execute($analysisIds);
    foreach ($fmStmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $firstMsgs[$row['analysis_session_id']] = $row['content'];
    }
}

// Сообщения по разборам (фазы analysis + reflection)
$msgsByAnalysis = [];
if ($analysisIds) {
    $placeholders = implode(',', array_fill(0, count($analysisIds), '?'));
    $msgsStmt = $db->prepare(
        "SELECT analysis_session_id, id, role, content, phase,
                review_status, reviewed_content, created_at
         FROM messages
         WHERE analysis_session_id IN ($placeholders)
         ORDER BY analysis_session_id, id ASC"
    );
    $msgsStmt->execute($analysisIds);
    foreach ($msgsStmt->fetchAll(PDO::FETCH_ASSOC) as $msg) {
        $msgsByAnalysis[$msg['analysis_session_id']][] = $msg;
    }
}

// Медитации по разборам
$medsByAnalysis = [];
if ($analysisIds) {
    $placeholders = implode(',', array_fill(0, count($analysisIds), '?'));
    $medStmt = $db->prepare(
        "SELECT m.*,
                (SELECT COUNT(*) FROM meditation_listens ml WHERE ml.meditation_id = m.id AND ml.user_id = ?) as listen_count,
                (SELECT COUNT(*) FROM meditation_listens ml WHERE ml.meditation_id = m.id AND ml.user_id = ? AND ml.completed = 1) as listen_completed,
                (SELECT 1 FROM meditation_purchases mp WHERE mp.meditation_id = m.id AND mp.user_id = ?) as purchased
         FROM meditations m
         WHERE m.analysis_id IN ($placeholders)
         ORDER BY m.analysis_id, m.id"
    );
    $medStmt->execute(array_merge([$userId, $userId, $userId], $analysisIds));
    foreach ($medStmt->fetchAll(PDO::FETCH_ASSOC) as $med) {
        $medsByAnalysis[$med['analysis_id']][] = $med;
    }
}

// Обновления профиля из разборов (history)
$historyBySource = [];
$memoriesBySource = [];
try {
    $histStmt = $db->prepare(
        "SELECT pph.*, pp.code, pp.label
         FROM profile_parameter_history pph
         JOIN profile_parameters pp ON pp.id = pph.parameter_id
         WHERE pph.user_id = ?
         ORDER BY pph.created_at ASC"
    );
    $histStmt->execute([$userId]);
    foreach ($histStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        $key = $h['source_type'] . '_' . $h['source_id'];
        $historyBySource[$key][] = $h;
    }

    $memStmt = $db->prepare(
        "SELECT * FROM user_memories WHERE user_id = ? ORDER BY created_at ASC"
    );
    $memStmt->execute([$userId]);
    foreach ($memStmt->fetchAll(PDO::FETCH_ASSOC) as $mem) {
        $key = ($mem['source_type'] ?? 'none') . '_' . ($mem['source_id'] ?? '0');
        $memoriesBySource[$key][] = $mem;
    }
} catch (\PDOException $e) {}

// ── Психоэмоциональный портрет ────────────────────────────────────────────────
$profileRepo    = new ProfileParameterRepository();
$allParams      = $profileRepo->getAllParameters();
$profileValsRaw = $profileRepo->getUserValues($userId);
$allMemories    = $profileRepo->getTopMemories($userId, 50);

// Группируем параметры по категории
$paramsByCategory = [];
foreach ($allParams as $p) {
    $paramsByCategory[$p['category']][] = $p;
}

// История параметров (все)
try {
    $fullHistory = $profileRepo->getHistory($userId, 100);
} catch (\PDOException $e) {
    $fullHistory = [];
}

// ── Дашборд: hero state ───────────────────────────────────────────────────────
$subscription = $user['subscription'] ?? null;
$lastCompleted = null;
$stmtLC = $db->prepare(
    "SELECT * FROM analysis_sessions
     WHERE user_id = ? AND status = 'completed'
     ORDER BY completed_at DESC LIMIT 1"
);
$stmtLC->execute([$userId]);
$lastCompleted = $stmtLC->fetch(PDO::FETCH_ASSOC);

$nextAnalysisDate = null;
$heroState = 4;
if ($subscription && $subscription['status'] === 'active') {
    $used  = (int)($subscription['analyses_used'] ?? 0);
    $limit = (int)($subscription['analyses_per_month'] ?? 1);
    if ($used < $limit) {
        $heroState = 1;
    } else {
        $heroState = 3;
    }
    if ($lastCompleted && BusinessConfig::analysisMinIntervalDays() > 0) {
        $nextTs = strtotime($lastCompleted['completed_at']) + BusinessConfig::analysisMinIntervalDays() * 86400;
        if ($nextTs > time()) {
            $heroState = 2;
            $nextAnalysisDate = date('d.m.Y H:i', $nextTs);
        }
    }
} else {
    // Нет подписки — первый разбор бесплатно
    $totalAnalyses = count($analyses);
    if ($totalAnalyses === 0) $heroState = 1;
}

// Чипсы тем
$suggestedTopics = [];
$topParams = $profileRepo->getTopParameters($userId, 6);
$topMems   = $profileRepo->getTopMemories($userId, 5);
foreach ($topParams as $p) {
    $vals = json_decode($p['value'] ?? '', true);
    if (is_array($vals)) {
        foreach ($vals as $v) {
            if (is_array($v) && isset($v['value'])) $suggestedTopics[] = ['text' => $v['value'], 'source' => 'param:' . $p['name'], 'confidence' => $v['confidence'] ?? null];
            elseif (is_string($v) && $v !== '') $suggestedTopics[] = ['text' => $v, 'source' => 'param:' . $p['name'], 'confidence' => null];
        }
    }
}
foreach ($topMems as $m) {
    if (count($suggestedTopics) >= 8) break;
    if (mb_strlen($m['content']) < 80) {
        $suggestedTopics[] = ['text' => $m['content'], 'source' => 'memory', 'confidence' => null, 'importance' => $m['importance_score']];
    }
}
$suggestedTopics = array_slice($suggestedTopics, 0, 6);

// ── Дневник ───────────────────────────────────────────────────────────────────
$diaryStmt = $db->prepare(
    "SELECT de.*, COUNT(dm.id) as msg_count
     FROM diary_entries de
     LEFT JOIN diary_messages dm ON dm.diary_entry_id = de.id
     WHERE de.user_id = ?
     GROUP BY de.id
     ORDER BY de.id DESC"
);
$diaryStmt->execute([$userId]);
$diaryEntries = $diaryStmt->fetchAll(PDO::FETCH_ASSOC);

// Сообщения дневника
$diaryMsgsByEntry = [];
if ($diaryEntries) {
    $dIds = array_column($diaryEntries, 'id');
    $dPlaceholders = implode(',', array_fill(0, count($dIds), '?'));
    $dmStmt = $db->prepare(
        "SELECT * FROM diary_messages WHERE diary_entry_id IN ($dPlaceholders) ORDER BY diary_entry_id, id ASC"
    );
    $dmStmt->execute($dIds);
    foreach ($dmStmt->fetchAll(PDO::FETCH_ASSOC) as $dm) {
        $diaryMsgsByEntry[$dm['diary_entry_id']][] = $dm;
    }
}

// ── Практики из таблицы practices ────────────────────────────────────────────
$practicesMap = [];
try {
    $prStmt = $db->query("SELECT slug, title FROM practices");
    foreach ($prStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
        $practicesMap[$pr['slug']] = $pr['title'];
    }
} catch (\PDOException $e) {}

// ── Промты (содержимое файлов) ────────────────────────────────────────────────
function readPromptFile(string $root, string $name): string {
    $path = $root . '/prompts/' . $name;
    return file_exists($path) ? file_get_contents($path) : '[файл не найден: ' . $name . ']';
}

// ── ElevenLabs конфиг ─────────────────────────────────────────────────────────
$elevenVoiceId = defined('MEDITATION_AUDIO_VOICE_ID') ? MEDITATION_AUDIO_VOICE_ID : (getenv('MEDITATION_AUDIO_VOICE_ID') ?: '—');
$elevenModel   = defined('MEDITATION_AUDIO_MODEL')    ? MEDITATION_AUDIO_MODEL    : (getenv('MEDITATION_AUDIO_MODEL') ?: '—');
$elevenApiUrl  = defined('MEDITATION_AUDIO_API_URL')  ? MEDITATION_AUDIO_API_URL  : (getenv('MEDITATION_AUDIO_API_URL') ?: '—');

$flash = $_GET['saved'] ?? '';

$pageTitle = 'Пользователь: ' . $user['email'];
$activeNav = 'users';
require dirname(__DIR__) . '/_layout.php';
?>

<?php if ($flash === '1'): ?><div class="adm-alert adm-alert--success">Изменения сохранены.</div><?php endif; ?>

<!-- ══ ШАПКА ══════════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">

    <div class="adm-card">
        <div class="adm-card__head"><div class="adm-card__title">Основное</div></div>
        <div style="padding:20px">
            <form class="adm-form" method="post" action="/admin/users/api/update.php">
                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                <div class="adm-field">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>">
                </div>
                <div class="adm-field">
                    <label>Роль</label>
                    <select name="role">
                        <option value="user"  <?php echo $user['role'] === 'user'  ? 'selected' : ''; ?>>Пользователь</option>
                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Администратор</option>
                    </select>
                </div>
                <div style="display:flex;gap:10px;align-items:center">
                    <button type="submit" class="adm-btn adm-btn--primary">Сохранить</button>
                    <span style="color:var(--muted);font-size:12px">ID: <?php echo $user['id']; ?> · Зарегистрирован: <?php echo date('d.m.Y', strtotime($user['created_at'])); ?></span>
                </div>
            </form>
        </div>
        <div style="padding:0 20px 16px;display:flex;gap:16px;color:var(--muted);font-size:13px">
            <span>📋 Разборов: <b><?php echo $user['analyses_count']; ?></b></span>
            <span>📔 Дневник: <b><?php echo $user['diary_count']; ?></b></span>
            <span>🎧 Медитаций: <b><?php echo $user['meditations_count']; ?></b></span>
        </div>
    </div>

    <!-- Подписка -->
    <div class="adm-card">
        <div class="adm-card__head"><div class="adm-card__title">Подписка</div></div>
        <div style="padding:20px">
            <?php if ($user['subscription']): $s = $user['subscription']; ?>
            <div style="margin-bottom:16px;padding:12px;background:var(--bg);border-radius:6px;font-size:13px">
                <div style="margin-bottom:6px">
                    <span class="adm-badge adm-badge--<?php echo $s['plan']; ?>"><?php echo $s['plan']; ?></span>
                    <span class="adm-badge adm-badge--<?php echo $s['status']; ?>" style="margin-left:6px"><?php echo $s['status']; ?></span>
                </div>
                <div style="color:var(--muted)">
                    Разборов: <?php echo $s['analyses_used']; ?> / <?php echo $s['analyses_per_month']; ?> &nbsp;·&nbsp;
                    До: <?php echo date('d.m.Y', strtotime($s['expires_at'])); ?>
                </div>
            </div>
            <form class="adm-form" method="post" action="/admin/subscriptions/api/update.php">
                <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                <input type="hidden" name="redirect" value="/admin/users/view.php?id=<?php echo $userId; ?>&saved=1">
                <div class="adm-form-row">
                    <div class="adm-field">
                        <label>Тариф</label>
                        <select name="plan">
                            <?php foreach (['start','basic','transformation'] as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo $s['plan'] === $p ? 'selected' : ''; ?>><?php echo ucfirst($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="adm-field">
                        <label>Статус</label>
                        <select name="status">
                            <option value="active"    <?php echo $s['status'] === 'active'    ? 'selected' : ''; ?>>Активна</option>
                            <option value="cancelled" <?php echo $s['status'] === 'cancelled' ? 'selected' : ''; ?>>Отменена</option>
                            <option value="expired"   <?php echo $s['status'] === 'expired'   ? 'selected' : ''; ?>>Истекла</option>
                        </select>
                    </div>
                </div>
                <div class="adm-form-row">
                    <div class="adm-field">
                        <label>Разборов в месяц</label>
                        <input type="number" name="analyses_per_month" value="<?php echo $s['analyses_per_month']; ?>" min="1" max="30">
                    </div>
                    <div class="adm-field">
                        <label>Использовано</label>
                        <input type="number" name="analyses_used" value="<?php echo $s['analyses_used']; ?>" min="0">
                    </div>
                </div>
                <div class="adm-field">
                    <label>Действует до</label>
                    <input type="date" name="expires_at" value="<?php echo date('Y-m-d', strtotime($s['expires_at'])); ?>">
                </div>
                <button type="submit" class="adm-btn adm-btn--primary">Обновить подписку</button>
            </form>
            <?php else: ?>
            <p style="color:var(--muted);margin-bottom:16px">Нет активной подписки</p>
            <?php endif; ?>
            <hr style="margin:16px 0;border:none;border-top:1px solid var(--border)">
            <div style="font-size:13px;font-weight:600;margin-bottom:12px;color:var(--muted)">НАЗНАЧИТЬ НОВУЮ ПОДПИСКУ</div>
            <form class="adm-form" method="post" action="/admin/subscriptions/api/create.php">
                <input type="hidden" name="user_id" value="<?php echo $userId; ?>">
                <input type="hidden" name="redirect" value="/admin/users/view.php?id=<?php echo $userId; ?>&saved=1">
                <div class="adm-form-row">
                    <div class="adm-field">
                        <label>Тариф</label>
                        <select name="plan">
                            <option value="start">Start</option>
                            <option value="basic">Basic</option>
                            <option value="transformation">Transformation</option>
                        </select>
                    </div>
                    <div class="adm-field">
                        <label>Разборов в месяц</label>
                        <input type="number" name="analyses_per_month" value="1" min="1" max="30">
                    </div>
                </div>
                <div class="adm-form-row">
                    <div class="adm-field">
                        <label>Начало</label>
                        <input type="date" name="starts_at" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="adm-field">
                        <label>Конец</label>
                        <input type="date" name="expires_at" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                    </div>
                </div>
                <button type="submit" class="adm-btn adm-btn--primary">Назначить</button>
            </form>
        </div>
    </div>
</div>

<!-- ══ ГЛАВНЫЙ ЭКРАН: ТЕКУЩЕЕ СОСТОЯНИЕ ══════════════════════════════════════ -->
<details open style="margin-bottom:20px">
<summary class="adm-section-toggle">📱 Главный экран пользователя (текущее состояние)</summary>
<div class="adm-card" style="margin-top:8px">
    <div style="padding:16px 20px">

        <?php
        $heroLabels = [1 => '🟢 Готов к разбору', 2 => '🌿 Режим отдыха', 3 => '🔴 Лимит исчерпан', 4 => '⚪ Нет подписки'];
        ?>
        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:16px;font-size:13px">
            <div>
                <span style="color:var(--muted)">Hero state:</span>
                <strong style="margin-left:6px"><?php echo $heroLabels[$heroState] ?? $heroState; ?></strong>
            </div>
            <?php if ($subscription): ?>
            <div>
                <span style="color:var(--muted)">Разборов:</span>
                <strong style="margin-left:6px"><?php echo $subscription['analyses_used']; ?> / <?php echo $subscription['analyses_per_month']; ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($nextAnalysisDate): ?>
            <div>
                <span style="color:var(--muted)">Следующий разбор:</span>
                <strong style="margin-left:6px"><?php echo $nextAnalysisDate; ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($lastCompleted): ?>
            <div>
                <span style="color:var(--muted)">Последний завершён:</span>
                <strong style="margin-left:6px"><?php echo date('d.m.Y H:i', strtotime($lastCompleted['completed_at'])); ?></strong>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($suggestedTopics)): ?>
        <div style="margin-bottom:8px;font-size:12px;font-weight:600;color:var(--muted)">ЧИПСЫ ТЕМ (откуда взяты):</div>
        <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($suggestedTopics as $chip): ?>
            <div style="display:flex;gap:10px;align-items:flex-start;font-size:13px">
                <span style="background:var(--bg);border:1px solid var(--border);border-radius:16px;padding:3px 10px;white-space:nowrap"><?php echo htmlspecialchars($chip['text']); ?></span>
                <span style="color:var(--muted);font-size:11px;padding-top:4px">
                    источник: <?php echo htmlspecialchars($chip['source']); ?>
                    <?php if (!empty($chip['confidence'])): ?> · уверенность: <?php echo round($chip['confidence'] * 100); ?>%<?php endif; ?>
                    <?php if (!empty($chip['importance'])): ?> · важность: <?php echo $chip['importance']; ?>/10<?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="color:var(--muted);font-size:13px">Чипсы не сформированы — нет параметров профиля</p>
        <?php endif; ?>
    </div>
</div>
</details>

<!-- ══ ПСИХОЭМОЦИОНАЛЬНЫЙ ПОРТРЕТ ════════════════════════════════════════════ -->
<details open style="margin-bottom:20px">
<summary class="adm-section-toggle">🧠 Психоэмоциональный портрет</summary>
<div class="adm-card" style="margin-top:8px">
    <div style="padding:16px 20px">

    <?php
    $categoryLabels = [
        'defense'      => 'Защиты и копинги',
        'fear'         => 'Страхи и триггеры',
        'body'         => 'Тело',
        'attachment'   => 'Привязанность',
        'emotion'      => 'Эмоции',
        'behavior'     => 'Поведение',
        'relationship' => 'Отношения',
        'trauma'       => 'Травма',
        'identity'     => 'Идентичность',
    ];
    $hasAnyProfile = false;
    foreach ($paramsByCategory as $cat => $params):
        $catValues = [];
        foreach ($params as $p) {
            $vals = $profileValsRaw[$p['code']] ?? [];
            if ($vals) $catValues[] = ['param' => $p, 'values' => $vals];
        }
        if (empty($catValues)) continue;
        $hasAnyProfile = true;
    ?>
        <div style="margin-bottom:20px">
            <div style="font-size:12px;font-weight:700;color:var(--muted);letter-spacing:.05em;margin-bottom:8px;text-transform:uppercase"><?php echo htmlspecialchars($categoryLabels[$cat] ?? $cat); ?></div>
            <?php foreach ($catValues as $cv): ?>
            <div style="margin-bottom:10px">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px"><?php echo htmlspecialchars($cv['param']['label']); ?></div>
                <div style="display:flex;flex-direction:column;gap:3px">
                <?php foreach ($cv['values'] as $v):
                    if (!is_array($v)) { $v = ['value' => $v]; }
                    $conf = isset($v['confidence']) ? round($v['confidence'] * 100) : null;
                    $ev   = $v['evidence_count'] ?? null;
                    $upd  = isset($v['updated_at']) ? date('d.m.Y', strtotime($v['updated_at'])) : null;
                ?>
                    <div style="display:flex;gap:10px;align-items:center;font-size:12px;padding:3px 8px;background:var(--bg);border-radius:4px">
                        <span style="flex:1"><?php echo htmlspecialchars((string)($v['value'] ?? '—')); ?></span>
                        <?php if ($conf !== null): ?><span style="color:<?php echo $conf >= 70 ? '#059669' : ($conf >= 40 ? '#d97706' : 'var(--muted)'); ?>">уверенность: <?php echo $conf; ?>%</span><?php endif; ?>
                        <?php if ($ev !== null): ?><span style="color:var(--muted)">упоминаний: <?php echo $ev; ?></span><?php endif; ?>
                        <?php if ($upd): ?><span style="color:var(--muted)"><?php echo $upd; ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$hasAnyProfile): ?>
        <p style="color:var(--muted)">Профиль ещё не сформирован</p>
    <?php endif; ?>

    <!-- Воспоминания AI -->
    <?php if (!empty($allMemories)): ?>
    <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:16px">
        <div style="font-size:12px;font-weight:700;color:var(--muted);letter-spacing:.05em;margin-bottom:10px;text-transform:uppercase">Наблюдения AI (user_memories)</div>
        <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($allMemories as $mem): ?>
            <div style="font-size:12px;padding:6px 10px;background:var(--bg);border-radius:4px;display:flex;gap:12px">
                <span style="flex:1;line-height:1.5"><?php echo htmlspecialchars($mem['content']); ?></span>
                <span style="color:var(--muted);white-space:nowrap">важность: <?php echo $mem['importance_score']; ?>/10</span>
                <span style="color:var(--muted);white-space:nowrap"><?php echo $mem['source_type'] ?? '—'; ?></span>
                <span style="color:var(--muted);white-space:nowrap"><?php echo date('d.m.Y', strtotime($mem['created_at'])); ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- История изменений профиля -->
    <?php if (!empty($fullHistory)): ?>
    <details style="margin-top:16px">
        <summary style="font-size:12px;font-weight:700;color:var(--muted);cursor:pointer;text-transform:uppercase;letter-spacing:.05em">История изменений профиля (<?php echo count($fullHistory); ?>)</summary>
        <div style="margin-top:8px;display:flex;flex-direction:column;gap:4px">
        <?php foreach ($fullHistory as $h): ?>
            <div style="font-size:11px;padding:4px 8px;background:var(--bg);border-radius:3px;display:flex;gap:10px">
                <span style="color:var(--muted);white-space:nowrap"><?php echo date('d.m.Y H:i', strtotime($h['created_at'])); ?></span>
                <span class="adm-badge adm-badge--<?php echo $h['event_type'] === 'added' ? 'active' : ($h['event_type'] === 'removed' ? 'cancelled' : 'pending'); ?>" style="font-size:10px;padding:1px 5px"><?php echo $h['event_type']; ?></span>
                <span style="font-weight:600"><?php echo htmlspecialchars($h['label']); ?></span>
                <span style="color:var(--muted)"><?php echo htmlspecialchars($h['source_type'] . ' #' . $h['source_id']); ?></span>
                <span style="color:var(--muted);flex:1;font-size:10px"><?php $ed = json_decode($h['event_data'], true); echo htmlspecialchars(is_array($ed) ? json_encode($ed, JSON_UNESCAPED_UNICODE) : $h['event_data']); ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    </div>
</div>
</details>

<!-- ══ РАЗБОРЫ ════════════════════════════════════════════════════════════════ -->
<details open style="margin-bottom:20px">
<summary class="adm-section-toggle">📋 Разборы (<?php echo count($analyses); ?>)</summary>

<?php foreach ($analyses as $a):
    $aid = $a['id'];
    $statusMap = ['created'=>'pending','active'=>'generating','completed'=>'active','abandoned'=>'cancelled','analysis_completed'=>'generating','practice_completed'=>'pending','reflection_in_progress'=>'generating'];
    $sCls = 'adm-badge--' . ($statusMap[$a['status']] ?? 'none');
    $analysisMsgs    = $msgsByAnalysis[$aid] ?? [];
    $analysisPhase   = array_values(array_filter($analysisMsgs, fn($m) => $m['phase'] === 'analysis'));
    $reflectionPhase = array_values(array_filter($analysisMsgs, fn($m) => $m['phase'] === 'reflection'));
    $meds = $medsByAnalysis[$aid] ?? [];
    $practiceTitle = $a['selected_practice'] ? ($practicesMap[$a['selected_practice']] ?? $a['selected_practice']) : '—';
    $histKey  = 'analysis_' . $aid;
    $memKey   = 'analysis_' . $aid;
    $memKey2  = 'reflection_' . $aid;
    $histItems = $historyBySource[$histKey] ?? [];
    $memItems  = array_merge($memoriesBySource[$memKey] ?? [], $memoriesBySource[$memKey2] ?? []);
?>
<div class="adm-card" style="margin-top:8px">
<details>
<summary style="padding:14px 20px;cursor:pointer;display:flex;gap:12px;align-items:center;list-style:none;font-size:14px">
    <span style="color:var(--muted);font-size:12px">#<?php echo $aid; ?></span>
    <span class="adm-badge <?php echo $sCls; ?>"><?php echo $a['status']; ?></span>
    <span style="flex:1;font-weight:600"><?php echo htmlspecialchars($a['topic'] ?: '(тема не задана)'); ?></span>
    <span style="color:var(--muted);font-size:12px"><?php echo date('d.m.Y', strtotime($a['created_at'])); ?></span>
    <a href="/admin/analyses/view.php?id=<?php echo $aid; ?>" class="adm-btn adm-btn--ghost adm-btn--sm" onclick="event.stopPropagation()">Открыть →</a>
</summary>
<div style="padding:0 20px 20px">

    <!-- Мета -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;font-size:12px">
        <div style="background:var(--bg);border-radius:6px;padding:10px">
            <div style="font-weight:700;margin-bottom:8px;color:var(--muted);font-size:11px;text-transform:uppercase">Параметры разбора</div>
            <div style="display:flex;flex-direction:column;gap:5px">
                <?php
                $metaRows = [
                    'Стартовая тема'  => htmlspecialchars($firstMsgs[$aid] ?? '—'),
                    'Финальная тема'  => htmlspecialchars($a['topic'] ?? '—'),
                    'Практика'        => htmlspecialchars($practiceTitle),
                    'Задание'         => htmlspecialchars($a['personal_task'] ?? '—'),
                    'Стадия диалога'  => htmlspecialchars($a['dialogue_stage'] ?? '—'),
                    'Риск'            => htmlspecialchars($a['risk_level'] ?? '—'),
                    'Сообщений'       => ($a['total_messages_count'] ?? count($analysisMsgs)),
                    'Создан'          => date('d.m.Y H:i', strtotime($a['created_at'])),
                    'Завершён'        => $a['completed_at'] ? date('d.m.Y H:i', strtotime($a['completed_at'])) : '—',
                ];
                foreach ($metaRows as $k => $v):
                ?>
                <div style="display:flex;gap:8px">
                    <span style="color:var(--muted);width:130px;flex-shrink:0"><?php echo $k; ?></span>
                    <span><?php echo $v; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="background:var(--bg);border-radius:6px;padding:10px">
            <div style="font-weight:700;margin-bottom:8px;color:var(--muted);font-size:11px;text-transform:uppercase">Итоги разбора</div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <?php if ($a['analysis_summary']): ?>
                <div>
                    <div style="color:var(--muted);font-size:11px;margin-bottom:2px">Итог анализа</div>
                    <div style="line-height:1.5"><?php echo nl2br(htmlspecialchars($a['analysis_summary'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($a['reflection_summary']): ?>
                <div>
                    <div style="color:var(--muted);font-size:11px;margin-bottom:2px">Итог рефлексии</div>
                    <div style="line-height:1.5"><?php echo nl2br(htmlspecialchars($a['reflection_summary'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($a['final_recommendations']): ?>
                <div>
                    <div style="color:var(--muted);font-size:11px;margin-bottom:2px">Финальные рекомендации</div>
                    <div style="line-height:1.5"><?php echo nl2br(htmlspecialchars($a['final_recommendations'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($a['dialogue_summary']): ?>
                <details>
                    <summary style="font-size:11px;color:var(--muted);cursor:pointer">dialogue_summary (JSON)</summary>
                    <pre style="font-size:10px;background:#f1f5f9;padding:6px;border-radius:4px;overflow:auto;max-height:200px;margin-top:4px"><?php echo htmlspecialchars(json_encode(json_decode($a['dialogue_summary']), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); ?></pre>
                </details>
                <?php endif; ?>
                <?php if ($a['pending_metadata']): ?>
                <div>
                    <div style="color:#d97706;font-size:11px;margin-bottom:2px">⏳ pending_metadata (supervisor)</div>
                    <pre style="font-size:10px;background:#fef3c7;padding:6px;border-radius:4px;overflow:auto;max-height:150px"><?php echo htmlspecialchars($a['pending_metadata']); ?></pre>
                </div>
                <?php endif; ?>
                <?php if (!$a['analysis_summary'] && !$a['reflection_summary']): ?>
                <span style="color:var(--muted)">Итоги ещё не сформированы</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Промт разбора -->
    <details style="margin-bottom:12px">
        <summary style="font-size:12px;cursor:pointer;padding:6px 0;color:var(--muted)">📄 Промт разбора (analysis-prompt.txt)</summary>
        <pre style="font-size:10px;background:#f8fafc;border:1px solid var(--border);padding:10px;border-radius:4px;overflow:auto;max-height:300px;white-space:pre-wrap;margin-top:6px"><?php echo htmlspecialchars(readPromptFile($root, 'analysis-prompt.txt')); ?></pre>
    </details>

    <!-- Диалог (фаза анализа) -->
    <?php if (!empty($analysisPhase)): ?>
    <details style="margin-bottom:12px">
        <summary style="font-size:12px;cursor:pointer;padding:6px 0;font-weight:600">💬 Диалог разбора (<?php echo count($analysisPhase); ?> сообщений)</summary>
        <div class="adm-chat-log" style="margin-top:6px">
        <?php foreach ($analysisPhase as $m):
            $isUser = $m['role'] === 'user';
            $text   = ($m['reviewed_content'] ?? null) ?: $m['content'];
        ?>
            <div class="adm-chat-msg adm-chat-msg--<?php echo $isUser ? 'user' : 'ai'; ?>">
                <div class="adm-chat-msg__meta">
                    <span class="adm-chat-msg__role"><?php echo $isUser ? 'Пользователь' : 'AI'; ?></span>
                    <?php if (($m['review_status'] ?? '') === 'rejected'): ?><span class="adm-badge adm-badge--cancelled" style="font-size:10px">REJECTED</span><?php endif; ?>
                    <?php if (($m['review_status'] ?? '') === 'pending_review'): ?><span class="adm-badge adm-badge--pending" style="font-size:10px">PENDING</span><?php endif; ?>
                    <span style="color:var(--muted);font-size:11px"><?php echo date('H:i', strtotime($m['created_at'])); ?></span>
                </div>
                <div class="adm-chat-msg__text"><?php echo nl2br(htmlspecialchars($text)); ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <!-- Самоисследование (рефлексия) -->
    <?php if (!empty($reflectionPhase)): ?>
    <details style="margin-bottom:12px">
        <summary style="font-size:12px;cursor:pointer;padding:6px 0;font-weight:600">🪞 Самоисследование / Рефлексия (<?php echo count($reflectionPhase); ?> сообщений)</summary>
        <details style="margin:6px 0 8px">
            <summary style="font-size:11px;cursor:pointer;color:var(--muted);padding:4px 0">📄 Промт рефлексии (reflection-prompt.txt)</summary>
            <pre style="font-size:10px;background:#f8fafc;border:1px solid var(--border);padding:10px;border-radius:4px;overflow:auto;max-height:250px;white-space:pre-wrap"><?php echo htmlspecialchars(readPromptFile($root, 'reflection-prompt.txt')); ?></pre>
        </details>
        <div class="adm-chat-log">
        <?php foreach ($reflectionPhase as $m):
            $isUser = $m['role'] === 'user';
            $text   = ($m['reviewed_content'] ?? null) ?: $m['content'];
        ?>
            <div class="adm-chat-msg adm-chat-msg--<?php echo $isUser ? 'user' : 'ai'; ?>">
                <div class="adm-chat-msg__meta">
                    <span class="adm-chat-msg__role"><?php echo $isUser ? 'Пользователь' : 'AI'; ?></span>
                    <span style="color:var(--muted);font-size:11px"><?php echo date('H:i', strtotime($m['created_at'])); ?></span>
                </div>
                <div class="adm-chat-msg__text"><?php echo nl2br(htmlspecialchars($text)); ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <!-- Обновления профиля из разбора -->
    <?php if (!empty($histItems) || !empty($memItems)): ?>
    <details style="margin-bottom:12px">
        <summary style="font-size:12px;cursor:pointer;padding:6px 0;color:#059669;font-weight:600">🧬 Что внесено в профиль из этого разбора</summary>
        <div style="margin-top:6px;display:flex;flex-direction:column;gap:4px">
        <?php foreach ($histItems as $h): ?>
            <div style="font-size:11px;padding:4px 8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:3px;display:flex;gap:8px">
                <span class="adm-badge adm-badge--<?php echo $h['event_type'] === 'added' ? 'active' : 'pending'; ?>" style="font-size:10px"><?php echo $h['event_type']; ?></span>
                <span style="font-weight:600"><?php echo htmlspecialchars($h['label']); ?></span>
                <span style="color:var(--muted);flex:1"><?php $ed = json_decode($h['event_data'], true); echo htmlspecialchars(is_array($ed) ? json_encode($ed, JSON_UNESCAPED_UNICODE) : $h['event_data']); ?></span>
                <span style="color:var(--muted)"><?php echo date('d.m H:i', strtotime($h['created_at'])); ?></span>
            </div>
        <?php endforeach; ?>
        <?php foreach ($memItems as $mem): ?>
            <div style="font-size:11px;padding:4px 8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:3px;display:flex;gap:8px">
                <span class="adm-badge adm-badge--none" style="font-size:10px">memory</span>
                <span style="flex:1"><?php echo htmlspecialchars($mem['content']); ?></span>
                <span style="color:var(--muted)">важность: <?php echo $mem['importance_score']; ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <!-- Медитации разбора -->
    <?php if (!empty($meds)): ?>
    <details style="margin-bottom:4px">
        <summary style="font-size:12px;cursor:pointer;padding:6px 0;font-weight:600">🎧 Медитации этого разбора (<?php echo count($meds); ?>)</summary>
        <?php foreach ($meds as $med): ?>
        <div style="background:var(--bg);border-radius:6px;padding:12px;margin-top:8px;font-size:12px">
            <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
                <span style="font-weight:600;font-size:13px"><?php echo htmlspecialchars($med['title'] ?? '(без названия)'); ?></span>
                <?php $msCls = ['ready'=>'active','pending'=>'pending','failed'=>'cancelled','generating'=>'generating'][$med['generation_status']] ?? 'none'; ?>
                <span class="adm-badge adm-badge--<?php echo $msCls; ?>"><?php echo $med['generation_status']; ?></span>
                <?php if ($med['purchased']): ?><span class="adm-badge adm-badge--active">куплено</span><?php endif; ?>
                <?php if ($med['listen_count'] > 0): ?><span style="color:var(--muted)">прослушиваний: <?php echo $med['listen_count']; ?> (завершено: <?php echo $med['listen_completed']; ?>)</span><?php endif; ?>
            </div>
            <?php if ($med['description']): ?><div style="color:var(--muted);margin-bottom:8px"><?php echo htmlspecialchars($med['description']); ?></div><?php endif; ?>

            <!-- ElevenLabs параметры -->
            <div style="background:#fff;border:1px solid var(--border);border-radius:4px;padding:8px;margin-bottom:8px;font-size:11px">
                <div style="font-weight:700;color:var(--muted);margin-bottom:4px">ElevenLabs TTS</div>
                <div style="display:flex;flex-wrap:wrap;gap:10px">
                    <span>Voice ID: <code><?php echo htmlspecialchars($elevenVoiceId); ?></code></span>
                    <span>Model: <code><?php echo htmlspecialchars($elevenModel); ?></code></span>
                    <span>Provider: <code><?php echo htmlspecialchars($med['generation_provider'] ?? '—'); ?></code></span>
                    <span>Job ID: <code><?php echo htmlspecialchars($med['generation_job_id'] ?? '—'); ?></code></span>
                    <?php if ($med['full_audio_url']): ?><span>Audio: <a href="<?php echo htmlspecialchars($med['full_audio_url']); ?>" target="_blank" style="color:var(--accent)">🎧 <?php echo htmlspecialchars($med['full_audio_url']); ?></a></span><?php endif; ?>
                </div>
            </div>

            <!-- Текст медитации -->
            <?php if ($med['personal_context']): ?>
            <details>
                <summary style="font-size:11px;cursor:pointer;color:var(--muted)">📝 Текст медитации</summary>
                <div style="margin-top:4px;padding:8px;background:#fff;border:1px solid var(--border);border-radius:4px;line-height:1.6;white-space:pre-wrap"><?php echo htmlspecialchars($med['personal_context']); ?></div>
            </details>
            <?php endif; ?>

            <!-- Промт генерации -->
            <details style="margin-top:4px">
                <summary style="font-size:11px;cursor:pointer;color:var(--muted)">📄 Промт генерации медитации</summary>
                <pre style="font-size:10px;background:#f8fafc;border:1px solid var(--border);padding:8px;border-radius:4px;overflow:auto;max-height:200px;white-space:pre-wrap;margin-top:4px"><?php echo htmlspecialchars(readPromptFile($root, 'meditation-generation-prompt.txt')); ?></pre>
            </details>

            <?php if ($med['expires_at']): ?>
            <div style="margin-top:6px;color:var(--muted);font-size:11px">Доступна до: <?php echo date('d.m.Y', strtotime($med['expires_at'])); ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </details>
    <?php else: ?>
    <div style="font-size:12px;color:var(--muted);padding:4px 0">Медитаций по этому разбору нет</div>
    <?php endif; ?>

</div>
</details>
</div>
<?php endforeach; ?>

<?php if (empty($analyses)): ?>
<div class="adm-card" style="margin-top:8px;padding:20px;text-align:center;color:var(--muted)">Разборов нет</div>
<?php endif; ?>
</details>

<!-- ══ ДНЕВНИК ════════════════════════════════════════════════════════════════ -->
<details style="margin-bottom:20px">
<summary class="adm-section-toggle">📔 Дневник (<?php echo count($diaryEntries); ?> записей)</summary>

<?php foreach ($diaryEntries as $de):
    $deId = $de['id'];
    $dMsgs = $diaryMsgsByEntry[$deId] ?? [];
    // Определяем режим по первому сообщению AI (если содержит ключевые слова)
    $mode = '—';
    $dhKey = 'diary_' . $deId;
    $dhItems  = $historyBySource[$dhKey] ?? [];
    $dmItems  = $memoriesBySource[$dhKey] ?? [];
?>
<div class="adm-card" style="margin-top:8px">
<details>
<summary style="padding:14px 20px;cursor:pointer;display:flex;gap:12px;align-items:center;list-style:none;font-size:14px">
    <span style="color:var(--muted);font-size:12px">#<?php echo $deId; ?></span>
    <span style="flex:1;color:var(--muted)"><?php echo htmlspecialchars(mb_substr($de['summary'] ?: '(нет саммари)', 0, 80)); ?></span>
    <span style="color:var(--muted);font-size:12px"><?php echo date('d.m.Y H:i', strtotime($de['created_at'])); ?></span>
    <span style="color:var(--muted);font-size:12px"><?php echo $de['msg_count']; ?> сообщ.</span>
    <a href="/admin/diary/view.php?id=<?php echo $deId; ?>" class="adm-btn adm-btn--ghost adm-btn--sm" onclick="event.stopPropagation()">Открыть →</a>
</summary>
<div style="padding:0 20px 20px">

    <!-- Саммари -->
    <?php if ($de['summary']): ?>
    <div style="background:var(--bg);border-radius:6px;padding:10px;margin-bottom:12px;font-size:13px;line-height:1.5">
        <div style="font-size:11px;font-weight:700;color:var(--muted);margin-bottom:4px;text-transform:uppercase">AI Саммари записи</div>
        <?php echo nl2br(htmlspecialchars($de['summary'])); ?>
    </div>
    <?php endif; ?>

    <!-- Промты дневника -->
    <details style="margin-bottom:10px">
        <summary style="font-size:12px;cursor:pointer;color:var(--muted)">📄 Промты дневника</summary>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:6px">
            <details>
                <summary style="font-size:11px;cursor:pointer;color:var(--muted)">Проработка (diary-prompt-vent.txt)</summary>
                <pre style="font-size:10px;background:#f8fafc;border:1px solid var(--border);padding:8px;border-radius:4px;overflow:auto;max-height:200px;white-space:pre-wrap;margin-top:4px"><?php echo htmlspecialchars(readPromptFile($root, 'diary-prompt-vent.txt')); ?></pre>
            </details>
            <details>
                <summary style="font-size:11px;cursor:pointer;color:var(--muted)">Рефлексия (diary-prompt-reflection.txt)</summary>
                <pre style="font-size:10px;background:#f8fafc;border:1px solid var(--border);padding:8px;border-radius:4px;overflow:auto;max-height:200px;white-space:pre-wrap;margin-top:4px"><?php echo htmlspecialchars(readPromptFile($root, 'diary-prompt-reflection.txt')); ?></pre>
            </details>
        </div>
    </details>

    <!-- Диалог -->
    <?php if (!empty($dMsgs)): ?>
    <details style="margin-bottom:10px">
        <summary style="font-size:12px;cursor:pointer;font-weight:600">💬 Диалог (<?php echo count($dMsgs); ?> сообщений)</summary>
        <div class="adm-chat-log" style="margin-top:6px">
        <?php foreach ($dMsgs as $dm):
            $isUser = $dm['role'] === 'user';
        ?>
            <div class="adm-chat-msg adm-chat-msg--<?php echo $isUser ? 'user' : 'ai'; ?>">
                <div class="adm-chat-msg__meta">
                    <span class="adm-chat-msg__role"><?php echo $isUser ? 'Пользователь' : 'AI'; ?></span>
                    <span style="color:var(--muted);font-size:11px"><?php echo date('H:i', strtotime($dm['created_at'])); ?></span>
                </div>
                <div class="adm-chat-msg__text"><?php echo nl2br(htmlspecialchars($dm['content'])); ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <!-- Что внесено в профиль -->
    <?php if (!empty($dhItems) || !empty($dmItems)): ?>
    <details>
        <summary style="font-size:12px;cursor:pointer;color:#059669;font-weight:600">🧬 Что внесено в профиль из этой записи</summary>
        <div style="margin-top:6px;display:flex;flex-direction:column;gap:4px">
        <?php foreach ($dhItems as $h): ?>
            <div style="font-size:11px;padding:4px 8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:3px;display:flex;gap:8px">
                <span class="adm-badge adm-badge--<?php echo $h['event_type'] === 'added' ? 'active' : 'pending'; ?>" style="font-size:10px"><?php echo $h['event_type']; ?></span>
                <span style="font-weight:600"><?php echo htmlspecialchars($h['label']); ?></span>
                <span style="color:var(--muted);flex:1"><?php $ed = json_decode($h['event_data'], true); echo htmlspecialchars(is_array($ed) ? json_encode($ed, JSON_UNESCAPED_UNICODE) : $h['event_data']); ?></span>
            </div>
        <?php endforeach; ?>
        <?php foreach ($dmItems as $mem): ?>
            <div style="font-size:11px;padding:4px 8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:3px;display:flex;gap:8px">
                <span class="adm-badge adm-badge--none" style="font-size:10px">memory</span>
                <span style="flex:1"><?php echo htmlspecialchars($mem['content']); ?></span>
                <span style="color:var(--muted)">важность: <?php echo $mem['importance_score']; ?></span>
            </div>
        <?php endforeach; ?>
        </div>
    </details>
    <?php elseif ($de['summary']): ?>
    <p style="font-size:12px;color:var(--muted)">Обновлений профиля из этой записи нет</p>
    <?php endif; ?>

</div>
</details>
</div>
<?php endforeach; ?>

<?php if (empty($diaryEntries)): ?>
<div class="adm-card" style="margin-top:8px;padding:20px;text-align:center;color:var(--muted)">Записей дневника нет</div>
<?php endif; ?>
</details>

<!-- ══ МЕДИТАЦИИ: ВСЕ ЛИЧНЫЕ ══════════════════════════════════════════════════ -->
<details style="margin-bottom:20px">
<summary class="adm-section-toggle">🎧 Все персональные медитации</summary>
<div class="adm-card" style="margin-top:8px">
<div style="padding:16px 20px">

<?php
$totalMeds = 0; $purchasedCount = 0; $listenCount = 0;
foreach ($medsByAnalysis as $amid => $meds) {
    foreach ($meds as $med) {
        $totalMeds++;
        if ($med['purchased']) $purchasedCount++;
        $listenCount += (int)$med['listen_count'];
    }
}
?>
<div style="display:flex;gap:24px;font-size:13px;margin-bottom:16px">
    <span>Всего: <strong><?php echo $totalMeds; ?></strong></span>
    <span>Куплено: <strong><?php echo $purchasedCount; ?></strong></span>
    <span>Прослушиваний: <strong><?php echo $listenCount; ?></strong></span>
</div>

<?php foreach ($analyses as $a):
    $aid = $a['id'];
    $meds = $medsByAnalysis[$aid] ?? [];
    if (empty($meds)) continue;
?>
<div style="margin-bottom:16px">
    <div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:8px">
        Разбор #<?php echo $aid; ?> — «<?php echo htmlspecialchars($a['topic'] ?: '(без темы)'); ?>» (<?php echo date('d.m.Y', strtotime($a['created_at'])); ?>)
    </div>
    <table class="adm-table" style="font-size:12px">
        <thead><tr><th>ID</th><th>Название</th><th>Статус</th><th>Куплено</th><th>Прослушиваний</th><th>Завершено</th><th>Доступна до</th><th>Аудио</th></tr></thead>
        <tbody>
        <?php foreach ($meds as $med):
            $msCls = ['ready'=>'active','pending'=>'pending','failed'=>'cancelled','generating'=>'generating'][$med['generation_status']] ?? 'none';
        ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $med['id']; ?></td>
            <td><?php echo htmlspecialchars($med['title'] ?? '—'); ?></td>
            <td><span class="adm-badge adm-badge--<?php echo $msCls; ?>"><?php echo $med['generation_status']; ?></span></td>
            <td><?php echo $med['purchased'] ? '✅' : '—'; ?></td>
            <td><?php echo $med['listen_count']; ?></td>
            <td><?php echo $med['listen_completed']; ?></td>
            <td style="color:var(--muted)"><?php echo $med['expires_at'] ? date('d.m.Y', strtotime($med['expires_at'])) : '∞'; ?></td>
            <td><?php if ($med['full_audio_url']): ?><a href="<?php echo htmlspecialchars($med['full_audio_url']); ?>" target="_blank" style="color:var(--accent)">🎧</a><?php else: ?>—<?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>

<?php if ($totalMeds === 0): ?>
<p style="color:var(--muted)">Персональных медитаций нет</p>
<?php endif; ?>

</div>
</div>
</details>

<!-- ══ ПЛАТЕЖИ ════════════════════════════════════════════════════════════════ -->
<?php if (!empty($payments)): ?>
<details style="margin-bottom:20px">
<summary class="adm-section-toggle">💳 История платежей</summary>
<div class="adm-card" style="margin-top:8px">
    <table class="adm-table" style="font-size:12px">
        <thead><tr><th>ID</th><th>Сумма</th><th>Статус</th><th>Провайдер</th><th>Описание</th><th>Дата</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p):
            $pCls = ['completed'=>'active','pending'=>'pending','failed'=>'cancelled','refunded'=>'none'][$p['status']] ?? 'none';
        ?>
        <tr>
            <td style="color:var(--muted)"><?php echo $p['id']; ?></td>
            <td><strong><?php echo number_format($p['amount'], 0, '.', ' '); ?> <?php echo $p['currency']; ?></strong></td>
            <td><span class="adm-badge adm-badge--<?php echo $pCls; ?>"><?php echo $p['status']; ?></span></td>
            <td style="color:var(--muted)"><?php echo htmlspecialchars($p['payment_provider']); ?></td>
            <td style="color:var(--muted)"><?php echo htmlspecialchars($p['description'] ?? ''); ?></td>
            <td style="color:var(--muted)"><?php echo date('d.m.Y H:i', strtotime($p['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</details>
<?php endif; ?>

<!-- ══ УДАЛИТЬ ПОЛЬЗОВАТЕЛЯ ═══════════════════════════════════════════════════ -->
<div style="margin-top:24px;padding:16px;background:#fff5f5;border:1px solid #fecaca;border-radius:8px">
    <div style="font-weight:600;margin-bottom:8px;color:var(--danger)">Удалить пользователя</div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:12px">Удалит аккаунт и все связанные данные. Действие необратимо.</p>
    <form method="post" action="/admin/users/api/delete.php" onsubmit="return confirm('Удалить пользователя <?php echo htmlspecialchars($user['email']); ?>? Это действие нельзя отменить.')">
        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
        <button type="submit" class="adm-btn adm-btn--danger">Удалить пользователя</button>
    </form>
</div>

<style>
.adm-section-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    padding: 10px 0;
    color: var(--text);
    list-style: none;
}
.adm-section-toggle::-webkit-details-marker { display: none; }
details > .adm-section-toggle::before { content: '▶'; font-size: 10px; color: var(--muted); transition: transform .2s; }
details[open] > .adm-section-toggle::before { transform: rotate(90deg); }
details > summary { list-style: none; }
details > summary::-webkit-details-marker { display: none; }
.adm-chat-log { display: flex; flex-direction: column; gap: 8px; padding: 8px; background: var(--bg); border-radius: 6px; max-height: 400px; overflow-y: auto; }
</style>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
