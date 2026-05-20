<?php

require_once __DIR__ . '/../../repositories/ProfileParameterRepository.php';

/**
 * Управление психоэмоциональным профилем пользователя.
 *
 * Логика обновления:
 * - AI предлагает изменения → ProfileService валидирует → применяет → пишет историю
 * - fixed-параметры: только значения из profile_parameter_options
 * - individual-параметры: уникальные строки, добавляются, не перезаписываются
 * - confidence пересчитывается как среднее при повторных упоминаниях
 * - evidence_count инкрементируется при каждом новом упоминании
 */
class ProfileService
{
    private ProfileParameterRepository $repo;

    public function __construct()
    {
        $this->repo = new ProfileParameterRepository();
    }

    /**
     * Возвращает профиль пользователя:
     * ['code' => ['label' => ..., 'value_type' => ..., 'category' => ..., 'value' => [...]]]
     */
    public function getProfile(int $userId): array
    {
        $params = $this->repo->getAllParameters();
        $values = $this->repo->getUserValues($userId);

        $profile = [];
        foreach ($params as $p) {
            $profile[$p['code']] = [
                'label'      => $p['label'],
                'value_type' => $p['value_type'],
                'category'   => $p['category'],
                'options'    => $p['options'],
                'value'      => $values[$p['code']] ?? [],
            ];
        }
        return $profile;
    }

    /**
     * Применяет обновления профиля от AI.
     *
     * $updates = [
     *   'psychological_defenses' => [
     *     ['value' => 'избегание', 'confidence' => 0.82],
     *     ...
     *   ],
     *   'phobias' => [
     *     ['value' => 'собаки', 'confidence' => 0.90],
     *   ],
     * ]
     *
     * Возвращает true если хотя бы один параметр изменился.
     */
    public function updateProfile(
        int    $userId,
        array  $updates,
        string $sourceType,
        int    $sourceId
    ): bool {
        $hasChanges = false;

        foreach ($updates as $code => $aiValues) {
            $param = $this->repo->getByCode($code);
            if (!$param) continue;

            $current = $this->repo->getUserParameterValue($userId, (int)$param['id']);
            $currentMap = [];
            foreach ($current as $item) {
                $currentMap[$item['value']] = $item;
            }

            // Для fixed-параметров фильтруем допустимые значения
            $allowedOptions = null;
            if ($param['value_type'] === 'fixed') {
                $allowedOptions = $this->repo->getOptions((int)$param['id']);
            }

            $changed = false;

            foreach ($aiValues as $aiItem) {
                $val        = trim($aiItem['value'] ?? '');
                $confidence = (float)($aiItem['confidence'] ?? 0.5);

                if ($val === '') continue;

                // Валидация для fixed
                if ($allowedOptions !== null && !in_array($val, $allowedOptions, true)) {
                    continue;
                }

                $now = date('c');

                if (isset($currentMap[$val])) {
                    // Уже есть — обновляем confidence и evidence_count
                    $old = $currentMap[$val];
                    $newCount      = ($old['evidence_count'] ?? 1) + 1;
                    $newConfidence = round(
                        (($old['confidence'] ?? $confidence) * ($newCount - 1) + $confidence) / $newCount,
                        4
                    );

                    $currentMap[$val] = [
                        'value'          => $val,
                        'confidence'     => $newConfidence,
                        'evidence_count' => $newCount,
                        'updated_at'     => $now,
                    ];

                    $this->repo->addHistory($userId, (int)$param['id'], 'updated', [
                        'value'              => $val,
                        'old_confidence'     => $old['confidence'] ?? null,
                        'new_confidence'     => $newConfidence,
                        'new_evidence_count' => $newCount,
                    ], $sourceType, $sourceId);

                } else {
                    // Новое значение
                    $currentMap[$val] = [
                        'value'          => $val,
                        'confidence'     => $confidence,
                        'evidence_count' => 1,
                        'updated_at'     => $now,
                    ];

                    $this->repo->addHistory($userId, (int)$param['id'], 'added', [
                        'value'      => $val,
                        'confidence' => $confidence,
                    ], $sourceType, $sourceId);
                }

                $changed    = true;
                $hasChanges = true;
            }

            if ($changed) {
                $this->repo->upsertUserParameterValue(
                    $userId,
                    (int)$param['id'],
                    array_values($currentMap)
                );
            }
        }

        return $hasChanges;
    }

    /** Добавить свободное AI-наблюдение. */
    public function addMemory(
        int    $userId,
        string $content,
        string $sourceType,
        int    $sourceId,
        int    $importanceScore = 6
    ): void {
        $this->repo->addMemory($userId, $content, $importanceScore, $sourceType, $sourceId);
    }

    /** Применить массив memories из AI-ответа. */
    public function addMemories(int $userId, array $memories, string $sourceType, int $sourceId): void
    {
        foreach ($memories as $mem) {
            $content = is_string($mem) ? $mem : ($mem['content'] ?? '');
            $score   = is_array($mem) ? (int)($mem['importance_score'] ?? 6) : 6;
            if ($content !== '') {
                $this->repo->addMemory($userId, $content, $score, $sourceType, $sourceId);
            }
        }
    }

    /**
     * Форматирует профиль + memories для подмешивания в system prompt AI.
     * Включает только параметры с непустыми значениями и confidence >= 0.5.
     */
    public function formatForPrompt(int $userId): string
    {
        $profile = $this->getProfile($userId);
        $memories = $this->repo->getTopMemories($userId, 10);

        $lines = ["=== Психоэмоциональный профиль пользователя ===\n"];

        $categories = [
            'defense'      => 'Психологические защиты и копинг',
            'fear'         => 'Страхи и триггеры',
            'body'         => 'Тело',
            'attachment'   => 'Стиль привязанности',
            'emotion'      => 'Эмоции и паттерны',
            'behavior'     => 'Поведенческие паттерны',
            'relationship' => 'Отношения',
            'trauma'       => 'Ключевые темы и события',
            'identity'     => 'Идентичность',
        ];

        foreach ($categories as $cat => $catLabel) {
            $catLines = [];
            foreach ($profile as $code => $data) {
                if ($data['category'] !== $cat) continue;
                $filtered = array_filter(
                    $data['value'],
                    fn($item) => ($item['confidence'] ?? 0) >= 0.5
                );
                if (empty($filtered)) continue;

                $vals = array_map(function ($item) {
                    $conf = $item['confidence'] ?? 0;
                    $label = $conf >= 0.8 ? 'выражено сильно'
                           : ($conf >= 0.65 ? 'умеренно' : 'слабо');
                    return "  • {$item['value']} ({$label})";
                }, $filtered);

                $catLines[] = "{$data['label']}:\n" . implode("\n", $vals);
            }

            if (!empty($catLines)) {
                $lines[] = "\n[$catLabel]";
                $lines   = array_merge($lines, $catLines);
            }
        }

        if (!empty($memories)) {
            $lines[] = "\n[Наблюдения AI]";
            foreach ($memories as $m) {
                $lines[] = "  • {$m['content']}";
            }
        }

        return implode("\n", $lines);
    }
}
