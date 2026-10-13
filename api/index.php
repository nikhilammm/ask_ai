<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/PdfTextExtractor.php';
require_once __DIR__ . '/lib/ContentDetector.php';
require_once __DIR__ . '/lib/LearningEngine.php';
require_once __DIR__ . '/lib/AiProviders.php';
require_once __DIR__ . '/lib/SimplePdf.php';
require_once __DIR__ . '/lib/UnicodeExamPdf.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = preg_replace('#^/api#', '', route_path());
$path = '/' . ltrim((string)$path, '/');

function user_payload(array $user, ?string $token = null): array
{
    $result = [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
    if ($token !== null) {
        $result['access_token'] = $token;
        $result['token_type'] = 'bearer';
    }
    return $result;
}

function fetch_book(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM books WHERE id = ?');
    $stmt->execute([$id]);
    $book = $stmt->fetch();
    if (!$book) {
        api_error('Book not found.', 404);
    }
    return $book;
}

function fetch_lesson(int $id): array
{
    $stmt = db()->prepare(
        'SELECT l.*, c.title AS chapter_title, c.book_id, b.title AS book_title,
                b.class_name, b.subject
         FROM lessons l
         JOIN chapters c ON c.id = l.chapter_id
         JOIN books b ON b.id = c.book_id
         WHERE l.id = ?'
    );
    $stmt->execute([$id]);
    $lesson = $stmt->fetch();
    if (!$lesson) {
        api_error('Selected lesson content was not found.', 404);
    }
    return $lesson;
}

function fetch_chapter_context(int $id): array
{
    $stmt = db()->prepare(
        'SELECT c.*, b.title AS book_title, b.class_name, b.subject
         FROM chapters c
         JOIN books b ON b.id = c.book_id
         WHERE c.id = ?'
    );
    $stmt->execute([$id]);
    $chapter = $stmt->fetch();
    if (!$chapter) {
        api_error('Selected chapter content was not found.', 404);
    }
    if (trim((string)$chapter['content']) === '') {
        $lessons = db()->prepare('SELECT content FROM lessons WHERE chapter_id = ? ORDER BY sort_order, id');
        $lessons->execute([$id]);
        $chapter['content'] = implode("\n\n", array_column($lessons->fetchAll(), 'content'));
    }
    return $chapter;
}

function fetch_book_context(int $id): array
{
    $book = fetch_book($id);
    $stmt = db()->prepare(
        'SELECT c.title AS chapter_title, c.content AS chapter_content,
                l.title AS lesson_title, l.content AS lesson_content
         FROM chapters c
         LEFT JOIN lessons l ON l.chapter_id = c.id
         WHERE c.book_id = ?
         ORDER BY c.sort_order, c.id, l.sort_order, l.id'
    );
    $stmt->execute([$id]);
    $parts = [];
    $seenChapters = [];
    foreach ($stmt->fetchAll() as $row) {
        $chapterTitle = trim((string)$row['chapter_title']);
        if (!isset($seenChapters[$chapterTitle])) {
            $parts[] = "Chapter: {$chapterTitle}";
            $chapterContent = trim((string)$row['chapter_content']);
            if ($chapterContent !== '') {
                $parts[] = $chapterContent;
            }
            $seenChapters[$chapterTitle] = true;
        }
        $lessonContent = trim((string)$row['lesson_content']);
        // Avoid duplicating lesson text when the chapter already stores the complete content.
        if ($lessonContent !== '' && trim((string)$row['chapter_content']) === '') {
            $parts[] = 'Lesson: ' . trim((string)$row['lesson_title']);
            $parts[] = $lessonContent;
        }
    }
    $book['content'] = trim(implode("\n\n", $parts));
    if ($book['content'] === '') {
        api_error('The selected book does not contain readable chapter content.', 404);
    }
    return $book;
}

function save_uploaded_pdf(array $upload, string $folder): string
{
    $base = dirname(__DIR__) . '/uploads/' . $folder;
    if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
        throw new RuntimeException('The upload directory could not be created.');
    }
    $filename = bin2hex(random_bytes(16)) . '.pdf';
    $target = $base . '/' . $filename;
    if (!move_uploaded_file($upload['tmp'], $target)) {
        throw new RuntimeException('The uploaded PDF could not be stored.');
    }
    return $filename;
}

function quiz_record(int $quizId, bool $withAnswers = false): array
{
    $stmt = db()->prepare('SELECT * FROM book_quizzes WHERE id = ?');
    $stmt->execute([$quizId]);
    $quiz = $stmt->fetch();
    if (!$quiz) {
        api_error('Test not found.', 404);
    }
    $q = db()->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order, id');
    $q->execute([$quizId]);
    $questions = [];
    foreach ($q->fetchAll() as $item) {
        $row = [
            'id' => (int)$item['id'],
            'question_type' => $item['question_type'],
            'question_text' => $item['question_text'],
            'question' => $item['question_text'],
            'options' => $item['options'] ? json_decode($item['options'], true) : null,
        ];
        if ($withAnswers) {
            $row['correct_answer'] = $item['correct_answer'];
        }
        $questions[] = $row;
    }
    $quiz['questions'] = $questions;
    return $quiz;
}

function generate_quiz(array $payload, bool $formal): array
{
    $auth = require_auth($formal ? ['teacher', 'admin'] : null);
    $lessonIds = array_map('intval', (array)($payload['lesson_ids'] ?? []));
    if (!$lessonIds && !empty($payload['lesson_id'])) {
        $lessonIds = [(int)$payload['lesson_id']];
    }
    $lessonIds = array_values(array_unique(array_filter($lessonIds)));
    if (!$lessonIds) {
        api_error('Select at least one lesson.', 400);
    }
    $lessons = array_map('fetch_lesson', $lessonIds);
    if (isset($payload['chapter_id'])) {
        foreach ($lessons as $lesson) {
            if ((int)$lesson['chapter_id'] !== (int)$payload['chapter_id']) {
                api_error('The selected lesson does not belong to the selected chapter.', 400);
            }
        }
    }
    if (isset($payload['book_id'])) {
        foreach ($lessons as $lesson) {
            if ((int)$lesson['book_id'] !== (int)$payload['book_id']) {
                api_error('The selected lesson does not belong to the selected book.', 400);
            }
        }
    }
    $content = implode("\n\n", array_column($lessons, 'content'));
    if (count($lessons) > 50) api_error('Select at most 50 lessons per paper.', 400);
    if (is_array($payload['question_counts'] ?? null) && array_sum(array_map('intval', $payload['question_counts'])) > 50) api_error('Generate at most 50 questions per paper.', 400);
    @set_time_limit(180);
    $questions = [];
    if (!empty($payload['question_counts']) && is_array($payload['question_counts'])) {
        $map = [
            'mcq' => 'mcq',
            'fill_in_the_blank' => 'fill_in_the_blank',
            'true_false' => 'true_false',
            'short_answer' => 'short_answer',
        ];
        foreach ($map as $key => $type) {
            $count = max(0, min(50, (int)($payload['question_counts'][$key] ?? 0)));
            if ($count > 0) {
                $generated = $formal ? LearningEngine::generate($content, $type, $count) : AiProviders::generate($content, $type, $count);
                if (count($generated) < $count) {
                    api_error("Only " . count($generated) . " complete {$type} question(s) could be created.", 400);
                }
                array_push($questions, ...$generated);
            }
        }
    } else {
        $count = max(1, min($formal ? 50 : 20, (int)($payload['number_of_questions'] ?? ($formal ? 10 : 5))));
        $generator = $formal ? LearningEngine::class : AiProviders::class;
        $questions = $generator::generate($content, (string)($payload['question_type'] ?? 'MCQ'), $count);
    }
    if (!$questions) {
        api_error('The selected lessons do not contain enough content for these question types.', 400);
    }

    $titlePrefix = $formal ? 'Test Paper' : 'Quiz';
    $title = count($lessons) === 1
        ? $titlePrefix . ': ' . $lessons[0]['title']
        : $titlePrefix . ': ' . count($lessons) . ' selected lessons';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO book_quizzes (title, chapter_id, created_by) VALUES (?, ?, ?)');
        $stmt->execute([$title, (int)$lessons[0]['chapter_id'], (int)$auth['sub']]);
        $quizId = (int)$pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO quiz_questions
             (quiz_id, question_type, question_text, options, correct_answer, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($questions as $index => $question) {
            $insert->execute([
                $quizId,
                $question['question_type'],
                $question['question_text'],
                $question['options'] === null ? null : json_encode($question['options'], JSON_UNESCAPED_UNICODE),
                $question['correct_answer'],
                $index,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    $stored = quiz_record($quizId, true);
    $result = [
        'quiz_id' => $quizId,
        'title' => $title,
        'questions' => array_map(static function (array $q, int $index): array {
            unset($q['correct_answer']);
            $q['number'] = $index + 1;
            return $q;
        }, $stored['questions'], array_keys($stored['questions'])),
        'book_id' => (int)$lessons[0]['book_id'],
        'chapter_id' => (int)$lessons[0]['chapter_id'],
        'chapter_title' => $lessons[0]['chapter_title'],
        'lesson_ids' => $lessonIds,
        'lesson_titles' => array_column($lessons, 'title'),
    ];
    if (in_array(strtolower((string)$auth['role']), ['teacher', 'admin'], true)) {
        $result['answer_key'] = array_map(
            static fn(array $q, int $index): array => ['number' => $index + 1, 'answer' => $q['correct_answer']],
            $stored['questions'],
            array_keys($stored['questions'])
        );
    }
    return $result;
}

// Authentication
if ($method === 'POST' && in_array($path, ['/auth/register', '/auth/signup'], true)) {
    $data = body_json();
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        api_error('Name is required and must be 100 characters or fewer.', 400);
    }
    $email = normalize_email($data['email'] ?? '');
    $password = (string)($data['password'] ?? '');
    if (strlen($password) < 8 || strlen($password) > 128) {
        api_error('Password must contain between 8 and 128 characters.', 400);
    }
    $role = valid_role($data['role'] ?? 'student', false);
    $exists = db()->prepare('SELECT id FROM users WHERE email = ?');
    $exists->execute([$email]);
    if ($exists->fetch()) {
        api_error('Email already registered.', 400);
    }
    $stmt = db()->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
    json_response(['id' => (int)db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => $role]);
}

if ($method === 'POST' && $path === '/auth/login') {
    $data = body_json();
    $email = normalize_email($data['email'] ?? '');
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user || !password_verify((string)($data['password'] ?? ''), $user['password'])) {
        api_error('Invalid email or password.', 401);
    }
    // Always use the role saved with the account. A wrong dropdown selection
    // must not block a valid email and password.
    json_response(user_payload($user, issue_token($user)));
}

if ($method === 'POST' && $path === '/auth/change-password') {
    $auth = require_auth();
    $data = body_json();
    $current = (string)($data['current_password'] ?? '');
    $next = (string)($data['new_password'] ?? '');
    if (strlen($next) < 10 || strlen($next) > 128) {
        api_error('The new password must contain between 10 and 128 characters.', 400);
    }
    $stmt = db()->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([(int)$auth['sub']]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($current, $user['password'])) {
        api_error('The current password is incorrect.', 401);
    }
    db()->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([password_hash($next, PASSWORD_DEFAULT), (int)$auth['sub']]);
    json_response(['message' => 'Password changed successfully.']);
}

if ($method === 'GET' && $path === '/auth/users') {
    require_auth(['admin']);
    $rows = db()->query('SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC')->fetchAll();
    json_response(array_map(static fn(array $row): array => [
        ...$row,
        'id' => (int)$row['id'],
        'password_saved' => true,
    ], $rows));
}

if ($method === 'DELETE' && preg_match('#^/auth/users/(\d+)$#', $path, $m)) {
    $auth = require_auth(['admin']);
    $userId = (int)$m[1];
    if ($userId === (int)$auth['sub']) {
        api_error('You cannot delete the account that is currently logged in.', 400);
    }
    $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) {
        api_error('User account not found.', 404);
    }
    if ($target['role'] === 'admin') {
        api_error('Admin accounts cannot be deleted from this screen.', 400);
    }
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    json_response([
        'message' => 'User deleted successfully.',
        'deleted_user' => [
            'id' => (int)$target['id'],
            'name' => $target['name'],
            'email' => $target['email'],
            'role' => $target['role'],
        ],
    ]);
}

if ($method === 'GET' && $path === '/protected/me') {
    $auth = require_auth();
    $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
    $stmt->execute([(int)$auth['sub']]);
    $user = $stmt->fetch();
    if (!$user) {
        api_error('User not found.', 404);
    }
    json_response(user_payload($user));
}

// Books and content
if ($method === 'GET' && $path === '/content/books') {
    $sql = 'SELECT b.*,
            (SELECT COUNT(*) FROM chapters c WHERE c.book_id = b.id) AS total_chapters,
            (SELECT COUNT(*) FROM lessons l JOIN chapters c2 ON c2.id = l.chapter_id WHERE c2.book_id = b.id) AS total_lessons,
            EXISTS(SELECT 1 FROM book_answer_keys a WHERE a.book_id = b.id) AS answer_key_uploaded
            FROM books b ORDER BY b.id DESC';
    $rows = db()->query($sql)->fetchAll();
    json_response(array_map(static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['total_chapters'] = (int)$row['total_chapters'];
        $row['total_lessons'] = (int)$row['total_lessons'];
        $row['answer_key_uploaded'] = (bool)$row['answer_key_uploaded'];
        unset($row['stored_filename'], $row['original_filename']);
        return $row;
    }, $rows));
}


// Per-page image recovery is staff-only, bounded, and does not require server OCR binaries.
if ($method === 'POST' && $path === '/content/ocr-page') {
    require_auth(['teacher', 'admin']);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 9000000) api_error('Page image is too large.', 413);
    $data = body_json();
    $image = (string)($data['image'] ?? '');
    if (!str_starts_with($image, 'data:image/jpeg;base64,') || strlen($image) > 8500000) api_error('A JPEG page image is required.', 400);
    $image = substr($image, 23);
    $bytes = base64_decode($image, true);
    $info = $bytes === false ? false : @getimagesizefromstring($bytes);
    if (!$info || ($info['mime'] ?? '') !== 'image/jpeg' || $info[0] * $info[1] > 6000000) api_error('Invalid page image.', 400);
    @set_time_limit(65);
    json_response(['text' => AiProviders::ocr($image)]);
}

if ($method === 'POST' && $path === '/content/books/create-from-text') {
    require_auth(['teacher', 'admin']);
    $body = body_json();
    $title = trim((string)($body['title'] ?? ''));
    $className = trim((string)($body['class_name'] ?? ''));
    $subject = trim((string)($body['subject'] ?? ''));
    $originalFilename = trim((string)($body['original_filename'] ?? 'uploaded-book.pdf'));
    $text = trim((string)($body['extracted_text'] ?? ''));

    if ($title === '' || $className === '' || $subject === '') {
        api_error('Title, class and subject are required.', 400);
    }
    if (mb_strlen($text) < 20) {
        api_error('No usable selectable text was found in this PDF.', 400);
    }
    if (mb_strlen($text) > 20000000) {
        api_error('The extracted PDF text is too large to process safely.', 413);
    }

    $originalFilename = basename($originalFilename) ?: 'uploaded-book.pdf';
    $detected = ContentDetector::detect($text);
    if (!$detected) {
        api_error('No valid chapters or lessons could be detected in this PDF.', 400);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO books (title, class_name, subject, original_filename, stored_filename)
             VALUES (?, ?, ?, ?, ?)'
        );
        // The source text is extracted in the browser, so the large original PDF
        // does not have to pass through a shared-hosting request timeout.
        $stmt->execute([$title, $className, $subject, $originalFilename, null]);
        $bookId = (int)$pdo->lastInsertId();
        $chapterInsert = $pdo->prepare('INSERT INTO chapters (book_id, title, content, sort_order) VALUES (?, ?, ?, ?)');
        $lessonInsert = $pdo->prepare('INSERT INTO lessons (chapter_id, title, content, sort_order) VALUES (?, ?, ?, ?)');
        $totalLessons = 0;
        foreach ($detected as $chapterIndex => $chapter) {
            $chapterInsert->execute([$bookId, $chapter['title'], $chapter['content'], $chapterIndex]);
            $chapterId = (int)$pdo->lastInsertId();
            foreach ($chapter['lessons'] as $lessonIndex => $lesson) {
                $lessonInsert->execute([$chapterId, $lesson['title'], $lesson['content'], $lessonIndex]);
                $totalLessons++;
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof PDOException && $error->getCode() === '23000') {
            api_error('This book already exists.', 400);
        }
        throw $error;
    }

    json_response([
        'message' => 'Book content uploaded and chapters/lessons detected successfully',
        'book_id' => $bookId,
        'title' => $title,
        'class_name' => $className,
        'subject' => $subject,
        'total_chapters' => count($detected),
        'total_lessons' => $totalLessons,
        'extracted_characters' => mb_strlen($text),
        'answer_key_uploaded' => false,
    ]);
}

if ($method === 'POST' && $path === '/content/books/upload-pdf') {
    require_auth(['teacher', 'admin']);
    $title = trim((string)($_POST['title'] ?? ''));
    $className = trim((string)($_POST['class_name'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    if ($title === '' || $className === '' || $subject === '') {
        api_error('Title, class and subject are required.', 400);
    }
    $upload = safe_upload($_FILES['file'] ?? [], MAX_BOOK_UPLOAD_BYTES);
    $text = trim((string)($_POST['extracted_text'] ?? ''));
    if (mb_strlen($text) > 20000000) {
        api_error('The extracted PDF text is too large to process safely.', 413);
    }
    if (mb_strlen($text) < 20) {
        try {
            $text = PdfTextExtractor::extract($upload['tmp']);
        } catch (RuntimeException $error) {
            api_error($error->getMessage(), 400);
        }
    }
    $detected = ContentDetector::detect($text);
    $filename = save_uploaded_pdf($upload, 'books');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO books (title, class_name, subject, original_filename, stored_filename)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$title, $className, $subject, $upload['name'], $filename]);
        $bookId = (int)$pdo->lastInsertId();
        $chapterInsert = $pdo->prepare('INSERT INTO chapters (book_id, title, content, sort_order) VALUES (?, ?, ?, ?)');
        $lessonInsert = $pdo->prepare('INSERT INTO lessons (chapter_id, title, content, sort_order) VALUES (?, ?, ?, ?)');
        $totalLessons = 0;
        foreach ($detected as $chapterIndex => $chapter) {
            $chapterInsert->execute([$bookId, $chapter['title'], $chapter['content'], $chapterIndex]);
            $chapterId = (int)$pdo->lastInsertId();
            foreach ($chapter['lessons'] as $lessonIndex => $lesson) {
                $lessonInsert->execute([$chapterId, $lesson['title'], $lesson['content'], $lessonIndex]);
                $totalLessons++;
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        @unlink(dirname(__DIR__) . '/uploads/books/' . $filename);
        if ($error instanceof PDOException && $error->getCode() === '23000') {
            api_error('This book already exists.', 400);
        }
        throw $error;
    }
    json_response([
        'message' => 'PDF uploaded and chapters/lessons detected successfully',
        'book_id' => $bookId,
        'title' => $title,
        'class_name' => $className,
        'subject' => $subject,
        'total_chapters' => count($detected),
        'total_lessons' => $totalLessons,
        'extracted_characters' => mb_strlen($text),
        'answer_key_uploaded' => false,
    ]);
}

if ($method === 'GET' && preg_match('#^/content/books/(\d+)/chapters$#', $path, $m)) {
    fetch_book((int)$m[1]);
    // Chapter cards need metadata only. Returning the full extracted chapter
    // text here can make large (up to 100 MB) book responses too large for the
    // browser, leaving the chapter screen empty even though detection succeeded.
    $stmt = db()->prepare('SELECT id, book_id, title FROM chapters WHERE book_id = ? ORDER BY sort_order, id');
    $stmt->execute([(int)$m[1]]);
    json_response($stmt->fetchAll());
}

if ($method === 'GET' && preg_match('#^/content/chapters/(\d+)/lessons$#', $path, $m)) {
    $stmt = db()->prepare(
        'SELECT id, chapter_id, title, content, "lesson" AS content_source
         FROM lessons WHERE chapter_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([(int)$m[1]]);
    json_response($stmt->fetchAll());
}

if ($method === 'GET' && preg_match('#^/content/lessons/(\d+)$#', $path, $m)) {
    $lesson = fetch_lesson((int)$m[1]);
    json_response([
        'id' => (int)$lesson['id'],
        'chapter_id' => (int)$lesson['chapter_id'],
        'title' => $lesson['title'],
        'content' => $lesson['content'],
        'content_source' => 'lesson',
    ]);
}

if ($method === 'GET' && preg_match('#^/content/books/(\d+)$#', $path, $m)) {
    $book = fetch_book((int)$m[1]);
    $chaptersStmt = db()->prepare('SELECT id, title, content FROM chapters WHERE book_id = ? ORDER BY sort_order, id');
    $chaptersStmt->execute([(int)$m[1]]);
    $chapters = [];
    foreach ($chaptersStmt->fetchAll() as $chapter) {
        $lessonStmt = db()->prepare('SELECT id, title, content FROM lessons WHERE chapter_id = ? ORDER BY sort_order, id');
        $lessonStmt->execute([(int)$chapter['id']]);
        $chapter['lessons'] = $lessonStmt->fetchAll();
        $chapters[] = $chapter;
    }
    unset($book['stored_filename']);
    $book['chapters'] = $chapters;
    json_response($book);
}

if ($method === 'GET' && preg_match('#^/content/books/(\d+)/pdf/download$#', $path, $m)) {
    require_auth(['teacher', 'admin']);
    $book = fetch_book((int)$m[1]);
    if (empty($book['stored_filename'])) {
        api_error('The original editable PDF is unavailable.', 404);
    }
    $file = dirname(__DIR__) . '/uploads/books/' . basename($book['stored_filename']);
    if (!is_file($file)) {
        api_error('The stored book PDF is missing.', 404);
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . rawurlencode($book['original_filename'] ?: 'book.pdf') . '"');
    readfile($file);
    exit;
}

if ($method === 'DELETE' && preg_match('#^/content/books/(\d+)$#', $path, $m)) {
    require_auth(['admin']);
    $book = fetch_book((int)$m[1]);
    $answer = db()->prepare('SELECT stored_filename FROM book_answer_keys WHERE book_id = ?');
    $answer->execute([(int)$m[1]]);
    $answerRow = $answer->fetch();
    $stmt = db()->prepare('DELETE FROM books WHERE id = ?');
    $stmt->execute([(int)$m[1]]);
    if (!empty($book['stored_filename'])) {
        @unlink(dirname(__DIR__) . '/uploads/books/' . basename($book['stored_filename']));
    }
    if ($answerRow) {
        @unlink(dirname(__DIR__) . '/uploads/answer_keys/' . basename($answerRow['stored_filename']));
    }
    json_response(['message' => 'Book and related content deleted successfully']);
}

if ($method === 'POST' && preg_match('#^/content/books/(\d+)/answer-key$#', $path, $m)) {
    require_auth(['admin']);
    $bookId = (int)$m[1];
    fetch_book($bookId);
    $upload = safe_upload($_FILES['file'] ?? [], MAX_ANSWER_KEY_UPLOAD_BYTES);
    $text = trim((string)($_POST['extracted_text'] ?? ''));
    if (mb_strlen($text) > 20000000) api_error('Answer key text is too large.', 413);
    if (mb_strlen($text) < 20) {
        try { $text = PdfTextExtractor::extract($upload['tmp']); }
        catch (RuntimeException $error) { api_error('PDF extraction failed. Please refresh the site and upload again using browser text recovery.', 422); }
    }
    $filename = save_uploaded_pdf($upload, 'answer_keys');
    $existing = db()->prepare('SELECT stored_filename FROM book_answer_keys WHERE book_id = ?');
    $existing->execute([$bookId]);
    $old = $existing->fetch();
    $stmt = db()->prepare(
        'INSERT INTO book_answer_keys (book_id, original_filename, stored_filename, extracted_text)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE original_filename = VALUES(original_filename),
         stored_filename = VALUES(stored_filename), extracted_text = VALUES(extracted_text)'
    );
    $stmt->execute([$bookId, $upload['name'], $filename, $text]);
    if ($old) {
        @unlink(dirname(__DIR__) . '/uploads/answer_keys/' . basename($old['stored_filename']));
    }
    json_response([
        'message' => 'Answer Key uploaded separately and linked to the book.',
        'book_id' => $bookId,
        'answer_key_uploaded' => true,
        'answer_key_filename' => $upload['name'],
        'extracted_characters' => mb_strlen($text),
    ]);
}

if ($method === 'DELETE' && preg_match('#^/content/books/(\d+)/answer-key$#', $path, $m)) {
    require_auth(['admin']);
    $stmt = db()->prepare('SELECT stored_filename FROM book_answer_keys WHERE book_id = ?');
    $stmt->execute([(int)$m[1]]);
    $row = $stmt->fetch();
    if (!$row) {
        api_error('No Answer Key is uploaded for this book.', 404);
    }
    db()->prepare('DELETE FROM book_answer_keys WHERE book_id = ?')->execute([(int)$m[1]]);
    @unlink(dirname(__DIR__) . '/uploads/answer_keys/' . basename($row['stored_filename']));
    json_response(['message' => 'Answer Key deleted. Book and lessons were not changed.']);
}

if ($method === 'GET' && preg_match('#^/content/books/(\d+)/answer-key/download$#', $path, $m)) {
    require_auth(['teacher', 'admin']);
    $stmt = db()->prepare('SELECT original_filename, stored_filename FROM book_answer_keys WHERE book_id = ?');
    $stmt->execute([(int)$m[1]]);
    $row = $stmt->fetch();
    if (!$row) {
        api_error('No Answer Key is uploaded for this book.', 404);
    }
    $file = dirname(__DIR__) . '/uploads/answer_keys/' . basename($row['stored_filename']);
    if (!is_file($file)) {
        api_error('The stored Answer Key file is missing.', 404);
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . rawurlencode($row['original_filename']) . '"');
    readfile($file);
    exit;
}

// Chat and lesson plan
if ($method === 'POST' && in_array($path, ['/chat', '/chat/'], true)) {
    $data = body_json();
    $auth = require_auth(['student', 'teacher', 'admin']);
    $message = trim((string)($data['message'] ?? ''));
    if ($message === '') {
        json_response(['reply' => 'Please enter a question.']);
    }
    if (!empty($data['book_id']) && empty($data['chapter_id'])) {
        $source = fetch_book_context((int)$data['book_id']);
        json_response([
'reply' => AiProviders::answer(
    $source['content'],
    $message,
    $source['title'],
    'book',
    (int)$data['book_id'],
    0,
    0,
    is_array($data['history'] ?? null) ? $data['history'] : []
),
            'source_type' => 'book',
            'source_title' => $source['title'],
        ]);
    }
    if (empty($data['chapter_id'])) {
        json_response(['reply' => 'Please select a chapter before asking a question.']);
    }
    $source = fetch_chapter_context((int)$data['chapter_id']);
    if (!empty($data['book_id']) && (int)$source['book_id'] !== (int)$data['book_id']) {
        api_error('The selected chapter does not belong to the selected book.', 400);
    }
    if (!empty($data['lesson_id'])) {
        $lesson = fetch_lesson((int)$data['lesson_id']);
        if ((int)$lesson['chapter_id'] !== (int)$source['id']) {
            api_error('The selected lesson does not belong to the selected chapter.', 400);
        }
        if (trim((string)$lesson['content']) !== '') {
            $source = $lesson;
            $source['source_type'] = 'lesson';
        }
    }
    json_response([
        'reply' => AiProviders::answer(
            $source['content'],
            $message,
            $source['title'],
            $source['source_type'] ?? 'chapter',
            (int)$source['book_id'],
            (int)$data['chapter_id'],
            (int)($data['lesson_id'] ?? 0),
            is_array($data['history'] ?? null) ? $data['history'] : []
        ),
        'source_type' => $source['source_type'] ?? 'chapter',
        'source_title' => $source['title'],
    ]);
}

if ($method === 'POST' && $path === '/lesson-plan/generate') {
    require_auth(['teacher', 'admin']);
    $data = body_json();
    $lesson = fetch_lesson((int)($data['lesson_id'] ?? 0));
    json_response(AiProviders::lessonPlan($lesson));
}

// Tests, quizzes, PDFs and progress
if ($method === 'POST' && $path === '/test/generate') {
    json_response(generate_quiz(body_json(), true));
}

if ($method === 'POST' && $path === '/test/quiz/generate') {
    json_response(generate_quiz(body_json(), false));
}

if ($method === 'GET' && preg_match('#^/test/(\d+)$#', $path, $m)) {
    $quiz = quiz_record((int)$m[1]);
    json_response(['quiz_id' => (int)$quiz['id'], 'title' => $quiz['title'], 'questions' => $quiz['questions']]);
}

if ($method === 'POST' && preg_match('#^/test/(\d+)/pdf$#', $path, $m)) {
    require_auth(['teacher', 'admin']);
    $data = body_json();
    $quiz = quiz_record((int)$m[1], true);
    $include = (bool)($data['include_answer_key'] ?? false);
    $pdf = UnicodeExamPdf::exam($quiz, $data, $include);
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)($data['exam_title'] ?? 'EduAI-Test'));
    $suffix = $include ? 'with-answer-key' : 'question-paper';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header("Content-Disposition: attachment; filename=\"{$name}-{$suffix}.pdf\"");
    echo $pdf;
    exit;
}

if ($method === 'POST' && $path === '/test/submit') {
    $auth = require_auth();
    $data = body_json();
    $studentId = (int)($data['student_id'] ?? 0);
    if (strtolower((string)$auth['role']) === 'student' && (int)$auth['sub'] !== $studentId) {
        api_error('You can submit a quiz only for your own account.', 403);
    }
    $quiz = quiz_record((int)($data['quiz_id'] ?? 0), true);
    $answers = is_array($data['answers'] ?? null) ? $data['answers'] : [];
    $score = 0;
    $breakdown = [];
    foreach ($quiz['questions'] as $question) {
        $answer = trim((string)($answers[(string)$question['id']] ?? ''));
        $correct = trim((string)$question['correct_answer']);
        $isCorrect = mb_strtolower($answer) === mb_strtolower($correct);
        if ($question['question_type'] === 'short_answer' && $answer !== '') {
            $answerWords = preg_split('/\s+/u', mb_strtolower($answer)) ?: [];
            $correctWords = preg_split('/\s+/u', mb_strtolower($correct)) ?: [];
            $isCorrect = count(array_intersect($answerWords, $correctWords)) >= max(1, min(3, intdiv(count($correctWords), 3)));
        }
        $score += $isCorrect ? 1 : 0;
        $breakdown[] = [
            'question_id' => $question['id'],
            'your_answer' => $answer ?: 'Not Attempted',
            'correct_answer' => $correct,
            'is_correct' => $isCorrect,
        ];
    }
    $total = count($quiz['questions']);
    $stmt = db()->prepare('INSERT INTO student_quiz_results (student_id, quiz_id, score, total) VALUES (?, ?, ?, ?)');
    $stmt->execute([$studentId, (int)$quiz['id'], $score, $total]);
    json_response([
        'score' => $score,
        'total' => $total,
        'percentage' => $total ? round($score * 100 / $total, 2) : 0,
        'breakdown' => $breakdown,
    ]);
}

if ($method === 'GET' && preg_match('#^/test/student/progress/(\d+)$#', $path, $m)) {
    $studentId = (int)$m[1];
    $stmt = db()->prepare(
        'SELECT r.quiz_id, r.score, r.total, r.submitted_at,
                COALESCE(q.title, CONCAT("Quiz ", r.quiz_id)) AS quiz_title
         FROM student_quiz_results r
         LEFT JOIN book_quizzes q ON q.id = r.quiz_id
         WHERE r.student_id = ?
         ORDER BY r.submitted_at DESC'
    );
    $stmt->execute([$studentId]);
    $history = $stmt->fetchAll();
    $percentages = array_map(
        static fn(array $row): float => (int)$row['total'] > 0 ? ((int)$row['score'] / (int)$row['total']) * 100 : 0,
        $history
    );
    json_response([
        'total_quizzes' => count($history),
        'average_score' => $history ? round(array_sum($percentages) / count($history), 2) : 0,
        'history' => $history,
    ]);
}

if ($method === 'GET' && $path === '/health') {
    db()->query('SELECT 1');
    json_response(['status' => 'ok', 'runtime' => 'PHP ' . PHP_VERSION, 'database' => 'MySQL']);
}

api_error('API route not found.', 404);
