<?php
session_start();

// Уже вошёл — редирект на next или дашборд
if (!empty($_SESSION['user_id'])) {
    $next = $_GET['next'] ?? '/dashboard/';
    // Защита от open redirect: только локальные пути
    if (!preg_match('#^/#', $next)) $next = '/dashboard/';
    header('Location: ' . $next);
    exit;
}

$next = htmlspecialchars($_GET['next'] ?? '/dashboard/');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Войти — Nirva</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    <style>
        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .login-card {
            width: 100%;
            max-width: 400px;
            background: var(--color-surface, #fff);
            border-radius: 16px;
            padding: 2.5rem 2rem;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
        }
        .login-card__logo {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 2rem;
            color: var(--color-primary, #6c5ce7);
        }
        .login-card__title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .login-card__input {
            width: 100%;
            padding: .85rem 1rem;
            border: 1.5px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            margin-bottom: 1rem;
            box-sizing: border-box;
            transition: border-color .2s;
        }
        .login-card__input:focus {
            outline: none;
            border-color: var(--color-primary, #6c5ce7);
        }
        .login-card__btn {
            width: 100%;
            padding: .9rem;
            background: var(--color-primary, #6c5ce7);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .2s;
        }
        .login-card__btn:disabled { opacity: .6; cursor: not-allowed; }
        .login-card__error {
            color: #e74c3c;
            font-size: .9rem;
            margin-top: .75rem;
            text-align: center;
        }
        .login-card__footer {
            text-align: center;
            margin-top: 1.5rem;
            font-size: .9rem;
            color: #888;
        }
        .login-card__footer a { color: var(--color-primary, #6c5ce7); text-decoration: none; }
    </style>
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-card__logo">Nirva</div>
        <div class="login-card__title">Войти в личный кабинет</div>

        <input type="email" id="login-email" class="login-card__input"
               placeholder="Email" autocomplete="email">
        <input type="password" id="login-password" class="login-card__input"
               placeholder="Пароль" autocomplete="current-password">

        <button id="login-btn" class="login-card__btn" type="button">Войти</button>

        <div id="login-error" class="login-card__error" hidden></div>

        <div class="login-card__footer">
            Нет аккаунта? <a href="/">Пройти разбор бесплатно</a>
        </div>
    </div>
</div>

<script>
(function () {
    const emailEl    = document.getElementById('login-email');
    const passwordEl = document.getElementById('login-password');
    const btnEl      = document.getElementById('login-btn');
    const errorEl    = document.getElementById('login-error');
    const next       = <?php echo json_encode($next); ?>;

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.hidden = false;
    }

    async function doLogin() {
        errorEl.hidden = true;
        const email    = emailEl.value.trim();
        const password = passwordEl.value;

        if (!email || !password) { showError('Введите email и пароль'); return; }

        btnEl.disabled = true;
        btnEl.textContent = 'Вход...';

        try {
            const res  = await fetch('/pages/login/api/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password }),
            });
            const data = await res.json();

            if (data.success) {
                const safeNext = (next && next.startsWith('/')) ? next : '/dashboard/';
                window.location.href = safeNext;
            } else {
                showError(data.message || 'Неверный email или пароль');
                btnEl.disabled = false;
                btnEl.textContent = 'Войти';
            }
        } catch (e) {
            showError('Ошибка соединения');
            btnEl.disabled = false;
            btnEl.textContent = 'Войти';
        }
    }

    btnEl.addEventListener('click', doLogin);
    [emailEl, passwordEl].forEach(el => el.addEventListener('keydown', e => {
        if (e.key === 'Enter') doLogin();
    }));
})();
</script>
</body>
</html>
