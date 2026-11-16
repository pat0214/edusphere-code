<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ClassroomContent_model extends Model
{
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
             WHERE table_schema = DATABASE() AND table_name IN (?, ?)',
            ['classroom_chat_messages', 'classroom_materials']
        )->fetchColumn();
        return (int)$count === 2;
    }

    public function hasAccess(string $classroomId, string $userId): bool
    {
        return (bool)$this->db->raw(
            "SELECT 1 FROM classrooms c
             WHERE c.id = ? AND c.status = 'active'
               AND (
                 EXISTS (SELECT 1 FROM classroom_teachers ct WHERE ct.classroom_id = c.id AND ct.user_id = ?)
                 OR EXISTS (SELECT 1 FROM classroom_members m WHERE m.classroom_id = c.id AND m.user_id = ? AND m.status = 'active')
               )
             LIMIT 1",
            [$classroomId, $userId, $userId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function teacherHasAccess(string $classroomId, string $userId): bool
    {
        return (bool)$this->db->raw(
            "SELECT 1 FROM classrooms c JOIN classroom_teachers ct ON ct.classroom_id = c.id
             WHERE c.id = ? AND c.status = 'active' AND ct.user_id = ? LIMIT 1",
            [$classroomId, $userId]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function messages(string $classroomId, string $userId): ?array
    {
        if (!$this->hasAccess($classroomId, $userId)) return null;
        return $this->db->raw(
            'SELECT recent.id, recent.user_id userId, u.name userName, recent.message, recent.created_at createdAt
             FROM (
               SELECT id, user_id, message, created_at FROM classroom_chat_messages
               WHERE classroom_id = ? ORDER BY created_at DESC, id DESC LIMIT 100
             ) recent
             JOIN users u ON u.id = recent.user_id
             ORDER BY recent.created_at ASC, recent.id ASC',
            [$classroomId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addMessage(string $classroomId, string $userId, string $message): ?array
    {
        if (!$this->hasAccess($classroomId, $userId)) return null;
        $id = uuid4();
        $this->db->raw(
            'INSERT INTO classroom_chat_messages (id, classroom_id, user_id, message) VALUES (?, ?, ?, ?)',
            [$id, $classroomId, $userId, $message]
        );
        return $this->db->raw(
            'SELECT m.id, m.user_id userId, u.name userName, m.message, m.created_at createdAt
             FROM classroom_chat_messages m JOIN users u ON u.id = m.user_id
             WHERE m.id = ? LIMIT 1',
            [$id]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function materials(string $classroomId, string $userId): ?array
    {
        if (!$this->hasAccess($classroomId, $userId)) return null;
        return $this->db->raw(
            'SELECT m.id, m.name, m.content_type contentType, m.bytes, m.created_at createdAt,
                    u.name uploaderName
             FROM classroom_materials m JOIN users u ON u.id = m.uploaded_by
             WHERE m.classroom_id = ? ORDER BY m.created_at DESC, m.id DESC',
            [$classroomId]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addMaterial(string $classroomId, string $userId, array $material): bool
    {
        if (!$this->teacherHasAccess($classroomId, $userId)) return false;
        $this->db->raw(
            'INSERT INTO classroom_materials (id, classroom_id, uploaded_by, name, storage_key, content_type, bytes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$material['id'], $classroomId, $userId, $material['name'], $material['storageKey'],
                $material['contentType'], $material['bytes']]
        );
        return true;
    }

    public function material(string $materialId): ?array
    {
        return $this->db->raw(
            'SELECT id, classroom_id classroomId, name, storage_key storageKey,
                    content_type contentType, bytes
             FROM classroom_materials WHERE id = ? LIMIT 1',
            [$materialId]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
