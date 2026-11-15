<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Persistence for XP, badges, notifications, and streaks. */
class Gamify_model extends Model
{
    protected $table = 'users';
    protected $primary_key = 'id';
    private $db;
    private $emailQueueReady;
    private $emailNotificationsEnabled;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function incrementXp($userId, $amount): void
    {
        $this->db->table('users')->where('id', $userId)->increment('xp', $amount);
    }

    public function xpFor($userId): int
    {
        return (int)$this->db->table('users')->select('xp')->where('id', $userId)->get()['xp'];
    }

    public function setLevel($userId, $level): void
    {
        $this->db->table('users')->where('id', $userId)->update(['level' => $level]);
    }

    public function insertNotification($userId, $type, $title, $body): bool
    {
        $notificationId = uuid4();
        if (!$this->emailQueueSchemaReady()) {
            $this->db->raw(
                'INSERT INTO notifications (id, user_id, type, title, body, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$notificationId, $userId, $type, $title, $body]
            );
            return false;
        }
        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO notifications (id, user_id, type, title, body, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$notificationId, $userId, $type, $title, $body]
            );
            $recipient = $this->notificationRecipient($userId);
            if ($recipient && (int)$recipient['email_verified'] === 1 && $this->emailNotificationsEnabled()) {
                $preferences = $this->notificationPreferences($userId);
                $isClassroom = str_starts_with((string)$type, 'classroom_');
                $emailEnabled = (int)($preferences['emailEnabled'] ?? 1) === 1
                    && (!$isClassroom || (int)($preferences['classroomEmailEnabled'] ?? 1) === 1);
                if ($emailEnabled) {
                    $html = '<div style="font-family:system-ui;color:#12142b"><h2>' .
                        htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2><p>' .
                        nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')) .
                        '</p><p>— EduSphere</p></div>';
                    $this->db->raw(
                        'INSERT INTO notification_email_queue (id, notification_id, user_id, recipient_email, subject, html_body, category)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [uuid4(), $notificationId, $userId, $recipient['email'], $title, $html, $isClassroom ? 'classroom' : 'system']
                    );
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return true;
    }

    public function emailQueueSchemaReady(): bool
    {
        if ($this->emailQueueReady !== null) return $this->emailQueueReady;
        $count = $this->db->raw(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?)',
            ['user_notification_preferences', 'notification_email_queue', 'platform_feature_settings']
        )->fetchColumn();
        $this->emailQueueReady = (int)$count === 3;
        return $this->emailQueueReady;
    }

    public function emailNotificationsEnabled(): bool
    {
        if ($this->emailNotificationsEnabled !== null) return $this->emailNotificationsEnabled;
        $row = $this->db->raw('SELECT email_notifications_enabled FROM platform_feature_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $this->emailNotificationsEnabled = !$row || (int)$row['email_notifications_enabled'] === 1;
        return $this->emailNotificationsEnabled;
    }

    public function notificationRecipient($userId)
    {
        return $this->db->raw(
            'SELECT email, email_verified FROM users WHERE id = ? LIMIT 1',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function notificationPreferences(string $userId): ?array
    {
        return $this->db->raw(
            'SELECT email_enabled emailEnabled, classroom_email_enabled classroomEmailEnabled
             FROM user_notification_preferences WHERE user_id = ? LIMIT 1',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function saveNotificationPreferences(string $userId, array $updates): array
    {
        $current = $this->notificationPreferences($userId) ?? ['emailEnabled' => 1, 'classroomEmailEnabled' => 1];
        $emailEnabled = array_key_exists('emailEnabled', $updates) ? ($updates['emailEnabled'] ? 1 : 0) : (int)$current['emailEnabled'];
        $classroomEnabled = array_key_exists('classroomEmailEnabled', $updates) ? ($updates['classroomEmailEnabled'] ? 1 : 0) : (int)$current['classroomEmailEnabled'];
        $this->db->raw(
            'INSERT INTO user_notification_preferences (user_id, email_enabled, classroom_email_enabled)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE email_enabled = VALUES(email_enabled), classroom_email_enabled = VALUES(classroom_email_enabled), updated_at = NOW()',
            [$userId, $emailEnabled, $classroomEnabled]
        );
        if (!$emailEnabled) {
            $this->db->raw(
                "UPDATE notification_email_queue SET status = 'cancelled', locked_at = NULL
                 WHERE user_id = ? AND status IN ('pending','processing')",
                [$userId]
            );
        } elseif (!$classroomEnabled) {
            $this->db->raw(
                "UPDATE notification_email_queue SET status = 'cancelled', locked_at = NULL
                 WHERE user_id = ? AND category = 'classroom' AND status IN ('pending','processing')",
                [$userId]
            );
        }
        return ['emailEnabled' => (bool)$emailEnabled, 'classroomEmailEnabled' => (bool)$classroomEnabled];
    }

    public function claimNotificationEmails(int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $this->db->transaction();
        try {
            $rows = $this->db->raw(
                "SELECT q.id, q.recipient_email recipientEmail, q.subject, q.html_body htmlBody, q.attempts
                 FROM notification_email_queue q
                 LEFT JOIN user_notification_preferences p ON p.user_id = q.user_id
                 JOIN platform_feature_settings f ON f.id = 1
                 WHERE ((q.status = 'pending' AND q.available_at <= NOW())
                    OR (q.status = 'processing' AND q.locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)))
                 AND f.email_notifications_enabled = 1
                 AND COALESCE(p.email_enabled, 1) = 1
                 AND (q.category = 'system' OR COALESCE(p.classroom_email_enabled, 1) = 1)
                 ORDER BY q.available_at, q.created_at
                 LIMIT $limit FOR UPDATE SKIP LOCKED"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $this->db->raw(
                    "UPDATE notification_email_queue SET status = 'processing', locked_at = NOW(), attempts = attempts + 1
                     WHERE id = ?",
                    [$row['id']]
                );
            }
            $this->db->commit();
            foreach ($rows as &$row) $row['attempts'] = (int)$row['attempts'] + 1;
            unset($row);
            return $rows;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function markNotificationEmailSent(string $id): void
    {
        $this->db->raw(
            "UPDATE notification_email_queue SET status = 'sent', sent_at = NOW(), locked_at = NULL, last_error = NULL
             WHERE id = ? AND status = 'processing'",
            [$id]
        );
    }

    public function markNotificationEmailFailed(string $id, int $attempts, string $reason): void
    {
        $status = $attempts >= 5 ? 'failed' : 'pending';
        $delay = min(3600, 60 * (2 ** max(0, $attempts - 1)));
        $availableAt = date('Y-m-d H:i:s', time() + $delay);
        $this->db->raw(
            'UPDATE notification_email_queue SET status = ?, available_at = ?, locked_at = NULL, last_error = ?
             WHERE id = ? AND status = \'processing\'',
            [$status, $availableAt, substr($reason, 0, 500), $id]
        );
    }

    public function emailQueueCounts(): array
    {
        $rows = $this->db->raw(
            'SELECT status, COUNT(*) total FROM notification_email_queue GROUP BY status'
        )->fetchAll(PDO::FETCH_ASSOC);
        $counts = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($rows as $row) $counts[$row['status']] = (int)$row['total'];
        return $counts;
    }

    public function badgeByCode($code)
    {
        return $this->db->table('badges')->where('code', $code)->limit(1)->get();
    }

    public function ownsBadge($userId, $badgeId): bool
    {
        return (bool)$this->db->table('user_badges')
            ->select('id')
            ->where(['user_id' => $userId, 'badge_id' => $badgeId])
            ->limit(1)
            ->get();
    }

    public function insertBadge($userId, $badgeId): void
    {
        $this->db->raw(
            'INSERT INTO user_badges (id, user_id, badge_id, awarded_at) VALUES (?, ?, ?, NOW())',
            [uuid4(), $userId, $badgeId]
        );
    }

    public function streakFor($userId)
    {
        return $this->db->table('streaks')->where('user_id', $userId)->limit(1)->get();
    }

    public function insertStreak($userId, $today): void
    {
        $this->db->raw(
            'INSERT INTO streaks (id, user_id, current, longest, last_activity_date) VALUES (?, ?, 1, 1, ?)',
            [uuid4(), $userId, $today]
        );
    }

    public function updateStreak($userId, $current, $longest, $today): void
    {
        $this->db->table('streaks')
            ->where('user_id', $userId)
            ->update(['current' => $current, 'longest' => $longest, 'last_activity_date' => $today]);
    }
}
