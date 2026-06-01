<?php require_once dirname(__DIR__, 2) . '/assets/php/helpers.php'; ?>
<!DOCTYPE html>
<!--
    pages/landing/landing.php — Сборка landing-страницы.

    Отвечает только за:
      - layout composition (HTML-обёртка)
      - php includes feature-блоков

    НЕ подключает CSS/JS отдельных features напрямую.
    Каждый feature-блок самостоятельно подключает свои assets внутри своего .php файла.

    Глобальные assets подключаются здесь один раз:
      /assets/css/main.css  — глобальные стили
      /assets/js/main.js    — глобальный init-координатор
-->
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nirva</title>

    <!-- Глобальные стили (один раз на всё приложение) -->
    <link rel="stylesheet" href="<?= asset_url('/assets/css/main.css') ?>">

    <!-- Стили layout landing-страницы (собственный asset landing-feature) -->
    <link rel="stylesheet" href="<?= asset_url('/pages/landing/landing.css') ?>">

    <!-- Лендинг скроллируемый: переопределяем overflow:hidden из main.css -->
    <style>
        body {
            overflow: auto;
            height: auto;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 16px 0;
        }
    </style>
</head>
<body>

<div class="phone landing">

    <header class="landing-header">
        <div class="landing-header__orb">N</div>
        <div>
            <div class="landing-header__brand">NIRVA</div>
            <div class="landing-header__sub">Телесные практики</div>
        </div>
    </header>

    <div class="landing-body">
        <?php include 'includes/hero-states/default-hero/default-hero.php'; ?>
        <?php include 'includes/hero-states/unfinished-analysis/unfinished-analysis.php'; ?>
        <?php include 'includes/hero-states/registration-gate/registration-gate.php'; ?>
    </div>

</div>

<!--
    chat-roller — fullscreen overlay поверх .landing.
    Находится вне .landing, чтобы не ограничивался его layout.
    Управляется через ChatRoller.open() / ChatRoller.close().
-->
<?php $chatRollerCloseMode = 'back'; include __DIR__ . '/../../features/chat-roller/chat-roller.php'; ?>

<!--
    voice-recorder — глобальный модуль голосового ввода.
    Не содержит HTML; подключает CSS и JS которые управляют кнопками
    #start-voice-recording и #start-chat-roller-voice-recording.
-->
<?php include __DIR__ . '/../../features/voice-recorder/voice-recorder.php'; ?>

<!-- JS landing-feature (определяет HeroStatesManager) -->
<script src="<?= asset_url('/pages/landing/landing.js') ?>"></script>

<!-- Глобальный init-координатор — загружается последним -->
<script src="<?= asset_url('/assets/js/main.js') ?>"></script>

</body>
</html>
