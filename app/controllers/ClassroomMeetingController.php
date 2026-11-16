<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ClassroomMeetingController extends MY_Controller
{
    private $users;
    private $meetings;
    private $classrooms;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('GoogleCalendarClient');
        $this->call->library('JitsiMeetingProvider');
        $this->users = $this->call->model('User_model');
        $this->meetings = $this->call->model('ClassroomMeeting_model');
        $this->classrooms = $this->call->model('SubjectClassroom_model');
        if (!$this->meetings->schemaReady()) fail_api('Run database migration 008_classrooms.sql before using classroom sessions.', 503);
        if (!$this->meetings->jitsiSchemaReady()) fail_api('Run database migration 009_jitsi_live_sessions.sql before using Jitsi sessions.', 503);
    }

    public function connection()
    {
        $user = require_role($this->users, ['student', 'instructor']);
        $settings = $this->meetings->platformSettings();
        $meetingSettings = $this->meetings->meetingSettings();
        $provider = JitsiMeetingProvider::settingsFromDatabase($meetingSettings);
        $googleConnection = $user['role'] === 'instructor'
            ? $this->meetings->googleConnection($user['id'])
            : ['connected' => false, 'googleEmail' => null, 'connectedAt' => null];
        respond($googleConnection + [
            'googleConfigured' => GoogleCalendarClient::configured() && $this->meetings->encryptionReady(),
            'provider' => $meetingSettings['provider'],
            'domain' => $meetingSettings['domain'],
            'configured' => $provider->configured(),
            'enabled' => $settings['classroomsEnabled'] && $settings['liveSessionsEnabled'],
            'maxDurationMinutes' => $this->maxSessionDuration($settings, $meetingSettings),
        ]);
    }

    public function connect()
    {
        $teacher = require_role($this->users, ['instructor']);
        $settings = $this->meetings->platformSettings();
        if (!$settings['classroomsEnabled'] || !$settings['liveSessionsEnabled']) fail_api('Live classroom sessions are disabled by an administrator.', 503);
        if (!GoogleCalendarClient::configured() || !$this->meetings->encryptionReady()) fail_api('Google Calendar OAuth and token encryption must be configured on the server.', 503);
        $state = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->meetings->storeOAuthState($teacher['id'], hash('sha256', $state), $verifier);
        respond(['authorizationUrl' => GoogleCalendarClient::authorizationUrl($state, $challenge)]);
    }

    public function callback()
    {
        $request = lava_instance()->request;
        $state = (string)$request->query('state', '');
        $code = (string)$request->query('code', '');
        $stateData = preg_match('/^[A-Za-z0-9_-]{40,60}$/', $state)
            ? $this->meetings->consumeOAuthState(hash('sha256', $state))
            : null;
        $appUrl = rtrim((string)(getenv('APP_URL') ?: 'https://edusphere-front.onrender.com'), '/');
        if (!$stateData || $request->query('error') || $code === '' || strlen($code) > 2048) {
            lava_instance()->response->redirect($appUrl . '/classrooms?google=error');
        }
        try {
            $tokens = GoogleCalendarClient::exchangeCode($code, $stateData['codeVerifier']);
            $profile = GoogleCalendarClient::profile((string)$tokens['access_token']);
            $email = filter_var($profile['email'] ?? null, FILTER_VALIDATE_EMAIL) ? (string)$profile['email'] : null;
            $this->meetings->saveGoogleAccount($stateData['userId'], (string)($tokens['refresh_token'] ?? ''), $email);
        } catch (Throwable $error) {
            error_log('Google Calendar account connection failed.');
            lava_instance()->response->redirect($appUrl . '/classrooms?google=error');
        }
        lava_instance()->response->redirect($appUrl . '/classrooms?google=connected');
    }

    public function disconnect()
    {
        $teacher = require_role($this->users, ['instructor']);
        $this->meetings->disconnectGoogle($teacher['id']);
        respond(['connected' => false], 'Google Calendar disconnected.');
    }

    public function sessions($classroomId)
    {
        $user = require_role($this->users, ['student', 'instructor', 'admin']);
        if (!$this->meetings->platformSettings()['classroomsEnabled']) fail_api('Subject classrooms are currently disabled by an administrator.', 503);
        $sessions = $this->meetings->sessionsForClassroom($classroomId, $user['id']);
        if ($sessions === null) fail_api('Classroom not found or you do not have access.', 404);
        respond($sessions);
    }

    public function start($classroomId)
    {
        $host = require_role($this->users, ['student', 'instructor']);
        $settings = $this->meetings->platformSettings();
        if (!$settings['classroomsEnabled'] || !$settings['liveSessionsEnabled']) fail_api('Live classroom sessions are disabled by an administrator.', 503);
        $meetingSettings = $this->meetings->meetingSettings();
        $provider = JitsiMeetingProvider::settingsFromDatabase($meetingSettings);
        if (!$provider->configured()) fail_api('Configure the selected Jitsi provider on the backend before starting a live session.', 503);
        $maxDuration = $this->maxSessionDuration($settings, $meetingSettings);

        $body = json_body();
        $requestId = (string)($body['requestId'] ?? '');
        $title = trim((string)($body['title'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $duration = filter_var($body['durationMinutes'] ?? 60, FILTER_VALIDATE_INT);
        if (!preg_match('/^[0-9a-f-]{36}$/i', $requestId)) fail_api('A valid session request ID is required.', 422);
        if ($title !== '' && (strlen($title) < 3 || strlen($title) > 160)) fail_api('Session title must be between 3 and 160 characters.', 422);
        if (strlen($description) > 1000) fail_api('Session topic cannot exceed 1,000 characters.', 422);
        if ($duration === false || $duration < 10 || $duration > $maxDuration) fail_api('Session length must be between 10 and ' . $maxDuration . ' minutes for the selected Jitsi provider.', 422);
        $notifyStudents = $host['role'] === 'student' ? true : ($body['notifyStudents'] ?? true);
        if (!is_bool($notifyStudents)) fail_api('notifyStudents must be true or false.', 422);

        $room = $provider->createRoom();
        $prepared = $this->meetings->prepareSession($classroomId, $host['id'], $requestId, $title, $description, $duration, $room);
        if (($prepared['error'] ?? null) === 'not_found') fail_api('Classroom not found.', 404);
        if (($prepared['error'] ?? null) === 'request_expired') fail_api('That start request belongs to a session that has ended. Start a new session instead.', 409);
        if (($prepared['error'] ?? null) === 'schedule_conflict') fail_api('A scheduled session overlaps with that time. Join or reschedule the upcoming class instead.', 409);
        $session = $prepared['session'];
        $title = $session['title'];
        $calendarNotice = null;
        if (empty($prepared['duplicate']) && $notifyStudents) {
            if ($host['role'] === 'instructor') {
                $calendarNotice = $this->createCalendarNotice($host['id'], $classroomId, $prepared['classroomName'], $session, $description);
            }
            $notificationFailures = $this->notifyMembers(
                $classroomId,
                $host['name'],
                $title,
                $host['id'],
                'classroom_live_session'
            );
        } else {
            $notificationFailures = 0;
        }
        $message = $notificationFailures
            ? 'Live session is active, but EduSphere notifications could not be queued for ' . $notificationFailures . ' classroom members.'
            : (!empty($prepared['duplicate']) ? 'An active live session already exists.' : 'Live session started.');
        if ($calendarNotice !== null) $message .= ' ' . $calendarNotice;
        respond(['session' => $session], $message, empty($prepared['duplicate']) ? 201 : 200);
    }

    public function schedule($classroomId)
    {
        $teacher = require_role($this->users, ['instructor']);
        if (!$this->meetings->scheduledSessionSchemaReady()) {
            fail_api('Run database migration 012_classroom_collaboration.sql before scheduling sessions.', 503);
        }
        $settings = $this->meetings->platformSettings();
        if (!$settings['classroomsEnabled'] || !$settings['liveSessionsEnabled']) fail_api('Live classroom sessions are disabled by an administrator.', 503);
        $meetingSettings = $this->meetings->meetingSettings();
        $provider = JitsiMeetingProvider::settingsFromDatabase($meetingSettings);
        if (!$provider->configured()) fail_api('Configure the selected Jitsi provider on the backend before scheduling a live session.', 503);
        $maxDuration = $this->maxSessionDuration($settings, $meetingSettings);

        $body = json_body();
        $requestId = (string)($body['requestId'] ?? '');
        $title = trim((string)($body['title'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $requestedStart = $body['startsAt'] ?? null;
        $duration = filter_var($body['durationMinutes'] ?? 60, FILTER_VALIDATE_INT);
        if (!preg_match('/^[0-9a-f-]{36}$/i', $requestId)) fail_api('A valid session request ID is required.', 422);
        if ($title !== '' && (strlen($title) < 3 || strlen($title) > 160)) fail_api('Session title must be between 3 and 160 characters.', 422);
        if (strlen($description) > 1000) fail_api('Session topic cannot exceed 1,000 characters.', 422);
        if ($duration === false || $duration < 10 || $duration > $maxDuration) fail_api('Session length must be between 10 and ' . $maxDuration . ' minutes for the selected Jitsi provider.', 422);
        if (!is_string($requestedStart)
            || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/', $requestedStart)) {
            fail_api('Choose a valid future date and time for the session.', 422);
        }
        $startTimestamp = strtotime($requestedStart);
        if ($startTimestamp === false || $startTimestamp < time() + 300 || $startTimestamp > time() + 365 * 86400) {
            fail_api('Schedule the session at least 5 minutes from now and within the next year.', 422);
        }
        $startsAt = gmdate('Y-m-d H:i:s', $startTimestamp);
        $notifyStudents = $body['notifyStudents'] ?? true;
        if (!is_bool($notifyStudents)) fail_api('notifyStudents must be true or false.', 422);

        $room = $provider->createRoom();
        $prepared = $this->meetings->scheduleSession(
            $classroomId,
            $teacher['id'],
            $requestId,
            $title,
            $description,
            $duration,
            $startsAt,
            $room
        );
        if (($prepared['error'] ?? null) === 'not_found') fail_api('Classroom not found.', 404);
        if (($prepared['error'] ?? null) === 'request_expired') fail_api('That schedule request is no longer available. Create a new schedule instead.', 409);
        if (($prepared['error'] ?? null) === 'schedule_conflict') fail_api('Another live session is already scheduled for that time.', 409);
        $session = $prepared['session'];
        $calendarNotice = null;
        if (empty($prepared['duplicate']) && $notifyStudents) {
            $calendarNotice = $this->createCalendarNotice($teacher['id'], $classroomId, $prepared['classroomName'], $session, $description);
            $notificationFailures = $this->notifyMembers(
                $classroomId,
                $teacher['name'],
                $session['title'],
                $teacher['id'],
                'classroom_live_session_scheduled'
            );
        } else {
            $notificationFailures = 0;
        }
        $message = $notificationFailures
            ? 'Session scheduled, but EduSphere notifications could not be queued for ' . $notificationFailures . ' classroom members.'
            : (!empty($prepared['duplicate']) ? 'That session is already scheduled.' : 'Live session scheduled.');
        if ($calendarNotice !== null) $message .= ' ' . $calendarNotice;
        respond(['session' => $session], $message, empty($prepared['duplicate']) ? 201 : 200);
    }

    public function join($sessionId)
    {
        $user = require_role($this->users, ['student', 'instructor']);
        $settings = $this->meetings->platformSettings();
        if (!$settings['classroomsEnabled'] || !$settings['liveSessionsEnabled']) fail_api('Live classroom sessions are disabled by an administrator.', 503);
        $meeting = $this->meetings->joinSession($sessionId, $user['id']);
        if (!$meeting) fail_api('Live session not found or you do not have access.', 404);
        $provider = new JitsiMeetingProvider($meeting['provider'], $meeting['domain']);
        if (!$provider->configured()) fail_api('The live-session provider is not configured. Contact your administrator.', 503);
        $credentials = $provider->joinCredentials(
            $meeting['roomName'],
            $meeting['userId'],
            $meeting['displayName'],
            $meeting['moderator'],
            $meeting['endsAt']
        );
        if (!$this->meetings->recordJoin($sessionId, $user['id'])) fail_api('Live session ended or access was removed.', 404);
        respond($credentials + ['title' => $meeting['title'], 'endsAt' => $meeting['endsAt']]);
    }

    public function leave($sessionId)
    {
        $user = require_role($this->users, ['student', 'instructor']);
        $this->meetings->leaveSession($sessionId, $user['id']);
        respond(['left' => true], 'Attendance updated.');
    }

    public function end($sessionId)
    {
        $teacher = require_role($this->users, ['instructor']);
        $ended = $this->meetings->endSession($sessionId, $teacher['id']);
        if ($ended === null) fail_api('Live session not found or already ended.', 404);
        $calendarUpdated = false;
        $account = $this->meetings->googleAccount($teacher['id']);
        if ($account && $ended['googleEventId']) {
            try {
                GoogleCalendarClient::finishCalendarEvent(
                    GoogleCalendarClient::refreshAccessToken($account['refreshToken']),
                    $ended['googleEventId'],
                    $ended['endsAt']
                );
                $calendarUpdated = true;
            } catch (Throwable $error) {
                error_log('Google Calendar event update failed after the Jitsi session ended.');
            }
        }
        respond(
            ['ended' => true, 'calendarEventUpdated' => $calendarUpdated],
            $calendarUpdated
                ? 'Live session ended and its Calendar event was updated.'
                : 'Live session ended.'
        );
    }

    public function adminStats()
    {
        require_role($this->users, ['admin']);
        respond($this->meetings->adminStats());
    }

    public function adminSettings()
    {
        require_role($this->users, ['admin']);
        $gamify = $this->call->model('Gamify_model');
        $meeting = $this->meetings->meetingSettings();
        $provider = JitsiMeetingProvider::settingsFromDatabase($meeting);
        respond([
            'settings' => $this->meetings->platformSettings() + [
                'provider' => $meeting['provider'],
                'domain' => $meeting['domain'],
                'jitsiConfigured' => $provider->configured(),
            ],
            'usage' => $this->meetings->adminStats(),
            'emailQueue' => $gamify->emailQueueSchemaReady() ? $gamify->emailQueueCounts() : null,
            'classrooms' => $this->classrooms->adminClassrooms(),
        ]);
    }

    public function updateAdminSettings()
    {
        $admin = require_role($this->users, ['admin']);
        $body = json_body();
        $values = [];
        foreach (['classroomsEnabled', 'liveSessionsEnabled', 'emailNotificationsEnabled'] as $field) {
            if (!array_key_exists($field, $body) || !is_bool($body[$field])) fail_api(ucfirst($field) . ' must be true or false.', 422);
            $values[$field] = $body[$field];
        }
        $maxStudents = filter_var($body['maxStudentsPerClassroom'] ?? null, FILTER_VALIDATE_INT);
        $maxDuration = filter_var($body['maxLiveSessionMinutes'] ?? null, FILTER_VALIDATE_INT);
        if ($maxStudents === false || $maxStudents < 1 || $maxStudents > 5000) fail_api('Classroom student limit must be between 1 and 5,000.', 422);
        if ($maxDuration === false || $maxDuration < 10 || $maxDuration > 240) fail_api('Live session limit must be between 10 and 240 minutes.', 422);
        $currentMeetingSettings = $this->meetings->meetingSettings();
        $meetingProvider = $body['provider'] ?? $currentMeetingSettings['provider'];
        if (!is_string($meetingProvider) || !JitsiMeetingProvider::validMode($meetingProvider)) fail_api('Choose a supported Jitsi provider.', 422);
        $rawDomain = $body['domain'] ?? $currentMeetingSettings['domain'];
        if (!is_string($rawDomain)) fail_api('Jitsi domain must be text.', 422);
        $domain = trim($rawDomain);
        if ($meetingProvider === 'public') $domain = 'meet.jit.si';
        if ($meetingProvider === 'self_hosted' && !JitsiMeetingProvider::validDomain($domain)) fail_api('Enter a valid self-hosted Jitsi domain without https:// or a path.', 422);
        $values['maxStudentsPerClassroom'] = $maxStudents;
        $values['maxLiveSessionMinutes'] = $maxDuration;
        $settings = $this->meetings->updatePlatformSettings($values, $admin['id']);
        $meeting = $this->meetings->updateMeetingSettings($meetingProvider, $domain, $admin['id']);
        $provider = JitsiMeetingProvider::settingsFromDatabase($meeting);
        $gamify = $this->call->model('Gamify_model');
        respond([
            'settings' => $settings + [
                'provider' => $meeting['provider'],
                'domain' => $meeting['domain'],
                'jitsiConfigured' => $provider->configured(),
            ],
            'usage' => $this->meetings->adminStats(),
            'emailQueue' => $gamify->emailQueueSchemaReady() ? $gamify->emailQueueCounts() : null,
            'classrooms' => $this->classrooms->adminClassrooms(),
        ], 'Classroom platform settings saved.');
    }

    private function createCalendarNotice(string $teacherId, string $classroomId, string $classroomName, array $session, string $topic): ?string
    {
        if (!GoogleCalendarClient::configured() || !$this->meetings->encryptionReady()) return null;
        $account = $this->meetings->googleAccount($teacherId);
        if (!$account) return null;
        try {
            $attendees = $this->meetings->activeMemberEmails($classroomId, 200);
            $appUrl = rtrim((string)(getenv('APP_URL') ?: ''), '/');
            $classroomUrl = $appUrl . '/classrooms/' . rawurlencode($classroomId);
            $description = $classroomName . "\n\nJoin through EduSphere: " . $classroomUrl;
            if ($topic !== '') $description .= "\n\nTopic: " . $topic;
            $event = [
                'id' => (string)$session['googleEventId'],
                'summary' => $classroomName . ' - ' . $session['title'],
                'description' => $description,
                'location' => $classroomUrl,
                'start' => ['dateTime' => gmdate('c', strtotime($session['startsAt'] . ' UTC')), 'timeZone' => 'UTC'],
                'end' => ['dateTime' => gmdate('c', strtotime($session['endsAt'] . ' UTC')), 'timeZone' => 'UTC'],
                'visibility' => 'private',
                'guestsCanModify' => false,
                'guestsCanInviteOthers' => false,
                'guestsCanSeeOtherGuests' => false,
                'attendees' => array_map(static fn($attendee) => [
                    'email' => $attendee['email'],
                    'displayName' => $attendee['displayName'],
                ], $attendees),
            ];
            $created = GoogleCalendarClient::createCalendarEvent(
                GoogleCalendarClient::refreshAccessToken($account['refreshToken']),
                $event
            );
            $this->meetings->setCalendarEvent($session['id'], $created['eventId']);
            return count($attendees) >= 200
                ? 'Google Calendar invitation was sent to up to 200 active students.'
                : 'Google Calendar invitation was sent to active students.';
        } catch (Throwable $error) {
            error_log('Optional Google Calendar notification could not be sent for a Jitsi session.');
            return 'The Jitsi session is ready, but the optional Google Calendar invitation could not be sent.';
        }
    }

    public function adminUpdateClassroom($classroomId)
    {
        $admin = require_role($this->users, ['admin']);
        $body = json_body();
        $status = $body['status'] ?? '';
        if (!in_array($status, ['active', 'archived', 'deleted'], true)) fail_api('Choose active, archived, or deleted status.', 422);
        if (!$this->classrooms->setAdminStatus($classroomId, $admin['id'], $status)) fail_api('Classroom not found.', 404);
        respond(['id' => $classroomId, 'status' => $status], 'Classroom status updated.');
    }

    private function notifyMembers(string $classroomId, string $hostName, string $title, string $hostId, string $type): int
    {
        $members = $this->meetings->sessionRecipientIds($classroomId);
        $gamifyModel = $this->call->model('Gamify_model');
        $gamify = $this->call->library('Gamify', $gamifyModel);
        $failed = 0;
        foreach ($members as $memberId) {
            if ($memberId === $hostId) continue;
            try {
                $scheduled = $type === 'classroom_live_session_scheduled';
                $message = $scheduled
                    ? $hostName . ' scheduled "' . $title . '". Open your classroom to see the schedule.'
                    : $hostName . ' started "' . $title . '". Open your classroom to join.';
                $gamify->notify(
                    $memberId,
                    $type,
                    $scheduled ? 'Live classroom scheduled' : 'Live classroom started',
                    $message
                );
            } catch (Throwable $error) {
                error_log('Classroom live-session notification could not be queued.');
                $failed++;
            }
        }
        return $failed;
    }

    private function maxSessionDuration(array $settings, array $meetingSettings): int
    {
        $configuredLimit = (int)$settings['maxLiveSessionMinutes'];
        return $meetingSettings['provider'] === 'public' ? min(90, $configuredLimit) : $configuredLimit;
    }
}
