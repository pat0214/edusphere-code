<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/** @var object $router **/

// API controllers apply the configured LavaLust CORS policy in before_action().

// ---- infra ----
$router->get('/',                              'AuthController::index');
$router->get('/api/v1/health',                 'AuthController::health');
$router->post('/api/pico/chat',                'PicoChat::send');

// ---- auth ----
$router->post('/api/v1/auth/register',         'AuthController::register');
$router->post('/api/v1/auth/login',            'AuthController::login');
$router->post('/api/v1/auth/google',           'AuthController::google');
$router->post('/api/v1/auth/verify-email',     'AuthController::verify_email');
$router->post('/api/v1/auth/resend-verification', 'AuthController::resend_verification');
$router->post('/api/v1/auth/refresh',          'AuthController::refresh');
$router->post('/api/v1/auth/logout',           'AuthController::logout');
$router->get ('/api/v1/auth/me',               'AuthController::me');
$router->patch('/api/v1/profile',              'AuthController::profile_update');

// ---- catalog ----
$router->get ('/api/v1/courses',               'CourseController::index');
$router->post('/api/v1/courses',               'CourseController::create');
$router->get ('/api/v1/courses/{id}',          'CourseController::detail');
$router->patch('/api/v1/courses/{id}',         'CourseController::update');
$router->post('/api/v1/courses/{id}/enroll',    'CourseController::enroll');
$router->put ('/api/v1/courses/{id}/final-assessment', 'CourseController::save_final_assessment');
$router->post('/api/v1/courses/{id}/final-assessment', 'CourseController::submit_final_assessment');
$router->post('/api/v1/courses/{id}/modules',   'CourseController::create_module');
$router->post('/api/v1/modules/{id}/quizzes',   'CourseController::create_quiz');
$router->post('/api/v1/quizzes/{id}/submit',   'CourseController::submit_quiz');
$router->post('/api/v1/modules/{id}/lessons',  'CourseController::create_lesson');
$router->post('/api/v1/modules/{id}/materials','CourseController::upload_material');
$router->get ('/api/v1/course-materials/{id}', 'CourseController::download_material');
$router->post('/api/v1/course-progress',        'CourseController::progress');
$router->get ('/api/v1/lessons/{id}',          'LearningController::lesson');

// ---- coding classroom ----
$router->get ('/api/v1/coding-assignments',               'ClassroomController::index');
$router->post('/api/v1/coding-assignments',               'ClassroomController::create');
$router->get ('/api/v1/coding-assignments/{id}/attempts', 'ClassroomController::attempts');
$router->get ('/api/v1/coding-assignments/{id}',          'ClassroomController::detail');
$router->patch('/api/v1/coding-assignments/{id}',         'ClassroomController::update');

// ---- subject classrooms ----
$router->get ('/api/v1/classrooms/google/callback',          'ClassroomMeetingController::callback');
$router->get ('/api/v1/classrooms/google/connection',        'ClassroomMeetingController::connection');
$router->post('/api/v1/classrooms/google/connect',           'ClassroomMeetingController::connect');
$router->delete('/api/v1/classrooms/google/connection',      'ClassroomMeetingController::disconnect');
$router->get ('/api/v1/classrooms',                         'SubjectClassroomController::index');
$router->post('/api/v1/classrooms',                         'SubjectClassroomController::create');
$router->get ('/api/v1/classrooms/invites/{token}',          'SubjectClassroomController::invitePreview');
$router->post('/api/v1/classrooms/join/code',               'SubjectClassroomController::joinCode');
$router->post('/api/v1/classrooms/join/invite/{token}',     'SubjectClassroomController::joinInvite');
$router->get ('/api/v1/classrooms/{id}/members',             'SubjectClassroomController::members');
$router->get ('/api/v1/classrooms/{id}/invite',              'SubjectClassroomController::inviteDetails');
$router->patch('/api/v1/classrooms/{id}/invite',             'SubjectClassroomController::updateInvite');
$router->post('/api/v1/classrooms/{id}/members/{memberId}/approve', 'SubjectClassroomController::approve');
$router->post('/api/v1/classrooms/{id}/members/{memberId}/decline', 'SubjectClassroomController::decline');
$router->post('/api/v1/classrooms/{id}/members/{memberId}/remove',  'SubjectClassroomController::removeMember');
$router->get ('/api/v1/classrooms/{id}/live-sessions',       'ClassroomMeetingController::sessions');
$router->post('/api/v1/classrooms/{id}/live-sessions/start', 'ClassroomMeetingController::start');
$router->post('/api/v1/classroom-sessions/{id}/join',        'ClassroomMeetingController::join');
$router->post('/api/v1/classroom-sessions/{id}/leave',       'ClassroomMeetingController::leave');
$router->post('/api/v1/classroom-sessions/{id}/end',         'ClassroomMeetingController::end');
$router->post('/api/v1/classrooms/{id}/leave',               'SubjectClassroomController::leave');
$router->post('/api/v1/classrooms/{id}/archive',             'SubjectClassroomController::archive');
$router->post('/api/v1/classrooms/{id}/restore',             'SubjectClassroomController::restore');
$router->get ('/api/v1/classrooms/{id}',                     'SubjectClassroomController::detail');
$router->patch('/api/v1/classrooms/{id}',                    'SubjectClassroomController::update');

// ---- assessment + path ----
$router->get ('/api/v1/assessments/placement',        'LearningController::placement');
$router->post('/api/v1/assessments/placement/submit', 'LearningController::placement_submit');
$router->get ('/api/v1/learning-path',                'LearningController::path');
$router->post('/api/v1/learning-path/generate',       'LearningController::path_generate');
$router->post('/api/v1/learning-path/coach',          'LearningController::path_coach');
$router->post('/api/v1/learning-path/progress',       'LearningController::path_progress');

// ---- playground ----
$router->get ('/api/v1/exercises',             'LabController::exercises');
$router->post('/api/v1/playground/run',        'LabController::run');
$router->get ('/api/v1/ai-tutor/credits',       'AiTutorController::credits');
$router->post('/api/v1/ai-tutor/chat',          'AiTutorController::chat');

// ---- submissions + peer review ----
$router->get ('/api/v1/submissions',           'LabController::submissions');
$router->post('/api/v1/submissions',           'LabController::submit_assignment');
$router->get ('/api/v1/peer-review/queue',     'LabController::review_queue');
$router->post('/api/v1/peer-review/submit',    'LabController::review_submit');
$router->get ('/api/v1/peer-review/feedback',  'LabController::review_feedback');

// ---- analytics ----
$router->get ('/api/v1/analytics/student',     'MetaController::analytics_student');
$router->get ('/api/v1/analytics/instructor',  'MetaController::analytics_instructor');
$router->get ('/api/v1/analytics/leaderboard', 'MetaController::leaderboard');

// ---- credentials ----
$router->get ('/api/v1/badges',                'MetaController::badges');
$router->get ('/api/v1/certificates',          'MetaController::certificates');
$router->post('/api/v1/certificates/{code}/email', 'MetaController::email_certificate');
$router->get ('/api/v1/verify/{code}',         'MetaController::verify');

// ---- sessions / notifications ----
$router->get ('/api/v1/sessions',              'MetaController::sessions');
$router->post('/api/v1/sessions',              'MetaController::session_create');
$router->get ('/api/v1/notifications',         'MetaController::notifications');
$router->patch('/api/v1/notifications',        'MetaController::notifications_read');
$router->get ('/api/v1/notifications/preferences', 'NotificationController::preferences');
$router->patch('/api/v1/notifications/preferences', 'NotificationController::updatePreferences');
$router->post('/api/v1/internal/notification-email-worker', 'NotificationController::processEmailQueue');

// ---- commerce / storage ----
$router->post('/api/v1/payments/checkout',     'MetaController::checkout');
$router->post('/api/v1/payments/verify',       'MetaController::verify_payment');
$router->get ('/api/v1/payments/subscription', 'MetaController::subscription');
$router->post('/api/v1/payments/paymongo-webhook', 'MetaController::paymongo_webhook');
$router->post('/api/v1/uploads/presign',       'MetaController::presign');

// ---- admin ----
$router->get ('/api/v1/admin/analytics',       'AdminController::analytics');
$router->get ('/api/v1/admin/users',           'AdminController::users');
$router->post('/api/v1/admin/instructors',      'AdminController::create_instructor');
$router->patch('/api/v1/admin/users',          'AdminController::update_user');
$router->get ('/api/v1/admin/ai-tutor',        'AdminController::ai_tutor');
$router->patch('/api/v1/admin/ai-tutor',      'AdminController::update_ai_tutor');
$router->get ('/api/v1/admin/notification-email-stats', 'NotificationController::adminEmailStats');
$router->get ('/api/v1/admin/classroom-stats', 'ClassroomMeetingController::adminStats');
$router->get ('/api/v1/admin/classroom-settings', 'ClassroomMeetingController::adminSettings');
$router->patch('/api/v1/admin/classroom-settings', 'ClassroomMeetingController::updateAdminSettings');
$router->patch('/api/v1/admin/classrooms/{id}', 'ClassroomMeetingController::adminUpdateClassroom');
$router->post('/api/v1/admin/classrooms/{id}/access', 'SubjectClassroomController::grantAdminAccess');
