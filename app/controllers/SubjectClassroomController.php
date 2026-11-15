<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SubjectClassroomController extends MY_Controller
{
    private $users;
    private $classrooms;
    private $auth;
    private $gamify;
    private $platformSettings;

    public function __construct()
    {
        parent::__construct();
        $this->users = $this->call->model('User_model');
        $this->classrooms = $this->call->model('SubjectClassroom_model');
        $this->auth = $this->call->model('Auth_model');
        $gamifyModel = $this->call->model('Gamify_model');
        $this->gamify = $this->call->library('Gamify', $gamifyModel);
        if (!$this->classrooms->schemaReady()) fail_api('Run database migration 008_classrooms.sql before using subject classrooms.', 503);
        $this->platformSettings = $this->classrooms->platformSettings();
        if (!$this->platformSettings['classroomsEnabled']) fail_api('Subject classrooms are currently disabled by an administrator.', 503);
    }

    public function index()
    {
        $user = require_role($this->users, ['student', 'instructor']);
        respond($this->classrooms->listForUser($user));
    }

    public function create()
    {
        $teacher = require_role($this->users, ['instructor']);
        $body = json_body();
        $name = trim((string)($body['name'] ?? ''));
        $subject = trim((string)($body['subject'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $gradeLevel = trim((string)($body['gradeLevel'] ?? ''));
        $themeColor = (string)($body['themeColor'] ?? '#f47762');
        $icon = (string)($body['icon'] ?? 'book-open');
        $approvalMode = (string)($body['approvalMode'] ?? 'teacher');
        $maxStudents = $body['maxStudents'] ?? $this->platformSettings['maxStudentsPerClassroom'];
        if ($maxStudents === null) $maxStudents = $this->platformSettings['maxStudentsPerClassroom'];

        if (strlen($name) < 3 || strlen($name) > 120) fail_api('Classroom name must be between 3 and 120 characters.', 422);
        if (strlen($subject) < 2 || strlen($subject) > 120) fail_api('Subject must be between 2 and 120 characters.', 422);
        if (strlen($description) > 500) fail_api('Description cannot exceed 500 characters.', 422);
        if (strlen($gradeLevel) > 80) fail_api('Grade level cannot exceed 80 characters.', 422);
        if (!preg_match('/^#[0-9a-f]{6}$/i', $themeColor)) fail_api('Choose a valid classroom color.', 422);
        if (!in_array($icon, ['book-open', 'code-2', 'flask-conical', 'calculator', 'globe-2', 'graduation-cap'], true)) fail_api('Choose a supported classroom icon.', 422);
        if (!in_array($approvalMode, ['teacher', 'auto'], true)) fail_api('Choose manual approval or automatic enrollment.', 422);
        if (!is_int($maxStudents) || $maxStudents < 1 || $maxStudents > $this->platformSettings['maxStudentsPerClassroom']) {
            fail_api('Maximum students exceeds the administrator-configured classroom limit.', 422);
        }

        $id = $this->classrooms->createClassroom([
            'name' => $name, 'subject' => $subject, 'description' => $description,
            'gradeLevel' => $gradeLevel !== '' ? $gradeLevel : null,
            'themeColor' => $themeColor, 'icon' => $icon, 'approvalMode' => $approvalMode,
            'maxStudents' => $maxStudents,
        ], $teacher['id']);
        respond(['id' => $id], 'Classroom created.', 201);
    }

    public function detail($id)
    {
        $user = require_role($this->users, ['student', 'instructor']);
        if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$id)) fail_api('Classroom not found.', 404);
        $classroom = $this->classrooms->detail($id, $user);
        if (!$classroom) fail_api('Classroom not found or you do not have access.', 404);
        respond($classroom);
    }

    public function update($id)
    {
        $teacher = require_role($this->users, ['instructor']);
        $body = json_body();
        $updates = [];
        $limits = ['name' => 120, 'subject' => 120, 'description' => 500, 'gradeLevel' => 80];
        foreach ($limits as $field => $limit) {
            if (!array_key_exists($field, $body)) continue;
            if (!is_string($body[$field])) fail_api(ucfirst($field) . ' must be text.', 422);
            $value = trim($body[$field]);
            if (($field === 'name' && strlen($value) < 3) || ($field === 'subject' && strlen($value) < 2) || strlen($value) > $limit) {
                fail_api(ucfirst($field) . ' has an invalid length.', 422);
            }
            $updates[$field] = $value;
        }
        if (array_key_exists('themeColor', $body)) {
            if (!is_string($body['themeColor']) || !preg_match('/^#[0-9a-f]{6}$/i', $body['themeColor'])) fail_api('Choose a valid classroom color.', 422);
            $updates['themeColor'] = $body['themeColor'];
        }
        if (array_key_exists('approvalMode', $body)) {
            if (!in_array($body['approvalMode'], ['teacher', 'auto'], true)) fail_api('Choose manual approval or automatic enrollment.', 422);
            $updates['approvalMode'] = $body['approvalMode'];
        }
        if (array_key_exists('maxStudents', $body)) {
            $maxStudents = $body['maxStudents'] ?? $this->platformSettings['maxStudentsPerClassroom'];
            if (!is_int($maxStudents) || $maxStudents < 1 || $maxStudents > $this->platformSettings['maxStudentsPerClassroom']) fail_api('Maximum students exceeds the administrator-configured classroom limit.', 422);
            $updates['maxStudents'] = $maxStudents;
        }
        if (!$updates) fail_api('Provide at least one valid classroom field to update.', 422);
        if (!$this->classrooms->updateClassroom($id, $teacher['id'], $updates)) fail_api('Classroom not found.', 404);
        respond(['id' => $id], 'Classroom updated.');
    }

    public function archive($id)
    {
        $teacher = require_role($this->users, ['instructor']);
        if (!$this->classrooms->setStatus($id, $teacher['id'], 'archived')) fail_api('Classroom not found.', 404);
        respond(['id' => $id], 'Classroom archived.');
    }

    public function restore($id)
    {
        $teacher = require_role($this->users, ['instructor']);
        if (!$this->classrooms->setStatus($id, $teacher['id'], 'active')) fail_api('Classroom not found.', 404);
        respond(['id' => $id], 'Classroom restored.');
    }

    public function members($id)
    {
        $teacher = require_role($this->users, ['instructor']);
        if (!$this->classrooms->teacherOwns($id, $teacher['id'])) fail_api('Classroom not found.', 404);
        respond($this->classrooms->members($id));
    }

    public function inviteDetails($id)
    {
        $teacher = require_role($this->users, ['instructor']);
        $details = $this->classrooms->inviteDetails($id, $teacher['id']);
        if (!$details) fail_api('Classroom not found.', 404);
        $details['invitationsEnabled'] = (bool)$details['invitationsEnabled'];
        $details['uses'] = (int)$details['uses'];
        respond($details);
    }

    public function updateInvite($id)
    {
        $teacher = require_role($this->users, ['instructor']);
        $body = json_body();
        $input = [];
        if (array_key_exists('enabled', $body)) {
            if (!is_bool($body['enabled'])) fail_api('Enabled must be true or false.', 422);
            $input['enabled'] = $body['enabled'];
        }
        if (array_key_exists('expiresAt', $body)) {
            if ($body['expiresAt'] !== null && (!is_string($body['expiresAt']) || strtotime($body['expiresAt']) === false || strtotime($body['expiresAt']) <= time())) {
                fail_api('Invitation expiry must be a future date and time.', 422);
            }
            $input['expiresAt'] = $body['expiresAt'] === null ? null : date('Y-m-d H:i:s', strtotime($body['expiresAt']));
        }
        if (array_key_exists('maxUses', $body)) {
            if ($body['maxUses'] !== null && (!is_int($body['maxUses']) || $body['maxUses'] < 1 || $body['maxUses'] > 50000)) fail_api('Maximum uses must be 1–50,000, or left blank.', 422);
            $input['maxUses'] = $body['maxUses'];
        }
        if (isset($body['reset'])) {
            if (!is_bool($body['reset'])) fail_api('Reset must be true or false.', 422);
            $input['reset'] = $body['reset'];
        }
        if (!$input) fail_api('Provide an invitation setting to update.', 422);
        $details = $this->classrooms->updateInvite($id, $teacher['id'], $input);
        if (!$details) fail_api('Classroom not found.', 404);
        $details['invitationsEnabled'] = (bool)$details['invitationsEnabled'];
        $details['uses'] = (int)$details['uses'];
        respond($details, 'Invitation settings updated.');
    }

    public function invitePreview($token)
    {
        if (!preg_match('/^[0-9a-f]{64}$/', (string)$token)) fail_api('Invitation is invalid or expired.', 404);
        $preview = $this->classrooms->invitePreview($token);
        if (!$preview) fail_api('Invitation is invalid or expired.', 404);
        respond($preview);
    }

    public function joinCode()
    {
        $student = require_role($this->users, ['student']);
        $this->enforceJoinRateLimit($student['id']);
        $body = json_body();
        $code = strtoupper(trim((string)($body['code'] ?? '')));
        if (!preg_match('/^[0-9A-F]{8}$/', $code)) fail_api('Enter a valid 8-character classroom code.', 422);
        $result = $this->classrooms->joinByInvite($code, 'code', $student['id']);
        $this->notifyAfterJoin($result, $student);
        $this->respondJoin($result);
    }

    public function joinInvite($token)
    {
        $student = require_role($this->users, ['student']);
        $this->enforceJoinRateLimit($student['id']);
        if (!preg_match('/^[0-9a-f]{64}$/', (string)$token)) fail_api('Invitation is invalid or expired.', 404);
        $result = $this->classrooms->joinByInvite($token, 'link', $student['id']);
        $this->notifyAfterJoin($result, $student);
        $this->respondJoin($result);
    }

    public function approve($id, $memberId)
    {
        $teacher = require_role($this->users, ['instructor']);
        $decision = $this->classrooms->decideMembership($id, $memberId, $teacher['id'], 'approve');
        if (!$decision) fail_api('Pending request not found.', 404);
        if (($decision['error'] ?? null) === 'full') fail_api('The classroom has reached its student limit.', 409);
        $studentId = $decision['userId'];
        $context = $this->classrooms->notificationContext($id);
        $this->notify($studentId, 'classroom_join_approved', 'Classroom request approved', 'Your request to join ' . ($context['name'] ?? 'a classroom') . ' was approved.');
        respond(['memberId' => $memberId], 'Student approved.');
    }

    public function decline($id, $memberId)
    {
        $teacher = require_role($this->users, ['instructor']);
        $body = json_body();
        $reason = trim((string)($body['reason'] ?? ''));
        if (strlen($reason) > 500) fail_api('Reason cannot exceed 500 characters.', 422);
        if (isset($body['blockRejoin']) && !is_bool($body['blockRejoin'])) fail_api('blockRejoin must be true or false.', 422);
        $decision = $this->classrooms->decideMembership($id, $memberId, $teacher['id'], 'decline', (bool)($body['blockRejoin'] ?? false), $reason);
        if (!$decision) {
            fail_api('Pending request not found.', 404);
        }
        $studentId = $decision['userId'];
        $context = $this->classrooms->notificationContext($id);
        $bodyText = 'Your request to join ' . ($context['name'] ?? 'a classroom') . ' was declined.';
        if ($reason !== '') $bodyText .= ' Teacher note: ' . $reason;
        $this->notify($studentId, 'classroom_join_declined', 'Classroom request update', $bodyText);
        respond(['memberId' => $memberId], 'Request declined.');
    }

    public function removeMember($id, $memberId)
    {
        $teacher = require_role($this->users, ['instructor']);
        $body = json_body();
        if (isset($body['blockRejoin']) && !is_bool($body['blockRejoin'])) fail_api('blockRejoin must be true or false.', 422);
        $studentId = $this->classrooms->removeMember($id, $memberId, $teacher['id'], (bool)($body['blockRejoin'] ?? false));
        if (!$studentId) fail_api('Active student not found.', 404);
        $context = $this->classrooms->notificationContext($id);
        $this->notify($studentId, 'classroom_student_removed', 'Classroom membership update', 'You were removed from ' . ($context['name'] ?? 'a classroom') . '.');
        respond(['memberId' => $memberId], 'Student removed.');
    }

    public function leave($id)
    {
        $student = require_role($this->users, ['student']);
        $ownerId = $this->classrooms->ownerId($id);
        if (!$this->classrooms->leave($id, $student['id'])) fail_api('Active classroom membership not found.', 404);
        if ($ownerId) $this->notify($ownerId, 'classroom_student_left', 'Student left a classroom', $student['name'] . ' left one of your classrooms.');
        respond(['classroomId' => $id], 'You left the classroom.');
    }

    private function notifyAfterJoin(array $result, array $student): void
    {
        if (isset($result['error']) || !empty($result['duplicate'])) return;
        $context = $this->classrooms->notificationContext((string)$result['classroomId']);
        if (!$context) return;
        if ($result['status'] === 'pending') {
            $this->notify($context['ownerId'], 'classroom_join_request', 'New classroom join request', $student['name'] . ' requested to join ' . $context['name'] . '.');
        } else {
            $this->notify($student['id'], 'classroom_joined', 'You joined a classroom', 'You are now a member of ' . $context['name'] . '.');
        }
    }

    private function notify(string $userId, string $type, string $title, string $body): void
    {
        $this->gamify->notify($userId, $type, $title, $body);
    }

    private function enforceJoinRateLimit(string $userId): void
    {
        $ip = (string)($this->request->ip_address() ?: 'unknown');
        if (rate_limited($this->auth, 'classroom_join_user:' . $userId, 12, 3600)
            || rate_limited($this->auth, 'classroom_join_ip:' . hash('sha256', $ip), 100, 3600)) {
            fail_api('Too many join attempts. Please try again later.', 429);
        }
    }

    private function respondJoin(array $result): void
    {
        $errors = [
            'invalid' => ['Invitation is invalid, disabled, expired, or has reached its use limit.', 404],
            'owner' => ['You cannot join a classroom that you teach.', 409],
            'blocked' => ['You are not allowed to rejoin this classroom.', 403],
            'full' => ['This classroom has reached its student limit.', 409],
        ];
        if (isset($result['error'])) {
            [$message, $status] = $errors[$result['error']] ?? ['Could not join this classroom.', 400];
            fail_api($message, $status);
        }
        $message = $result['status'] === 'active' ? 'You joined the classroom.' : 'Your request was sent to the teacher.';
        if (!empty($result['duplicate'])) $message = $result['status'] === 'active' ? 'You are already in this classroom.' : 'Your request is already pending.';
        respond($result, $message, empty($result['duplicate']) ? 201 : 200);
    }
}
