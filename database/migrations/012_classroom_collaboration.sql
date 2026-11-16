ALTER TABLE classroom_live_sessions
  MODIFY status ENUM('provisioning','scheduled','live','ended','cancelled','failed') NOT NULL DEFAULT 'provisioning';

CREATE TABLE IF NOT EXISTS classroom_chat_messages (
  id CHAR(36) PRIMARY KEY,
  classroom_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  message VARCHAR(2000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY classroom_chat_messages_idx (classroom_id, created_at, id),
  CONSTRAINT classroom_chat_class_fk FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
  CONSTRAINT classroom_chat_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classroom_materials (
  id CHAR(36) PRIMARY KEY,
  classroom_id CHAR(36) NOT NULL,
  uploaded_by CHAR(36) NOT NULL,
  name VARCHAR(200) NOT NULL,
  storage_key CHAR(36) NOT NULL,
  content_type VARCHAR(127) NOT NULL,
  bytes INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY classroom_material_storage_uq (storage_key),
  KEY classroom_materials_idx (classroom_id, created_at, id),
  CONSTRAINT classroom_material_class_fk FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,
  CONSTRAINT classroom_material_uploader_fk FOREIGN KEY (uploaded_by) REFERENCES users(id)
) ENGINE=InnoDB;
