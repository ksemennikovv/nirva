<!--
    features/chat-roller/chat-roller.php — Self-contained feature "Chat Roller".

    Fullscreen overlay с чатом анализа.
    Подключает собственные CSS и JS самостоятельно.

    Управление видимостью:
      ChatRoller.open()  → добавляет класс .active → section становится видимым
      ChatRoller.close() → удаляет класс .active

    Инициализируется через ChatRoller.init() из assets/js/main.js.

    Данные:
      #chat-roller-title → тема анализа из $_SESSION['analysis_topic']
      #chat-roller-messages → bubbles добавляются через ChatRoller.appendMessage()
-->

<link rel="stylesheet" href="/features/chat-roller/chat-roller.css?v=<?php echo filemtime(__DIR__ . '/chat-roller.css'); ?>">

<section id="chat-roller" data-close-mode="<?php echo htmlspecialchars($chatRollerCloseMode ?? 'stay'); ?>">

    <!-- Шапка: кнопка назад + тема анализа -->
    <div class="chat-roller__header">

        <!-- Возврат на landing; слушатель → ChatRoller.close() -->
        <button id="go-back-from-chat-roller" type="button">обратно</button>

        <!--
            Заголовок: "Разбор — [тема]".
            Тема — динамически из сессии; span выделяется цветом через CSS.
        -->
        <div id="chat-roller-title">
            Разбор —
            <span id="chat-roller-topic" class="chat-roller__topic">
                <?php echo htmlspecialchars($_SESSION['analysis_topic'] ?? ''); ?>
            </span>
        </div>

    </div>

    <!-- Область сообщений (scroll); bubbles добавляются через ChatRoller.appendMessage() -->
    <div id="chat-roller-messages"></div>

    <!-- Нижняя панель ввода (fixed bottom) -->
    <div class="chat-roller__input-bar">

        <textarea
            id="chat-roller-input"
            placeholder="Напиши ответ…"
        ></textarea>

        <div class="chat-roller__input-actions">

            <!-- Голосовой ввод — управляется VoiceRecorder.init() -->
            <button id="start-chat-roller-voice-recording" type="button">диктофон</button>

            <!-- Отправить — слушатель → ChatRoller.handleSendMessage() -->
            <button id="send-chat-roller-message" type="button">отправить</button>

        </div>
    </div>

</section>

<script src="/features/chat-roller/chat-roller.js?v=<?php echo filemtime(__DIR__ . '/chat-roller.js'); ?>"></script>
