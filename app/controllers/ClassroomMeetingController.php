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
        $this->users = $this->call->model('User_model');
        $this->meetings = $this->call->model('ClassroomMeeting_model');
        $this->classrooms = $this->call->model('SubjectClassroom_model');
        if (!$this->meetings->schemaReady()) fail_api('Run database migration 008_classrooms.sql before using classroom sessions.', 503);
    }

    public function connection()
    {
        $teacher = require_role($this->users, ['instructor']);
        $settings = $this->meetings->platformSettings();
        respond($this->meetings->googleConnection($teacher['id']) + [
            'configured' => GoogleCalendarClient::configured() && $this->meetings->encryptionReady(),
            'enabled' => $settings['classroomsEnabled'] && $settings['liveSessionsEnabled'],
            'maxDurationMinutes' => $settings['maxLiveSessionMinutes'],
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
        $user = require_role($this->users, ['student', 'instructor']);
        if (!$this->meetings->platformSettings()['classroomsEnabled']) fail_api('Subject classrooms are currently disabled by an administrator.', 503);
        $sessions = $this->meetings->sessionsForClassroom($classroomId, $user['id']);
        if ($sessions === null) fail_api('Classroom not found or you do not have access.', 404);
        respond($sessions);
    }

    public function start($classroomId)
    {
        $teacher = require_role($this->users, ['instructor']);
        $settings = $this->meetings->platformSettings();
        if (!$settings['classroomsEnabled'] || !$settings['liveSessionsEnabled']) fail_api('Live classroom sessions are disabled by an administrator.', 503);
        if (!GoogleCalendarClient::configured() || !$this->meetings->encryptionReady()) fail_api('Google Calendar OAuth and token encryption must be configured on the server.', 503);
        $account = $this->meetings->googleAccount($teacher['id']);
        if (!$account) fail_api('Connect Google Calendar before starting a live session.', 409);

        $body = json_body();
        $requestId = (string)($body['requestId'] ?? '');
        $title = trim((string)($body['title'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $duration = filter_var($body['durationMinutes'] ?? 60, FILTER_VALIDATE_INT);
        if (!preg_match('/^[0-9a-f-]{36}$/i', $requestId)) fail_api('A valid session request ID is required.', 422);
        if (strlen($title) < 3 || strlen($title) > 160) fail_api('Session title must be between 3 and 160 characters.', 422);
        if (strlen($description) > 1000) fail_api('Session description cannot exceed 1,000 characters.', 422);
        if ($duration === false || $duration < 10 || $duration > $settings['maxLiveSessionMinutes']) fail_api('Session length exceeds the administrator-configured session limit.', 422);

        $prepared = $this->meetings->prepareSession($classroomId, $teacher['id'], $requestId, $title, $description, $duration);
        if (($prepared['error'] ?? null) === 'not_found') fail_api('Classroom not found.', 404);
        if (($prepared['error'] ?? null) === 'too_many_attendees') fail_api('Google Calendar sessions support no more than 500 invited students per event.', 409);
        $session = $prepared['session'];
        if ($session['status'] === 'live' && $session['meetUrl']) {
            respond(['session' => $session], 'Live session is ready.');
        }

        try {
            $accessToken = GoogleCalendarClient::refreshAccessToken($account['refreshToken']);
            $eventId = (string)$session['googleEventId'];
            $event = [
                'id' => $eventId,
                'summary' => $title,
                'description' => $prepared['classroomName'] . ($description !== '' ? "\n\n" . $description : ''),
                'start' => ['dateTime' => gmdate('c', strtotime($session['startsAt'])), 'timeZone' => 'UTC'],
                'end' => ['dateTime' => gmdate('c', strtotime($session['endsAt'])), 'timeZone' => 'UTC'],
                'visibility' => 'private',
                'guestsCanModify' => false,
                'guestsCanInviteOthers' => false,
                'guestsCanSeeOtherGuests' => false,
                'attendees' => array_map(static fn($attendee) => [
                    'email' => $attendee['email'],
                    'displayName' => $attendee['displayName'],
                ], $prepared['attendees']),
                'conferenceData' => [
                    'createRequest' => [
                        'requestId' => str_replace('-', '', $session['id']),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                    ],
                ],
            ];
            $created = GoogleCalendarClient::createMeetEvent($accessToken, $event);
            $this->meetings->completeSession($session['id'], $created['eventId'], $created['meetUrl']);
            $session['status'] = $created['meetUrl'] ? 'live' : 'provisioning';
            $session['meetUrl'] = $created['meetUrl'];
        } catch (Throwable $error) {
            $this->meetings->markSessionFailed($session['id']);
            error_log('Google Calendar could not provision a classroom meeting.');
            fail_api('Google could not create the meeting. Reconnect Google Calendar if the authorization has expired, then try again.', 502);
        }
        if (!$session['meetUrl']) respond(['session' => $session], 'Google is preparing the Meet link. Refresh shortly.', 202);
        $notificationFailures = $this->notifyMembers($classroomId, $teacher['name'], $title);
        respond(
            ['session' => $session, 'notificationFailures' => $notificationFailures],
            $notificationFailures
                ? 'Live session started, but notifications could not be queued for ' . $notificationFailures . ' students.'
                : 'Live session started.'
        );
    }

    public function join($sessionId)
    {
        $user = require_role($this->users, ['student', 'instructor']);
        $settings = $this->meetings->platformSettings();
        if (!$settings['classroomsEnabled'] || !$settings['liveSessionsEnabled']) fail_api('Live classroom sessions are disabled by an administrator.', 503);
        $meeting = $this->meetings->joinSession($sessionId, $user['id']);
        if (!$meeting) fail_api('Live session not found or you do not have access.', 404);
        respond($meeting);
    }

    public function end($sessionId)
    {
        $teacher = require_role($this->users, ['instructor']);
        $eventId = $this->meetings->endSession($sessionId, $teacher['id']);
        if ($eventId === null) fail_api('Live session not found or already ended.', 404);
        $calendarRemoved = false;
        $account = $this->meetings->googleAccount($teacher['id']);
        if ($account) {
            try {
                GoogleCalendarClient::deleteEvent(
                    GoogleCalendarClient::refreshAccessToken($account['refreshToken']),
                    $eventId
                );
                $calendarRemoved = true;
            } catch (Throwable $error) {
                error_log('Google Calendar event cancellation failed after the EduSphere session was ended.');
            }
        }
        respond(
            ['ended' => true, 'calendarEventRemoved' => $calendarRemoved],
            $calendarRemoved
                ? 'Session ended and its Calendar event was cancelled.'
                : 'Session access ended in EduSphere, but its Calendar event could not be cancelled. Review it in Google Calendar.'
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
        respond([
            'settings' => $this->meetings->platformSettings(),
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
        $values['maxStudentsPerClassroom'] = $maxStudents;
        $values['maxLiveSessionMinutes'] = $maxDuration;
        $settings = $this->meetings->updatePlatformSettings($values, $admin['id']);
        $gamify = $this->call->model('Gamify_model');
        respond([
            'settings' => $settings,
            'usage' => $this->meetings->adminStats(),
            'emailQueue' => $gamify->emailQueueSchemaReady() ? $gamify->emailQueueCounts() : null,
            'classrooms' => $this->classrooms->adminClassrooms(),
        ], 'Classroom platform settings saved.');
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

    private function notifyMembers(string $classroomId, string $teacherName, string $title): int
    {
        $members = $this->meetings->activeMemberIds($classroomId);
        $gamifyModel = $this->call->model('Gamify_model');
        $gamify = $this->call->library('Gamify', $gamifyModel);
        $failed = 0;
        foreach ($members as $memberId) {
            try {
                $gamify->notify($memberId, 'classroom_live_session', 'Live classroom started', $teacherName . ' started "' . $title . '". Open your classroom to join.');
            } catch (Throwable $error) {
                error_log('Classroom live-session notification could not be queued.');
                $failed++;
            }
        }
        return $failed;
    }
}
