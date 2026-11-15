CREATE TABLE IF NOT EXISTS classrooms (
  id CHAR(36) PRIMARY KEY,
  owner_id CHAR(36) NOT NULL,
  name VARCHAR(120) NOT NULL,
  subject VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  grade_level VARCHAR(80) NULL,
  theme_color VARCHAR(24) NOT NULL DEFAULT 'coral',
  icon VARCHAR(32) NOT NULL DEFAULT 'book-open',
  approval_mode ENUM('teacher','auto') NOT NULL DEFAULT 'teacher',
  max_students INT NULL,
  status ENUM('active','archived','deleted') NOT NULL DEFAULT 'active',
  invitations_enabled TINYINT(1) NOT NULL DEFAULT 1,
  join_code CHAR(8) NOT NULL,
  invite_token CHAR(64) NOT NULL,
  invite_expires_at DATETIME NULL,
  invite_max_uses INT NULL,
  invite_uses INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY classroom_join_code_uq (join_code),
  UNIQUE KEY classroom_invite_token_uq (invite_token),
  KEY classroom_owner_status_idx (owner_id, status),
  CONSTRAINT classroom_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classroom_teachers (
  classroom_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  role ENUM('owner','co_teacher') NOT NULL DEFAULT 'owner',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (classroom_id, user_id),
  CONSTRAINT classroom_teacher_class_fk FOREIGN KEY (classroom_id) REFERENCES classrooms(id),
  CONSTRAINT classroom_teacher_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classroom_members (
  id CHAR(36) PRIMARY KEY,
  classroom_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  status ENUM('pending','active','declined','removed','left') NOT NULL DEFAULT 'pending',
  blocked_from_rejoining TINYINT(1) NOT NULL DEFAULT 0,
  decline_reason VARCHAR(500) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  decided_by CHAR(36) NULL,
  UNIQUE KEY classroom_member_uq (classroom_id, user_id),
  KEY classroom_members_status_idx (classroom_id, status, requested_at),
  KEY classroom_student_status_idx (user_id, status, requested_at),
  CONSTRAINT classroom_member_class_fk FOREIGN KEY (classroom_id) REFERENCES classrooms(id),
  CONSTRAINT classroom_member_user_fk FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT classroom_member_decider_fk FOREIGN KEY (decided_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classroom_audit_events (
  id CHAR(36) PRIMARY KEY,
  classroom_id CHAR(36) NOT NULL,
  actor_id CHAR(36) NOT NULL,
  subject_user_id CHAR(36) NULL,
  action VARCHAR(40) NOT NULL,
  detail JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY classroom_audit_idx (classroom_id, created_at),
  CONSTRAINT classroom_audit_class_fk FOREIGN KEY (classroom_id) REFERENCES classrooms(id),
  CONSTRAINT classroom_audit_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id),
  CONSTRAINT classroom_audit_subject_fk FOREIGN KEY (subject_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_notification_preferences (
  user_id CHAR(36) PRIMARY KEY,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  classroom_email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT notification_preferences_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_email_queue (
  id CHAR(36) PRIMARY KEY,
  notification_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  recipient_email VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  html_body MEDIUMTEXT NOT NULL,
  category ENUM('system','classroom') NOT NULL DEFAULT 'system',
  status ENUM('pending','processing','sent','failed','cancelled') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at DATETIME NULL,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  UNIQUE KEY notification_email_notification_uq (notification_id),
  KEY notification_email_ready_idx (status, available_at, created_at),
  CONSTRAINT notification_email_notification_fk FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  CONSTRAINT notification_email_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS platform_feature_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  classrooms_enabled TINYINT(1) NOT NULL DEFAULT 1,
  live_sessions_enabled TINYINT(1) NOT NULL DEFAULT 1,
  email_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
  max_students_per_classroom INT NOT NULL DEFAULT 5000,
  max_live_session_minutes SMALLINT NOT NULL DEFAULT 240,
  updated_by CHAR(36) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT platform_settings_admin_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO platform_feature_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS classroom_live_sessions (
  id CHAR(36) PRIMARY KEY,
  classroom_id CHAR(36) NOT NULL,
  created_by CHAR(36) NOT NULL,
  title VARCHAR(160) NOT NULL,
  description VARCHAR(1000) NOT NULL DEFAULT '',
  status ENUM('provisioning','live','ended','cancelled','failed') NOT NULL DEFAULT 'provisioning',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  google_event_id VARCHAR(255) NULL,
  request_id CHAR(36) NOT NULL,
  meet_url VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY classroom_live_sessions_idx (classroom_id, starts_at),
  KEY classroom_live_status_idx (status, starts_at),
  UNIQUE KEY classroom_live_request_uq (classroom_id, request_id),
  CONSTRAINT classroom_live_class_fk FOREIGN KEY (classroom_id) REFERENCES classrooms(id),
  CONSTRAINT classroom_live_creator_fk FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classroom_session_attendance (
  session_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at DATETIME NULL,
  PRIMARY KEY (session_id, user_id),
  CONSTRAINT classroom_attendance_session_fk FOREIGN KEY (session_id) REFERENCES classroom_live_sessions(id),
  CONSTRAINT classroom_attendance_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classroom_google_accounts (
  user_id CHAR(36) PRIMARY KEY,
  encrypted_refresh_token TEXT NOT NULL,
  token_nonce BINARY(12) NOT NULL,
  google_email VARCHAR(190) NULL,
  connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT classroom_google_account_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS classroom_google_oauth_states (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  state_hash CHAR(64) NOT NULL,
  code_verifier VARCHAR(128) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY classroom_oauth_state_hash_uq (state_hash),
  KEY classroom_oauth_expiry_idx (expires_at),
  CONSTRAINT classroom_oauth_state_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
