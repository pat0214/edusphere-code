<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class CourseController extends MY_Controller
{
    private $users;
    private $courses;
    private $gamify;
    private $certificates;
    private $materialUpload;

    public function __construct()
    {
        parent::__construct();
        $this->call->library('Clients');
        $this->users = $this->call->model('User_model');
        $this->courses = $this->call->model('Course_model');
        $gamifyModel = $this->call->model('Gamify_model');
        $this->gamify = $this->call->library('Gamify', $gamifyModel);
        $this->certificates = $this->call->library('CourseCertificate', [
            $this->courses,
            $this->users,
            $this->gamify,
        ]);
        $this->materialUpload = $this->call->library('MaterialUpload');
    }

    public function index()
    {
        $user = auth_user($this->users);
        $rows = $this->courses->coursesForUser($user);
        respond(array_map(static function (array $course): array {
            $course['published'] = (bool)$course['published'];
            $course['enrolled'] = (bool)$course['enrolled'];
            $course['lessonCount'] = (int)$course['lessonCount'];
            $course['completedLessons'] = (int)$course['completedLessons'];
            return $course;
        }, $rows));
    }

    public function create()
    {
        $user = require_role($this->users, ['instructor']);
        $body = json_body();
        $title = trim((string)($body['title'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $level = (string)($body['level'] ?? 'beginner');
        if (strlen($title) < 3 || strlen($title) > 160) fail_api('Course title must be between 3 and 160 characters.', 422, ['title' => 'Invalid title.']);
        if (strlen($description) < 10 || strlen($description) > 5000) fail_api('Course description must be between 10 and 5,000 characters.', 422, ['description' => 'Invalid description.']);
        if (!in_array($level, ['beginner', 'intermediate', 'advanced'], true)) fail_api('Choose a valid course level.', 422);

        $course = $this->courses->createCourse($user['id'], $title, $description, $level);
        respond($course + ['title' => $title], 'Course created as a draft. Add lessons, course materials, coding assignments, and a final assessment before publishing.', 201);
    }

    public function detail($id)
    {
        $user = auth_user($this->users);
        $course = $this->courses->courseDetail($id, $user);
        if (!$course) fail_api('Course not found.', 404);
        respond($course);
    }

    public function update($id)
    {
        $user = require_role($this->users, ['instructor']);
        $body = json_body();
        if (!array_key_exists('published', $body) || !is_bool($body['published'])) {
            fail_api('Set published to true or false.', 422);
        }
        if (!$this->courses->courseOwner($id, $user['id'])) fail_api('Course not found.', 404);
        if ($body['published'] && !$this->courses->canPublish($id)) {
            fail_api('Before publishing, add at least one lesson, one published coding assignment, and a final assessment with questions.', 409);
        }
        $this->courses->setPublished($id, $body['published']);
        respond(['id' => $id, 'published' => $body['published']], $body['published'] ? 'Course published.' : 'Course unpublished.');
    }

    public function enroll($id)
    {
        $user = require_role($this->users, ['student']);
        if (!$this->courses->enroll($id, $user['id'])) fail_api('Published course not found.', 404);
        respond(['enrolled' => true], "You're enrolled in this course.");
    }

    public function save_final_assessment($id)
    {
        $user = require_role($this->users, ['instructor']);
        $body = json_body();
        $title = trim((string)($body['title'] ?? ''));
        $passScore = filter_var($body['passScore'] ?? 80, FILTER_VALIDATE_INT);
        $questions = $body['questions'] ?? null;
        if (!$this->courses->courseOwner($id, $user['id'])) fail_api('Course not found in your instructor account.', 404);
        if (strlen($title) < 3 || strlen($title) > 160) fail_api('Assessment title must be between 3 and 160 characters.', 422);
        if ($passScore === false || $passScore < 50 || $passScore > 100) fail_api('Passing score must be between 50 and 100.', 422);
        if (!is_array($questions) || count($questions) < 1 || count($questions) > 100) fail_api('Add between 1 and 100 assessment questions.', 422);

        $validated = [];
        foreach ($questions as $question) {
            if (!is_array($question)) fail_api('Each assessment question must be an object.', 422);
            $prompt = trim((string)($question['prompt'] ?? ''));
            $options = $question['options'] ?? null;
            $correctOption = filter_var($question['correctOption'] ?? null, FILTER_VALIDATE_INT);
            $points = filter_var($question['points'] ?? 1, FILTER_VALIDATE_INT);
            if (strlen($prompt) < 5 || strlen($prompt) > 2000) fail_api('Question prompts must be between 5 and 2,000 characters.', 422);
            if (!is_array($options) || count($options) !== 4) fail_api('Each question must have exactly four options.', 422);
            foreach ($options as $option) {
                if (!is_string($option) || strlen(trim($option)) < 1 || strlen($option) > 500) fail_api('Each answer option must be between 1 and 500 characters.', 422);
            }
            if ($correctOption === false || $correctOption < 0 || $correctOption > 3) fail_api('Choose a valid correct option for each question.', 422);
            if ($points === false || $points < 1 || $points > 100) fail_api('Question points must be between 1 and 100.', 422);
            $validated[] = [
                'prompt' => $prompt,
                'options' => array_map('trim', $options),
                'correctOption' => $correctOption,
                'points' => $points,
            ];
        }

        $assessmentId = $this->courses->saveAssessment($id, $title, $passScore, $validated);
        respond(['id' => $assessmentId], 'Final assessment saved.');
    }

    public function submit_final_assessment($id)
    {
        $student = require_role($this->users, ['student']);
        $body = json_body();
        $answers = $body['answers'] ?? null;
        if (!is_array($answers) || !$answers) fail_api('Answer every question before submitting.', 422);
        if (!$this->courses->hasAccess($id, $student['id'])) fail_api('Enroll in this course before taking its final assessment.', 403);
        $assessment = $this->courses->assessmentForSubmission($id);
        if (!$assessment) fail_api('This course has no final assessment yet.', 404);
        $questions = $this->courses->questionsForGrading($assessment['id']);
        if (!$questions) fail_api('The final assessment has no questions.', 409);

        $answerMap = [];
        foreach ($answers as $answer) {
            if (!is_array($answer) || empty($answer['questionId'])) fail_api('Each answer must include its question ID and selected option.', 422);
            $selectedOption = filter_var($answer['selectedOption'] ?? null, FILTER_VALIDATE_INT);
            if ($selectedOption === false || $selectedOption < 0 || $selectedOption > 3) fail_api('Choose a valid option for every question.', 422);
            $questionId = (string)$answer['questionId'];
            if (isset($answerMap[$questionId])) fail_api('Answer every question exactly once.', 422);
            $answerMap[$questionId] = $selectedOption;
        }
        if (count($answerMap) !== count($questions)) fail_api('Answer every question exactly once.', 422);

        $earned = 0;
        $possible = 0;
        foreach ($questions as $question) {
            if (!array_key_exists($question['id'], $answerMap)) fail_api('Answer every question exactly once.', 422);
            $points = (int)$question['points'];
            $possible += $points;
            if ($answerMap[$question['id']] === (int)$question['correct_option']) $earned += $points;
        }
        $score = round($earned / $possible * 100, 2);
        $passed = $score >= (int)$assessment['pass_score'];
        $this->courses->recordAssessmentAttempt($assessment['id'], $student['id'], $score, $passed);
        $issued = $passed ? $this->certificates->issue($id, $student['id']) : ['code' => null, 'emailSent' => null];
        $message = $passed
            ? ($issued['code'] ? 'Passed. Course certificate issued.' : 'Passed. Complete all course lessons and coding assignments to earn the certificate.')
            : "Scored $score%. {$assessment['pass_score']}% is required to pass.";
        respond([
            'score' => $score,
            'passed' => $passed,
            'passScore' => (int)$assessment['pass_score'],
            'certificateCode' => $issued['code'],
        ], $message);
    }

    public function progress()
    {
        $student = require_role($this->users, ['student']);
        $body = json_body();
        $courseId = (string)($body['courseId'] ?? '');
        $lessonId = (string)($body['lessonId'] ?? '');
        $timeSpentSec = filter_var($body['timeSpentSec'] ?? 0, FILTER_VALIDATE_INT);
        if (!preg_match('/^[0-9a-f-]{36}$/i', $courseId) || !preg_match('/^[0-9a-f-]{36}$/i', $lessonId)) {
            fail_api('A valid course and lesson are required.', 422);
        }
        if ($timeSpentSec === false || $timeSpentSec < 0 || $timeSpentSec > 86400) fail_api('Time spent must be between 0 and 86,400 seconds.', 422);
        if (!$this->courses->hasAccess($courseId, $student['id'])) fail_api('Enroll in this course to record lesson progress.', 403);
        if (!$this->courses->lessonInCourse($lessonId, $courseId)) fail_api('Lesson not found in this course.', 404);
        if (!$this->courses->recordCourseProgress($student['id'], $lessonId, $timeSpentSec)) fail_api('This lesson is already complete.', 409);

        $xp = $this->gamify->addXp($student['id'], 40);
        $streak = $this->gamify->touchStreak($student['id']);
        $issued = $this->certificates->issue($courseId, $student['id']);
        respond([
            'completed' => true,
            'xp' => $xp['xp'],
            'level' => $xp['level'],
            'streak' => $streak,
            'certificateCode' => $issued['code'],
        ], $issued['code'] ? 'Lesson complete. Course certificate issued.' : 'Lesson complete.');
    }

    public function create_module($courseId)
    {
        $user = require_role($this->users, ['instructor']);
        $body = json_body();
        $title = trim((string)($body['title'] ?? ''));
        if (strlen($title) < 2 || strlen($title) > 160) fail_api('Module title must be between 2 and 160 characters.', 422);
        if (!$this->courses->courseOwner($courseId, $user['id'])) fail_api('Course not found in your instructor account.', 404);
        $id = $this->courses->createModule($courseId, $title);
        respond(['id' => $id, 'title' => $title], 'Module added.', 201);
    }

    public function create_quiz($moduleId)
    {
        $user = require_role($this->users, ['instructor']);
        $body = json_body();
        $title = trim((string)($body['title'] ?? ''));
        $questions = $body['questions'] ?? null;
        $module = $this->courses->moduleCourse($moduleId);
        if (!$module || !$this->courses->courseOwner($module['course_id'], $user['id'])) {
            fail_api('Module not found in a course you teach.', 404);
        }
        if (strlen($title) < 3 || strlen($title) > 160) {
            fail_api('Quiz title must be between 3 and 160 characters.', 422);
        }
        if (!is_array($questions) || count($questions) < 1 || count($questions) > 50) {
            fail_api('Add between 1 and 50 quiz questions.', 422);
        }

        $validated = [];
        foreach ($questions as $question) {
            if (!is_array($question)) fail_api('Each quiz question must be an object.', 422);
            $type = $question['type'] ?? '';
            $prompt = trim((string)($question['prompt'] ?? ''));
            $points = filter_var($question['points'] ?? 1, FILTER_VALIDATE_INT);
            if (!in_array($type, ['multiple_choice', 'identification'], true)) {
                fail_api('Choose multiple choice or identification for each question.', 422);
            }
            if (strlen($prompt) < 5 || strlen($prompt) > 2000) {
                fail_api('Question prompts must be between 5 and 2,000 characters.', 422);
            }
            if ($points === false || $points < 1 || $points > 100) {
                fail_api('Question points must be between 1 and 100.', 422);
            }

            $validatedQuestion = [
                'type' => $type,
                'prompt' => $prompt,
                'options' => null,
                'correctOption' => null,
                'acceptedAnswers' => null,
                'points' => $points,
            ];
            if ($type === 'multiple_choice') {
                $options = $question['options'] ?? null;
                $correctOption = filter_var($question['correctOption'] ?? null, FILTER_VALIDATE_INT);
                if (!is_array($options) || count($options) !== 4) {
                    fail_api('Each multiple-choice question must have exactly four options.', 422);
                }
                foreach ($options as $option) {
                    if (!is_string($option) || strlen(trim($option)) < 1 || strlen($option) > 500) {
                        fail_api('Each answer option must be between 1 and 500 characters.', 422);
                    }
                }
                if ($correctOption === false || $correctOption < 0 || $correctOption > 3) {
                    fail_api('Choose the correct option for each multiple-choice question.', 422);
                }
                $validatedQuestion['options'] = array_map('trim', $options);
                $validatedQuestion['correctOption'] = $correctOption;
            } else {
                $acceptedAnswers = $question['acceptedAnswers'] ?? null;
                if (!is_array($acceptedAnswers) || count($acceptedAnswers) < 1 || count($acceptedAnswers) > 20) {
                    fail_api('Add between 1 and 20 accepted answers for each identification question.', 422);
                }
                foreach ($acceptedAnswers as $answer) {
                    if (!is_string($answer) || strlen(trim($answer)) < 1 || strlen($answer) > 500) {
                        fail_api('Accepted answers must be between 1 and 500 characters.', 422);
                    }
                }
                $validatedQuestion['acceptedAnswers'] = array_values(array_unique(array_map('trim', $acceptedAnswers)));
            }
            $validated[] = $validatedQuestion;
        }

        $quizId = $this->courses->createModuleQuiz($moduleId, $title, $validated);
        respond(['id' => $quizId], 'Quiz added to this module.', 201);
    }

    public function submit_quiz($quizId)
    {
        $student = require_role($this->users, ['student']);
        $body = json_body();
        $answers = $body['answers'] ?? null;
        if (!is_array($answers) || !$answers) fail_api('Answer every question before submitting.', 422);

        $quiz = $this->courses->quizCourse($quizId);
        if (!$quiz) fail_api('Quiz not found.', 404);
        if (!$this->courses->hasAccess($quiz['courseId'], $student['id'])) {
            fail_api('Enroll in this course before taking its quizzes.', 403);
        }
        $questions = $this->courses->questionsForQuiz($quizId);
        if (!$questions) fail_api('This quiz has no questions.', 409);

        $questionsById = [];
        foreach ($questions as $question) $questionsById[$question['id']] = $question;
        $answersById = [];
        foreach ($answers as $answer) {
            if (!is_array($answer) || empty($answer['questionId'])) {
                fail_api('Each answer must include its question ID and response.', 422);
            }
            $questionId = (string)$answer['questionId'];
            if (!isset($questionsById[$questionId])) fail_api('This quiz contains an unknown question.', 422);
            if (isset($answersById[$questionId])) fail_api('Answer every question exactly once.', 422);

            $question = $questionsById[$questionId];
            if ($question['type'] === 'multiple_choice') {
                $selectedOption = filter_var($answer['selectedOption'] ?? null, FILTER_VALIDATE_INT);
                if ($selectedOption === false || $selectedOption < 0 || $selectedOption > 3) {
                    fail_api('Choose an option for every multiple-choice question.', 422);
                }
                $response = (string)$selectedOption;
            } else {
                $response = trim((string)($answer['response'] ?? ''));
                if ($response === '' || strlen($response) > 500) {
                    fail_api('Identification answers must be between 1 and 500 characters.', 422);
                }
            }
            $answersById[$questionId] = $response;
        }
        if (count($answersById) !== count($questions)) fail_api('Answer every question exactly once.', 422);

        $earnedPoints = 0;
        $possiblePoints = 0;
        $gradedAnswers = [];
        $results = [];
        foreach ($questions as $question) {
            $questionId = $question['id'];
            if (!array_key_exists($questionId, $answersById)) fail_api('Answer every question exactly once.', 422);
            $response = $answersById[$questionId];
            $points = (int)$question['points'];
            $possiblePoints += $points;
            $options = $question['type'] === 'multiple_choice'
                ? json_decode($question['options'], true, 512, JSON_THROW_ON_ERROR)
                : [];
            $acceptedAnswers = $question['type'] === 'identification'
                ? json_decode($question['acceptedAnswers'], true, 512, JSON_THROW_ON_ERROR)
                : [];
            if ($question['type'] === 'multiple_choice') {
                $correct = (int)$response === (int)$question['correctOption'];
                $displayResponse = $options[(int)$response];
                $expected = $options[(int)$question['correctOption']];
            } else {
                $normalize = static function (string $value): string {
                    return preg_replace('/\s+/u', ' ', mb_strtolower(trim($value), 'UTF-8')) ?? '';
                };
                $correct = in_array($normalize($response), array_map($normalize, $acceptedAnswers), true);
                $displayResponse = $response;
                $expected = $acceptedAnswers;
            }
            $pointsAwarded = $correct ? $points : 0;
            $earnedPoints += $pointsAwarded;
            $gradedAnswers[] = [
                'questionId' => $questionId,
                'response' => $response,
                'correct' => $correct,
                'pointsAwarded' => $pointsAwarded,
            ];
            $results[] = [
                'questionId' => $questionId,
                'prompt' => $question['prompt'],
                'response' => $displayResponse,
                'correct' => $correct,
                'expected' => $expected,
                'pointsAwarded' => $pointsAwarded,
                'points' => $points,
            ];
        }
        $score = round($earnedPoints / $possiblePoints * 100, 2);
        $this->courses->recordQuizAttempt(
            $quizId,
            $student['id'],
            $score,
            $earnedPoints,
            $possiblePoints,
            $gradedAnswers
        );
        respond([
            'score' => $score,
            'earnedPoints' => $earnedPoints,
            'possiblePoints' => $possiblePoints,
            'results' => $results,
        ], "Quiz submitted: $score% ($earnedPoints/$possiblePoints points).");
    }

    public function create_lesson($moduleId)
    {
        $user = require_role($this->users, ['instructor']);
        $body = json_body();
        $title = trim((string)($body['title'] ?? ''));
        $content = trim((string)($body['content'] ?? ''));
        $minutes = filter_var($body['estimatedMinutes'] ?? 20, FILTER_VALIDATE_INT);
        if (strlen($title) < 2 || strlen($title) > 160) fail_api('Lesson title must be between 2 and 160 characters.', 422);
        if (strlen($content) < 10 || strlen($content) > 500000) fail_api('Lesson content must be between 10 and 500,000 characters.', 422);
        if ($minutes === false || $minutes < 1 || $minutes > 600) fail_api('Estimated minutes must be between 1 and 600.', 422);
        $module = $this->courses->moduleCourse($moduleId);
        if (!$module || !$this->courses->courseOwner($module['course_id'], $user['id'])) fail_api('Module not found in a course you teach.', 404);
        $id = $this->courses->createLesson($moduleId, $title, $content, $minutes);
        respond(['id' => $id, 'title' => $title], 'Lesson added.', 201);
    }

    public function upload_material($moduleId)
    {
        $user = require_role($this->users, ['instructor']);
        $module = $this->courses->moduleCourse($moduleId);
        if (!$module || !$this->courses->courseOwner($module['course_id'], $user['id'])) fail_api('Module not found in a course you teach.', 404);
        $storageRoot = rtrim(APP_DIR, '/\\') . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'course-materials';
        try {
            $stored = $this->materialUpload->store($_FILES['file'] ?? null, $storageRoot);
        } catch (MaterialUploadException $error) {
            fail_api($error->getMessage(), $error->statusCode());
        }
        $storageKey = $stored['storageKey'];
        $materialId = uuid4();
        try {
            $this->courses->insertMaterial([
                'id' => $materialId,
                'courseId' => $module['course_id'],
                'moduleId' => $moduleId,
                'uploadedBy' => $user['id'],
                'name' => $stored['name'],
                'storageKey' => $storageKey,
                'contentType' => $stored['contentType'],
                'bytes' => $stored['bytes'],
            ]);
        } catch (Throwable $error) {
            $destination = $storageRoot . DIRECTORY_SEPARATOR . $storageKey;
            if (is_file($destination) && !unlink($destination)) {
                error_log("Failed to remove orphaned course material $storageKey.");
            }
            throw $error;
        }
        respond([
            'id' => $materialId, 'name' => $stored['name'], 'bytes' => $stored['bytes'],
            'contentType' => $stored['contentType'],
        ], 'Course material uploaded.', 201);
    }

    public function download_material($id)
    {
        $user = auth_user($this->users);
        $material = $this->courses->materialById($id);
        if (!$material) fail_api('Course material not found.', 404);
        if ($user['role'] !== 'admin' && $material['instructorId'] !== $user['id']
            && !$this->courses->hasAccess($material['courseId'], $user['id'])) {
            fail_api('Enroll in this course to access its materials.', 403);
        }
        if (!preg_match('/^[0-9a-f-]{36}$/i', $material['storageKey'])) fail_api('Course material storage key is invalid.', 500);
        $storageRoot = rtrim(APP_DIR, '/\\') . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '.data' . DIRECTORY_SEPARATOR . 'course-materials';
        $path = $storageRoot . DIRECTORY_SEPARATOR . $material['storageKey'];
        if (!is_file($path) || !is_readable($path)) fail_api('The stored course material is missing. Ask the instructor to upload it again.', 404);

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
