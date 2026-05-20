<?php
/**
 * features/voice-recorder/api/transcribe-audio.php — Транскрибирует аудио через OpenAI Whisper.
 *
 * Вызывается из VoiceRecorder.sendChunk() (POST, multipart/form-data).
 * Принимает аудио-blob как файл в поле 'audio', отправляет в OpenAI Whisper API.
 *
 * Ответ: JSON { success, data: { transcript }, message, error }
 */

session_start();
header('Content-Type: application/json');

// Путь к корню: api/ → voice-recorder/ → features/ → public_html/
$root = dirname(__DIR__, 3);

require_once $root . '/config/app.php';
require_once $root . '/config/ai.php';

// ─── Получить аудио-файл ──────────────────────────────────────────────────────

$audioFile = $_FILES['audio'] ?? null;

if (!$audioFile || $audioFile['error'] !== UPLOAD_ERR_OK) {
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Аудио не получено',
        'error'   => 'no_audio',
    ]);
    exit;
}

if ($audioFile['size'] === 0) {
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Пустой аудио-файл',
        'error'   => 'empty_audio',
    ]);
    exit;
}

// ─── Отправить в OpenAI Whisper ───────────────────────────────────────────────

$ch = curl_init('https://api.openai.com/v1/audio/transcriptions');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [
        'file'  => new CURLFile($audioFile['tmp_name'], $audioFile['type'] ?: 'audio/webm', 'audio.webm'),
        'model' => 'whisper-1',
    ],
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$raw   = curl_exec($ch);
$errno = curl_errno($ch);
curl_close($ch);

if ($errno) {
    error_log('transcribe-audio.php cURL error: ' . $errno);
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Ошибка соединения с AI',
        'error'   => 'curl_error',
    ]);
    exit;
}

// ─── Распарсить ответ ─────────────────────────────────────────────────────────

$data       = json_decode($raw, true);
$transcript = $data['text'] ?? null;

if ($transcript === null) {
    error_log('transcribe-audio.php unexpected response: ' . $raw);
    echo json_encode([
        'success' => false,
        'data'    => null,
        'message' => 'Не удалось распознать речь',
        'error'   => 'transcription_failed',
    ]);
    exit;
}

// ─── Ответ ────────────────────────────────────────────────────────────────────

echo json_encode([
    'success' => true,
    'data'    => ['transcript' => trim($transcript)],
    'message' => '',
    'error'   => null,
]);
