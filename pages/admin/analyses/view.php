<?php
session_start();
$root = dirname(__DIR__, 3);
require_once $root . '/config/app.php';
require_once $root . '/config/database.php';
require_once $root . '/src/services/Database/Database.php';
require_once $root . '/config/business.php';
require_once $root . '/src/middleware/admin.php';
require_once $root . '/src/repositories/MessageRepository.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); echo 'Bad request'; exit; }

$debugView = isset($_GET['debug']);

$db = Database::getConnection();

$stmt = $db->prepare("SELECT a.*, u.email FROM analysis_sessions a JOIN users u ON u.id = a.user_id WHERE a.id = ?");
$stmt->execute([$id]);
$analysis = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$analysis) { http_response_code(404); echo 'Разбор не найден'; exit; }

$msgRepo = new MessageRepository();
try {
    $messages = $debugView
        ? $msgRepo->getAllWithReviewStatus($id)
        : $msgRepo->getApprovedMessages($id);
} catch (\PDOException $e) {
    $stmt2 = $db->prepare("SELECT * FROM messages WHERE analysis_session_id = ? ORDER BY id ASC");
    $stmt2->execute([$id]);
    $messages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

// Разделяем по фазам
$analysisPhase   = array_values(array_filter($messages, fn($m) => ($m['phase'] ?? 'analysis') === 'analysis'));
$reflectionPhase = array_values(array_filter($messages, fn($m) => ($m['phase'] ?? '') === 'reflection'));

// Медитации (с деталями)
$medStmt = $db->prepare(
    "SELECT m.*,
            (SELECT COUNT(*) FROM meditation_listens ml WHERE ml.meditation_id = m.id AND ml.user_id = ?) as listen_count,
            (SELECT COUNT(*) FROM meditation_listens ml WHERE ml.meditation_id = m.id AND ml.user_id = ? AND ml.completed=1) as listen_completed,
            (SELECT 1 FROM meditation_purchases mp WHERE mp.meditation_id = m.id AND mp.user_id = ?) as purchased
     FROM meditations m WHERE m.analysis_id = ? ORDER BY m.id"
);
$medStmt->execute([$analysis['user_id'], $analysis['user_id'], $analysis['user_id'], $id]);
$meditations = $medStmt->fetchAll(PDO::FETCH_ASSOC);

// Обновления профиля из этого разбора
$profileHistory = [];
$profileMemories = [];
try {
    $phStmt = $db->prepare(
        "SELECT pph.*, pp.code, pp.label
         FROM profile_parameter_history pph
         JOIN profile_parameters pp ON pp.id = pph.parameter_id
         WHERE pph.user_id = ? AND pph.source_type IN ('analysis','reflection') AND pph.source_id = ?
         ORDER BY pph.created_at ASC"
    );
    $phStmt->execute([$analysis['user_id'], $id]);
    $profileHistory = $phStmt->fetchAll(PDO::FETCH_ASSOC);

    $pmStmt = $db->prepare(
        "SELECT * FROM user_memories
         WHERE user_id = ? AND source_type IN ('analysis','reflection') AND source_id = ?
         ORDER BY created_at ASC"
    );
    $pmStmt->execute([$analysis['user_id'], $id]);
    $profileMemories = $pmStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {}

// Первое сообщение пользователя (стартовая тема)
$firstUserMsg = null;
foreach ($analysisPhase as $m) {
    if ($m['role'] === 'user') { $firstUserMsg = $m['content']; break; }
}

// Таблица практик
$practicesMap = [];
try {
    foreach ($db->query("SELECT slug, title FROM practices")->fetchAll(PDO::FETCH_ASSOC) as $pr) {
        $practicesMap[$pr['slug']] = $pr['title'];
    }
} catch (\PDOException $e) {}

// ElevenLabs конфиг
$elevenVoiceId = defined('MEDITATION_AUDIO_VOICE_ID') ? MEDITATION_AUDIO_VOICE_ID : (getenv('MEDITATION_AUDIO_VOICE_ID') ?: '—');
$elevenModel   = defined('MEDITATION_AUDIO_MODEL')    ? MEDITATION_AUDIO_MODEL    : (getenv('MEDITATION_AUDIO_MODEL') ?: '—');

function readPromptFile(string $root, string $name): string {
    $path = $root . '/prompts/' . $name;
    return file_exists($path) ? file_get_contents($path) : '[файл не найден: ' . $name . ']';
}

$pageTitle = 'Разбор #' . $id;
$activeNav = 'analyses';
require dirname(__DIR__) . '/_layout.php';
?>

<div style="display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap">
    <a href="/admin/analyses/" class="adm-btn adm-btn--ghost adm-btn--sm">← Все разборы</a>
    <a href="/admin/users/view.php?id=<?php echo $analysis['user_id']; ?>" class="adm-btn adm-btn--ghost adm-btn--sm">👤 Профиль: <?php echo htmlspecialchars($analysis['email']); ?></a>
    <?php if ($debugView): ?>
    <a href="/admin/analyses/view.php?id=<?php echo $id; ?>" class="adm-btn adm-btn--primary adm-btn--sm">🔧 Режим отладки ВКЛ</a>
    <?php else: ?>
    <a href="/admin/analyses/view.php?id=<?php echo $id; ?>&debug=1" class="adm-btn adm-btn--ghost adm-btn--sm">🔧 Режим отладки</a>
    <?php endif; ?>
</div>

<!-- ── Метаданные ──────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
    <div class="adm-card">
        <div class="adm-card__head"><div class="adm-card__title">Метаданные разбора</div></div>
        <div style="padding:16px 20px;font-size:13px;display:flex;flex-direction:column;gap:7px">
            <?php
            $practiceTitle = $analysis['selected_practice']
                ? ($practicesMap[$analysis['selected_practice']] ?? $analysis['selected_practice'])
                : '—';
            $meta = [
                'ID'              => $analysis['id'],
                'Статус'          => '<span class="adm-badge adm-badge--' . (['created'=>'pending','active'=>'generating','completed'=>'active','abandoned'=>'cancelled','analysis_completed'=>'generating','practice_completed'=>'pending','reflection_in_progress'=>'generating'][$analysis['status']] ?? 'none') . '">' . $analysis['status'] . '</span>',
                'Тема (AI)'       => htmlspecialchars($analysis['topic'] ?? '—'),
                'Практика'        => htmlspecialchars($practiceTitle),
                'Задание'         => htmlspecialchars($analysis['personal_task'] ?? '—'),
                'Стадия диалога'  => htmlspecialchars($analysis['dialogue_stage'] ?? '—'),
                'Сообщ. в стадии' => $analysis['stage_messages_count'] ?? '—',
                'Всего сообщений' => $analysis['total_messages_count'] ?? count($messages),
                'Уровень риска'   => '<span style="color:' . (['safe'=>'#059669','elevated'=>'#d97706','crisis'=>'#dc2626','psychosis'=>'#7c3aed'][$analysis['risk_level'] ?? 'safe'] ?? '#666') . '">' . ($analysis['risk_level'] ?? '—') . '</span>',
                'Создан'          => date('d.m.Y H:i', strtotime($analysis['created_at'])),
                'Завершён'        => $analysis['completed_at'] ? date('d.m.Y H:i', strtotime($analysis['completed_at'])) : '—',
            ];
            foreach ($meta as $k => $v):
            ?>
            <div style="display:flex;gap:12px">
                <span style="color:var(--muted);width:140px;flex-shrink:0"><?php echo $k; ?></span>
                <span><?php echo $v; ?></span>
            </div>
            <?php endforeach; ?>
        <?php if ($firstUserMsg): ?>
        <details style="margin:0 16px 12px">
            <summary style="font-size:12px;cursor:pointer;color:var(--muted);padding:4px 0;list-style:none;display:flex;align-items:center;gap:6px">
                <span style="font-size:10px">▶</span> Первое сообщение пользователя
            </summary>
            <div style="margin-top:6px;padding:10px;background:var(--bg);border-radius:6px;font-size:12px;line-height:1.6;white-space:pre-wrap;max-height:300px;overflow-y:auto"><?php echo htmlspecialchars($firstUserMsg); ?></div>
        </details>
        <?php endif; ?>
        </div>
    </div>

    <div class="adm-card">
        <div class="adm-card__head"><div class="adm-card__title">Итоги разбора</div></div>
        <div style="padding:16px 20px;font-size:13px;display:flex;flex-direction:column;gap:12px">
            <?php if ($analysis['analysis_summary']): ?>
            <div>
                <div style="color:var(--muted);margin-bottom:4px;font-size:11px;text-transform:uppercase;font-weight:700">Итог анализа</div>
                <div style="line-height:1.5"><?php echo nl2br(htmlspecialchars($analysis['analysis_summary'])); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($analysis['reflection_summary']): ?>
            <div>
                <div style="color:var(--muted);margin-bottom:4px;font-size:11px;text-transform:uppercase;font-weight:700">Итог рефлексии</div>
                <div style="line-height:1.5"><?php echo nl2br(htmlspecialchars($analysis['reflection_summary'])); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($analysis['final_recommendations']): ?>
            <div>
                <div style="color:var(--muted);margin-bottom:4px;font-size:11px;text-transform:uppercase;font-weight:700">Финальные рекомендации</div>
                <div style="line-height:1.5"><?php echo nl2br(htmlspecialchars($analysis['final_recommendations'])); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($analysis['dialogue_summary']): ?>
            <details>
                <summary style="font-size:11px;color:var(--muted);cursor:pointer">dialogue_summary (JSON)</summary>
                <pre style="font-size:10px;background:#f1f5f9;padding:6px;border-radius:4px;overflow:auto;max-height:200px;margin-top:4px"><?php echo htmlspecialchars(json_encode(json_decode($analysis['dialogue_summary']), JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); ?></pre>
            </details>
            <?php endif; ?>
            <?php if ($analysis['pending_metadata']): ?>
            <div>
                <div style="color:#d97706;font-size:11px;margin-bottom:2px;font-weight:700">⏳ pending_metadata (ожидает supervisor)</div>
                <pre style="font-size:10px;background:#fef3c7;padding:6px;border-radius:4px;overflow:auto;max-height:150px"><?php echo htmlspecialchars($analysis['pending_metadata']); ?></pre>
            </div>
            <?php endif; ?>
            <?php if (!$analysis['analysis_summary'] && !$analysis['reflection_summary']): ?>
            <span style="color:var(--muted)">Итоги ещё не сформированы</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Промт разбора ───────────────────────────────────────────────────────── -->
<div class="adm-card" style="margin-bottom:20px">
    <details>
        <summary class="adm-card__head" style="cursor:pointer;list-style:none">
            <div class="adm-card__title">📄 Промт разбора (analysis-prompt.txt)</div>
        </summary>
        <div style="padding:0 20px 16px">
            <pre style="font-size:11px;background:#f8fafc;border:1px solid var(--border);padding:12px;border-radius:4px;overflow:auto;max-height:400px;white-space:pre-wrap"><?php echo htmlspecialchars(readPromptFile($root, 'analysis-prompt.txt')); ?></pre>
        </div>
    </details>
</div>

<!-- ── Диалог (фаза анализа) ──────────────────────────────────────────────── -->
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">💬 Диалог разбора (<?php echo count($analysisPhase); ?> сообщений)</div></div>
    <div class="adm-chat-log">
    <?php foreach ($analysisPhase as $m):
        $reviewStatus = $m['review_status'] ?? 'approved';
        $isUser       = $m['role'] === 'user';
        $isRejected   = $reviewStatus === 'rejected';
        $isPending    = $reviewStatus === 'pending_review';
        $displayText  = ($m['reviewed_content'] ?? null) ?: ($m['content'] ?? '');
    ?>
        <?php if ($m['supervisor_instruction'] ?? null): ?>
        <div style="align-self:stretch;padding:8px 14px;background:#ede9fe;border:1px solid #c4b5fd;border-radius:6px;font-size:12px;line-height:1.5">
            <span style="font-weight:700;color:#5b21b6">🔧 SUPERVISOR:</span>
            <span style="color:#4c1d95"><?php echo htmlspecialchars($m['supervisor_instruction']); ?></span>
        </div>
        <?php endif; ?>
        <div class="adm-chat-msg adm-chat-msg--<?php echo $isUser ? 'user' : 'ai'; ?>"
             style="<?php echo $isRejected ? 'opacity:.5' : ''; ?>">
            <div class="adm-chat-msg__meta">
                <span class="adm-chat-msg__role"><?php echo $isUser ? 'Пользователь' : 'AI'; ?></span>
                <?php if ($isRejected): ?><span class="adm-badge adm-badge--cancelled" style="font-size:10px;padding:2px 6px">REJECTED</span><?php endif; ?>
                <?php if ($isPending): ?><span class="adm-badge adm-badge--pending" style="font-size:10px;padding:2px 6px">PENDING</span><?php endif; ?>
                <?php if (!$isUser && $debugView && !$isRejected && !$isPending): ?><span class="adm-badge adm-badge--active" style="font-size:10px;padding:2px 6px">APPROVED</span><?php endif; ?>
                <?php if ($m['reviewed_content'] ?? null): ?><span style="color:#6d28d9;font-size:10px">✎ отредактировано</span><?php endif; ?>
                <span style="color:var(--muted);font-size:11px"><?php echo date('H:i:s', strtotime($m['created_at'])); ?></span>
            </div>
            <div class="adm-chat-msg__text" style="<?php echo $isRejected ? 'text-decoration:line-through;opacity:.7' : ''; ?>"><?php echo nl2br(htmlspecialchars($displayText)); ?></div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($analysisPhase)): ?>
    <div style="padding:24px;text-align:center;color:var(--muted)">Сообщений нет</div>
    <?php endif; ?>
    </div>
</div>

<!-- ── Самоисследование (рефлексия) ───────────────────────────────────────── -->
<?php if (!empty($reflectionPhase)): ?>
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">🪞 Самоисследование / Рефлексия (<?php echo count($reflectionPhase); ?> сообщений)</div></div>
    <div style="padding:8px 20px">
        <details style="margin-bottom:10px">
            <summary style="font-size:12px;cursor:pointer;color:var(--muted)">📄 Промт рефлексии (reflection-prompt.txt)</summary>
            <pre style="font-size:10px;background:#f8fafc;border:1px solid var(--border);padding:10px;border-radius:4px;overflow:auto;max-height:300px;white-space:pre-wrap;margin-top:6px"><?php echo htmlspecialchars(readPromptFile($root, 'reflection-prompt.txt')); ?></pre>
        </details>
    </div>
    <div class="adm-chat-log" style="margin:0 20px 16px">
    <?php foreach ($reflectionPhase as $m):
        $isUser      = $m['role'] === 'user';
        $displayText = ($m['reviewed_content'] ?? null) ?: ($m['content'] ?? '');
    ?>
        <div class="adm-chat-msg adm-chat-msg--<?php echo $isUser ? 'user' : 'ai'; ?>">
            <div class="adm-chat-msg__meta">
                <span class="adm-chat-msg__role"><?php echo $isUser ? 'Пользователь' : 'AI'; ?></span>
                <span style="color:var(--muted);font-size:11px"><?php echo date('H:i:s', strtotime($m['created_at'])); ?></span>
            </div>
            <div class="adm-chat-msg__text"><?php echo nl2br(htmlspecialchars($displayText)); ?></div>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Обновления профиля из разбора ─────────────────────────────────────── -->
<?php if (!empty($profileHistory) || !empty($profileMemories)): ?>
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">🧬 Что внесено в профиль из этого разбора</div></div>
    <div style="padding:12px 20px;display:flex;flex-direction:column;gap:6px">
    <?php foreach ($profileHistory as $h): ?>
        <div style="font-size:12px;padding:5px 8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;display:flex;gap:10px;align-items:center">
            <span class="adm-badge adm-badge--<?php echo $h['event_type'] === 'added' ? 'active' : ($h['event_type'] === 'removed' ? 'cancelled' : 'pending'); ?>" style="font-size:10px"><?php echo $h['event_type']; ?></span>
            <span style="font-weight:600;min-width:180px"><?php echo htmlspecialchars($h['label']); ?></span>
            <span style="color:var(--muted);flex:1;font-size:11px"><?php $ed = json_decode($h['event_data'], true); echo htmlspecialchars(is_array($ed) ? json_encode($ed, JSON_UNESCAPED_UNICODE) : $h['event_data']); ?></span>
            <span style="color:var(--muted);font-size:11px;white-space:nowrap"><?php echo date('d.m H:i', strtotime($h['created_at'])); ?></span>
        </div>
    <?php endforeach; ?>
    <?php foreach ($profileMemories as $mem): ?>
        <div style="font-size:12px;padding:5px 8px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:4px;display:flex;gap:10px">
            <span class="adm-badge adm-badge--none" style="font-size:10px">memory</span>
            <span style="flex:1"><?php echo htmlspecialchars($mem['content']); ?></span>
            <span style="color:var(--muted)">важность: <?php echo $mem['importance_score']; ?></span>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Медитации ──────────────────────────────────────────────────────────── -->
<?php if (!empty($meditations)): ?>
<div class="adm-card" style="margin-bottom:20px">
    <div class="adm-card__head"><div class="adm-card__title">🎧 Медитации (<?php echo count($meditations); ?>)</div></div>
    <div style="padding:12px 20px;display:flex;flex-direction:column;gap:12px">
    <?php foreach ($meditations as $med):
        $msCls = ['ready'=>'active','pending'=>'pending','failed'=>'cancelled','generating'=>'generating'][$med['generation_status']] ?? 'none';
    ?>
        <div style="background:var(--bg);border-radius:6px;padding:12px">
            <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
                <span style="font-weight:600"><?php echo htmlspecialchars($med['title'] ?? '—'); ?></span>
                <span class="adm-badge adm-badge--<?php echo $msCls; ?>"><?php echo $med['generation_status']; ?></span>
                <?php if ($med['purchased']): ?><span class="adm-badge adm-badge--active">куплено</span><?php endif; ?>
                <?php if ($med['listen_count'] > 0): ?><span style="color:var(--muted);font-size:12px">прослушиваний: <?php echo $med['listen_count']; ?> (завершено: <?php echo $med['listen_completed']; ?>)</span><?php endif; ?>
            </div>
            <?php if ($med['description']): ?><div style="font-size:12px;color:var(--muted);margin-bottom:8px"><?php echo htmlspecialchars($med['description']); ?></div><?php endif; ?>

            <!-- ElevenLabs -->
            <div style="background:#fff;border:1px solid var(--border);border-radius:4px;padding:8px;margin-bottom:8px;font-size:11px">
                <div style="font-weight:700;color:var(--muted);margin-bottom:4px">ElevenLabs TTS</div>
                <div style="display:flex;flex-wrap:wrap;gap:12px">
                    <span>Voice ID: <code><?php echo htmlspecialchars($elevenVoiceId); ?></code></span>
                    <span>Model: <code><?php echo htmlspecialchars($elevenModel); ?></code></span>
                    <span>Provider: <code><?php echo htmlspecialchars($med['generation_provider'] ?? '—'); ?></code></span>
                    <span>Job ID: <code><?php echo htmlspecialchars($med['generation_job_id'] ?? '—'); ?></code></span>
                    <?php if ($med['full_audio_url']): ?>
                    <span>Audio: <a href="<?php echo htmlspecialchars($med['full_audio_url']); ?>" target="_blank" style="color:var(--accent)">🎧 <?php echo htmlspecialchars($med['full_audio_url']); ?></a></span>
                    <?php endif; ?>
                    <?php if ($med['expires_at']): ?>
                    <span>Доступна до: <?php echo date('d.m.Y', strtotime($med['expires_at'])); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Текст медитации -->
            <?php if ($med['personal_context']): ?>
            <details style="margin-bottom:6px">
                <summary style="font-size:11px;cursor:pointer;color:var(--muted)">📝 Полный текст медитации</summary>
                <div style="margin-top:6px;padding:10px;background:#fff;border:1px solid var(--border);border-radius:4px;line-height:1.7;white-space:pre-wrap;font-size:12px"><?php echo htmlspecialchars($med['personal_context']); ?></div>
            </details>
            <?php endif; ?>

            <!-- Промт генерации -->
            <details>
                <summary style="font-size:11px;cursor:pointer;color:var(--muted)">📄 Промт генерации (meditation-generation-prompt.txt)</summary>
                <pre style="font-size:10px;background:#f8fafc;border:1px solid var(--border);padding:8px;border-radius:4px;overflow:auto;max-height:250px;white-space:pre-wrap;margin-top:4px"><?php echo htmlspecialchars(readPromptFile($root, 'meditation-generation-prompt.txt')); ?></pre>
            </details>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Удалить разбор ─────────────────────────────────────────────────────── -->
<div style="margin-top:24px;border:1px solid var(--border);border-radius:8px;overflow:hidden">
    <div style="padding:14px 16px;display:flex;gap:16px;align-items:center;flex-wrap:wrap">

        <!-- Кнопка 1: просто удалить -->
        <form method="post" action="/admin/analyses/api/delete.php"
              onsubmit="return confirm('Удалить разбор #<?php echo $id; ?>?\n\nПортрет пользователя останется без изменений.')">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="redirect" value="/admin/analyses/">
            <input type="hidden" name="rollback_profile" value="0">
            <button type="submit" class="adm-btn adm-btn--ghost" style="border-color:#fca5a5;color:var(--danger)">
                Удалить разбор
            </button>
        </form>
        <div style="font-size:12px;color:var(--muted)">Сессия, сообщения и медитации.<br>Портрет не изменяется.</div>

        <div style="width:1px;height:40px;background:var(--border);flex-shrink:0"></div>

        <!-- Кнопка 2: удалить + откатить портрет -->
        <form method="post" action="/admin/analyses/api/delete.php"
              onsubmit="return confirm('Удалить разбор #<?php echo $id; ?> и откатить портрет?\n\nИз психоэмоционального портрета пользователя будут удалены параметры и воспоминания, добавленные этим разбором. Остальные разборы не затрагиваются.')">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <input type="hidden" name="redirect" value="/admin/analyses/">
            <input type="hidden" name="rollback_profile" value="1">
            <button type="submit" class="adm-btn adm-btn--danger">
                Удалить + откатить портрет
            </button>
        </form>
        <div style="font-size:12px;color:var(--muted)">То же, плюс удалит параметры<br>и воспоминания из этого разбора.</div>

    </div>
</div>

<style>
.adm-chat-log { display:flex; flex-direction:column; gap:8px; padding:16px; background:var(--bg); }
</style>

<?php require dirname(__DIR__) . '/_layout_end.php'; ?>
