/**
 * features/voice-recorder/voice-recorder.js — Голосовой ввод через MediaRecorder.
 *
 * Определяет глобальный объект VoiceRecorder.
 * init() вызывается из assets/js/main.js на DOMContentLoaded.
 *
 * Поддерживаемые inputs:
 *   #hero-text-input          (default-hero)
 *   #chat-roller-input        (chat-roller)
 *
 * Flow записи:
 *   1. Пользователь нажимает кнопку диктофона → toggle()
 *   2. start() — запрашивает доступ к микрофону, запускает MediaRecorder
 *   3. Каждые 3 секунды MediaRecorder выдаёт chunk → sendChunk() → transcribe-audio.php
 *   4. Transcript вставляется в конец целевого поля ввода
 *   5. Повторное нажатие → stop()
 */

const VoiceRecorder = {

    /** ID текущего целевого поля ввода */
    targetInputId: null,

    /** Экземпляр MediaRecorder */
    mediaRecorder: null,

    /** Медиапоток (для остановки треков) */
    stream: null,

    /** true пока идёт запись */
    isRecording: false,

    /**
     * init() — вешает обработчики на кнопки диктофона.
     * Привязывает каждую кнопку к соответствующему полю ввода.
     */
    init() {
        const heroBtn = document.getElementById('start-voice-recording');
        const chatBtn = document.getElementById('start-chat-roller-voice-recording');

        if (heroBtn) {
            heroBtn.addEventListener('click', () => {
                VoiceRecorder.setTargetInput('hero-text-input');
                VoiceRecorder.toggle();
            });
        }

        if (chatBtn) {
            chatBtn.addEventListener('click', () => {
                VoiceRecorder.setTargetInput('chat-roller-input');
                VoiceRecorder.toggle();
            });
        }
    },

    /**
     * setTargetInput(inputId) — устанавливает целевое поле для вставки транскрипции.
     * @param {string} inputId
     */
    setTargetInput(inputId) {
        VoiceRecorder.targetInputId = inputId;
    },

    /**
     * toggle() — переключает запись вкл/выкл.
     * Если уже записывает — останавливает; иначе — запускает.
     */
    toggle() {
        if (VoiceRecorder.isRecording) {
            VoiceRecorder.stop();
        } else {
            VoiceRecorder.start();
        }
    },

    /**
     * start() — запрашивает доступ к микрофону и начинает запись.
     *
     * MediaRecorder запускается с timeslice 3000мс:
     * ondataavailable срабатывает каждые 3 секунды с накопленными данными.
     */
    start() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Ваш браузер не поддерживает запись звука.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(stream => {
                VoiceRecorder.stream        = stream;
                VoiceRecorder.mediaRecorder = new MediaRecorder(stream);

                VoiceRecorder.mediaRecorder.ondataavailable = e => {
                    if (e.data && e.data.size > 0) {
                        VoiceRecorder.sendChunk(e.data);
                    }
                };

                VoiceRecorder.mediaRecorder.start(3000);
                VoiceRecorder.isRecording = true;
                VoiceRecorder.updateButtonState();
            })
            .catch(() => {
                alert('Не удалось получить доступ к микрофону. Проверьте разрешения браузера.');
            });
    },

    /**
     * stop() — останавливает запись и освобождает микрофон.
     */
    stop() {
        if (!VoiceRecorder.isRecording) return;

        VoiceRecorder.mediaRecorder.stop();
        VoiceRecorder.stream.getTracks().forEach(track => track.stop());

        VoiceRecorder.isRecording   = false;
        VoiceRecorder.mediaRecorder = null;
        VoiceRecorder.stream        = null;

        VoiceRecorder.updateButtonState();
    },

    /**
     * sendChunk(blob) — отправляет аудио-фрагмент на транскрипцию.
     *
     * POST к transcribe-audio.php как multipart/form-data.
     * При успехе вызывает insertTranscript() с полученным текстом.
     *
     * @param {Blob} blob
     */
    sendChunk(blob) {
        const formData = new FormData();
        formData.append('audio', blob, 'chunk.webm');

        fetch('/features/voice-recorder/api/transcribe-audio.php', {
            method: 'POST',
            body:   formData,
        })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data.transcript) {
                    VoiceRecorder.insertTranscript(data.data.transcript);
                }
            })
            .catch(() => {});
    },

    /**
     * insertTranscript(text) — вставляет транскрипцию в конец целевого поля ввода.
     *
     * Сохраняет уже введённый текст — не перезаписывает, а дописывает.
     *
     * @param {string} text
     */
    insertTranscript(text) {
        if (!VoiceRecorder.targetInputId) return;

        const input = document.getElementById(VoiceRecorder.targetInputId);
        if (!input) return;

        const current  = input.value;
        input.value    = current ? current + ' ' + text : text;
    },

    /**
     * updateButtonState() — обновляет визуальное состояние кнопок диктофона.
     * Класс .recording добавляется/удаляется в зависимости от isRecording.
     */
    updateButtonState() {
        const heroBtn = document.getElementById('start-voice-recording');
        const chatBtn = document.getElementById('start-chat-roller-voice-recording');

        [heroBtn, chatBtn].forEach(btn => {
            if (!btn) return;
            if (VoiceRecorder.isRecording) {
                btn.classList.add('recording');
            } else {
                btn.classList.remove('recording');
            }
        });
    },
};
