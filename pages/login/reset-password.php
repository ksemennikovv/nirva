<?php
session_start();
require_once dirname(__DIR__, 2) . '/assets/php/helpers.php';
require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/src/services/Database/Database.php';

if (!empty($_SESSION['user_id'])) { header('Location: /dashboard/'); exit; }

$token   = trim($_GET['token'] ?? '');
$invalid = false;

if (!$token) {
    $invalid = true;
} else {
    $db   = Database::getConnection();
    $stmt = $db->prepare('
        SELECT id FROM password_reset_tokens
        WHERE token = ? AND expires_at > NOW() AND used_at IS NULL LIMIT 1
    ');
    $stmt->execute([$token]);
    if (!$stmt->fetch()) $invalid = true;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
    <title>Новый пароль — Nirva</title>
    <link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">
    <style>
        body { display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; background: var(--bg); }
        .auth-page { width: 100%; max-width: 480px; min-height: 100vh; display: flex; flex-direction: column; background: var(--bg); }
        .auth-hero {
            background: linear-gradient(145deg, var(--gold1) 0%, var(--gold2) 50%, var(--gold3) 100%);
            padding: 48px 32px 36px;
            text-align: center;
        }
        .auth-hero__orb {
            width: 72px; height: 72px;
            background: rgba(59,31,10,.18);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; font-weight: 800; color: #3B1F0A;
            margin: 0 auto 16px;
        }
        .auth-hero__title { font-size: 24px; font-weight: 800; color: #3B1F0A; letter-spacing: -.3px; }
        .auth-hero__sub   { font-size: 14px; color: rgba(59,31,10,.65); margin-top: 6px; font-weight: 500; }
        .auth-body {
            padding: 32px 24px 40px;
            display: flex; flex-direction: column; gap: 14px;
            flex: 1;
        }
        .auth-field { display: flex; flex-direction: column; gap: 6px; }
        .auth-field label { font-size: 12px; font-weight: 600; color: var(--t3); text-transform: uppercase; letter-spacing: .5px; }
        .auth-field input {
            width: 100%; padding: 14px 16px;
            border: 1.5px solid var(--bd); border-radius: 12px;
            font-family: inherit; font-size: 15px; color: var(--t1);
            background: #fff; transition: border-color .2s; outline: none;
        }
        .auth-field input:focus { border-color: var(--gold2); }
        .auth-btn {
            width: 100%; padding: 16px;
            background: linear-gradient(135deg, var(--gold1), var(--gold2), var(--gold3));
            border: none; border-radius: var(--radius);
            font-family: inherit; font-size: 15px; font-weight: 700; color: #3B1F0A;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(232,155,44,.3);
            transition: opacity .2s;
        }
        .auth-btn:disabled { opacity: .6; cursor: not-allowed; }
        .auth-error {
            font-size: 13px; color: var(--danger);
            background: rgba(224,80,80,.08); border-radius: 10px;
            padding: 10px 14px; text-align: center;
        }
        .auth-invalid {
            font-size: 14px; color: var(--t2);
            background: rgba(224,80,80,.06); border-radius: 12px;
            padding: 16px; text-align: center; line-height: 1.6;
        }
        .auth-invalid a { color: var(--gold3); font-weight: 600; text-decoration: none; }
        .auth-footer { text-align: center; font-size: 13px; color: var(--t3); margin-top: 8px; }
        .auth-footer a { color: var(--gold3); text-decoration: none; font-weight: 600; }
        .password-hint { font-size: 12px; color: var(--t3); margin-top: 2px; }
    </style>
</head>
<body>
<div class="auth-page">
    <div class="auth-hero">
        <div class="auth-hero__orb">N</div>
        <div class="auth-hero__title">NIRVA</div>
        <div class="auth-hero__sub">Новый пароль</div>
    </div>
    <div class="auth-body">
        <?php if ($invalid): ?>
        <div class="auth-invalid">
            Ссылка недействительна или истекла.<br>
            <a href="/pages/login/forgot-password.php">Запросить новую ссылку</a>
        </div>
        <?php else: ?>
        <div class="auth-field">
            <label>Новый пароль</label>
            <input type="password" id="rp-password" placeholder="Минимум 6 символов" autocomplete="new-password">
            <span class="password-hint">Не менее 6 символов</span>
        </div>
        <div class="auth-field">
            <label>Повторите пароль</label>
            <input type="password" id="rp-confirm" placeholder="••••••••" autocomplete="new-password">
        </div>

        <button id="rp-btn" class="auth-btn" type="button">Сохранить пароль</button>

        <div id="rp-error" class="auth-error" hidden></div>

        <div class="auth-footer">
            <a href="/pages/login/">← Вернуться к входу</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php if (!$invalid): ?>
<script>
(function () {
    const passEl    = document.getElementById('rp-password');
    const confirmEl = document.getElementById('rp-confirm');
    const btnEl     = document.getElementById('rp-btn');
    const errorEl   = document.getElementById('rp-error');
    const token     = <?= json_encode($token) ?>;

    async function submit() {
        errorEl.hidden = true;
        const password = passEl.value;
        const confirm  = confirmEl.value;

        if (password.length < 6) { errorEl.textContent = 'Пароль должен быть не менее 6 символов'; errorEl.hidden = false; return; }
        if (password !== confirm) { errorEl.textContent = 'Пароли не совпадают'; errorEl.hidden = false; return; }

        btnEl.disabled = true;
        btnEl.textContent = 'Сохранение...';

        try {
            const res  = await fetch('/pages/login/api/reset-password.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token, password }),
            });
            const data = await res.json();
            if (data.success) {
                window.location.href = '/dashboard/';
            } else {
                errorEl.textContent = data.message || 'Ошибка. Попробуйте ещё раз.';
                errorEl.hidden = false;
                btnEl.disabled = false;
                btnEl.textContent = 'Сохранить пароль';
            }
        } catch (e) {
            errorEl.textContent = 'Ошибка соединения';
            errorEl.hidden = false;
            btnEl.disabled = false;
            btnEl.textContent = 'Сохранить пароль';
        }
    }

    btnEl.addEventListener('click', submit);
    [passEl, confirmEl].forEach(el => el.addEventListener('keydown', e => { if (e.key === 'Enter') submit(); }));
})();
</script>
<?php endif; ?>
</body>
</html>
