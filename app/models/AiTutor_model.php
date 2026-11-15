<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AiTutor_model extends Model
{
    protected $table = 'ai_tutor_usage';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function settings(): array
    {
        $row = $this->db->raw('SELECT * FROM ai_tutor_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('AI tutor settings are missing. Run migration 007_ai_tutor.sql.');
        return [
            'enabled' => (bool)$row['enabled'],
            'dailyCredits' => (int)$row['daily_credits'],
            'costs' => [
                'hint' => (int)$row['cost_hint'],
                'error' => (int)$row['cost_error'],
                'concept' => (int)$row['cost_concept'],
                'pseudocode' => (int)$row['cost_pseudocode'],
                'solution' => (int)$row['cost_solution'],
            ],
            'model' => trim((string)$row['model']),
            'inputCostPerMillionUsd' => (float)$row['input_cost_per_million_usd'],
            'outputCostPerMillionUsd' => (float)$row['output_cost_per_million_usd'],
        ];
    }

    public function updateSettings(array $values): void
    {
        $this->db->raw(
            'UPDATE ai_tutor_settings
             SET enabled = ?, daily_credits = ?, cost_hint = ?, cost_error = ?, cost_concept = ?,
                 cost_pseudocode = ?, cost_solution = ?, model = ?, input_cost_per_million_usd = ?,
                 output_cost_per_million_usd = ?
             WHERE id = 1',
            [
                $values['enabled'] ? 1 : 0, $values['dailyCredits'], $values['costs']['hint'],
                $values['costs']['error'], $values['costs']['concept'], $values['costs']['pseudocode'],
                $values['costs']['solution'], $values['model'], $values['inputCostPerMillionUsd'],
                $values['outputCostPerMillionUsd'],
            ]
        );
    }

    public function exerciseForStudent(string $exerciseId): ?array
    {
        $row = $this->db->raw(
            "SELECT e.id, e.title, e.prompt, e.language, c.title course, s.name skill
             FROM coding_exercises e
             JOIN courses c ON c.id = e.course_id
             LEFT JOIN skills s ON s.id = e.skill_id
             WHERE e.id = ? LIMIT 1",
            [$exerciseId]
        )->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function credits(string $userId, array $settings): array
    {
        $this->releaseStaleReservations($userId);
        $row = $this->db->raw(
            'SELECT credits_used FROM ai_tutor_daily_credits WHERE user_id = ? AND credit_date = ?',
            [$userId, date('Y-m-d')]
        )->fetch(PDO::FETCH_ASSOC);
        $used = (int)($row['credits_used'] ?? 0);
        return [
            'dailyLimit' => $settings['dailyCredits'],
            'used' => $used,
            'remaining' => max(0, $settings['dailyCredits'] - $used),
            'costs' => $settings['costs'],
        ];
    }

    /** Locks the user's daily balance before reserving credits and checking idempotency. */
    public function reserve(
        string $userId,
        string $requestId,
        string $requestHash,
        ?string $exerciseId,
        string $mode,
        string $action,
        int $cost,
        int $dailyLimit,
        string $model
    ): array {
        $this->releaseStaleReservations($userId);
        $today = date('Y-m-d');
        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT IGNORE INTO ai_tutor_daily_credits (user_id, credit_date, credits_used) VALUES (?, ?, 0)',
                [$userId, $today]
            );
            $balance = $this->db->raw(
                'SELECT credits_used FROM ai_tutor_daily_credits WHERE user_id = ? AND credit_date = ? FOR UPDATE',
                [$userId, $today]
            )->fetch(PDO::FETCH_ASSOC);
            $used = (int)($balance['credits_used'] ?? 0);
            $existing = $this->db->raw(
                'SELECT id, request_hash, status, response_text, credits, credit_date, hint_level, created_at
                 FROM ai_tutor_usage WHERE user_id = ? AND request_id = ? LIMIT 1',
                [$userId, $requestId]
            )->fetch(PDO::FETCH_ASSOC);
            $allocatedHintLevel = $action === 'hint'
                ? $this->hintCount($userId, $exerciseId, $requestId) + 1
                : null;
            if ($allocatedHintLevel !== null) $allocatedHintLevel = min(3, $allocatedHintLevel);

            if ($existing) {
                if (!hash_equals((string)$existing['request_hash'], $requestHash)) {
                    $this->db->roll_back();
                    return ['conflict' => true];
                }
                if ($existing['status'] === 'succeeded') {
                    $this->db->commit();
                    return [
                        'duplicate' => true,
                        'response' => (string)$existing['response_text'],
                        'remaining' => max(0, $dailyLimit - $used),
                        'cost' => (int)$existing['credits'],
                        'hintLevel' => $existing['hint_level'] === null ? null : (int)$existing['hint_level'],
                    ];
                }
                if ($existing['status'] === 'pending') {
                    if (strtotime((string)$existing['created_at']) > time() - 120) {
                        $this->db->roll_back();
                        return ['pending' => true];
                    }
                    $this->db->raw(
                        'UPDATE ai_tutor_daily_credits SET credits_used = GREATEST(0, credits_used - ?)
                         WHERE user_id = ? AND credit_date = ?',
                        [(int)$existing['credits'], $userId, $existing['credit_date']]
                    );
                    if ($existing['credit_date'] === $today) {
                        $used = max(0, $used - (int)$existing['credits']);
                    }
                    $this->db->raw(
                        "UPDATE ai_tutor_usage SET status = 'failed', error_code = 'reservation_expired', completed_at = NOW()
                         WHERE id = ? AND status = 'pending'",
                        [$existing['id']]
                    );
                }
                if ($used + $cost > $dailyLimit) {
                    $this->db->commit();
                    return ['insufficient' => true, 'remaining' => max(0, $dailyLimit - $used)];
                }
                $this->db->raw(
                    "UPDATE ai_tutor_usage
                     SET status = 'pending', credit_date = ?, hint_level = ?, credits = ?, model = ?, response_text = NULL, error_code = NULL,
                         created_at = NOW(), completed_at = NULL
                     WHERE id = ?",
                    [$today, $allocatedHintLevel, $cost, $model, $existing['id']]
                );
                $usageId = $existing['id'];
            } else {
                if ($used + $cost > $dailyLimit) {
                    $this->db->commit();
                    return ['insufficient' => true, 'remaining' => max(0, $dailyLimit - $used)];
                }
                $usageId = uuid4();
                $this->db->raw(
                    'INSERT INTO ai_tutor_usage
                     (id, user_id, exercise_id, request_id, request_hash, credit_date, mode, action, hint_level, status, credits, model)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?)',
                    [$usageId, $userId, $exerciseId, $requestId, $requestHash, $today, $mode, $action, $allocatedHintLevel, $cost, $model]
                );
            }
            $this->db->raw(
                'UPDATE ai_tutor_daily_credits SET credits_used = credits_used + ? WHERE user_id = ? AND credit_date = ?',
                [$cost, $userId, $today]
            );
            $this->db->commit();
            return [
                'usageId' => $usageId,
                'remaining' => max(0, $dailyLimit - $used - $cost),
                'hintLevel' => $allocatedHintLevel,
            ];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    private function hintCount(string $userId, ?string $exerciseId, string $requestId): int
    {
        $sql = "SELECT COUNT(*) FROM ai_tutor_usage
                WHERE user_id = ? AND request_id <> ? AND action = 'hint' AND status IN ('pending', 'succeeded') ";
        $params = [$userId, $requestId];
        if ($exerciseId === null) {
            $sql .= 'AND exercise_id IS NULL';
        } else {
            $sql .= 'AND exercise_id = ?';
            $params[] = $exerciseId;
        }
        return (int)$this->db->raw($sql, $params)->fetchColumn();
    }

    private function releaseStaleReservations(string $userId): void
    {
        $today = date('Y-m-d');
        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT IGNORE INTO ai_tutor_daily_credits (user_id, credit_date, credits_used) VALUES (?, ?, 0)',
                [$userId, $today]
            );
            $this->db->raw(
                'SELECT credits_used FROM ai_tutor_daily_credits WHERE user_id = ? AND credit_date = ? FOR UPDATE',
                [$userId, $today]
            )->fetch(PDO::FETCH_ASSOC);
            $expired = $this->db->raw(
                "SELECT id, credit_date, credits FROM ai_tutor_usage
                 WHERE user_id = ? AND status = 'pending'
                   AND created_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                 FOR UPDATE",
                [$userId]
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($expired as $reservation) {
                $this->db->raw(
                    'UPDATE ai_tutor_daily_credits SET credits_used = GREATEST(0, credits_used - ?)
                     WHERE user_id = ? AND credit_date = ?',
                    [(int)$reservation['credits'], $userId, $reservation['credit_date']]
                );
                $this->db->raw(
                    "UPDATE ai_tutor_usage SET status = 'failed', error_code = 'reservation_expired', completed_at = NOW()
                     WHERE id = ? AND status = 'pending'",
                    [$reservation['id']]
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function succeed(
        string $usageId,
        string $reply,
        int $promptTokens,
        int $outputTokens,
        float $estimatedCost,
        string $model
    ): void {
        $this->db->raw(
            "UPDATE ai_tutor_usage
             SET status = 'succeeded', response_text = ?, prompt_tokens = ?, output_tokens = ?,
                 estimated_cost_usd = ?, model = ?, completed_at = NOW()
             WHERE id = ? AND status = 'pending'",
            [$reply, $promptTokens, $outputTokens, $estimatedCost, $model, $usageId]
        );
    }

    public function fail(string $usageId, string $userId, int $cost, string $errorCode): void
    {
        $this->db->transaction();
        try {
            $usage = $this->db->raw(
                'SELECT status, credit_date FROM ai_tutor_usage WHERE id = ? AND user_id = ? FOR UPDATE',
                [$usageId, $userId]
            )->fetch(PDO::FETCH_ASSOC);
            if ($usage && $usage['status'] === 'pending') {
                $this->db->raw(
                    'UPDATE ai_tutor_daily_credits SET credits_used = GREATEST(0, credits_used - ?)
                     WHERE user_id = ? AND credit_date = ?',
                    [$cost, $userId, $usage['credit_date']]
                );
                $this->db->raw(
                    "UPDATE ai_tutor_usage SET status = 'failed', error_code = ?, completed_at = NOW()
                     WHERE id = ? AND status = 'pending'",
                    [$errorCode, $usageId]
                );
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function recentUsage(string $userId): array
    {
        return $this->db->raw(
            'SELECT action, mode, credits, status, created_at createdAt
             FROM ai_tutor_usage WHERE user_id = ? ORDER BY created_at DESC LIMIT 20',
            [$userId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function studentAnalytics(string $userId): array
    {
        $runs = $this->db->raw(
            'SELECT COUNT(*) completed,
                    COALESCE(SUM(CASE WHEN EXISTS (
                      SELECT 1 FROM ai_tutor_usage a WHERE a.user_id = ? AND a.exercise_id = f.exercise_id
                        AND a.status = \'succeeded\' AND a.completed_at <= f.first_pass_at
                    ) THEN 1 ELSE 0 END), 0) assisted,
                    COALESCE(SUM(CASE WHEN NOT EXISTS (
                      SELECT 1 FROM ai_tutor_usage a WHERE a.user_id = ? AND a.exercise_id = f.exercise_id
                        AND a.status = \'succeeded\' AND a.completed_at <= f.first_pass_at
                    ) THEN 1 ELSE 0 END), 0) independent,
                    (SELECT COUNT(*) FROM execution_results e WHERE e.user_id = ? AND e.exercise_id IS NOT NULL
                       AND e.total_count > 0 AND e.passed_count = e.total_count) successful_attempts
             FROM (SELECT exercise_id, MIN(created_at) first_pass_at
                   FROM execution_results WHERE user_id = ? AND exercise_id IS NOT NULL
                     AND total_count > 0 AND passed_count = total_count
                   GROUP BY exercise_id) f',
            [$userId, $userId, $userId, $userId]
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $usage = $this->db->raw(
            "SELECT COALESCE(SUM(credits), 0) credits, COALESCE(SUM(action = 'hint'), 0) hints
             FROM ai_tutor_usage WHERE user_id = ? AND status = 'succeeded'",
            [$userId]
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $topics = $this->db->raw(
            'SELECT s.name, ROUND(sc.score) score
             FROM student_skill_scores sc JOIN skills s ON s.id = sc.skill_id
             JOIN (SELECT skill_id, MAX(measured_at) measured_at FROM student_skill_scores WHERE user_id = ? GROUP BY skill_id) latest
               ON latest.skill_id = sc.skill_id AND latest.measured_at = sc.measured_at
             WHERE sc.user_id = ? ORDER BY sc.score DESC',
            [$userId, $userId]
        )->fetchAll(PDO::FETCH_ASSOC);
        return [
            'totalExercisesCompleted' => (int)($runs['completed'] ?? 0),
            'solvedIndependently' => (int)($runs['independent'] ?? 0),
            'solvedWithAiAssistance' => (int)($runs['assisted'] ?? 0),
            'successfulAttempts' => (int)($runs['successful_attempts'] ?? 0),
            'hintsRequested' => (int)($usage['hints'] ?? 0),
            'aiCreditsConsumed' => (int)($usage['credits'] ?? 0),
            'topicsMastered' => array_values(array_map(
                static fn(array $row): string => $row['name'],
                array_filter($topics, static fn(array $row): bool => (int)$row['score'] >= 80)
            )),
            'areasToImprove' => array_values(array_map(
                static fn(array $row): string => $row['name'],
                array_filter($topics, static fn(array $row): bool => (int)$row['score'] < 60)
            )),
        ];
    }

    public function exerciseAchievementEligibility(string $userId, string $exerciseId, string $executionId): array
    {
        $usage = $this->db->raw(
            "SELECT COUNT(*) requests, COALESCE(SUM(action = 'hint'), 0) hints,
                    COALESCE(SUM(action = 'solution'), 0) solutions
             FROM ai_tutor_usage
             WHERE user_id = ? AND exercise_id = ? AND status = 'succeeded'
               AND completed_at <= (SELECT created_at FROM execution_results WHERE id = ? AND user_id = ?)",
            [$userId, $exerciseId, $executionId, $userId]
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'independent' => (int)($usage['requests'] ?? 0) === 0,
            'criticalThinker' => (int)($usage['hints'] ?? 0) > 0 && (int)($usage['solutions'] ?? 0) === 0,
        ];
    }

    public function adminOverview(): array
    {
        $usage = $this->db->raw(
            "SELECT COUNT(*) total_requests, COALESCE(SUM(credits), 0) credits_used,
                    COALESCE(SUM(prompt_tokens), 0) input_tokens, COALESCE(SUM(output_tokens), 0) output_tokens,
                    COALESCE(SUM(estimated_cost_usd), 0) estimated_cost_usd,
                    COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)), 0) requests_30d
             FROM ai_tutor_usage WHERE status = 'succeeded'"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $features = $this->db->raw(
            "SELECT action, COUNT(*) requests, COALESCE(SUM(credits), 0) credits
             FROM ai_tutor_usage WHERE status = 'succeeded' GROUP BY action ORDER BY action"
        )->fetchAll(PDO::FETCH_ASSOC);
        return [
            'totalRequests' => (int)($usage['total_requests'] ?? 0),
            'creditsUsed' => (int)($usage['credits_used'] ?? 0),
            'inputTokens' => (int)($usage['input_tokens'] ?? 0),
            'outputTokens' => (int)($usage['output_tokens'] ?? 0),
            'estimatedCostUsd' => (float)($usage['estimated_cost_usd'] ?? 0),
            'requestsLast30Days' => (int)($usage['requests_30d'] ?? 0),
            'features' => array_map(static fn(array $row): array => [
                'action' => $row['action'], 'requests' => (int)$row['requests'], 'credits' => (int)$row['credits'],
            ], $features),
        ];
    }
}
