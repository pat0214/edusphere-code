<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Subject classrooms and their membership workflows. */
class SubjectClassroom_model extends Model
{
    protected $table = 'classrooms';
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
             WHERE table_schema = DATABASE() AND table_name IN (?, ?, ?, ?, ?)',
            ['classrooms', 'classroom_teachers', 'classroom_members', 'classroom_audit_events', 'platform_feature_settings']
        )->fetchColumn();
        return (int)$count === 5;
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

    public function adminClassrooms(): array
    {
        $rows = $this->db->raw(
            "SELECT c.id, c.name, c.subject, c.status, c.created_at createdAt, u.name teacherName,
                    (SELECT COUNT(*) FROM classroom_members m WHERE m.classroom_id = c.id AND m.status = 'active') studentCount,
                    (SELECT COUNT(*) FROM classroom_members m WHERE m.classroom_id = c.id AND m.status = 'pending') pendingCount
             FROM classrooms c JOIN users u ON u.id = c.owner_id
             ORDER BY FIELD(c.status,'active','archived'), c.updated_at DESC LIMIT 200"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['studentCount'] = (int)$row['studentCount'];
            $row['pendingCount'] = (int)$row['pendingCount'];
        }
        unset($row);
        return $rows;
    }

    public function setAdminStatus(string $classroomId, string $adminId, string $status): bool
    {
        $classroom = $this->db->raw('SELECT owner_id FROM classrooms WHERE id = ? LIMIT 1', [$classroomId])->fetch(PDO::FETCH_ASSOC);
        if (!$classroom) return false;
        $this->db->raw('UPDATE classrooms SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $classroomId]);
        $this->audit($classroomId, $adminId, $classroom['owner_id'], 'admin_status_changed', ['status' => $status]);
        return true;
    }

    public function listForUser(array $user): array
    {
        if ($user['role'] === 'instructor') {
            $rows = $this->db->raw(
                "SELECT c.id, c.name, c.subject, c.description, c.grade_level gradeLevel, c.theme_color themeColor,
                        c.icon, c.approval_mode approvalMode, c.status, c.created_at createdAt,
                        (SELECT COUNT(*) FROM classroom_members m WHERE m.classroom_id = c.id AND m.status = 'active') studentCount,
                        (SELECT COUNT(*) FROM classroom_members m WHERE m.classroom_id = c.id AND m.status = 'pending') pendingCount,
                        ct.role teacherRole
                 FROM classrooms c JOIN classroom_teachers ct ON ct.classroom_id = c.id
                 WHERE ct.user_id = ? AND c.status <> 'deleted'
                 ORDER BY FIELD(c.status, 'active', 'archived'), c.updated_at DESC",
                [$user['id']]
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $rows = $this->db->raw(
                "SELECT c.id, c.name, c.subject, c.description, c.grade_level gradeLevel, c.theme_color themeColor,
                        c.icon, c.status, c.created_at createdAt, m.status membershipStatus, m.requested_at requestedAt,
                        u.name teacherName, u.avatar teacherAvatar
                 FROM classroom_members m
                 JOIN classrooms c ON c.id = m.classroom_id
                 JOIN users u ON u.id = c.owner_id
                 WHERE m.user_id = ? AND c.status = 'active' AND m.status IN ('active', 'pending')
                 ORDER BY m.requested_at DESC",
                [$user['id']]
            )->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($rows as &$row) {
            if (isset($row['studentCount'])) $row['studentCount'] = (int)$row['studentCount'];
            if (isset($row['pendingCount'])) $row['pendingCount'] = (int)$row['pendingCount'];
        }
        unset($row);
        return $rows;
    }

    public function createClassroom(array $input, string $ownerId): string
    {
        $id = uuid4();
        $joinCode = strtoupper(bin2hex(random_bytes(4)));
        $token = bin2hex(random_bytes(32));
        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO classrooms (id, owner_id, name, subject, description, grade_level, theme_color, icon, approval_mode, max_students, join_code, invite_token)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $ownerId, $input['name'], $input['subject'], $input['description'], $input['gradeLevel'],
                    $input['themeColor'], $input['icon'], $input['approvalMode'], $input['maxStudents'], $joinCode, $token]
            );
            $this->db->raw(
                "INSERT INTO classroom_teachers (classroom_id, user_id, role) VALUES (?, ?, 'owner')",
                [$id, $ownerId]
            );
            $this->audit($id, $ownerId, null, 'created', ['name' => $input['name']]);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
        return $id;
    }

    public function detail(string $id, array $user): ?array
    {
        $row = $this->db->raw(
            "SELECT c.id, c.owner_id ownerId, c.name, c.subject, c.description, c.grade_level gradeLevel,
                    c.theme_color themeColor, c.icon, c.approval_mode approvalMode, c.max_students maxStudents,
                    c.status, c.invitations_enabled invitationsEnabled, c.created_at createdAt,
                    (SELECT COUNT(*) FROM classroom_members m WHERE m.classroom_id = c.id AND m.status = 'active') studentCount,
                    (SELECT COUNT(*) FROM classroom_members m WHERE m.classroom_id = c.id AND m.status = 'pending') pendingCount,
                    ct.role teacherRole, m.status membershipStatus
             FROM classrooms c
             LEFT JOIN classroom_teachers ct ON ct.classroom_id = c.id AND ct.user_id = ?
             LEFT JOIN classroom_members m ON m.classroom_id = c.id AND m.user_id = ?
             WHERE c.id = ? AND c.status <> 'deleted' LIMIT 1",
            [$user['id'], $user['id'], $id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if (!$row['teacherRole'] && !in_array($row['membershipStatus'], ['active', 'pending'], true)) return null;
        $row['studentCount'] = (int)$row['studentCount'];
        $row['pendingCount'] = (int)$row['pendingCount'];
        $row['maxStudents'] = $row['maxStudents'] === null ? null : (int)$row['maxStudents'];
        $row['invitationsEnabled'] = (bool)$row['invitationsEnabled'];
        return $row;
    }

    public function updateClassroom(string $id, string $ownerId, array $updates): bool
    {
        if (!$this->isTeacher($id, $ownerId)) return false;
        $columns = [
            'name' => 'name', 'subject' => 'subject', 'description' => 'description',
            'gradeLevel' => 'grade_level', 'themeColor' => 'theme_color',
            'approvalMode' => 'approval_mode', 'maxStudents' => 'max_students',
        ];
        $sets = [];
        $values = [];
        foreach ($columns as $key => $column) {
            if (array_key_exists($key, $updates)) {
                $sets[] = "$column = ?";
                $values[] = $updates[$key];
            }
        }
        if (!$sets) return false;
        $values[] = $id;
        $this->db->raw('UPDATE classrooms SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?', $values);
        return true;
    }

    public function setStatus(string $id, string $ownerId, string $status): bool
    {
        if (!$this->isTeacher($id, $ownerId)) return false;
        $this->db->raw('UPDATE classrooms SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);
        return true;
    }

    public function members(string $id): array
    {
        return $this->db->raw(
            "SELECT m.id, m.user_id userId, u.name, u.email, u.avatar, m.status, m.requested_at requestedAt,
                    m.decided_at decidedAt, m.blocked_from_rejoining blockedFromRejoining
             FROM classroom_members m JOIN users u ON u.id = m.user_id
             WHERE m.classroom_id = ? AND m.status IN ('pending','active','declined','removed','left')
             ORDER BY FIELD(m.status,'pending','active','declined','removed','left'), m.requested_at DESC",
            [$id]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function inviteDetails(string $id, string $ownerId): ?array
    {
        return $this->db->raw(
            'SELECT id, join_code joinCode, invite_token inviteToken, invitations_enabled invitationsEnabled,
                    invite_expires_at expiresAt, invite_max_uses maxUses, invite_uses uses
             FROM classrooms WHERE id = ? AND owner_id = ? AND status = \'active\' LIMIT 1',
            [$id, $ownerId]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateInvite(string $id, string $ownerId, array $input): ?array
    {
        if (!$this->isTeacher($id, $ownerId)) return null;
        $sets = [];
        $values = [];
        if (array_key_exists('enabled', $input)) {
            $sets[] = 'invitations_enabled = ?';
            $values[] = $input['enabled'] ? 1 : 0;
        }
        if (array_key_exists('expiresAt', $input)) {
            $sets[] = 'invite_expires_at = ?';
            $values[] = $input['expiresAt'];
        }
        if (array_key_exists('maxUses', $input)) {
            $sets[] = 'invite_max_uses = ?';
            $values[] = $input['maxUses'];
        }
        if (($input['reset'] ?? false) === true) {
            $sets[] = 'join_code = ?';
            $values[] = strtoupper(bin2hex(random_bytes(4)));
            $sets[] = 'invite_token = ?';
            $values[] = bin2hex(random_bytes(32));
            $sets[] = 'invite_uses = 0';
        }
        if ($sets) {
            $values[] = $id;
            $this->db->raw('UPDATE classrooms SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?', $values);
        }
        return $this->inviteDetails($id, $ownerId);
    }

    public function invitePreview(string $token): ?array
    {
        return $this->db->raw(
            "SELECT c.id, c.name, c.subject, c.description, c.grade_level gradeLevel, u.name teacherName
             FROM classrooms c JOIN users u ON u.id = c.owner_id
             WHERE c.invite_token = ? AND c.status = 'active' AND c.invitations_enabled = 1
               AND (c.invite_expires_at IS NULL OR c.invite_expires_at > NOW())
               AND (c.invite_max_uses IS NULL OR c.invite_uses < c.invite_max_uses)
             LIMIT 1",
            [$token]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function joinByInvite(string $invite, string $kind, string $userId): array
    {
        $this->db->transaction();
        try {
            $column = $kind === 'code' ? 'join_code' : 'invite_token';
            $row = $this->db->raw(
                "SELECT id, owner_id, approval_mode, max_students, invite_uses, invite_max_uses
                 FROM classrooms WHERE $column = ? AND status = 'active' AND invitations_enabled = 1
                   AND (invite_expires_at IS NULL OR invite_expires_at > NOW())
                   AND (invite_max_uses IS NULL OR invite_uses < invite_max_uses)
                 LIMIT 1 FOR UPDATE",
                [$invite]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->db->roll_back();
                return ['error' => 'invalid'];
            }
            if ($row['owner_id'] === $userId) {
                $this->db->roll_back();
                return ['error' => 'owner'];
            }

            $existing = $this->db->raw(
                'SELECT id, status, blocked_from_rejoining blocked FROM classroom_members WHERE classroom_id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
                [$row['id'], $userId]
            )->fetch(PDO::FETCH_ASSOC);
            if ($existing && in_array($existing['status'], ['active', 'pending'], true)) {
                $this->db->commit();
                return ['status' => $existing['status'], 'classroomId' => $row['id'], 'duplicate' => true];
            }
            if ($existing && (bool)$existing['blocked']) {
                $this->db->roll_back();
                return ['error' => 'blocked'];
            }
            if ($row['max_students'] !== null) {
                $count = $this->db->raw(
                    "SELECT COUNT(*) count FROM classroom_members WHERE classroom_id = ? AND status = 'active'",
                    [$row['id']]
                )->fetch(PDO::FETCH_ASSOC);
                if ((int)$count['count'] >= (int)$row['max_students']) {
                    $this->db->roll_back();
                    return ['error' => 'full'];
                }
            }

            $status = $row['approval_mode'] === 'auto' ? 'active' : 'pending';
            if ($existing) {
                $this->db->raw(
                    "UPDATE classroom_members SET status = ?, requested_at = NOW(), decided_at = NULL, decided_by = NULL,
                        decline_reason = NULL WHERE id = ?",
                    [$status, $existing['id']]
                );
            } else {
                $this->db->raw(
                    'INSERT INTO classroom_members (id, classroom_id, user_id, status) VALUES (?, ?, ?, ?)',
                    [uuid4(), $row['id'], $userId, $status]
                );
            }
            $this->db->raw('UPDATE classrooms SET invite_uses = invite_uses + 1 WHERE id = ?', [$row['id']]);
            $this->audit($row['id'], $userId, $userId, $status === 'active' ? 'joined' : 'join_requested', ['source' => $kind]);
            $this->db->commit();
            return ['status' => $status, 'classroomId' => $row['id'], 'duplicate' => false];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function decideMembership(string $classroomId, string $memberId, string $ownerId, string $decision, bool $block = false, string $reason = ''): ?array
    {
        $this->db->transaction();
        try {
            $classroom = $this->db->raw(
                "SELECT c.max_students FROM classrooms c
                 JOIN classroom_teachers ct ON ct.classroom_id = c.id
                 WHERE c.id = ? AND ct.user_id = ? AND c.status = 'active' LIMIT 1 FOR UPDATE",
                [$classroomId, $ownerId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$classroom) {
                $this->db->roll_back();
                return null;
            }
            $member = $this->db->raw(
                'SELECT id, user_id FROM classroom_members WHERE classroom_id = ? AND id = ? AND status = \'pending\' LIMIT 1 FOR UPDATE',
                [$classroomId, $memberId]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$member) {
                $this->db->roll_back();
                return null;
            }
            if ($decision === 'approve' && $classroom['max_students'] !== null) {
                $count = $this->db->raw(
                    "SELECT COUNT(*) count FROM classroom_members WHERE classroom_id = ? AND status = 'active'",
                    [$classroomId]
                )->fetch(PDO::FETCH_ASSOC);
                if ((int)$count['count'] >= (int)$classroom['max_students']) {
                    $this->db->roll_back();
                    return ['error' => 'full'];
                }
            }
            $status = $decision === 'approve' ? 'active' : 'declined';
            $this->db->raw(
                'UPDATE classroom_members SET status = ?, blocked_from_rejoining = ?, decline_reason = ?, decided_at = NOW(), decided_by = ? WHERE id = ?',
                [$status, $block ? 1 : 0, $reason !== '' ? $reason : null, $ownerId, $memberId]
            );
            $this->audit($classroomId, $ownerId, $member['user_id'], 'join_' . $status);
            $this->db->commit();
            return ['userId' => (string)$member['user_id']];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function removeMember(string $classroomId, string $memberId, string $ownerId, bool $block): ?string
    {
        if (!$this->isTeacher($classroomId, $ownerId)) return null;
        $member = $this->db->raw(
            "SELECT user_id FROM classroom_members WHERE classroom_id = ? AND id = ? AND status = 'active' LIMIT 1",
            [$classroomId, $memberId]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$member) return null;
        $this->db->raw(
            "UPDATE classroom_members SET status = 'removed', blocked_from_rejoining = ?, decided_at = NOW(), decided_by = ? WHERE classroom_id = ? AND id = ?",
            [$block ? 1 : 0, $ownerId, $classroomId, $memberId]
        );
        $this->audit($classroomId, $ownerId, $member['user_id'], 'member_removed', ['blocked' => $block]);
        return (string)$member['user_id'];
    }

    public function leave(string $classroomId, string $userId): bool
    {
        $result = $this->db->raw(
            "UPDATE classroom_members SET status = 'left', decided_at = NOW(), decided_by = NULL
             WHERE classroom_id = ? AND user_id = ? AND status = 'active'",
            [$classroomId, $userId]
        );
        return $result->rowCount() > 0;
    }

    public function teacherOwns(string $classroomId, string $userId): bool
    {
        return $this->isTeacher($classroomId, $userId);
    }

    public function ownerId(string $classroomId): ?string
    {
        $row = $this->db->raw('SELECT owner_id FROM classrooms WHERE id = ? LIMIT 1', [$classroomId])->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['owner_id'] : null;
    }

    public function notificationContext(string $classroomId): ?array
    {
        return $this->db->raw(
            'SELECT c.name, c.owner_id ownerId FROM classrooms c WHERE c.id = ? LIMIT 1',
            [$classroomId]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function isTeacher(string $classroomId, string $userId): bool
    {
        return (bool)$this->db->raw(
            "SELECT 1 FROM classroom_teachers ct JOIN classrooms c ON c.id = ct.classroom_id
             WHERE ct.classroom_id = ? AND ct.user_id = ? AND c.status <> 'deleted' LIMIT 1",
            [$classroomId, $userId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    private function audit(string $classroomId, string $actorId, ?string $subjectId, string $action, ?array $detail = null): void
    {
        $this->db->raw(
            'INSERT INTO classroom_audit_events (id, classroom_id, actor_id, subject_user_id, action, detail)
             VALUES (?, ?, ?, ?, ?, ?)',
            [uuid4(), $classroomId, $actorId, $subjectId, $action, $detail === null ? null : json_encode($detail)]
        );
    }
}
