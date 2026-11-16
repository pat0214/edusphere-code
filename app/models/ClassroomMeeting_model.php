<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Google account state, live sessions, and click-based attendance records. */
class ClassroomMeeting_model extends Model
{
    protected $table = 'classroom_live_sessions';
    protected $primary_key = 'id';
    private $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = load_class('database', 'database')::instance(NULL);
    }

    public function schemaReady(): bool
    {
        $count = $this->db->raw(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'classrooms', 'classroom_teachers', 'classroom_members', 'classroom_audit_events',
                'classroom_live_sessions', 'classroom_session_attendance', 'classroom_google_accounts',
                'classroom_google_oauth_states', 'platform_feature_settings',
            ]
        )->fetchColumn();
        return (int)$count === 9;
    }

    public function jitsiSchemaReady(): bool
    {
        $count = $this->db->raw(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name IN (?, ?)',
            ['classroom_meeting_settings', 'classroom_jitsi_sessions']
        )->fetchColumn();
        return (int)$count === 2;
    }

    public function scheduledSessionSchemaReady(): bool
    {
        $type = $this->db->raw(
            "SELECT column_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'classroom_live_sessions' AND column_name = 'status' LIMIT 1"
        )->fetchColumn();
        return is_string($type) && strpos($type, "'scheduled'") !== false;
    }

    public function meetingSettings(): array
    {
        $row = $this->db->raw(
            'SELECT provider, domain FROM classroom_meeting_settings WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Jitsi settings are missing. Run migration 009_jitsi_live_sessions.sql.');
        return $row;
    }

    public function updateMeetingSettings(string $provider, string $domain, string $adminId): array
    {
        $this->db->raw(
            'UPDATE classroom_meeting_settings SET provider = ?, domain = ?, updated_by = ?, updated_at = NOW() WHERE id = 1',
            [$provider, $domain, $adminId]
        );
        return $this->meetingSettings();
    }

    public function platformSettings(): array
    {
        $row = $this->db->raw(
            'SELECT classrooms_enabled classroomsEnabled, live_sessions_enabled liveSessionsEnabled,
                    email_notifications_enabled emailNotificationsEnabled, max_students_per_classroom maxStudentsPerClassroom,
                    max_live_session_minutes maxLiveSessionMinutes
             FROM platform_feature_settings WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Platform settings are missing. Run migration 008_classrooms.sql.');
        foreach (['classroomsEnabled', 'liveSessionsEnabled', 'emailNotificationsEnabled'] as $key) $row[$key] = (bool)$row[$key];
        foreach (['maxStudentsPerClassroom', 'maxLiveSessionMinutes'] as $key) $row[$key] = (int)$row[$key];
        return $row;
    }

    public function updatePlatformSettings(array $values, string $adminId): array
    {
        $this->db->raw(
            'UPDATE platform_feature_settings SET classrooms_enabled = ?, live_sessions_enabled = ?,
                email_notifications_enabled = ?, max_students_per_classroom = ?, max_live_session_minutes = ?, updated_by = ?, updated_at = NOW()
             WHERE id = 1',
            [
                $values['classroomsEnabled'] ? 1 : 0, $values['liveSessionsEnabled'] ? 1 : 0,
                $values['emailNotificationsEnabled'] ? 1 : 0, $values['maxStudentsPerClassroom'],
                $values['maxLiveSessionMinutes'], $adminId,
            ]
        );
        if (!$values['emailNotificationsEnabled']) {
            $queue = $this->db->raw(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = ?',
                ['notification_email_queue']
            )->fetchColumn();
            if ((int)$queue === 1) {
                $this->db->raw(
                    "UPDATE notification_email_queue SET status = 'cancelled', locked_at = NULL WHERE status IN ('pending','processing')"
                );
            }
        }
        return $this->platformSettings();
    }

    public function encryptionReady(): bool
    {
        $configured = getenv('GOOGLE_TOKEN_ENCRYPTION_KEY');
        $key = is_string($configured) ? base64_decode($configured, true) : false;
        return is_string($key) && strlen($key) === 32;
    }

    public function storeOAuthState(string $userId, string $stateHash, string $verifier): void
    {
        $this->db->raw('DELETE FROM classroom_google_oauth_states WHERE expires_at < NOW() OR user_id = ?', [$userId]);
        $this->db->raw(
            'INSERT INTO classroom_google_oauth_states (id, user_id, state_hash, code_verifier, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))',
            [uuid4(), $userId, $stateHash, $verifier]
        );
    }

    public function consumeOAuthState(string $stateHash): ?array
    {
        $this->db->transaction();
        try {
            $row = $this->db->raw(
                'SELECT id, user_id userId, code_verifier codeVerifier
                 FROM classroom_google_oauth_states WHERE state_hash = ? AND expires_at > NOW() LIMIT 1 FOR UPDATE',
                [$stateHash]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->roll_back();
                return null;
            }
            $this->db->raw('DELETE FROM classroom_google_oauth_states WHERE id = ?', [$row['id']]);
            $this->db->commit();
            unset($row['id']);
            return $row;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function saveGoogleAccount(string $userId, string $refreshToken, ?string $email): void
    {
        if ($refreshToken === '') {
            $existing = $this->db->raw(
                'SELECT user_id FROM classroom_google_accounts WHERE user_id = ? LIMIT 1',
                [$userId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$existing) throw new RuntimeException('Google did not issue a refresh token. Reconnect and approve offline access.');
            $this->db->raw(
                'UPDATE classroom_google_accounts SET google_email = ?, updated_at = NOW() WHERE user_id = ?',
                [$email, $userId]
            );
            return;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($refreshToken, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext) || strlen($tag) !== 16) throw new RuntimeException('Google token could not be encrypted.');
        $this->db->raw(
            'INSERT INTO classroom_google_accounts (user_id, encrypted_refresh_token, token_nonce, google_email)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE encrypted_refresh_token = VALUES(encrypted_refresh_token), token_nonce = VALUES(token_nonce),
                 google_email = VALUES(google_email), updated_at = NOW()',
            [$userId, base64_encode($ciphertext . $tag), $nonce, $email]
        );
    }

    public function googleAccount(string $userId): ?array
    {
        $row = $this->db->raw(
            'SELECT encrypted_refresh_token encryptedRefreshToken, token_nonce tokenNonce, google_email googleEmail
             FROM classroom_google_accounts WHERE user_id = ? LIMIT 1',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $combined = base64_decode((string)$row['encryptedRefreshToken'], true);
        if (!is_string($combined) || strlen($combined) < 17) throw new RuntimeException('Stored Google authorization is invalid.');
        $tag = substr($combined, -16);
        $ciphertext = substr($combined, 0, -16);
        $refreshToken = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, (string)$row['tokenNonce'], $tag);
        if (!is_string($refreshToken) || $refreshToken === '') throw new RuntimeException('Stored Google authorization could not be decrypted. Reconnect the account.');
        return ['refreshToken' => $refreshToken, 'googleEmail' => $row['googleEmail']];
    }

    public function googleConnection(string $userId): array
    {
        $row = $this->db->raw(
            'SELECT google_email googleEmail, connected_at connectedAt FROM classroom_google_accounts WHERE user_id = ? LIMIT 1',
            [$userId]
        )->fetch(PDO::FETCH_ASSOC);
        return ['connected' => (bool)$row, 'googleEmail' => $row['googleEmail'] ?? null, 'connectedAt' => $row['connectedAt'] ?? null];
    }

    public function disconnectGoogle(string $userId): void
    {
        $this->db->raw('DELETE FROM classroom_google_accounts WHERE user_id = ?', [$userId]);
    }

    public function prepareSession(string $classroomId, string $hostId, string $requestId, string $title, string $description, int $durationMinutes, array $meeting): array
    {
        $this->db->transaction();
        try {
            $classroom = $this->db->raw(
                "SELECT c.id, c.name FROM classrooms c
                 WHERE c.id = ? AND c.status = 'active'
                   AND (
                     EXISTS (SELECT 1 FROM classroom_teachers ct WHERE ct.classroom_id = c.id AND ct.user_id = ?)
                     OR EXISTS (SELECT 1 FROM classroom_members m WHERE m.classroom_id = c.id AND m.user_id = ? AND m.status = 'active')
                   )
                 LIMIT 1 FOR UPDATE",
                [$classroomId, $hostId, $hostId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$classroom) {
                $this->db->roll_back();
                return ['error' => 'not_found'];
            }
            if ($title === '') $title = $classroom['name'] . ' - Live Session';
            $this->db->raw(
                "UPDATE classroom_live_sessions SET status = 'ended', meet_url = NULL, updated_at = NOW()
                 WHERE classroom_id = ? AND status IN ('provisioning','scheduled','live') AND ends_at <= NOW()",
                [$classroomId]
            );
            $this->db->raw(
                "UPDATE classroom_live_sessions SET status = 'live', updated_at = NOW()
                 WHERE classroom_id = ? AND status = 'scheduled' AND starts_at <= NOW() AND ends_at > NOW()",
                [$classroomId]
            );
            $active = $this->db->raw(
                "SELECT s.id, s.google_event_id googleEventId, s.request_id requestId, s.status, s.title,
                        s.starts_at startsAt, s.ends_at endsAt, j.provider, j.domain, j.room_name roomName
                 FROM classroom_live_sessions s
                 JOIN classroom_jitsi_sessions j ON j.session_id = s.id
                 WHERE s.classroom_id = ? AND s.status IN ('provisioning','live') AND s.ends_at > NOW()
                 ORDER BY s.created_at DESC LIMIT 1 FOR UPDATE",
                [$classroomId]
            )->fetch(PDO::FETCH_ASSOC);
            if ($active) {
                $this->db->commit();
                return ['session' => $active, 'classroomName' => $classroom['name'], 'duplicate' => true];
            }
            $existing = $this->db->raw(
                'SELECT id, google_event_id googleEventId, request_id requestId, status, title, starts_at startsAt, ends_at endsAt
                 FROM classroom_live_sessions WHERE classroom_id = ? AND request_id = ? LIMIT 1 FOR UPDATE',
                [$classroomId, $requestId]
            )->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $jitsi = $this->db->raw(
                    'SELECT provider, domain, room_name roomName FROM classroom_jitsi_sessions WHERE session_id = ? LIMIT 1',
                    [$existing['id']]
                )->fetch(PDO::FETCH_ASSOC);
                if ($jitsi && $existing['status'] === 'live' && strtotime($existing['endsAt'] . ' UTC') > time()) {
                    $existing = array_merge($existing, $jitsi);
                    $this->db->commit();
                    return ['session' => $existing, 'classroomName' => $classroom['name'], 'duplicate' => true];
                }
                $this->db->roll_back();
                return ['error' => 'request_expired'];
            }
            $start = time();
            $startsAt = gmdate('Y-m-d H:i:s', $start);
            $endsAt = gmdate('Y-m-d H:i:s', $start + $durationMinutes * 60);
            $conflict = $this->db->raw(
                "SELECT id FROM classroom_live_sessions
                 WHERE classroom_id = ? AND status = 'scheduled'
                   AND starts_at < ? AND ends_at > ? LIMIT 1 FOR UPDATE",
                [$classroomId, $endsAt, $startsAt]
            )->fetch(PDO::FETCH_ASSOC);
            if ($conflict) {
                $this->db->roll_back();
                return ['error' => 'schedule_conflict'];
            }
            $sessionId = uuid4();
            $eventId = $this->googleEventId();
            $this->db->raw(
                'INSERT INTO classroom_live_sessions
                 (id, classroom_id, created_by, title, description, status, starts_at, ends_at, google_event_id, request_id)
                 VALUES (?, ?, ?, ?, ?, \'live\', ?, ?, ?, ?)',
                [$sessionId, $classroomId, $hostId, $title, $description, $startsAt, $endsAt, $eventId, $requestId]
            );
            $this->db->raw(
                'INSERT INTO classroom_jitsi_sessions (session_id, provider, domain, room_name) VALUES (?, ?, ?, ?)',
                [$sessionId, $meeting['provider'], $meeting['domain'], $meeting['roomName']]
            );
            $session = [
                'id' => $sessionId, 'googleEventId' => $eventId, 'requestId' => $requestId, 'status' => 'live',
                'title' => $title, 'startsAt' => $startsAt, 'endsAt' => $endsAt,
                'provider' => $meeting['provider'], 'domain' => $meeting['domain'], 'roomName' => $meeting['roomName'],
            ];
            $this->db->commit();
            return ['session' => $session, 'classroomName' => $classroom['name'], 'duplicate' => false];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function scheduleSession(
        string $classroomId,
        string $teacherId,
        string $requestId,
        string $title,
        string $description,
        int $durationMinutes,
        string $startsAt,
        array $meeting
    ): array {
        $this->db->transaction();
        try {
            $classroom = $this->db->raw(
                "SELECT c.id, c.name FROM classrooms c JOIN classroom_teachers ct ON ct.classroom_id = c.id
                 WHERE c.id = ? AND ct.user_id = ? AND c.status = 'active' LIMIT 1 FOR UPDATE",
                [$classroomId, $teacherId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$classroom) {
                $this->db->roll_back();
                return ['error' => 'not_found'];
            }
            if ($title === '') $title = $classroom['name'] . ' - Live Session';

            $existing = $this->db->raw(
                'SELECT id, google_event_id googleEventId, request_id requestId, status, title,
                        starts_at startsAt, ends_at endsAt
                 FROM classroom_live_sessions WHERE classroom_id = ? AND request_id = ? LIMIT 1 FOR UPDATE',
                [$classroomId, $requestId]
            )->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $jitsi = $this->db->raw(
                    'SELECT provider, domain, room_name roomName FROM classroom_jitsi_sessions WHERE session_id = ? LIMIT 1',
                    [$existing['id']]
                )->fetch(PDO::FETCH_ASSOC);
                if ($jitsi && in_array($existing['status'], ['scheduled', 'live'], true)
                    && strtotime($existing['endsAt'] . ' UTC') > time()) {
                    $existing = array_merge($existing, $jitsi);
                    $this->db->commit();
                    return ['session' => $existing, 'classroomName' => $classroom['name'], 'duplicate' => true];
                }
                $this->db->roll_back();
                return ['error' => 'request_expired'];
            }

            $startTimestamp = strtotime($startsAt . ' UTC');
            $endsAt = gmdate('Y-m-d H:i:s', $startTimestamp + $durationMinutes * 60);
            $conflict = $this->db->raw(
                "SELECT id FROM classroom_live_sessions
                 WHERE classroom_id = ? AND status IN ('scheduled','provisioning','live')
                   AND starts_at < ? AND ends_at > ? LIMIT 1 FOR UPDATE",
                [$classroomId, $endsAt, $startsAt]
            )->fetch(PDO::FETCH_ASSOC);
            if ($conflict) {
                $this->db->roll_back();
                return ['error' => 'schedule_conflict'];
            }

            $sessionId = uuid4();
            $eventId = $this->googleEventId();
            $this->db->raw(
                "INSERT INTO classroom_live_sessions
                 (id, classroom_id, created_by, title, description, status, starts_at, ends_at, google_event_id, request_id)
                 VALUES (?, ?, ?, ?, ?, 'scheduled', ?, ?, ?, ?)",
                [$sessionId, $classroomId, $teacherId, $title, $description, $startsAt, $endsAt, $eventId, $requestId]
            );
            $this->db->raw(
                'INSERT INTO classroom_jitsi_sessions (session_id, provider, domain, room_name) VALUES (?, ?, ?, ?)',
                [$sessionId, $meeting['provider'], $meeting['domain'], $meeting['roomName']]
            );
            $session = [
                'id' => $sessionId, 'googleEventId' => $eventId, 'requestId' => $requestId,
                'status' => 'scheduled', 'title' => $title, 'startsAt' => $startsAt, 'endsAt' => $endsAt,
                'provider' => $meeting['provider'], 'domain' => $meeting['domain'], 'roomName' => $meeting['roomName'],
            ];
            $this->db->commit();
            return ['session' => $session, 'classroomName' => $classroom['name'], 'duplicate' => false];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function setCalendarEvent(string $sessionId, string $eventId): void
    {
        $this->db->raw(
            "UPDATE classroom_live_sessions SET google_event_id = ?, updated_at = NOW()
             WHERE id = ? AND status IN ('scheduled','live')",
            [$eventId, $sessionId]
        );
    }

    public function markSessionFailed(string $sessionId): void
    {
        $this->db->raw(
            "UPDATE classroom_live_sessions SET status = 'failed', updated_at = NOW() WHERE id = ? AND status = 'provisioning'",
            [$sessionId]
        );
    }

    public function sessionsForClassroom(string $classroomId, string $userId): ?array
    {
        $allowed = $this->db->raw(
            "SELECT 1 FROM classroom_teachers ct JOIN classrooms c ON c.id = ct.classroom_id
             WHERE ct.classroom_id = ? AND ct.user_id = ? AND c.status = 'active'
             UNION
             SELECT 1 FROM classroom_members m JOIN classrooms c ON c.id = m.classroom_id
             WHERE m.classroom_id = ? AND m.user_id = ? AND m.status = 'active' AND c.status = 'active'
             LIMIT 1",
            [$classroomId, $userId, $classroomId, $userId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$allowed) return null;
        $isTeacher = (bool)$this->db->raw(
            'SELECT 1 FROM classroom_teachers WHERE classroom_id = ? AND user_id = ? LIMIT 1',
            [$classroomId, $userId]
        )->fetch(PDO::FETCH_ASSOC);
        $this->expireSessions($classroomId);
        $rows = $this->db->raw(
            "SELECT s.id, s.request_id requestId, s.title, s.description, s.status, s.starts_at startsAt, s.ends_at endsAt,
                    u.name teacherName,
                    EXISTS(SELECT 1 FROM classroom_session_attendance a WHERE a.session_id = s.id AND a.user_id = ?) attended,
                    (SELECT COUNT(*) FROM classroom_session_attendance a WHERE a.session_id = s.id) attendanceCount
             FROM classroom_live_sessions s JOIN users u ON u.id = s.created_by
             WHERE s.classroom_id = ?
             ORDER BY s.starts_at DESC LIMIT 50",
            [$userId, $classroomId]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['attended'] = (bool)$row['attended'];
            $row['attendanceCount'] = $isTeacher ? (int)$row['attendanceCount'] : 0;
        }
        unset($row);
        return $rows;
    }

    public function joinSession(string $sessionId, string $userId): ?array
    {
        $this->db->raw(
            "UPDATE classroom_live_sessions SET status = 'ended', meet_url = NULL, updated_at = NOW()
             WHERE id = ? AND status IN ('provisioning','scheduled','live') AND ends_at <= NOW()",
            [$sessionId]
        );
        $this->db->raw(
            "UPDATE classroom_live_sessions SET status = 'live', updated_at = NOW()
             WHERE id = ? AND status = 'scheduled' AND starts_at <= NOW() AND ends_at > NOW()",
            [$sessionId]
        );
        $this->db->raw(
            "UPDATE classroom_session_attendance SET left_at = COALESCE(left_at, NOW())
             WHERE session_id = ? AND left_at IS NULL
               AND EXISTS (SELECT 1 FROM classroom_live_sessions WHERE id = ? AND ends_at <= NOW())",
            [$sessionId, $sessionId]
        );
        $row = $this->db->raw(
            "SELECT s.id, s.classroom_id classroomId, s.title, s.status, s.ends_at endsAt,
                    j.provider, j.domain, j.room_name roomName, u.name displayName,
                    EXISTS(SELECT 1 FROM classroom_teachers ct WHERE ct.classroom_id = s.classroom_id AND ct.user_id = ?) isTeacher,
                    EXISTS(SELECT 1 FROM classroom_members m WHERE m.classroom_id = s.classroom_id AND m.user_id = ? AND m.status = 'active') isMember
             FROM classroom_live_sessions s
             JOIN classrooms c ON c.id = s.classroom_id AND c.status = 'active'
             JOIN classroom_jitsi_sessions j ON j.session_id = s.id
             JOIN users u ON u.id = ?
             WHERE s.id = ? LIMIT 1",
            [$userId, $userId, $userId, $sessionId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row || (!(bool)$row['isTeacher'] && !(bool)$row['isMember']) || $row['status'] !== 'live' || strtotime($row['endsAt'] . ' UTC') <= time()) return null;
        return [
            'provider' => $row['provider'], 'domain' => $row['domain'], 'roomName' => $row['roomName'],
            'endsAt' => $row['endsAt'], 'userId' => $userId, 'displayName' => $row['displayName'],
            'moderator' => (bool)$row['isTeacher'], 'title' => $row['title'],
        ];
    }

    public function recordJoin(string $sessionId, string $userId): bool
    {
        $allowed = $this->db->raw(
            "SELECT 1 FROM classroom_live_sessions s
             JOIN classrooms c ON c.id = s.classroom_id AND c.status = 'active'
             WHERE s.id = ? AND s.status = 'live' AND s.ends_at > NOW()
               AND (
                 EXISTS (SELECT 1 FROM classroom_teachers ct WHERE ct.classroom_id = s.classroom_id AND ct.user_id = ?)
                 OR EXISTS (SELECT 1 FROM classroom_members m WHERE m.classroom_id = s.classroom_id AND m.user_id = ? AND m.status = 'active')
               )
             LIMIT 1",
            [$sessionId, $userId, $userId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$allowed) return false;
        $this->db->raw(
            'INSERT INTO classroom_session_attendance (session_id, user_id, joined_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE left_at = NULL',
            [$sessionId, $userId]
        );
        return true;
    }

    public function leaveSession(string $sessionId, string $userId): bool
    {
        $result = $this->db->raw(
            'UPDATE classroom_session_attendance SET left_at = COALESCE(left_at, NOW())
             WHERE session_id = ? AND user_id = ?',
            [$sessionId, $userId]
        );
        return $result->rowCount() > 0;
    }

    public function endSession(string $sessionId, string $teacherId): ?array
    {
        $row = $this->db->raw(
            "SELECT s.id, s.google_event_id googleEventId FROM classroom_live_sessions s
             JOIN classroom_teachers ct ON ct.classroom_id = s.classroom_id
             WHERE s.id = ? AND ct.user_id = ? AND s.status IN ('live','provisioning') LIMIT 1",
            [$sessionId, $teacherId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $this->db->raw(
            "UPDATE classroom_live_sessions SET status = 'ended', meet_url = NULL, updated_at = NOW() WHERE id = ?",
            [$sessionId]
        );
        $this->db->raw('UPDATE classroom_session_attendance SET left_at = COALESCE(left_at, NOW()) WHERE session_id = ?', [$sessionId]);
        return ['googleEventId' => $row['googleEventId'] ? (string)$row['googleEventId'] : null, 'endsAt' => gmdate('Y-m-d H:i:s')];
    }

    public function adminStats(): array
    {
        $row = $this->db->raw(
            "SELECT
                (SELECT COUNT(*) FROM classrooms WHERE status = 'active') activeClassrooms,
                (SELECT COUNT(*) FROM classroom_members WHERE status = 'active') activeMemberships,
                (SELECT COUNT(*) FROM classroom_members WHERE status = 'pending') pendingRequests,
                (SELECT COUNT(*) FROM classroom_live_sessions WHERE status = 'live') liveSessions,
                (SELECT COUNT(*) FROM classroom_live_sessions WHERE status = 'ended') endedSessions,
                (SELECT COUNT(*) FROM classroom_session_attendance) joinClicks"
        )->fetch(PDO::FETCH_ASSOC);
        foreach ($row as $key => $value) $row[$key] = (int)$value;
        return $row;
    }

    public function activeMemberIds(string $classroomId): array
    {
        return array_map(
            static fn($row) => (string)$row['user_id'],
            $this->db->raw(
                "SELECT user_id FROM classroom_members WHERE classroom_id = ? AND status = 'active'",
                [$classroomId]
            )->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function sessionRecipientIds(string $classroomId): array
    {
        return array_map(
            static fn($row) => (string)$row['user_id'],
            $this->db->raw(
                "SELECT user_id FROM classroom_members WHERE classroom_id = ? AND status = 'active'
                 UNION
                 SELECT user_id FROM classroom_teachers WHERE classroom_id = ?",
                [$classroomId, $classroomId]
            )->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function activeMemberEmails(string $classroomId, int $limit = 200): array
    {
        return $this->db->raw(
            "SELECT DISTINCT u.email email, u.name displayName FROM classroom_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.classroom_id = ? AND m.status = 'active' AND u.active = 1 AND u.email_verified = 1
             ORDER BY m.requested_at LIMIT $limit",
            [$classroomId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function expireSessions(string $classroomId): void
    {
        $this->db->raw(
            "UPDATE classroom_live_sessions SET status = 'ended', meet_url = NULL, updated_at = NOW()
             WHERE classroom_id = ? AND status IN ('provisioning','scheduled','live') AND ends_at <= NOW()",
            [$classroomId]
        );
        $this->db->raw(
            "UPDATE classroom_live_sessions SET status = 'live', updated_at = NOW()
             WHERE classroom_id = ? AND status = 'scheduled' AND starts_at <= NOW() AND ends_at > NOW()",
            [$classroomId]
        );
        $this->db->raw(
            'UPDATE classroom_session_attendance a JOIN classroom_live_sessions s ON s.id = a.session_id
             SET a.left_at = COALESCE(a.left_at, s.ends_at) WHERE s.classroom_id = ? AND s.status = \'ended\'',
            [$classroomId]
        );
    }

    private function encryptionKey(): string
    {
        $configured = getenv('GOOGLE_TOKEN_ENCRYPTION_KEY');
        $key = is_string($configured) ? base64_decode($configured, true) : false;
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('GOOGLE_TOKEN_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }
        return $key;
    }

    private function googleEventId(): string
    {
        $alphabet = '0123456789abcdefghijklmnopqrstuv';
        $bytes = random_bytes(32);
        $id = '';
        for ($i = 0; $i < 32; $i++) $id .= $alphabet[ord($bytes[$i]) & 31];
        return $id;
    }
}
