<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class NotificationController extends MY_Controller
{
    private $users;
    private $gamify;

    public function __construct()
    {
        parent::__construct();
        $this->users = $this->call->model('User_model');
        $this->gamify = $this->call->model('Gamify_model');
    }

    public function preferences()
    {
        $user = auth_user($this->users);
        if (!$this->gamify->emailQueueSchemaReady()) fail_api('Run database migration 008_classrooms.sql before using email preferences.', 503);
        $preferences = $this->gamify->notificationPreferences($user['id'])
            ?? ['emailEnabled' => 1, 'classroomEmailEnabled' => 1];
        respond([
            'emailEnabled' => (bool)$preferences['emailEnabled'],
            'classroomEmailEnabled' => (bool)$preferences['classroomEmailEnabled'],
        ]);
    }

    public function updatePreferences()
    {
        $user = auth_user($this->users);
        if (!$this->gamify->emailQueueSchemaReady()) fail_api('Run database migration 008_classrooms.sql before using email preferences.', 503);
        $body = json_body();
        $updates = [];
        foreach (['emailEnabled', 'classroomEmailEnabled'] as $field) {
            if (!array_key_exists($field, $body)) continue;
            if (!is_bool($body[$field])) fail_api(ucfirst($field) . ' must be true or false.', 422);
            $updates[$field] = $body[$field];
        }
        if (!$updates) fail_api('Select an email preference to update.', 422);
        respond($this->gamify->saveNotificationPreferences($user['id'], $updates), 'Email preferences saved.');
    }

    public function processEmailQueue()
    {
        $configuredToken = getenv('EMAIL_WORKER_TOKEN');
        $providedToken = lava_instance()->request->bearer_token();
        if (!is_string($configuredToken) || strlen($configuredToken) < 32) fail_api('Email worker is not configured.', 503);
        if (!is_string($providedToken) || !hash_equals($configuredToken, $providedToken)) fail_api('Not authorized.', 401);
        if (!$this->gamify->emailQueueSchemaReady()) fail_api('Run database migration 008_classrooms.sql before processing email.', 503);
        if (!$this->gamify->emailNotificationsEnabled()) respond(['claimed' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => true]);
        if (!MailService::configured()) fail_api('Configure Resend or SMTP before processing queued email.', 503);

        $rows = $this->gamify->claimNotificationEmails(3);
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            try {
                $delivered = MailService::send($row['recipientEmail'], $row['subject'], $row['htmlBody']);
            } catch (Throwable $error) {
                error_log('Notification email provider threw an exception.');
                $delivered = false;
            }
            if ($delivered) {
                $this->gamify->markNotificationEmailSent($row['id']);
                $sent++;
            } else {
                $this->gamify->markNotificationEmailFailed($row['id'], (int)$row['attempts'], 'mail_provider_failed');
                $failed++;
            }
        }
        respond(['claimed' => count($rows), 'sent' => $sent, 'failed' => $failed]);
    }

    public function adminEmailStats()
    {
        require_role($this->users, ['admin']);
        if (!$this->gamify->emailQueueSchemaReady()) fail_api('Run database migration 008_classrooms.sql before viewing email statistics.', 503);
        respond($this->gamify->emailQueueCounts());
    }
}
