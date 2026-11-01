<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($requestPath)) {
    http_response_code(400);
    exit;
}

$requestPath = rawurldecode(str_replace('\\', '/', $requestPath));
$segments = explode('/', trim($requestPath, '/'));
$topLevel = strtolower($segments[0] ?? '');
$baseName = basename($requestPath);
if ($requestPath === '/router.php'
    || in_array($topLevel, ['config', 'src', 'sql', 'tests', 'scripts', 'storage', '.git'], true)
    || ($topLevel === 'admin' && str_starts_with($baseName, '_'))
    || preg_match('~(?:^|/)\.[^/]*~', $requestPath) === 1) {
    http_response_code(404);
    exit;
}

if ($requestPath === '/') {
    header('Location: index.php', true, 302);
    exit;
}

$extension = strtolower(pathinfo($requestPath, PATHINFO_EXTENSION));
if ($extension === 'html' && $topLevel === 'admin') {
    header('Location: /auth.php?action=login', true, 302);
    exit;
}
if ($extension === 'html' && $topLevel === 'organizer') {
    header('Location: /organizer/events.php', true, 302);
    exit;
}

$filePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace(
    '/',
    DIRECTORY_SEPARATOR,
    ltrim($requestPath, '/')
);
if (is_file($filePath)) {
    return false;
}

http_response_code(404);
