UPDATE classroom_live_sessions s
JOIN classroom_jitsi_sessions j ON j.session_id = s.id
SET s.status = 'ended', s.meet_url = NULL, s.updated_at = NOW()
WHERE j.provider = 'jaas' AND s.status IN ('live','provisioning');

UPDATE classroom_session_attendance a
JOIN classroom_jitsi_sessions j ON j.session_id = a.session_id
SET a.left_at = COALESCE(a.left_at, NOW())
WHERE j.provider = 'jaas' AND a.left_at IS NULL;

UPDATE classroom_meeting_settings
SET provider = 'self_hosted', domain = ''
WHERE provider = 'jaas';

UPDATE classroom_jitsi_sessions
SET provider = 'self_hosted', domain = ''
WHERE provider = 'jaas';

ALTER TABLE classroom_meeting_settings
  MODIFY provider ENUM('public','self_hosted') NOT NULL DEFAULT 'self_hosted',
  MODIFY domain VARCHAR(253) NOT NULL DEFAULT '';

ALTER TABLE classroom_jitsi_sessions
  MODIFY provider ENUM('public','self_hosted') NOT NULL;
