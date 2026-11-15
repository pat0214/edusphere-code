CREATE TABLE ai_tutor_settings (
  id TINYINT PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  daily_credits INT NOT NULL DEFAULT 5,
  cost_hint INT NOT NULL DEFAULT 1,
  cost_error INT NOT NULL DEFAULT 1,
  cost_concept INT NOT NULL DEFAULT 1,
  cost_pseudocode INT NOT NULL DEFAULT 2,
  cost_solution INT NOT NULL DEFAULT 3,
  model VARCHAR(100) NOT NULL DEFAULT '',
  input_cost_per_million_usd DECIMAL(10,4) NOT NULL DEFAULT 0,
  output_cost_per_million_usd DECIMAL(10,4) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO ai_tutor_settings (id) VALUES (1);

INSERT INTO badges (id, code, name, description, icon, accent, xp_reward) VALUES
  ('b-indsolver','independent-solver','Independent Solver','Completed an exercise without using the AI assistant first.','target','mint',25),
  ('b-thinker','critical-thinker','Critical Thinker','Solved an exercise after using hints without requesting a complete solution.','brain','indigo',30);

CREATE TABLE ai_tutor_daily_credits (
  user_id CHAR(36) NOT NULL,
  credit_date DATE NOT NULL,
  credits_used INT NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, credit_date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ai_tutor_usage (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  exercise_id CHAR(36) NULL,
  request_id CHAR(36) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  credit_date DATE NOT NULL,
  mode ENUM('learning','debugging','review') NOT NULL,
  action ENUM('hint','error','concept','pseudocode','solution') NOT NULL,
  hint_level TINYINT NULL,
  status ENUM('pending','succeeded','failed') NOT NULL DEFAULT 'pending',
  credits INT NOT NULL,
  model VARCHAR(100) NOT NULL,
  response_text MEDIUMTEXT NULL,
  prompt_tokens INT NOT NULL DEFAULT 0,
  output_tokens INT NOT NULL DEFAULT 0,
  estimated_cost_usd DECIMAL(12,8) NULL,
  error_code VARCHAR(40) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  UNIQUE KEY ai_tutor_request_uq (user_id, request_id),
  KEY ai_tutor_usage_user_date_idx (user_id, created_at),
  KEY ai_tutor_usage_exercise_idx (user_id, exercise_id, status),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (exercise_id) REFERENCES coding_exercises(id) ON DELETE SET NULL
) ENGINE=InnoDB;
