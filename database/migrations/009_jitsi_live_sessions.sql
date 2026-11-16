CREATE TABLE IF NOT EXISTS classroom_meeting_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  provider ENUM('public','self_hosted') NOT NULL DEFAULT 'self_hosted',
  domain VARCHAR(253) NOT NULL DEFAULT '',
  updated_by CHAR(36) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT meeting_settings_admin_fk FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO classroom_meeting_settings (id, provider, domain) VALUES (1, 'self_hosted', '');

CREATE TABLE IF NOT EXISTS classroom_jitsi_sessions (
  session_id CHAR(36) PRIMARY KEY,
  provider ENUM('public','self_hosted') NOT NULL,
  domain VARCHAR(253) NOT NULL,
  room_name VARCHAR(320) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY classroom_jitsi_room_uq (provider, domain, room_name),
  CONSTRAINT classroom_jitsi_session_fk FOREIGN KEY (session_id)
    REFERENCES classroom_live_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

UPDATE classroom_live_sessions s
LEFT JOIN classroom_jitsi_sessions j ON j.session_id = s.id
SET s.status = 'ended', s.meet_url = NULL, s.updated_at = NOW()
WHERE s.status IN ('live','provisioning') AND j.session_id IS NULL;
