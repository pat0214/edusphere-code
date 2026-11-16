-- Persist instructor subjects and link each subject classroom to its course.
-- Re-runnable guards are used because this migration may be applied manually.

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'subject') = 0,
  CONCAT('ALTER TABLE users ADD COLUMN subject VARCHAR(160) NOT NULL DEFAULT ', QUOTE(''), ' AFTER bio'),
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'classrooms' AND column_name = 'course_id') = 0,
  'ALTER TABLE classrooms ADD COLUMN course_id CHAR(36) NULL AFTER owner_id',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'classrooms' AND index_name = 'classroom_course_idx') = 0,
  'ALTER TABLE classrooms ADD KEY classroom_course_idx (course_id)',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

SET @migration_sql = IF(
  (SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'classrooms'
     AND constraint_name = 'classroom_course_fk' AND constraint_type = 'FOREIGN KEY') = 0,
  'ALTER TABLE classrooms ADD CONSTRAINT classroom_course_fk FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

INSERT INTO courses (id, instructor_id, title, slug, description, level, published, created_at)
SELECT UUID(), c.owner_id, c.subject,
       CONCAT('classroom-', LOWER(REPLACE(UUID(), '-', ''))),
       CONCAT('Automatically generated course for ', c.subject, '.'),
       'beginner', 0, NOW()
FROM (
  SELECT DISTINCT owner_id, subject
  FROM classrooms
  WHERE status <> 'deleted'
) c
WHERE NOT EXISTS (
    SELECT 1 FROM courses co
    WHERE co.instructor_id = c.owner_id AND co.title = c.subject
  );

UPDATE classrooms c
JOIN courses co ON co.instructor_id = c.owner_id AND co.title = c.subject
SET c.course_id = co.id
WHERE c.course_id IS NULL AND c.status <> 'deleted';
