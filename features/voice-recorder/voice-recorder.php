<!--
    features/voice-recorder/voice-recorder.php — Self-contained feature "Голосовой ввод".

    Чистый модульный include: подключает собственные CSS и JS.
    Не содержит HTML — кнопки #start-voice-recording и #start-chat-roller-voice-recording
    находятся в default-hero.php и chat-roller.php соответственно.

    VoiceRecorder работает с любым textarea через setTargetInput(inputId).
    Инициализируется через VoiceRecorder.init() из assets/js/main.js.
-->

<link rel="stylesheet" href="/features/voice-recorder/voice-recorder.css">
<script src="/features/voice-recorder/voice-recorder.js"></script>
