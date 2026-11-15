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
