/**
 * features/chat-roller/chat-roller.js — Универсальный fullscreen chat-roller.
 *
 * Поддерживает три режима:
 *   analysis_chat   — основной разбор
 *   reflection_chat — самоисследование после практики
 *   diary_chat      — дневник
 *
 * Открытие:
 *   ChatRoller.open()               — analysis_chat (продолжение/новый)
 *   ChatRoller.openReflection(id)   — reflection_chat
 *   ChatRoller.openDiary(id, text)  — diary_chat
 *   ChatRoller.openReadonly()       — просмотр истории без ввода
 *
 * Самоинициализируется на DOMContentLoaded — не требует вызова из main.js.
 */

const ChatRoller = {

    _chatMode:             'analysis_chat',
    _entityId:             null,
    _paywallMode:          false,
    _paywallTriggered:     false,
    _diaryMode:            null,
    _diaryModeSelected:    false,

    init() {
        const sendBtn = document.getElementById('send-chat-roller-message');
        const backBtn = document.getElementById('go-back-from-chat-roller');
        const input   = document.getElementById('chat-roller-input');

        if (!sendBtn) return; // chat-roller не подключён на этой странице

        sendBtn.addEventListener('click', () => ChatRoller.handleSendMessage());
        backBtn.addEventListener('click', () => ChatRoller.close());

        // Отправка по Ctrl+Enter / Cmd+Enter
        if (input) {
            input.addEventListener('keydown', e => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    e.preventDefault();
                    ChatRoller.handleSendMessage();
                }
            });
        }
    },

    // ── Открытие в режиме анализа ─────────────────────────────────────────

    open() {
        ChatRoller._chatMode         = 'analysis_chat';
        ChatRoller._entityId         = null;
        // Если сервер сказал ANALYSIS_PAYWALL=false — сбрасываем sessionStorage
        if (typeof ANALYSIS_PAYWALL !== 'undefined' && !ANALYSIS_PAYWALL) {
            sessionStorage.removeItem('nirva_paywall');
        }
        ChatRoller._paywallMode      = (typeof ANALYSIS_PAYWALL !== 'undefined' && ANALYSIS_PAYWALL)
                                     || sessionStorage.getItem('nirva_paywall') === '1';
        ChatRoller._paywallTriggered = false;
        ChatRoller._showInput();
        ChatRoller._activate();

        ChatRoller._loadMessages('analysis');
    },

    // ── Открытие в режиме самоисследования ──────────────────────────────

    openReflection(analysisId) {
        ChatRoller._chatMode = 'reflection_chat';
        ChatRoller._entityId = analysisId;
        ChatRoller._setTitle('Самоисследование');
        ChatRoller._showInput();
        ChatRoller._activate();

        // Инициирующее сообщение от пользователя
        ChatRoller.sendMessage('Я завершил практику');
    },

    // ── Открытие в режиме дневника ────────────────────────────────────────

    openDiary(entryId, initialText, readonly = false, diaryMode = null) {
        ChatRoller._chatMode          = 'diary_chat';
        ChatRoller._entityId          = entryId;
        ChatRoller._diaryMode         = diaryMode;
        ChatRoller._diaryModeSelected = !!diaryMode;
        ChatRoller._setTitle('Дневник');
        ChatRoller._activate();

        if (readonly) {
            const el = document.getElementById('chat-roller');
            el.classList.add('chat-roller--readonly');
            const input = document.getElementById('chat-roller-input');
            const btn   = document.getElementById('send-chat-roller-message');
            if (input) input.style.display = 'none';
            if (btn)   btn.style.display   = 'none';

            fetch('/features/chat-roller/api/load-messages.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ mode: 'diary', entity_id: entryId }),
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.data.messages.length > 0) {
                        data.data.messages.forEach(msg => ChatRoller.appendMessage(msg));
                        ChatRoller.scrollToBottom();
                    }
                })
                .catch(() => {});
        } else {
            ChatRoller._showInput();
            const firstMsg = (initialText && initialText.trim()) || 'Хочу записать в дневник';
            ChatRoller.sendMessage(firstMsg);
        }
    },

    // ── Открытие в режиме readonly (просмотр истории) ────────────────────

    openReadonly() {
        ChatRoller._chatMode = 'analysis_chat';
        ChatRoller._entityId = null;

        const el = document.getElementById('chat-roller');
        el.classList.add('active', 'chat-roller--readonly');

        const input = document.getElementById('chat-roller-input');
        const btn   = document.getElementById('send-chat-roller-message');
        if (input) input.style.display = 'none';
        if (btn)   btn.style.display   = 'none';

        document.getElementById('chat-roller-messages').innerHTML = '';

        ChatRoller._loadMessages('analysis');
    },

    // ── Закрытие ─────────────────────────────────────────────────────────

    close() {
        const el = document.getElementById('chat-roller');
        el.classList.remove('active', 'chat-roller--readonly');
        // Обновляем hero-state на лендинге: сессия уже активна → покажет unfinished-analysis
        if (typeof HeroStatesManager !== 'undefined') {
            HeroStatesManager.init();
        }
    },

    // ── Отправка сообщения ────────────────────────────────────────────────

    handleSendMessage() {
        const input = document.getElementById('chat-roller-input');
        const text  = input ? input.value.trim() : '';
        if (!text) return;
        input.value = '';
        ChatRoller.sendMessage(text);
    },

    sendMessage(text) {
        ChatRoller.appendMessage({ role: 'user', content: text });
        ChatRoller.setInputLocked(true);
        ChatRoller.showTyping();

        const body = {
            message:   text,
            chat_mode: ChatRoller._chatMode,
        };
        if (ChatRoller._entityId) body.entity_id = ChatRoller._entityId;
        if (ChatRoller._chatMode === 'diary_chat' && ChatRoller._diaryMode) {
            body.diary_mode = ChatRoller._diaryMode;
        }

        fetch('/features/chat-roller/api/send-message.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(body),
        })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    ChatRoller.hideTyping();
                    ChatRoller.appendMessage({ role: 'assistant', content: data.message || 'Произошла ошибка. Попробуйте ещё раз.' });
                    ChatRoller.setInputLocked(false);
                    return;
                }

                // Supervisor mode: ждём одобрения психолога
                if (data.data && data.data.waiting) {
                    ChatRoller._startReviewPolling(data.data.session_id);
                    return;
                }

                ChatRoller._handleAIResponse(data.data);
            })
            .catch(() => {
                ChatRoller.hideTyping();
                ChatRoller.setInputLocked(false);
                ChatRoller.appendMessage({ role: 'assistant', content: 'Нет соединения с сервером.' });
            });
    },

    _startReviewPolling(sessionId) {
        if (ChatRoller._reviewPollInterval) clearInterval(ChatRoller._reviewPollInterval);
        let polls = 0;
        ChatRoller._reviewPollInterval = setInterval(async () => {
            polls++;
            if (polls > 600) { // 20 минут максимум
                clearInterval(ChatRoller._reviewPollInterval);
                ChatRoller.hideTyping();
                ChatRoller.setInputLocked(false);
                return;
            }
            try {
                const res  = await fetch('/features/chat-roller/api/poll-review.php?session_id=' + sessionId);
                const json = await res.json();
                if (json.success && json.data && !json.data.waiting) {
                    clearInterval(ChatRoller._reviewPollInterval);
                    ChatRoller._handleAIResponse(json.data);
                }
            } catch (_) {}
        }, 2000);
    },

    _handleAIResponse(data) {
        ChatRoller.hideTyping();

        if (data.message) {
            ChatRoller.appendMessage(data.message);
        }

        if (ChatRoller._chatMode === 'analysis_chat') {
            ChatRoller.saveToLocalStorage();
        }

        // Paywall: блюр после первого ответа ИИ
        if (ChatRoller._paywallMode && !ChatRoller._paywallTriggered
            && ChatRoller._chatMode === 'analysis_chat') {
            ChatRoller._paywallTriggered = true;
            ChatRoller._triggerPaywall();
        }

        if (data.topic)               ChatRoller.updateTopic(data.topic);
        if (data.analysis_completed)  ChatRoller._onAnalysisCompleted(data);
        if (data.reflection_completed) ChatRoller._onReflectionCompleted();
        if (data.diary_completed)     ChatRoller._onDiaryCompleted();

        ChatRoller.setInputLocked(false);
    },

    _showDiaryModeChips(initialText) {
        const messages = document.getElementById('chat-roller-messages');
        const chips = document.createElement('div');
        chips.id = 'diary-mode-chips';
        chips.className = 'diary-mode-chips';
        chips.innerHTML = `
            <p class="diary-mode-chips__label">Что сегодня было важным для Вас? Опиши событие, мысль, эмоцию или телесное ощущение.</p>
            <p class="diary-mode-chips__sublabel">Как хотите провести эту запись?</p>
            <button class="diary-chip" data-mode="vent">Просто выговориться</button>
            <button class="diary-chip" data-mode="reflection">Провести мини-исследование эмоций</button>
        `;
        messages.appendChild(chips);
        ChatRoller.scrollToBottom();

        chips.querySelectorAll('.diary-chip').forEach(btn => {
            btn.addEventListener('click', () => {
                ChatRoller._diaryMode = btn.dataset.mode;
                ChatRoller._diaryModeSelected = true;
                chips.remove();
                // Если пользователь уже ввёл текст на странице — подставляем в textarea
                const input = document.getElementById('chat-roller-input');
                if (input && initialText && initialText.trim()) {
                    input.value = initialText.trim();
                }
                ChatRoller.setInputLocked(false);
                if (input) input.focus();
            });
        });
    },

    // ── Обработчики завершения по режиму ──────────────────────────────────

    _onAnalysisCompleted(responseData) {
        const { selected_practice, personal_task } = responseData;

        if (selected_practice?.title && typeof RegistrationGate !== 'undefined') {
            RegistrationGate.setPracticeTitle(selected_practice.title);
        }
        if (personal_task && typeof RegistrationGate !== 'undefined') {
            RegistrationGate.setPersonalTask(personal_task);
        }

        setTimeout(() => {
            ChatRoller.close();
            if (typeof HeroStatesManager !== 'undefined') {
                HeroStatesManager.applyState('registration-gate');
            }
        }, 1500);
    },

    _onReflectionCompleted() {
        setTimeout(() => {
            ChatRoller.close();
            window.location.reload();
        }, 2000);
    },

    _onDiaryCompleted() {
        setTimeout(() => {
            ChatRoller.close();
            window.location.reload();
        }, 1500);
    },

    // ── UI-хелперы ────────────────────────────────────────────────────────

    appendMessage({ role, content }) {
        const messages = document.getElementById('chat-roller-messages');
        const wrap     = document.createElement('div');
        wrap.classList.add('chat-message', `${role}-message`);
        const inner = document.createElement('div');
        inner.classList.add('chat-message-content');
        inner.textContent = content;
        wrap.appendChild(inner);
        messages.appendChild(wrap);
        ChatRoller.scrollToBottom();
    },

    scrollToBottom() {
        const el = document.getElementById('chat-roller-messages');
        if (el) el.scrollTop = el.scrollHeight;
    },

    showTyping() {
        const messages = document.getElementById('chat-roller-messages');
        const wrap     = document.createElement('div');
        wrap.id = 'chat-roller-typing';
        wrap.classList.add('chat-message', 'assistant-message');
        const inner = document.createElement('div');
        inner.classList.add('chat-message-content', 'chat-roller__typing');
        inner.textContent = '...';
        wrap.appendChild(inner);
        messages.appendChild(wrap);
        ChatRoller.scrollToBottom();
    },

    hideTyping() {
        const el = document.getElementById('chat-roller-typing');
        if (el) el.remove();
    },

    setInputLocked(locked) {
        const input = document.getElementById('chat-roller-input');
        const btn   = document.getElementById('send-chat-roller-message');
        if (input) input.disabled = locked;
        if (btn)   btn.disabled   = locked;
    },

    updateTopic(topic) {
        // Заголовок внутри chat-roller
        const el = document.getElementById('chat-roller-topic');
        if (el) el.textContent = topic;

        // Мета-строка на странице разбора
        const pageTopic = document.getElementById('analysis-page-topic');
        if (pageTopic) pageTopic.textContent = topic;

        // Незавершённые разборы на дашборде
        const unfinished = document.getElementById('unfinished-analysis-topic');
        if (unfinished) unfinished.textContent = topic;

        // <title> браузера
        if (topic) document.title = topic + ' — Nirva';
    },

    _setTitle(subtitle) {
        const el = document.getElementById('chat-roller-topic');
        if (el) el.textContent = subtitle;
    },

    _activate() {
        const el = document.getElementById('chat-roller');
        el.classList.remove('chat-roller--readonly');
        el.classList.add('active');
        document.getElementById('chat-roller-messages').innerHTML = '';
    },

    _showInput() {
        const input = document.getElementById('chat-roller-input');
        const btn   = document.getElementById('send-chat-roller-message');
        if (input) input.style.display = '';
        if (btn)   btn.style.display   = '';
    },

    // ── LocalStorage (анонимный анализ до регистрации) ───────────────────

    saveToLocalStorage() {
        const sessionId = ChatRoller._entityId;
        if (!sessionId) return;

        const messages = [];
        document.querySelectorAll('#chat-roller-messages .chat-message').forEach(el => {
            const role    = el.classList.contains('user-message') ? 'user' : 'assistant';
            const content = el.querySelector('.chat-message-content')?.textContent || '';
            messages.push({ role, content });
        });

        localStorage.setItem('nirva_session', JSON.stringify({
            analysis_session_id: sessionId,
            messages,
            topic:    document.getElementById('chat-roller-topic')?.textContent || '',
            state:    'chat_in_progress',
            saved_at: Math.floor(Date.now() / 1000),
        }));
    },

    clearLocalStorage() {
        localStorage.removeItem('nirva_session');
    },

    // ── Paywall: блюр + CTA после первого ответа ─────────────────────────

    _triggerPaywall() {
        sessionStorage.removeItem('nirva_paywall');

        // Блюрим хвост последнего сообщения ассистента
        const messages  = document.getElementById('chat-roller-messages');
        const lastMsgs  = messages.querySelectorAll('.assistant-message');
        const lastMsg   = lastMsgs[lastMsgs.length - 1];
        if (lastMsg) {
            const content = lastMsg.querySelector('.chat-message-content');
            if (content) {
                const text     = content.textContent || '';
                const splitAt  = Math.floor(text.length * 0.55); // блюрим последние ~45%
                // Найти ближайший пробел к точке разбивки (не режем слово)
                let cutIdx = splitAt;
                while (cutIdx < text.length && text[cutIdx] !== ' ') cutIdx++;

                const visible = text.slice(0, cutIdx);
                const blurred = text.slice(cutIdx);

                const blurSpan       = document.createElement('span');
                blurSpan.className   = 'chat-message-tail--blur';
                blurSpan.textContent = blurred;
                content.textContent  = visible;
                content.appendChild(blurSpan);
            }
        }

        // Блокируем ввод
        ChatRoller.setInputLocked(true);

        // Открываем plan-modal с контекстным сообщением
        setTimeout(function () {
            if (typeof PlanModal !== 'undefined') {
                PlanModal.open('Оплатите подписку, чтобы продолжить разбор и получить персональные практики и медитации.');
            }
        }, 600);
    },

    // ── Загрузка истории из БД по фазе ───────────────────────────────────

    _loadMessages(mode, entityId = null) {
        const body = { mode };
        if (entityId) body.entity_id = entityId;

        fetch('/features/chat-roller/api/load-messages.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(body),
        })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data.messages.length > 0) {
                    data.data.messages.forEach(msg => ChatRoller.appendMessage(msg));
                    ChatRoller.scrollToBottom();

                    // Paywall: если история уже содержит ответ ИИ — сразу показываем модаль
                    if (ChatRoller._paywallMode && !ChatRoller._paywallTriggered) {
                        const hasAssistant = data.data.messages.some(m => m.role === 'assistant');
                        if (hasAssistant) {
                            ChatRoller._paywallTriggered = true;
                            ChatRoller._triggerPaywall();
                        }
                    }
                }

                // Если есть pending-сообщения (supervisor mode) — восстанавливаем polling
                if (data.success && data.data.waiting && data.data.session_id) {
                    ChatRoller.setInputLocked(true);
                    ChatRoller.showTyping();
                    ChatRoller._startReviewPolling(data.data.session_id);
                }
            })
            .catch(() => {});
    },
};

// init() вызывается из main.js через typeof ChatRoller !== 'undefined'
