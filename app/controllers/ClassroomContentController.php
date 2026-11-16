<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ClassroomContentController extends MY_Controller
{
    private $users;
    private $content;
    private $materialUpload;

    public function __construct()
    {
        parent::__construct();
        $this->users = $this->call->model('User_model');
        $this->content = $this->call->model('ClassroomContent_model');
        $this->materialUpload = $this->call->library('MaterialUpload');
        if (!$this->content->schemaReady()) {
            fail_api('Run database migration 012_classroom_collaboration.sql before using classroom chat and materials.', 503);
        }
    }

    public function chat($classroomId)
    {
        $user = require_role($this->users, ['student', 'instructor', 'admin']);
        if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$classroomId)) fail_api('Classroom not found.', 404);
        $messages = $this->content->messages($classroomId, $user['id']);
        if ($messages === null) fail_api('Classroom not found or you do not have access.', 404);
        respond($messages);
    }

    public function sendMessage($classroomId)
    {
        $user = require_role($this->users, ['student', 'instructor', 'admin']);
        if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$classroomId)) fail_api('Classroom not found.', 404);
        $body = json_body();
        $message = trim((string)($body['message'] ?? ''));
        $characters = preg_match_all('/./us', $message);
        if ($message === '' || strlen($message) > 8000 || $characters === false || $characters > 2000) {
            fail_api('Chat messages must be between 1 and 2,000 characters.', 422);
        }
        $created = $this->content->addMessage($classroomId, $user['id'], $message);
        if (!$created) fail_api('Classroom not found or you do not have access.', 404);
        respond($created, 'Message sent.', 201);
    }

    public function materials($classroomId)
    {
        $user = require_role($this->users, ['student', 'instructor', 'admin']);
        if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$classroomId)) fail_api('Classroom not found.', 404);
        $materials = $this->content->materials($classroomId, $user['id']);
        if ($materials === null) fail_api('Classroom not found or you do not have access.', 404);
        respond($materials);
    }

    public function uploadMaterial($classroomId)
    {
        $teacher = require_role($this->users, ['instructor']);
        if (!preg_match('/^[0-9a-f-]{36}$/i', (string)$classroomId)
            || !$this->content->teacherHasAccess($classroomId, $teacher['id'])) {
            fail_api('Classroom not found or you do not teach this class.', 404);
        }

        $storageRoot = rtrim(APP_DIR, '/\\') . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'classroom-materials';
        try {
            $stored = $this->materialUpload->store($_FILES['file'] ?? null, $storageRoot);
        } catch (MaterialUploadException $error) {
            fail_api($error->getMessage(), $error->statusCode());
        }

        $materialId = uuid4();
        try {
            $saved = $this->content->addMaterial($classroomId, $teacher['id'], [
                'id' => $materialId,
                'name' => $stored['name'],
                'storageKey' => $stored['storageKey'],
                'contentType' => $stored['contentType'],
                'bytes' => $stored['bytes'],
            ]);
        } catch (Throwable $error) {
            $destination = $storageRoot . DIRECTORY_SEPARATOR . $stored['storageKey'];
            if (is_file($destination) && !unlink($destination)) {
                error_log("Failed to remove orphaned classroom material {$stored['storageKey']}.");
            }
            throw $error;
        }
        if (!$saved) {
            $destination = $storageRoot . DIRECTORY_SEPARATOR . $stored['storageKey'];
            if (is_file($destination) && !unlink($destination)) {
                error_log("Failed to remove unauthorized classroom material {$stored['storageKey']}.");
            }
            fail_api('Classroom access changed before the material could be saved.', 404);
        }
        respond([
            'id' => $materialId, 'name' => $stored['name'], 'bytes' => $stored['bytes'],
            'contentType' => $stored['contentType'],
        ], 'Classroom material uploaded.', 201);
    }

    public function downloadMaterial($materialId)
    {
        $user = auth_user($this->users);
        $material = $this->content->material($materialId);
        if (!$material) fail_api('Classroom material not found.', 404);
        if (!$this->content->hasAccess($material['classroomId'], $user['id'])) {
            fail_api('You must be an active classroom member to access this material.', 403);
        }
        if (!preg_match('/^[0-9a-f-]{36}$/i', $material['storageKey'])) fail_api('Classroom material storage key is invalid.', 500);
        $storageRoot = rtrim(APP_DIR, '/\\') . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'classroom-materials';
        $path = $storageRoot . DIRECTORY_SEPARATOR . $material['storageKey'];
        if (!is_file($path) || !is_readable($path)) fail_api('The stored classroom material is missing. Ask the instructor to upload it again.', 404);

        $filename = rawurlencode($material['name']);
        header('Content-Type: ' . $material['contentType']);
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: attachment; filename*=UTF-8''$filename");
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
