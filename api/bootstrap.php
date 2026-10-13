<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/uploads/eduai-error.log');
header_remove('X-Powered-By');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) === preg_replace('/:\d+$/', '', $host)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
    return $pdo;
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $detail, int $status = 400): never
{
    json_response(['detail' => $detail], $status);
}

function body_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        api_error('A valid JSON request body is required.', 400);
    }
    return $data;
}

function normalize_email(mixed $email): string
{
    $value = strtolower(trim((string)$email));
    if (!filter_var($value, FILTER_VALIDATE_EMAIL) || strlen($value) > 190) {
        api_error('A valid email address is required.', 400);
    }
    return $value;
}

function valid_role(mixed $role, bool $allowAdmin = true): string
{
    $value = strtolower(trim((string)($role ?: 'student')));
    $allowed = $allowAdmin ? ['student', 'teacher', 'admin'] : ['student', 'teacher'];
    if (!in_array($value, $allowed, true)) {
        api_error('Invalid role.', 400);
    }
    return $value;
}

function b64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function b64url_decode(string $value): string|false
{
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function issue_token(array $user): string
{
    $header = b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $now = time();
    $payload = b64url_encode(json_encode([
        'sub' => (string)$user['id'],
        'email' => $user['email'],
        'role' => $user['role'],
        'iat' => $now,
        'exp' => $now + TOKEN_LIFETIME_SECONDS,
    ], JSON_THROW_ON_ERROR));
    $signature = b64url_encode(hash_hmac('sha256', "{$header}.{$payload}", APP_SECRET, true));
    return "{$header}.{$payload}.{$signature}";
}

function bearer_token(): ?string
{
    $header = (string)(
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    );
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                $header = (string)$value;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $match)) {
        return trim($match[1]);
    }
    return null;
}

function token_payload(?string $token = null): ?array
{
    $token ??= bearer_token();
    if (!$token) {
        return null;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$header, $payload, $signature] = $parts;
    $expected = b64url_encode(hash_hmac('sha256', "{$header}.{$payload}", APP_SECRET, true));
    if (!hash_equals($expected, $signature)) {
        return null;
    }
    $decoded = b64url_decode($payload);
    $data = $decoded === false ? null : json_decode($decoded, true);
    if (!is_array($data) || (int)($data['exp'] ?? 0) < time()) {
        return null;
    }
    return $data;
}

function require_auth(?array $roles = null): array
{
    $payload = token_payload();
    if (!$payload) {
        api_error('Login is required.', 401);
    }
    if ($roles !== null && !in_array(strtolower((string)($payload['role'] ?? '')), $roles, true)) {
        api_error('You do not have permission to perform this action.', 403);
    }
    return $payload;
}

function route_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($scriptBase !== '' && $scriptBase !== '/' && str_starts_with($path, $scriptBase)) {
        $path = substr($path, strlen($scriptBase));
    }
    if (!str_starts_with($path, '/api/')) {
        $apiPos = strpos($path, '/api/');
        if ($apiPos !== false) {
            $path = substr($path, $apiPos);
        }
    }
    return '/' . ltrim(rtrim($path, '/'), '/');
}

function safe_upload(array $file, int $maxBytes): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        api_error('The PDF upload did not complete.', 400);
    }
    $size = (int)($file['size'] ?? 0);
    if ($size < 1 || $size > $maxBytes) {
        api_error('The PDF file exceeds the allowed upload size.', 400);
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $name = basename((string)($file['name'] ?? 'document.pdf'));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if ($extension !== 'pdf' || !in_array($mime, ['application/pdf', 'application/x-pdf'], true)) {
        api_error('Only genuine PDF files are allowed.', 400);
    }
    $signature = file_get_contents($tmp, false, null, 0, 5);
    if ($signature !== '%PDF-') {
        api_error('The uploaded file is not a valid PDF.', 400);
    }
    return ['tmp' => $tmp, 'name' => $name, 'size' => $size];
}

set_exception_handler(static function (Throwable $error): never {
    if ($error instanceof ContentDetectionException) api_error($error->getMessage(), 422);
    if ($error instanceof AiServiceException) api_error($error->getMessage(), 503);
    error_log($error->__toString());
    api_error(APP_ENV === 'development' ? $error->getMessage() : 'The server could not complete this request.', 500);
});
