<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AdminController extends MY_Controller
{
    private $users;
    private $admin;
    private $tutor;

    public function __construct()
    {
        parent::__construct();
        $this->users = $this->call->model('User_model');
        $this->admin = $this->call->model('Admin_model');
        $this->tutor = $this->call->model('AiTutor_model');
    }

    public function analytics()
    {
        require_role($this->users, ['admin']);
        respond($this->admin->analytics());
    }

    public function users()
    {
        require_role($this->users, ['admin']);
        respond($this->users->adminLists());
    }

    public function create_instructor()
    {
        require_role($this->users, ['admin']);
        if (!$this->users->instructorSubjectSchemaReady()) {
            fail_api('Run database migration 011_instructor_subject_courses.sql before creating instructors.', 503);
        }

        $body = json_body();
        $name = trim((string)($body['name'] ?? ''));
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $subject = trim((string)($body['subject'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            fail_api('Name must be between 2 and 100 characters.', 422, ['name' => 'Enter a name between 2 and 100 characters.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            fail_api('Enter a valid email address.', 422, ['email' => 'Enter a valid email address.']);
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            fail_api('Initial password must be between 8 and 72 characters.', 422, ['password' => 'Use 8 to 72 characters.']);
        }
        if (mb_strlen($subject) < 1 || mb_strlen($subject) > 160) {
            fail_api('Teaching subject must be between 1 and 160 characters.', 422, ['subject' => 'Enter a subject up to 160 characters.']);
        }
        if ($this->users->byEmail($email)) {
            fail_api('An account with this email already exists.', 409, ['email' => 'Already registered.']);
        }

        $id = uuid4();
        $this->users->createInstructor($id, $name, $email, password_hash($password, PASSWORD_BCRYPT), $subject);
        respond([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'role' => 'instructor',
            'subject' => $subject,
        ], 'Instructor account created. Share the initial password securely.', 201);
    }

    public function update_user()
    {
        $admin = require_role($this->users, ['admin']);
        $body = json_body();
        if (empty($body['id'])) fail_api('Missing user id.', 422);
        if ($body['id'] === $admin['id'] && empty($body['active'])) {
            fail_api("You can't deactivate your own account.", 409);
        }

        $active = !empty($body['active']);
        $this->users->setActive($body['id'], $active);
        respond(null, $active ? 'Account reactivated.' : 'Account deactivated.');
    }

    public function ai_tutor()
    {
        require_role($this->users, ['admin']);
        respond([
            'settings' => $this->tutor->settings(),
            'usage' => $this->tutor->adminOverview(),
        ]);
    }

    public function update_ai_tutor()
    {
        require_role($this->users, ['admin']);
        $body = json_body();
        $costs = $body['costs'] ?? null;
        if (!is_array($costs)) fail_api('AI feature costs are required.', 422);

        $dailyCredits = filter_var($body['dailyCredits'] ?? null, FILTER_VALIDATE_INT);
        if ($dailyCredits === false || $dailyCredits < 0 || $dailyCredits > 1000) {
            fail_api('Daily credits must be a whole number between 0 and 1,000.', 422);
        }
        $cleanCosts = [];
        foreach (['hint', 'error', 'concept', 'pseudocode', 'solution'] as $feature) {
            $cost = filter_var($costs[$feature] ?? null, FILTER_VALIDATE_INT);
            if ($cost === false || $cost < 0 || $cost > 100) {
                fail_api('Each feature cost must be a whole number between 0 and 100.', 422);
            }
            $cleanCosts[$feature] = $cost;
        }

        $modelValue = $body['model'] ?? '';
        if (!is_string($modelValue)) fail_api('Gemini model must be text.', 422);
        $model = trim($modelValue);
        if (strlen($model) > 100 || ($model !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $model))) {
            fail_api('Enter a valid Gemini model name, or leave it blank to use GEMINI_MODEL.', 422);
        }
        $inputRate = $body['inputCostPerMillionUsd'] ?? null;
        $outputRate = $body['outputCostPerMillionUsd'] ?? null;
        if (!is_numeric($inputRate) || !is_numeric($outputRate)
            || (float)$inputRate < 0 || (float)$outputRate < 0
            || (float)$inputRate > 10000 || (float)$outputRate > 10000) {
            fail_api('Token cost rates must be between 0 and 10,000 USD per million tokens.', 422);
        }
        if (!array_key_exists('enabled', $body) || !is_bool($body['enabled'])) {
            fail_api('The enabled setting must be true or false.', 422);
        }

        $this->tutor->updateSettings([
            'enabled' => $body['enabled'],
            'dailyCredits' => $dailyCredits,
            'costs' => $cleanCosts,
            'model' => $model,
            'inputCostPerMillionUsd' => (float)$inputRate,
            'outputCostPerMillionUsd' => (float)$outputRate,
        ]);
        respond([
            'settings' => $this->tutor->settings(),
            'usage' => $this->tutor->adminOverview(),
        ], 'AI assistant settings saved.');
    }
}
