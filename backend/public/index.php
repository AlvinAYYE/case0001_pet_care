<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Env.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/ContentRepository.php';
require_once __DIR__ . '/../src/DevRepository.php';

loadEnv(__DIR__ . '/../.env');
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Taipei') ?? 'Asia/Taipei');

$frontendOrigin = env('APP_FRONTEND_ORIGIN', 'http://localhost:5173');
header('Access-Control-Allow-Origin: ' . $frontendOrigin);
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-KEY');
header('Access-Control-Allow-Credentials: true');
header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($scriptDir !== '/' && $scriptDir !== '.') {
    $uriPath = (string) preg_replace('#^' . preg_quote($scriptDir, '#') . '#', '', (string) $uriPath, 1);
}
$segments = pathSegments((string) $uriPath);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$appDebug = filter_var((string) (env('APP_DEBUG', 'false') ?? 'false'), FILTER_VALIDATE_BOOL);

$logError = static function (Throwable $e, string $context): void {
    $logDir = __DIR__ . '/../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    $message = sprintf(
        "[%s] %s: %s in %s:%d\n%s\n",
        date(DATE_ATOM),
        $context,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    @error_log($message, 3, $logDir . '/app.log');
    error_log($context . ': ' . $e->getMessage());
};

$safeRead = static function (callable $callback, mixed $fallback, string $context) use ($logError): mixed {
    try {
        return $callback();
    } catch (Throwable $e) {
        $logError($e, $context);
        return $fallback;
    }
};

$normalizeMedia = static fn(array $body): array => [
    'media_type' => (string) ($body['media_type'] ?? 'image'),
    'title' => $body['title'] ?? null,
    'file_path' => (string) ($body['file_path'] ?? ''),
    'alt_text' => $body['alt_text'] ?? null,
    'sort_order' => (int) ($body['sort_order'] ?? 0),
    'is_active' => isset($body['is_active']) ? (int) (bool) $body['is_active'] : 1,
];
$normalizeLink = static fn(array $body): array => [
    'platform' => (string) ($body['platform'] ?? ''),
    'label' => $body['label'] ?? null,
    'url' => (string) ($body['url'] ?? ''),
    'is_active' => isset($body['is_active']) ? (int) (bool) $body['is_active'] : 1,
    'sort_order' => (int) ($body['sort_order'] ?? 0),
];

$apiKey = env('APP_API_KEY', '');
$requireApiKey = static function (string $apiKey): void {
    if ($apiKey === '') {
        jsonResponse(['success' => false, 'message' => 'API key is not configured.'], 500);
        exit;
    }

    $headerKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($headerKey === '' && is_string($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
        $headerKey = substr($authHeader, 7);
    }

    if (!is_string($headerKey) || $headerKey === '' || !hash_equals($apiKey, $headerKey)) {
        jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
        exit;
    }
};

try {
    $repo = new ContentRepository(Database::getConnection());

    if ($segments === ['api', 'health'] && $method === 'GET') {
        jsonResponse(['success' => true, 'message' => 'Backend is running.', 'timestamp' => date(DATE_ATOM)]);
        exit;
    }

    if (($segments === ['api', 'news'] || $segments === ['api', 'news.php']) && $method === 'GET') {
        jsonResponse($safeRead(static fn(): array => $repo->getPublicNews(4), [], 'read.news'));
        exit;
    }

    if (($segments === ['api', 'articles'] || $segments === ['api', 'articles.php']) && $method === 'GET') {
        jsonResponse($safeRead(static fn(): array => $repo->getPublicArticles(4), [], 'read.articles'));
        exit;
    }

    if (($segments === ['api', 'senior-care'] || $segments === ['api', 'senior-care.php']) && $method === 'GET') {
        jsonResponse($safeRead(static fn(): array => $repo->getSeniorCareContent(), [], 'read.senior-care'));
        exit;
    }

    if (($segments === ['api', 'about'] || $segments === ['api', 'about.php']) && $method === 'GET') {
        jsonResponse([
            'content' => $safeRead(static fn(): array => $repo->getAboutContent(), ['id' => 1, 'title' => '', 'content' => '', 'updated_at' => null], 'read.about-content'),
            'modals' => $safeRead(static fn(): array => $repo->getAboutModals(), [], 'read.about-modals'),
        ]);
        exit;
    }

    if ($segments === ['api', 'content'] && $method === 'GET') {
        jsonResponse(['success' => true, 'data' => [
            'sections' => $safeRead(static fn(): array => $repo->getSections(), [], 'read.content.sections'),
            'media' => $safeRead(static fn(): array => $repo->getMedia(), [], 'read.content.media'),
            'links' => $safeRead(static fn(): array => $repo->getLinks(), [], 'read.content.links'),
            'store_info' => $safeRead(static fn(): array => $repo->getStoreInfo(), [], 'read.content.store-info'),
            'about_content' => $safeRead(static fn(): array => $repo->getAboutContent(), ['id' => 1, 'title' => '', 'content' => '', 'updated_at' => null], 'read.content.about-content'),
            'about_modals' => $safeRead(static fn(): array => $repo->getAboutModals(), [], 'read.content.about-modals'),
            'articles' => $safeRead(static fn(): array => $repo->getPublicArticles(20), [], 'read.content.articles'),
            'senior_care' => $safeRead(static fn(): array => $repo->getSeniorCareContent(), [], 'read.content.senior-care'),
        ]]);
        exit;
    }

    if (count($segments) === 4 && $segments[0] === 'api' && $segments[1] === 'content' && $segments[2] === 'sections' && $method === 'PUT') {
        $requireApiKey($apiKey);
        $body = readJsonBody();
        $sectionKey = trim($segments[3]);
        if ($sectionKey === '') {
            jsonResponse(['success' => false, 'message' => 'section key is required.'], 400);
            exit;
        }
        jsonResponse(['success' => true, 'data' => $repo->upsertSection($sectionKey, $body['title'] ?? null, $body['content'] ?? null)]);
        exit;
    }

    if ($segments === ['api', 'content', 'media'] && $method === 'GET') { jsonResponse(['success' => true, 'data' => $safeRead(static fn(): array => $repo->getMedia(), [], 'read.content.media-list')]); exit; }
    if ($segments === ['api', 'content', 'media'] && $method === 'POST') { $requireApiKey($apiKey); jsonResponse(['success' => true, 'data' => $repo->createMedia($normalizeMedia(readJsonBody()))], 201); exit; }
    if (count($segments) === 4 && $segments[0] === 'api' && $segments[1] === 'content' && $segments[2] === 'media' && $method === 'PUT') { $requireApiKey($apiKey); jsonResponse(['success' => true, 'data' => $repo->updateMedia(intParam($segments[3], 'media id'), $normalizeMedia(readJsonBody()))]); exit; }
    if (count($segments) === 4 && $segments[0] === 'api' && $segments[1] === 'content' && $segments[2] === 'media' && $method === 'DELETE') { $requireApiKey($apiKey); $repo->deleteMedia(intParam($segments[3], 'media id')); jsonResponse(['success' => true, 'message' => 'Deleted media.']); exit; }

    if ($segments === ['api', 'content', 'links'] && $method === 'GET') { jsonResponse(['success' => true, 'data' => $safeRead(static fn(): array => $repo->getLinks(), [], 'read.content.link-list')]); exit; }
    if ($segments === ['api', 'content', 'links'] && $method === 'POST') { $requireApiKey($apiKey); jsonResponse(['success' => true, 'data' => $repo->createLink($normalizeLink(readJsonBody()))], 201); exit; }
    if (count($segments) === 4 && $segments[0] === 'api' && $segments[1] === 'content' && $segments[2] === 'links' && $method === 'PUT') { $requireApiKey($apiKey); jsonResponse(['success' => true, 'data' => $repo->updateLink(intParam($segments[3], 'link id'), $normalizeLink(readJsonBody()))]); exit; }
    if (count($segments) === 4 && $segments[0] === 'api' && $segments[1] === 'content' && $segments[2] === 'links' && $method === 'DELETE') { $requireApiKey($apiKey); $repo->deleteLink(intParam($segments[3], 'link id')); jsonResponse(['success' => true, 'message' => 'Deleted link.']); exit; }

    if ($segments === ['api', 'content', 'store-info'] && $method === 'GET') { jsonResponse(['success' => true, 'data' => $safeRead(static fn(): array => $repo->getStoreInfo(), [], 'read.content.store-info-single')]); exit; }
    if ($segments === ['api', 'content', 'store-info'] && $method === 'PUT') {
        $requireApiKey($apiKey);
        $body = readJsonBody();
        jsonResponse(['success' => true, 'data' => $repo->upsertStoreInfo([
            'business_hours' => $body['business_hours'] ?? null,
        ])]);
        exit;
    }

    if ($segments === ['api', 'content', 'articles'] && $method === 'GET') { jsonResponse(['success' => true, 'data' => $safeRead(static fn(): array => $repo->getAllArticles(), [], 'read.content.articles-list')]); exit; }
    if ($segments === ['api', 'content', 'articles'] && $method === 'POST') {
        $requireApiKey($apiKey);
        $body = readJsonBody();
        jsonResponse(['success' => true, 'data' => $repo->createArticle([
            'title' => trim((string) ($body['title'] ?? '')),
            'excerpt' => trim((string) ($body['excerpt'] ?? '')),
            'image_url' => trim((string) ($body['image_url'] ?? '')),
            'published_at' => (string) ($body['published_at'] ?? date('Y-m-d H:i:s')),
            'link' => trim((string) ($body['link'] ?? '')),
            'is_active' => isset($body['is_active']) ? (int) (bool) $body['is_active'] : 1,
        ])], 201);
        exit;
    }
    if (count($segments) === 4 && $segments[0] === 'api' && $segments[1] === 'content' && $segments[2] === 'articles' && $method === 'PUT') {
        $requireApiKey($apiKey);
        $body = readJsonBody();
        jsonResponse(['success' => true, 'data' => $repo->updateArticle(intParam($segments[3], 'article id'), [
            'title' => trim((string) ($body['title'] ?? '')),
            'excerpt' => trim((string) ($body['excerpt'] ?? '')),
            'image_url' => trim((string) ($body['image_url'] ?? '')),
            'published_at' => (string) ($body['published_at'] ?? date('Y-m-d H:i:s')),
            'link' => trim((string) ($body['link'] ?? '')),
            'is_active' => isset($body['is_active']) ? (int) (bool) $body['is_active'] : 1,
        ])]);
        exit;
    }
    if (count($segments) === 4 && $segments[0] === 'api' && $segments[1] === 'content' && $segments[2] === 'articles' && $method === 'DELETE') {
        $requireApiKey($apiKey);
        $repo->deleteArticle(intParam($segments[3], 'article id'));
        jsonResponse(['success' => true, 'message' => 'Deleted article.']);
        exit;
    }

    if ($segments === ['api', 'content', 'about'] && $method === 'GET') {
        jsonResponse(['success' => true, 'data' => [
            'content' => $safeRead(static fn(): array => $repo->getAboutContent(), ['id' => 1, 'title' => '', 'content' => '', 'updated_at' => null], 'read.content.about-content-single'),
            'modals' => $safeRead(static fn(): array => $repo->getAboutModals(), [], 'read.content.about-modal-list'),
        ]]);
        exit;
    }
    if ($segments === ['api', 'content', 'about'] && $method === 'PUT') {
        $requireApiKey($apiKey);
        $body = readJsonBody();
        jsonResponse(['success' => true, 'data' => $repo->upsertAboutContent([
            'title' => trim((string) ($body['title'] ?? '')),
            'content' => trim((string) ($body['content'] ?? '')),
        ])]);
        exit;
    }
    if (count($segments) === 5 && $segments[0] === 'api' && $segments[1] === 'content' && $segments[2] === 'about' && $segments[3] === 'modals' && $method === 'PUT') {
        $requireApiKey($apiKey);
        $body = readJsonBody();
        jsonResponse(['success' => true, 'data' => $repo->upsertAboutModal(trim($segments[4]), [
            'title' => trim((string) ($body['title'] ?? '')),
            'content' => trim((string) ($body['content'] ?? '')),
            'image_url' => trim((string) ($body['image_url'] ?? '')),
        ])]);
        exit;
    }

    if ($segments === ['api', 'upload'] && $method === 'POST') {
        $requireApiKey($apiKey);
        if (!isset($_FILES['file']) || !is_array($_FILES['file']) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['success' => false, 'message' => 'file is required or invalid.'], 400);
            exit;
        }
        $maxUploadBytes = (int) (env('APP_MAX_UPLOAD_BYTES', '5242880') ?? '5242880');
        if (((int) ($_FILES['file']['size'] ?? 0)) > $maxUploadBytes) {
            jsonResponse(['success' => false, 'message' => 'File is too large.'], 400);
            exit;
        }
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/heic' => 'heic',
            'image/heif' => 'heic',
        ];
        $mime = mime_content_type($_FILES['file']['tmp_name']);
        if (!is_string($mime) || !isset($allowed[$mime])) { jsonResponse(['success' => false, 'message' => 'Unsupported file type.'], 400); exit; }
        $uploadDir = __DIR__ . '/uploads';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) { jsonResponse(['success' => false, 'message' => 'Cannot create upload directory.'], 500); exit; }
        if (!is_writable($uploadDir)) {
            @chmod($uploadDir, 0775);
        }
        if (!is_writable($uploadDir)) { jsonResponse(['success' => false, 'message' => 'Upload directory is not writable.'], 500); exit; }
        $filename = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
        $targetPath = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) { jsonResponse(['success' => false, 'message' => 'Cannot move uploaded file. Check permissions and disk space.'], 500); exit; }
        jsonResponse(['success' => true, 'data' => ['file_path' => '/uploads/' . $filename, 'mime_type' => $mime]], 201);
        exit;
    }

    // --- DEV API Block Start ---
    $isDevRoute = (count($segments) >= 2 && $segments[0] === 'api' && $segments[1] === 'dev');
    if ($isDevRoute) {
        $requireApiKey($apiKey);
        $enableDevApi = filter_var((string) (env('APP_ENABLE_DEV_API', 'false') ?? 'false'), FILTER_VALIDATE_BOOL);
        if (!$enableDevApi) {
            jsonResponse(['success' => false, 'message' => 'DEV APIs are disabled.'], 403);
            exit;
        }

        $devRepo = new DevRepository(Database::getConnection());

        if ($segments === ['api', 'dev', 'gallery'] && $method === 'GET') {
            $uploadsDir = __DIR__ . '/uploads';
            jsonResponse(['success' => true, 'data' => $devRepo->getGallery($uploadsDir)]);
            exit;
        }

        if (count($segments) === 4 && $segments[2] === 'gallery' && $method === 'DELETE') {
            $uploadsDir = __DIR__ . '/uploads';
            $filename = basename(urldecode($segments[3]));
            $success = $devRepo->deleteImage($uploadsDir, $filename);
            if ($success) {
                jsonResponse(['success' => true, 'message' => 'Image deleted.']);
            } else {
                jsonResponse(['success' => false, 'message' => 'Image not found or cannot be deleted.'], 404);
            }
            exit;
        }

        if ($segments === ['api', 'dev', 'db', 'export'] && $method === 'GET') {
            $sql = $devRepo->exportDatabase();
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="backup_' . date('Ymd_His') . '.sql"');
            echo $sql;
            exit;
        }

        if ($segments === ['api', 'dev', 'db', 'import'] && $method === 'POST') {
            if (!isset($_FILES['db_file']) || !is_array($_FILES['db_file']) || (int) $_FILES['db_file']['error'] !== UPLOAD_ERR_OK) {
                jsonResponse(['success' => false, 'message' => 'Database sql file is required or invalid.'], 400);
                exit;
            }
            $sqlContent = file_get_contents($_FILES['db_file']['tmp_name']);
            if ($sqlContent === false || trim($sqlContent) === '') {
                jsonResponse(['success' => false, 'message' => 'Empty or unreadable SQL file.'], 400);
                exit;
            }

            try {
                $devRepo->importDatabase($sqlContent);
                jsonResponse(['success' => true, 'message' => 'Database imported successfully.']);
            } catch (Exception $e) {
                jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
            }
            exit;
        }
    }
    // --- DEV API Block End ---

    jsonResponse(['success' => false, 'message' => 'Not Found', 'path' => '/' . implode('/', $segments)], 404);
} catch (Throwable $e) {
    $logError($e, 'fatal');
    $payload = ['success' => false, 'message' => 'Internal Server Error'];
    if ($appDebug) {
        $payload['error'] = $e->getMessage();
    }
    jsonResponse($payload, 500);
}
