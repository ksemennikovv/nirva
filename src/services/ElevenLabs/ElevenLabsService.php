<?php

/**
 * Интеграция с ElevenLabs Text-to-Speech API.
 * ElevenLabs TTS синхронный — возвращает байты MP3 сразу.
 */
class ElevenLabsService
{
    private string $apiKey;
    private string $voiceId;
    private string $model;
    private string $apiUrl;

    public function __construct()
    {
        $this->apiKey  = MEDITATION_AUDIO_API_KEY;
        $this->voiceId = MEDITATION_AUDIO_VOICE_ID;
        $this->model   = MEDITATION_AUDIO_MODEL;
        $this->apiUrl  = MEDITATION_AUDIO_API_URL;
    }

    /**
     * Генерирует MP3 из текста через ElevenLabs, сохраняет на диск.
     * Возвращает публичный URL файла.
     */
    public function generateSpeech(string $text, int $meditationId): string
    {
        $endpoint = $this->apiUrl . '/text-to-speech/' . $this->voiceId;

        $payload = json_encode([
            'text'           => $text,
            'model_id'       => $this->model,
            'voice_settings' => [
                'stability'        => 0.5,
                'similarity_boost' => 0.75,
            ],
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'xi-api-key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: audio/mpeg',
            ],
            CURLOPT_TIMEOUT => 120,
        ]);

        $audioBytes = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno      = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException('ElevenLabs cURL error: ' . $errno);
        }

        if ($httpCode !== 200) {
            $err = json_decode($audioBytes, true);
            $msg = $err['detail']['message'] ?? $audioBytes;
            throw new RuntimeException("ElevenLabs API error $httpCode: $msg");
        }

        return $this->saveAudio($audioBytes, $meditationId);
    }

    /**
     * Сохраняет байты аудио в assets/audio/meditations/{id}.mp3.
     * Возвращает публичный URL.
     */
    private function saveAudio(string $bytes, int $meditationId): string
    {
        $dir  = dirname(__DIR__, 3) . '/assets/audio/meditations';
        @mkdir($dir, 0755, true);

        $path = $dir . '/' . $meditationId . '.mp3';
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException("Failed to save audio to $path");
        }

        return '/assets/audio/meditations/' . $meditationId . '.mp3';
    }

    /**
     * Заглушка наложения фоновой музыки.
     * В будущем: отправить на сервис микширования и вернуть URL с музыкой.
     */
    public function applyMusicOverlay(string $audioUrl): string
    {
        return $audioUrl;
    }
}
