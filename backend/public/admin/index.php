<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Env.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/ContentRepository.php';

loadEnv(__DIR__ . '/../../.env');
date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Taipei') ?? 'Asia/Taipei');

$httpsHeader = strtolower((string)($_SERVER['HTTPS'] ?? ''));
$forwardedProtoHeader = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
$forwardedProto = strtolower(trim(explode(',', $forwardedProtoHeader)[0] ?? ''));
$forwardedSsl = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
$frontEndHttps = strtolower(trim((string)($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '')));
$isHttps = ($httpsHeader !== '' && $httpsHeader !== 'off')
    || $forwardedProto === 'https'
    || $forwardedSsl === 'on'
    || $frontEndHttps === 'on'
    || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
$enforceHttpsAdmin = env('APP_ENFORCE_HTTPS_ADMIN', 'false') === 'true';

if ($enforceHttpsAdmin && !$isHttps) {
    $forwardedHost = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    $hostHeader = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $hostCandidate = $forwardedHost !== '' ? $forwardedHost : $hostHeader;
    $host = preg_replace('/[^A-Za-z0-9\.\-:]/', '', $hostCandidate ?? '');
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/admin/');

    if ($host !== '') {
        header('Location: https://' . $host . $requestUri, true, 302);
        exit;
    }
}

if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' data: blob: https:; font-src 'self' data: https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$adminUser = env('ADMIN_USER', '');
$adminPass = env('ADMIN_PASS', '');
if ($adminUser === '' || $adminPass === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Admin credentials are not configured.';
    exit;
}

$sessionCookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => 0,
    'path' => (string) ($sessionCookieParams['path'] ?? '/'),
    'domain' => (string) ($sessionCookieParams['domain'] ?? ''),
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function isValidAdminPassword(string $expectedPassword, string $submittedPassword): bool
{
    $passwordInfo = password_get_info($expectedPassword);
    if (($passwordInfo['algo'] ?? 0) !== 0) {
        return password_verify($submittedPassword, $expectedPassword);
    }

    return hash_equals($expectedPassword, $submittedPassword);
}

function getCsrfToken(): string
{
    $token = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || $token === '') {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
    }

    return $token;
}

function verifyCsrfToken(): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($sessionToken)
        || $sessionToken === ''
        || !is_string($submittedToken)
        || $submittedToken === ''
        || !hash_equals($sessionToken, $submittedToken)
    ) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid CSRF token.';
        exit;
    }
}

$csrfToken = getCsrfToken();

function getAdminBasePath(): string
{
    $configuredPath = trim((string) (env('APP_ADMIN_PATH', '') ?? ''));
    if ($configuredPath !== '') {
        return '/' . trim($configuredPath, '/');
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/');
    $path = (string) parse_url($requestUri, PHP_URL_PATH);
    $normalized = rtrim(str_replace('\\', '/', $path), '/');

    if ($normalized === '') {
        return '/admin';
    }

    if (str_ends_with($normalized, '/index.php')) {
        $normalized = substr($normalized, 0, -10);
    }

    return $normalized === '' ? '/admin' : $normalized;
}

function redirectToAdmin(?string $suffix = null, int $statusCode = 303): void
{
    $basePath = getAdminBasePath();
    $target = $suffix === null ? $basePath . '/' : $basePath . '/' . ltrim($suffix, '/');
    header('Location: ' . $target, true, $statusCode);
    exit;
}

function redirectWithMessage(string $type, string $message): void
{
    $_SESSION['flash_' . $type] = $message;
    redirectToAdmin();
}

function normalizeUploadedImage(array $file, bool $compressToJpeg): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('圖片上傳失敗。');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_file($tmpPath)) {
        throw new RuntimeException('找不到上傳暫存檔。');
    }

    $mime = mime_content_type($tmpPath);
    if (!is_string($mime)) {
        throw new RuntimeException('無法辨識圖片格式。');
    }

    $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/heic' => 'heic',
            'image/heif' => 'heic',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('僅支援 JPG、PNG、WEBP、GIF、HEIC。');
    }

    $uploadDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('uploads 目錄建立失敗。');
    }

    $uploadDirReal = realpath($uploadDir);
    if ($uploadDirReal === false) {
        throw new RuntimeException('uploads 路徑解析失敗。');
    }

    if (!is_writable($uploadDirReal)) {
        @chmod($uploadDirReal, 0775);
    }
    if (!is_writable($uploadDirReal)) {
        throw new RuntimeException('uploads 目錄不可寫入，請確認 /var/www/html/public/uploads 權限。');
    }

    $filenameBase = date('YmdHis') . '_' . bin2hex(random_bytes(5));

    if ($compressToJpeg) {
        if ($mime === 'image/heic' || $mime === 'image/heif') {
            throw new RuntimeException('HEIC 目前不支援後台壓縮轉檔，請取消壓縮選項或改傳 JPG/PNG。');
        }

        if (!function_exists('imagecreatefromjpeg') || !function_exists('imagejpeg')) {
            throw new RuntimeException('目前伺服器未啟用 GD，無法壓縮為 JPEG。');
        }

        $image = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($tmpPath),
            'image/png' => imagecreatefrompng($tmpPath),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($tmpPath) : false,
            'image/gif' => imagecreatefromgif($tmpPath),
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException('圖片讀取失敗，無法轉為 JPEG。');
        }

        $targetPath = $uploadDirReal . '/' . $filenameBase . '.jpg';
        $saved = imagejpeg($image, $targetPath, 82);
        imagedestroy($image);

        if (!$saved) {
            throw new RuntimeException('圖片轉檔失敗。');
        }

        return '/uploads/' . basename($targetPath);
    }

    $targetPath = $uploadDirReal . '/' . $filenameBase . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('儲存圖片失敗，請確認 uploads 權限與磁碟空間。');
    }

    return '/uploads/' . basename($targetPath);
}

function resolveDisplayImageUrl(string $rawPath, string $publicBasePath): string
{
    $value = trim($rawPath);
    if ($value === '') {
        return '';
    }

    if (preg_match('#^(https?:)?//#i', $value) === 1 || str_starts_with($value, 'data:') || str_starts_with($value, 'blob:')) {
        return $value;
    }

    if ($publicBasePath !== '' && str_starts_with($value, $publicBasePath . '/')) {
        return $value;
    }

    if (str_starts_with($value, '/')) {
        return $publicBasePath . $value;
    }

    return $publicBasePath . '/' . ltrim($value, '/');
}

function deleteLocalUploadIfUnused(ContentRepository $repo, string $imagePath): void
{
    $value = trim($imagePath);
    if ($value === '' || !str_starts_with($value, '/uploads/')) {
        return;
    }

    if ($repo->isImagePathInUse($value)) {
        return;
    }

    $uploadsBase = realpath(__DIR__ . '/../uploads');
    if ($uploadsBase === false) {
        return;
    }

    $target = realpath(__DIR__ . '/..' . $value);
    if ($target === false || !str_starts_with(str_replace('\\', '/', $target), str_replace('\\', '/', $uploadsBase) . '/')) {
        return;
    }

    if (is_file($target)) {
        @unlink($target);
    }
}

$notice = trim((string)($_SESSION['flash_notice'] ?? ''));
$error = trim((string)($_SESSION['flash_error'] ?? ''));
unset($_SESSION['flash_notice'], $_SESSION['flash_error']);

$action = (string)($_POST['action'] ?? '');
$isAuthenticated = ($_SESSION['admin_authenticated'] ?? false) === true
    && hash_equals($adminUser, (string)($_SESSION['admin_username'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'login') {
    verifyCsrfToken();

    $submittedUser = trim((string)($_POST['username'] ?? ''));
    $submittedPass = (string)($_POST['password'] ?? '');

    if (hash_equals($adminUser, $submittedUser) && isValidAdminPassword($adminPass, $submittedPass)) {
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        $_SESSION['admin_username'] = $adminUser;
        unset($_SESSION['csrf_token']);
        $csrfToken = getCsrfToken();
        redirectWithMessage('notice', '已登入後台。');
    }

    $error = '帳號或密碼錯誤。';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'logout' && $isAuthenticated) {
    verifyCsrfToken();
    unset($_SESSION['admin_authenticated'], $_SESSION['admin_username'], $_SESSION['csrf_token']);
    session_regenerate_id(true);
    getCsrfToken();
    redirectWithMessage('notice', '已登出後台。');
}

$isAuthenticated = ($_SESSION['admin_authenticated'] ?? false) === true
    && hash_equals($adminUser, (string)($_SESSION['admin_username'] ?? ''));

$baseApi = '/case0001_20260301/backend/public/api';
$scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$publicBasePath = (string)preg_replace('#/admin/index\.php$#', '', $scriptName);

$newsRows = [];
$articleRows = [];
$aboutContent = [];
$aboutModals = [];
$storeInfo = [];
$senior = [];
$heroMain = [];
$heroVisual = [];
$seniorPreviewUrl = '';

if ($isAuthenticated) {
    $repo = new ContentRepository(Database::getConnection());

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verifyCsrfToken();

        try {
            if ($action === 'news_create') {
                $imageUrl = trim((string)($_POST['image_url'] ?? ''));
                $compressToJpeg = isset($_POST['compress_to_jpeg']);
                if (isset($_FILES['image_file']) && is_array($_FILES['image_file']) && (int)($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $imageUrl = normalizeUploadedImage($_FILES['image_file'], $compressToJpeg);
                }

                $repo->createNews([
                        'title' => trim((string)($_POST['title'] ?? '')),
                        'excerpt' => trim((string)($_POST['excerpt'] ?? '')),
                        'image_url' => $imageUrl,
                        'published_at' => (string)($_POST['published_at'] ?? date('Y-m-d H:i:s')),
                        'link' => trim((string)($_POST['link'] ?? '')),
                        'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ]);
                redirectWithMessage('notice', '最新消息已新增。');
            } elseif ($action === 'news_update') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $currentNews = $repo->getNewsById($id);
                    $oldImagePath = trim((string)($currentNews['image_url'] ?? ''));
                    $imageUrl = trim((string)($_POST['image_url'] ?? ''));
                    $compressToJpeg = isset($_POST['compress_to_jpeg']);
                    if (isset($_FILES['image_file']) && is_array($_FILES['image_file']) && (int)($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $imageUrl = normalizeUploadedImage($_FILES['image_file'], $compressToJpeg);
                    }

                    $repo->updateNews($id, [
                            'title' => trim((string)($_POST['title'] ?? '')),
                            'excerpt' => trim((string)($_POST['excerpt'] ?? '')),
                            'image_url' => $imageUrl,
                            'published_at' => (string)($_POST['published_at'] ?? date('Y-m-d H:i:s')),
                            'link' => trim((string)($_POST['link'] ?? '')),
                            'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    ]);

                    if ($oldImagePath !== '' && $oldImagePath !== $imageUrl) {
                        deleteLocalUploadIfUnused($repo, $oldImagePath);
                    }

                    redirectWithMessage('notice', '最新消息已更新。');
                }
            } elseif ($action === 'news_delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $currentNews = $repo->getNewsById($id);
                    $oldImagePath = trim((string)($currentNews['image_url'] ?? ''));
                    $repo->deleteNews($id);

                    if ($oldImagePath !== '') {
                        deleteLocalUploadIfUnused($repo, $oldImagePath);
                    }

                    redirectWithMessage('notice', '最新消息已刪除。');
                }
            } elseif ($action === 'article_create') {
                $imageUrl = trim((string)($_POST['image_url'] ?? ''));
                $compressToJpeg = isset($_POST['compress_to_jpeg']);
                if (isset($_FILES['image_file']) && is_array($_FILES['image_file']) && (int)($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $imageUrl = normalizeUploadedImage($_FILES['image_file'], $compressToJpeg);
                }

                $repo->createArticle([
                    'title' => trim((string)($_POST['title'] ?? '')),
                    'excerpt' => trim((string)($_POST['excerpt'] ?? '')),
                    'image_url' => $imageUrl,
                    'published_at' => (string)($_POST['published_at'] ?? date('Y-m-d H:i:s')),
                    'link' => trim((string)($_POST['link'] ?? '')),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ]);
                redirectWithMessage('notice', '文章分享已新增。');
            } elseif ($action === 'article_update') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $currentArticle = $repo->getArticleById($id);
                    $oldImagePath = trim((string)($currentArticle['image_url'] ?? ''));
                    $imageUrl = trim((string)($_POST['image_url'] ?? ''));
                    $compressToJpeg = isset($_POST['compress_to_jpeg']);
                    if (isset($_FILES['image_file']) && is_array($_FILES['image_file']) && (int)($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $imageUrl = normalizeUploadedImage($_FILES['image_file'], $compressToJpeg);
                    }

                    $repo->updateArticle($id, [
                        'title' => trim((string)($_POST['title'] ?? '')),
                        'excerpt' => trim((string)($_POST['excerpt'] ?? '')),
                        'image_url' => $imageUrl,
                        'published_at' => (string)($_POST['published_at'] ?? date('Y-m-d H:i:s')),
                        'link' => trim((string)($_POST['link'] ?? '')),
                        'is_active' => isset($_POST['is_active']) ? 1 : 0,
                    ]);

                    if ($oldImagePath !== '' && $oldImagePath !== $imageUrl) {
                        deleteLocalUploadIfUnused($repo, $oldImagePath);
                    }

                    redirectWithMessage('notice', '文章分享已更新。');
                }
            } elseif ($action === 'article_delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id > 0) {
                    $currentArticle = $repo->getArticleById($id);
                    $oldImagePath = trim((string)($currentArticle['image_url'] ?? ''));
                    $repo->deleteArticle($id);
                    if ($oldImagePath !== '') {
                        deleteLocalUploadIfUnused($repo, $oldImagePath);
                    }
                    redirectWithMessage('notice', '文章分享已刪除。');
                }
            } elseif ($action === 'about_update') {
                $repo->upsertAboutContent([
                    'title' => trim((string)($_POST['title'] ?? '')),
                    'content' => trim((string)($_POST['content'] ?? '')),
                ]);
                redirectWithMessage('notice', '關於恆寵愛已更新。');
            } elseif ($action === 'about_modal_update') {
                $modalKey = trim((string)($_POST['modal_key'] ?? ''));
                if ($modalKey !== '') {
                    $currentImage = trim((string)($_POST['current_image_url'] ?? ''));
                    $imageUrl = trim((string)($_POST['image_url'] ?? ''));
                    $compressToJpeg = isset($_POST['compress_to_jpeg']);
                    if (isset($_FILES['image_file']) && is_array($_FILES['image_file']) && (int)($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $imageUrl = normalizeUploadedImage($_FILES['image_file'], $compressToJpeg);
                    }

                    $repo->upsertAboutModal($modalKey, [
                        'title' => trim((string)($_POST['title'] ?? '')),
                        'content' => trim((string)($_POST['content'] ?? '')),
                        'image_url' => $imageUrl,
                    ]);

                    if ($currentImage !== '' && $currentImage !== $imageUrl) {
                        deleteLocalUploadIfUnused($repo, $currentImage);
                    }

                    redirectWithMessage('notice', '關於恆寵愛彈窗已更新。');
                }
            } elseif ($action === 'store_update') {
                $repo->upsertStoreInfo([
                    'business_hours' => trim((string)($_POST['business_hours'] ?? '')),
                ]);
                redirectWithMessage('notice', '營業時間已更新。');
            } elseif ($action === 'hero_update') {
                $heroImageUrl = trim((string)($_POST['hero_image_url'] ?? ''));
                $compressToJpeg = isset($_POST['compress_to_jpeg']);
                $currentHeroVisual = $repo->getSectionByKey('hero_visual');
                $oldHeroImagePath = trim((string)($currentHeroVisual['content'] ?? ''));

                if (isset($_FILES['hero_image_file']) && is_array($_FILES['hero_image_file']) && (int)($_FILES['hero_image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $heroImageUrl = normalizeUploadedImage($_FILES['hero_image_file'], $compressToJpeg);
                }

                $repo->upsertSection(
                    'hero',
                    trim((string)($_POST['hero_title'] ?? '')),
                    trim((string)($_POST['hero_content'] ?? ''))
                );
                $repo->upsertSection('hero_visual', '主視覺背景', $heroImageUrl);

                if ($oldHeroImagePath !== '' && $oldHeroImagePath !== $heroImageUrl) {
                    deleteLocalUploadIfUnused($repo, $oldHeroImagePath);
                }

                redirectWithMessage('notice', '主視覺內容已更新。');
            } elseif ($action === 'senior_update') {
                $imageUrl = trim((string)($_POST['image_url'] ?? ''));
                $compressToJpeg = isset($_POST['compress_to_jpeg']);

                if (isset($_FILES['image_file']) && is_array($_FILES['image_file']) && (int)($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $imageUrl = normalizeUploadedImage($_FILES['image_file'], $compressToJpeg);
                    $notice = $compressToJpeg ? '樂齡館內容已更新，圖片已壓縮為 JPEG。' : '樂齡館內容已更新，圖片已上傳。';
                }

                $repo->upsertSeniorCareContent([
                        'title' => trim((string)($_POST['title'] ?? '樂齡館')),
                        'subtitle' => trim((string)($_POST['subtitle'] ?? '')),
                        'description' => trim((string)($_POST['description'] ?? '')),
                        'tags' => trim((string)($_POST['tags'] ?? '')),
                        'image_url' => $imageUrl,
                ]);
                if ($notice === '') {
                    $notice = '樂齡館內容已更新。';
                }

                redirectWithMessage('notice', $notice);
            }
        } catch (Throwable $e) {
            redirectWithMessage('error', '操作失敗：' . $e->getMessage());
        }
    }

    $newsRows = $repo->getAllNews();
    $articleRows = $repo->getAllArticles();
    $aboutContent = $repo->getAboutContent();
    $aboutModals = $repo->getAboutModals();
    $storeInfo = $repo->getStoreInfo();
    $senior = $repo->getSeniorCareContent();
    $heroMain = $repo->getSectionByKey('hero');
    $heroVisual = $repo->getSectionByKey('hero_visual');
    $seniorPreviewUrl = resolveDisplayImageUrl((string)($senior['imageUrl'] ?? ''), $publicBasePath);
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>後台管理 - 恆寵愛</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(150deg, #f6efe4 0%, #eee5d6 45%, #e2d5c1 100%);
        }

        .admin-shell {
            max-width: 1240px;
        }

        .admin-card {
            border: 0;
            border-radius: 1rem;
            box-shadow: 0 14px 28px rgba(56, 40, 22, 0.08);
        }

        .admin-card > .card-header {
            background: linear-gradient(135deg, #fff7ec 0%, #f5e2c8 100%);
            border-bottom: 1px solid #ead8be;
            font-weight: 700;
        }

        .admin-kicker {
            letter-spacing: .08em;
            text-transform: uppercase;
            font-size: .75rem;
            color: #7f6344;
            margin-bottom: .25rem;
        }

        .muted-code {
            background: #f6efe4;
            padding: .15rem .4rem;
            border-radius: .35rem;
        }

        .news-row {
            border: 1px solid #ead8be;
            border-radius: .9rem;
            background: #fff;
        }

        .nav-tabs {
            border-bottom-color: #d9c2a1;
        }

        .nav-tabs .nav-link {
            color: #6f5438;
            background: #efe2cf;
            border-color: #d9c2a1;
        }

        .nav-tabs .nav-link:hover,
        .nav-tabs .nav-link:focus {
            color: #5a4129;
            background: #e6d6bf;
            border-color: #d9c2a1;
        }

        .nav-tabs .nav-link.active {
            color: #3f2e1d;
            background: #fff;
            border-color: #d9c2a1 #d9c2a1 #fff;
        }
    </style>
</head>
<body>
<?php if (!$isAuthenticated): ?>
<div class="container admin-shell py-5">
    <div class="row justify-content-center">
        <div class="col-12 col-md-7 col-lg-5">
            <div class="admin-card card">
                <div class="card-body p-4 p-lg-5">
                    <p class="admin-kicker mb-1">Perpetuity Backend Console</p>
                    <h1 class="h3 mb-2"><i class="bi bi-shield-lock me-2"></i>後台登入</h1>
                    <p class="text-muted mb-4">請使用管理員帳號密碼登入後台。</p>

                    <?php if ($notice !== ''): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="post" class="row g-3">
                        <input type="hidden" name="action" value="login">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="col-12">
                            <label class="form-label" for="login-username">帳號</label>
                            <input type="text" name="username" id="login-username" class="form-control form-control-lg" autocomplete="username" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="login-password">密碼</label>
                            <input type="password" name="password" id="login-password" class="form-control form-control-lg" autocomplete="current-password" required>
                        </div>
                        <div class="col-12 d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">登入後台</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="container admin-shell py-4 py-lg-5">
    <div class="admin-card card mb-4">
        <div class="card-body d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
            <div>
                <p class="admin-kicker mb-1">Perpetuity Backend Console</p>
                <h1 class="h3 mb-1"><i class="bi bi-speedometer2 me-2"></i>後台管理</h1>
                <div class="small text-muted">快速維護首頁最新消息與樂齡館內容</div>
            </div>
            <div class="text-lg-end small text-muted">
                <div class="mb-2">API: <code class="muted-code"><?= htmlspecialchars($baseApi, ENT_QUOTES, 'UTF-8') ?></code></div>
                <form method="post" class="d-inline-flex align-items-center gap-2">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <span>已登入：<?= htmlspecialchars((string)($_SESSION['admin_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <button type="submit" class="btn btn-sm btn-outline-secondary">登出</button>
                </form>
            </div>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>


    <div class="modal fade" id="createNewsModal" tabindex="-1" aria-labelledby="createNewsModalLabel"
         aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <form method="post" class="modal-content" enctype="multipart/form-data">
                <input type="hidden" name="action" value="news_create">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="createNewsModalLabel">新增最新消息</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" name="title" class="form-control" id="create-title"
                                       placeholder="標題" required>
                                <label for="create-title">標題</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="datetime-local" name="published_at" class="form-control"
                                       id="create-published-at" value="<?= date('Y-m-d\TH:i') ?>"
                                       placeholder="發布時間">
                                <label for="create-published-at">發布時間</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                    <textarea name="excerpt" class="form-control" id="create-excerpt" placeholder="摘要"
                                              style="height: 110px" required></textarea>
                                <label for="create-excerpt">摘要</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="text" name="image_url" class="form-control" id="create-image-url"
                                       data-preview-url="news-create"
                                       placeholder="https://...">
                                <label for="create-image-url">圖片連結（可選）</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="file" name="image_file" class="form-control" id="create-image-file"
                                       data-preview-file="news-create"
                                       accept="image/jpeg,image/png,image/webp,image/gif">
                                <label for="create-image-file">或上傳圖片（可壓縮）</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label mb-2">圖片即時預覽</label>
                            <div class="position-relative border rounded-3 overflow-hidden bg-light">
                                <img id="create-image-preview" data-preview-target="news-create"
                                     data-public-base-path="<?= htmlspecialchars($publicBasePath, ENT_QUOTES, 'UTF-8') ?>"
                                     src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="
                                     alt="貼文圖片預覽" class="w-100" style="min-height: 220px; object-fit: cover;">
                                <div data-preview-placeholder="news-create" class="position-absolute top-50 start-50 translate-middle text-secondary">目前無圖片</div>
                            </div>
                            <div class="form-text">輸入圖片連結或選擇本機檔案後會立即顯示預覽。</div>
                        </div>
                        <div class="col-12 form-check ms-1">
                            <input type="checkbox" name="compress_to_jpeg" class="form-check-input"
                                   id="create-compress-to-jpeg">
                            <label for="create-compress-to-jpeg" class="form-check-label">上傳圖壓縮並轉 JPEG</label>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="url" name="link" class="form-control" id="create-link"
                                       placeholder="https://...">
                                <label for="create-link">連結（可選）</label>
                            </div>
                        </div>
                        <div class="col-12 form-check ms-1">
                            <input type="checkbox" name="is_active" class="form-check-input" id="news-active"
                                   checked>
                            <label for="news-active" class="form-check-label">啟用顯示</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">新增消息</button>
                </div>
            </form>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3" id="adminManageTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-news-btn" data-bs-toggle="tab" data-bs-target="#tab-news" type="button" role="tab" aria-controls="tab-news" aria-selected="true">最新消息</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-hero-btn" data-bs-toggle="tab" data-bs-target="#tab-hero" type="button" role="tab" aria-controls="tab-hero" aria-selected="false">主視覺</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-article-btn" data-bs-toggle="tab" data-bs-target="#tab-article" type="button" role="tab" aria-controls="tab-article" aria-selected="false">文章分享</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-about-btn" data-bs-toggle="tab" data-bs-target="#tab-about" type="button" role="tab" aria-controls="tab-about" aria-selected="false">關於恆寵愛</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-store-btn" data-bs-toggle="tab" data-bs-target="#tab-store" type="button" role="tab" aria-controls="tab-store" aria-selected="false">營業時間</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-senior-btn" data-bs-toggle="tab" data-bs-target="#tab-senior" type="button" role="tab" aria-controls="tab-senior" aria-selected="false">樂齡館</button>
        </li>
    </ul>

    <div class="tab-content" id="adminManageTabContent">
    <div class="tab-pane fade show active" id="tab-news" role="tabpanel" aria-labelledby="tab-news-btn" tabindex="0">

    <div class="admin-card card mb-4">
        <div class="card-header justify-content-between d-flex fw-semibold">
            <span><i class="bi bi-list-ul me-2"></i>最新消息列表（可編輯）</span>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal"
                    data-bs-target="#createNewsModal">
                <i class="bi bi-plus-circle me-1"></i>新增最新消息
            </button>
        </div>
        <div class="card-body">
            <?php if ($newsRows === []): ?>
                <p class="text-muted mb-0">尚無資料</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="width: 80px;">ID</th>
                            <th style="width: 120px;">圖片</th>
                            <th>標題</th>
                            <th style="width: 180px;">發布時間</th>
                            <th style="width: 110px;">狀態</th>
                            <th style="width: 180px;">操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($newsRows as $row): ?>
                            <?php $newsImageUrl = resolveDisplayImageUrl((string)($row['image_url'] ?? ''), $publicBasePath); ?>
                            <tr>
                                <td><?= (int)$row['id'] ?></td>
                                <td>
                                    <?php if ($newsImageUrl !== ''): ?>
                                        <img src="<?= htmlspecialchars($newsImageUrl, ENT_QUOTES, 'UTF-8') ?>"
                                             alt="貼文圖片" class="rounded border"
                                             style="width:88px;height:56px;object-fit:cover;">
                                    <?php else: ?>
                                        <span class="badge text-bg-light">無圖片</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="small text-muted text-truncate"
                                         style="max-width: 520px;"><?= htmlspecialchars((string)$row['excerpt'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars((string)$row['published_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ((int)$row['is_active'] === 1): ?>
                                        <span class="badge text-bg-success">啟用</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">停用</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editNewsModal-<?= (int)$row['id'] ?>">
                                            <i class="bi bi-pencil-square me-1"></i>編輯
                                        </button>
                                        <form method="post" class="m-0">
                                            <input type="hidden" name="action" value="news_delete">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('確定刪除這筆消息？')">
                                                <i class="bi bi-trash3 me-1"></i>刪除
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php foreach ($newsRows as $row): ?>
                    <?php $newsImageUrl = resolveDisplayImageUrl((string)($row['image_url'] ?? ''), $publicBasePath); ?>
                    <div class="modal fade" id="editNewsModal-<?= (int)$row['id'] ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-scrollable modal-lg">
                            <form class="modal-content" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="news_update">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <div class="modal-header bg-light">
                                    <h5 class="modal-title">編輯消息 #<?= (int)$row['id'] ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"
                                            aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <div class="form-floating">
                                                <input type="text" name="title" class="form-control"
                                                       id="edit-title-<?= (int)$row['id'] ?>"
                                                       value="<?= htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') ?>"
                                                       placeholder="標題" required>
                                                <label for="edit-title-<?= (int)$row['id'] ?>">標題</label>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="datetime-local" name="published_at"
                                                       class="form-control"
                                                       id="edit-published-<?= (int)$row['id'] ?>"
                                                       value="<?= htmlspecialchars((string)date('Y-m-d\TH:i', strtotime((string)$row['published_at'])), ENT_QUOTES, 'UTF-8') ?>"
                                                       placeholder="發布時間">
                                                <label for="edit-published-<?= (int)$row['id'] ?>">發布時間</label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-floating">
                                                    <textarea name="excerpt" class="form-control"
                                                              id="edit-excerpt-<?= (int)$row['id'] ?>"
                                                              placeholder="摘要" style="height: 110px"
                                                              required><?= htmlspecialchars((string)$row['excerpt'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                                <label for="edit-excerpt-<?= (int)$row['id'] ?>">摘要</label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-floating">
                                                    <input type="text" name="image_url" class="form-control"
                                                           id="edit-image-url-<?= (int)$row['id'] ?>"
                                                           data-preview-url="news-edit-<?= (int)$row['id'] ?>"
                                                           value="<?= htmlspecialchars((string)($row['image_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                           placeholder="圖片連結">
                                                <label for="edit-image-url-<?= (int)$row['id'] ?>">圖片連結</label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-floating">
                                                    <input type="file" name="image_file" class="form-control"
                                                           id="edit-image-file-<?= (int)$row['id'] ?>"
                                                           data-preview-file="news-edit-<?= (int)$row['id'] ?>"
                                                           accept="image/jpeg,image/png,image/webp,image/gif">
                                                <label for="edit-image-file-<?= (int)$row['id'] ?>">或上傳圖片（可壓縮）</label>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label mb-2">目前圖片 / 即時預覽</label>
                                                <div class="position-relative border rounded-3 overflow-hidden bg-light">
                                                    <img
                                                            id="edit-image-preview-<?= (int)$row['id'] ?>"
                                                            data-preview-target="news-edit-<?= (int)$row['id'] ?>"
                                                            data-public-base-path="<?= htmlspecialchars($publicBasePath, ENT_QUOTES, 'UTF-8') ?>"
                                                            src="<?= htmlspecialchars($newsImageUrl !== '' ? $newsImageUrl : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', ENT_QUOTES, 'UTF-8') ?>"
                                                            alt="貼文圖片預覽"
                                                            class="w-100"
                                                            style="min-height: 220px; object-fit: cover;">
                                                    <div data-preview-placeholder="news-edit-<?= (int)$row['id'] ?>" class="position-absolute top-50 start-50 translate-middle text-secondary <?= $newsImageUrl !== '' ? 'd-none' : '' ?>">目前無圖片</div>
                                                </div>
                                                <div class="form-text">若不更換圖片，會保留目前圖片。</div>
                                            </div>
                                        <div class="col-12 form-check ms-1">
                                            <input type="checkbox" name="compress_to_jpeg" class="form-check-input"
                                                   id="edit-compress-to-jpeg-<?= (int)$row['id'] ?>">
                                            <label class="form-check-label"
                                                   for="edit-compress-to-jpeg-<?= (int)$row['id'] ?>">上傳圖壓縮並轉
                                                JPEG</label>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-floating">
                                                <input type="text" name="link" class="form-control"
                                                       id="edit-link-<?= (int)$row['id'] ?>"
                                                       value="<?= htmlspecialchars((string)$row['link'], ENT_QUOTES, 'UTF-8') ?>"
                                                       placeholder="連結">
                                                <label for="edit-link-<?= (int)$row['id'] ?>">連結</label>
                                            </div>
                                        </div>
                                        <div class="col-12 form-check ms-1">
                                            <input type="checkbox" name="is_active" class="form-check-input"
                                                   id="active-<?= (int)$row['id'] ?>" <?= (int)$row['is_active'] === 1 ? 'checked' : '' ?>>
                                            <label class="form-check-label"
                                                   for="active-<?= (int)$row['id'] ?>">啟用顯示</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消
                                    </button>
                                    <button type="submit" class="btn btn-success"><i
                                                class="bi bi-check2-circle me-1"></i>儲存更新
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    </div>

    <div class="modal fade" id="createArticleModal" tabindex="-1" aria-labelledby="createArticleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <form method="post" class="modal-content" enctype="multipart/form-data">
                <input type="hidden" name="action" value="article_create">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="createArticleModalLabel">新增文章分享</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" name="title" class="form-control" id="article-create-title" placeholder="標題" required>
                                <label for="article-create-title">標題</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="datetime-local" name="published_at" class="form-control" id="article-create-published-at" value="<?= date('Y-m-d\TH:i') ?>" placeholder="發布時間">
                                <label for="article-create-published-at">發布時間</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <textarea name="excerpt" class="form-control" id="article-create-excerpt" placeholder="摘要" style="height: 110px" required></textarea>
                                <label for="article-create-excerpt">摘要</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="text" name="image_url" class="form-control" id="article-create-image-url" data-preview-url="article-create" placeholder="圖片連結">
                                <label for="article-create-image-url">圖片連結（可選）</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="file" name="image_file" class="form-control" id="article-create-image-file" data-preview-file="article-create" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif">
                                <label for="article-create-image-file">或上傳圖片（可壓縮）</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label mb-2">圖片即時預覽</label>
                            <div class="position-relative border rounded-3 overflow-hidden bg-light">
                                <img id="article-create-image-preview" data-preview-target="article-create" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="文章預覽" class="w-100" style="min-height: 220px; object-fit: cover;">
                                <div data-preview-placeholder="article-create" class="position-absolute top-50 start-50 translate-middle text-secondary">目前無圖片</div>
                            </div>
                        </div>
                        <div class="col-12 form-check ms-1">
                            <input type="checkbox" name="compress_to_jpeg" class="form-check-input" id="article-create-compress-to-jpeg">
                            <label for="article-create-compress-to-jpeg" class="form-check-label">上傳圖壓縮並轉 JPEG</label>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="url" name="link" class="form-control" id="article-create-link" placeholder="https://...">
                                <label for="article-create-link">連結（可選）</label>
                            </div>
                        </div>
                        <div class="col-12 form-check ms-1">
                            <input type="checkbox" name="is_active" class="form-check-input" id="article-create-active" checked>
                            <label for="article-create-active" class="form-check-label">啟用顯示</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">新增文章分享</button>
                </div>
            </form>
        </div>
    </div>

    <div class="tab-pane fade" id="tab-hero" role="tabpanel" aria-labelledby="tab-hero-btn" tabindex="0">
    <div class="admin-card card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-image me-2"></i>主視覺內容</div>
        <div class="card-body">
            <form method="post" class="row g-3" enctype="multipart/form-data">
                <input type="hidden" name="action" value="hero_update">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-lg-7">
                    <div class="row g-3">
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="text" name="hero_title" class="form-control" id="hero-title"
                                       value="<?= htmlspecialchars((string)($heroMain['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="主視覺標題" required>
                                <label for="hero-title">主視覺標題</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <textarea name="hero_content" class="form-control" id="hero-content"
                                          placeholder="主視覺說明" style="height: 130px" required><?= htmlspecialchars((string)($heroMain['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <label for="hero-content">主視覺說明</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="text" name="hero_image_url" class="form-control" id="hero-image-url"
                                       data-preview-url="hero-visual"
                                       value="<?= htmlspecialchars((string)($heroVisual['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="圖片連結">
                                <label for="hero-image-url">主視覺背景圖片連結</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="file" name="hero_image_file" class="form-control" id="hero-image-file"
                                       data-preview-file="hero-visual"
                                       accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif">
                                <label for="hero-image-file">或上傳主視覺圖片（可壓縮）</label>
                            </div>
                        </div>
                        <div class="col-12 form-check ms-1">
                            <input type="checkbox" name="compress_to_jpeg" class="form-check-input" id="hero-compress-to-jpeg">
                            <label for="hero-compress-to-jpeg" class="form-check-label">上傳圖壓縮並轉 JPEG</label>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>儲存主視覺</button>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <label class="form-label">主視覺即時預覽</label>
                    <?php $heroPreviewUrl = resolveDisplayImageUrl((string)($heroVisual['content'] ?? ''), $publicBasePath); ?>
                    <div class="position-relative border rounded-3 overflow-hidden bg-light">
                        <img id="hero-image-preview" data-preview-target="hero-visual" data-public-base-path="<?= htmlspecialchars($publicBasePath, ENT_QUOTES, 'UTF-8') ?>"
                             src="<?= htmlspecialchars($heroPreviewUrl !== '' ? $heroPreviewUrl : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', ENT_QUOTES, 'UTF-8') ?>"
                             alt="主視覺預覽" class="w-100" style="min-height: 220px; object-fit: cover;">
                        <div data-preview-placeholder="hero-visual" class="position-absolute top-50 start-50 translate-middle text-secondary <?= $heroPreviewUrl !== '' ? 'd-none' : '' ?>">目前無圖片</div>
                    </div>
                </div>
            </form>
        </div>
    </div>
    </div>

    <div class="tab-pane fade" id="tab-article" role="tabpanel" aria-labelledby="tab-article-btn" tabindex="0">
    <div class="admin-card card mb-4">
        <div class="card-header justify-content-between d-flex fw-semibold"><span><i class="bi bi-journal-richtext me-2"></i>文章分享</span><button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createArticleModal"><i class="bi bi-plus-circle me-1"></i>新增文章分享</button></div>
        <div class="card-body">

            <?php if ($articleRows === []): ?>
                <p class="text-muted mb-0">尚無文章分享資料</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="width: 80px;">ID</th>
                            <th style="width: 120px;">圖片</th>
                            <th>標題</th>
                            <th style="width: 180px;">發布時間</th>
                            <th style="width: 110px;">狀態</th>
                            <th style="width: 180px;">操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($articleRows as $row): ?>
                            <?php $articleImageUrl = resolveDisplayImageUrl((string)($row['image_url'] ?? ''), $publicBasePath); ?>
                            <tr>
                                <td><?= (int)$row['id'] ?></td>
                                <td>
                                    <?php if ($articleImageUrl !== ''): ?>
                                        <img src="<?= htmlspecialchars($articleImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt="文章圖片" class="rounded border" style="width:88px;height:56px;object-fit:cover;">
                                    <?php else: ?>
                                        <span class="badge text-bg-light">無圖片</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="small text-muted text-truncate" style="max-width: 520px;"><?= htmlspecialchars((string)$row['excerpt'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars((string)$row['published_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ((int)$row['is_active'] === 1): ?>
                                        <span class="badge text-bg-success">啟用</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-secondary">停用</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editArticleModal-<?= (int)$row['id'] ?>">
                                            <i class="bi bi-pencil-square me-1"></i>編輯
                                        </button>
                                        <form method="post" class="m-0">
                                            <input type="hidden" name="action" value="article_delete">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('確定刪除這篇文章分享？')">刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3">
                    <?php foreach ($articleRows as $row): ?>
                        <?php $articleImageUrl = resolveDisplayImageUrl((string)($row['image_url'] ?? ''), $publicBasePath); ?>
                        <div class="modal fade" id="editArticleModal-<?= (int)$row['id'] ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-scrollable modal-lg">
                                <form method="post" class="modal-content" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="article_update">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <div class="modal-header bg-light">
                                        <h5 class="modal-title">編輯文章 #<?= (int)$row['id'] ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row g-3">
                                            <div class="col-md-8">
                                                <div class="form-floating">
                                                    <input type="text" name="title" class="form-control" id="article-title-<?= (int)$row['id'] ?>" value="<?= htmlspecialchars((string)$row['title'], ENT_QUOTES, 'UTF-8') ?>" placeholder="標題" required>
                                                    <label for="article-title-<?= (int)$row['id'] ?>">標題</label>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-floating">
                                                    <input type="datetime-local" name="published_at" class="form-control" id="article-published-<?= (int)$row['id'] ?>" value="<?= htmlspecialchars((string)date('Y-m-d\TH:i', strtotime((string)$row['published_at'])), ENT_QUOTES, 'UTF-8') ?>" placeholder="發布時間">
                                                    <label for="article-published-<?= (int)$row['id'] ?>">發布時間</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="form-floating">
                                                    <textarea name="excerpt" class="form-control" id="article-excerpt-<?= (int)$row['id'] ?>" placeholder="摘要" style="height: 110px" required><?= htmlspecialchars((string)$row['excerpt'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                                    <label for="article-excerpt-<?= (int)$row['id'] ?>">摘要</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="form-floating">
                                                    <input type="text" name="image_url" class="form-control" id="article-image-url-<?= (int)$row['id'] ?>" data-preview-url="article-edit-<?= (int)$row['id'] ?>" value="<?= htmlspecialchars((string)($row['image_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="圖片連結">
                                                    <label for="article-image-url-<?= (int)$row['id'] ?>">圖片連結</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="form-floating">
                                                    <input type="file" name="image_file" class="form-control" id="article-image-file-<?= (int)$row['id'] ?>" data-preview-file="article-edit-<?= (int)$row['id'] ?>" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif">
                                                    <label for="article-image-file-<?= (int)$row['id'] ?>">或上傳圖片（可壓縮）</label>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label mb-2">目前圖片 / 即時預覽</label>
                                                <div class="position-relative border rounded-3 overflow-hidden bg-light">
                                                    <img id="article-image-preview-<?= (int)$row['id'] ?>" data-preview-target="article-edit-<?= (int)$row['id'] ?>" src="<?= htmlspecialchars($articleImageUrl !== '' ? $articleImageUrl : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', ENT_QUOTES, 'UTF-8') ?>" alt="文章圖片預覽" class="w-100" style="min-height: 220px; object-fit: cover;">
                                                    <div data-preview-placeholder="article-edit-<?= (int)$row['id'] ?>" class="position-absolute top-50 start-50 translate-middle text-secondary <?= $articleImageUrl !== '' ? 'd-none' : '' ?>">目前無圖片</div>
                                                </div>
                                            </div>
                                            <div class="col-12 form-check ms-1">
                                                <input type="checkbox" name="compress_to_jpeg" class="form-check-input" id="article-compress-<?= (int)$row['id'] ?>">
                                                <label class="form-check-label" for="article-compress-<?= (int)$row['id'] ?>">上傳圖壓縮並轉 JPEG</label>
                                            </div>
                                            <div class="col-12">
                                                <div class="form-floating">
                                                    <input type="url" name="link" class="form-control" id="article-link-<?= (int)$row['id'] ?>" value="<?= htmlspecialchars((string)$row['link'], ENT_QUOTES, 'UTF-8') ?>" placeholder="連結">
                                                    <label for="article-link-<?= (int)$row['id'] ?>">連結</label>
                                                </div>
                                            </div>
                                            <div class="col-12 form-check ms-1">
                                                <input type="checkbox" name="is_active" class="form-check-input" id="article-active-<?= (int)$row['id'] ?>" <?= (int)$row['is_active'] === 1 ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="article-active-<?= (int)$row['id'] ?>">啟用顯示</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                        <button type="submit" class="btn btn-success">儲存文章</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    </div>

    <div class="tab-pane fade" id="tab-about" role="tabpanel" aria-labelledby="tab-about-btn" tabindex="0">
    <div class="admin-card card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-chat-square-heart me-2"></i>關於恆寵愛</div>
        <div class="card-body">
            <form method="post" class="row g-3 mb-4">
                <input type="hidden" name="action" value="about_update">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-12">
                    <div class="form-floating">
                        <input type="text" name="title" class="form-control" id="about-title" value="<?= htmlspecialchars((string)($aboutContent['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="標題" required>
                        <label for="about-title">標題</label>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-floating">
                        <textarea name="content" class="form-control" id="about-content" placeholder="內容" style="height: 220px" required><?= htmlspecialchars((string)($aboutContent['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        <label for="about-content">內容</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">儲存關於內容</button>
                </div>
            </form>
            <p>詳細資料修改:</p>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($aboutModals as $modal): ?>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#aboutModalEditor-<?= (int)$modal['id'] ?>">
                        <?= htmlspecialchars((string)$modal['title'], ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($aboutModals as $modal): ?>
                <?php $modalImageUrl = resolveDisplayImageUrl((string)($modal['image_url'] ?? ''), $publicBasePath); ?>
                <div class="modal fade" id="aboutModalEditor-<?= (int)$modal['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-scrollable modal-lg">
                        <form method="post" class="modal-content" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="about_modal_update">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="modal_key" value="<?= htmlspecialchars((string)$modal['modal_key'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="current_image_url" value="<?= htmlspecialchars((string)($modal['image_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="modal-header bg-light">
                                <h5 class="modal-title">編輯彈窗：<?= htmlspecialchars((string)$modal['title'], ENT_QUOTES, 'UTF-8') ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="form-floating">
                                            <input type="text" name="title" class="form-control" id="about-modal-title-<?= (int)$modal['id'] ?>" value="<?= htmlspecialchars((string)$modal['title'], ENT_QUOTES, 'UTF-8') ?>" placeholder="彈窗標題" required>
                                            <label for="about-modal-title-<?= (int)$modal['id'] ?>">彈窗標題（<?= htmlspecialchars((string)$modal['modal_key'], ENT_QUOTES, 'UTF-8') ?>）</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-floating">
                                            <textarea name="content" class="form-control" id="about-modal-content-<?= (int)$modal['id'] ?>" placeholder="彈窗內容" style="height: 160px" required><?= htmlspecialchars((string)$modal['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                                            <label for="about-modal-content-<?= (int)$modal['id'] ?>">彈窗內容</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-floating">
                                            <input type="text" name="image_url" class="form-control" id="about-modal-image-url-<?= (int)$modal['id'] ?>" data-preview-url="about-modal-<?= (int)$modal['id'] ?>" value="<?= htmlspecialchars((string)($modal['image_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="圖片連結（店長可用）">
                                            <label for="about-modal-image-url-<?= (int)$modal['id'] ?>">圖片連結（店長可用）</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-floating">
                                            <input type="file" name="image_file" class="form-control" id="about-modal-image-file-<?= (int)$modal['id'] ?>" data-preview-file="about-modal-<?= (int)$modal['id'] ?>" accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif">
                                            <label for="about-modal-image-file-<?= (int)$modal['id'] ?>">或上傳圖片（可壓縮）</label>
                                        </div>
                                    </div>
                                    <div class="col-12 form-check ms-1">
                                        <input type="checkbox" name="compress_to_jpeg" class="form-check-input" id="about-modal-compress-<?= (int)$modal['id'] ?>">
                                        <label for="about-modal-compress-<?= (int)$modal['id'] ?>" class="form-check-label">上傳圖壓縮並轉 JPEG</label>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label mb-2">目前圖片 / 即時預覽</label>
                                        <div class="position-relative border rounded-3 overflow-hidden bg-light">
                                            <img id="about-modal-image-preview-<?= (int)$modal['id'] ?>" data-preview-target="about-modal-<?= (int)$modal['id'] ?>" src="<?= htmlspecialchars($modalImageUrl !== '' ? $modalImageUrl : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', ENT_QUOTES, 'UTF-8') ?>" alt="彈窗圖片預覽" class="w-100" style="min-height: 220px; object-fit: cover;">
                                            <div data-preview-placeholder="about-modal-<?= (int)$modal['id'] ?>" class="position-absolute top-50 start-50 translate-middle text-secondary <?= $modalImageUrl !== '' ? 'd-none' : '' ?>">目前無圖片</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="submit" class="btn btn-success">儲存彈窗內容</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    </div>

    <div class="tab-pane fade" id="tab-store" role="tabpanel" aria-labelledby="tab-store-btn" tabindex="0">
    <div class="admin-card card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-clock-history me-2"></i>店家營業時間</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="store_update">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-12">
                    <div class="form-floating">
                        <input autocomplete="off"
                                type="text" name="business_hours" class="form-control" id="store-business-hours"
                               value="<?= htmlspecialchars((string)($storeInfo['business_hours'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="營業時間" required>
                        <label for="store-business-hours">營業時間（business_hours）</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">儲存營業時間</button>
                </div>
            </form>
        </div>
    </div>
    </div>

    <div class="tab-pane fade" id="tab-senior" role="tabpanel" aria-labelledby="tab-senior-btn" tabindex="0">
    <div class="admin-card card mb-4">
        <div class="card-header fw-semibold"><i class="bi bi-house-heart me-2"></i>樂齡館內容（前端動態區塊）</div>
        <div class="card-body">
            <form method="post" class="row g-3" enctype="multipart/form-data">
                <input type="hidden" name="action" value="senior_update">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="col-lg-7">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" name="title" class="form-control" id="senior-title"
                                       value="<?= htmlspecialchars((string)($senior['title'] ?? '樂齡館'), ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="標題（title）" required>
                                <label for="senior-title">標題（title）</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" name="subtitle" class="form-control" id="senior-subtitle"
                                       value="<?= htmlspecialchars((string)($senior['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="副標（subtitle）" required>
                                <label for="senior-subtitle">副標（subtitle）</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <textarea name="description" class="form-control" id="senior-description"
                                          placeholder="說明（description）" style="height: 150px"
                                          required><?= htmlspecialchars((string)($senior['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                <label for="senior-description">說明（description）</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="text" name="tags" class="form-control" id="senior-tags"
                                       value="<?= htmlspecialchars((string)($senior['tags'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="#tags" required>
                                <label for="senior-tags">#tags (通過空白進行分隔)</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="url" name="image_url" class="form-control" id="senior-image-url"
                                       value="<?= htmlspecialchars((string)($senior['imageUrl'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="圖片連結（imageUrl）" required>
                                <label for="senior-image-url">圖片連結（imageUrl）</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                <input type="file" name="image_file" class="form-control" id="senior-image-file"
                                       accept="image/jpeg,image/png,image/webp,image/gif">
                                <label for="senior-image-file">或上傳圖片檔案（優先於圖片連結）</label>
                            </div>
                            <div class="form-text">檔案會儲存到 <code>backend/public/uploads</code></div>
                        </div>
                        <div class="col-12 form-check">
                            <input type="checkbox" class="form-check-input" id="compress-to-jpeg"
                                   name="compress_to_jpeg">
                            <label class="form-check-label" for="compress-to-jpeg">是否壓縮並轉成 JPEG（建議勾選）</label>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <label class="form-label">圖片即時預覽</label>
                    <div class="position-relative border rounded-3 overflow-hidden bg-light">
                        <img
                            id="senior-image-preview"
                            src="<?= htmlspecialchars($seniorPreviewUrl, ENT_QUOTES, 'UTF-8') ?>"
                            data-public-base-path="<?= htmlspecialchars($publicBasePath, ENT_QUOTES, 'UTF-8') ?>"
                            alt="樂齡館預覽"
                            class="w-100"
                            style="min-height: 220px; object-fit: cover;">
                        <div data-preview-placeholder="senior" class="position-absolute top-50 start-50 translate-middle text-secondary <?= $seniorPreviewUrl !== '' ? 'd-none' : '' ?>">目前無圖片</div>
                    </div>
                    <div class="form-text">輸入圖片連結或選擇本機檔案後會立即顯示預覽。</div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>儲存樂齡館內容
                    </button>
                </div>
            </form>
        </div>
    </div>
    </div>
    </div>

    <div class="small text-muted">
        後台路徑：<code>/case0001_20260301/backend/public/admin/index.php</code>
    </div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($isAuthenticated): ?>
<script>
    (function () {
        const tabStorageKey = 'admin.activeTab';
        const tabButtons = document.querySelectorAll('[data-bs-toggle="tab"]');

        const restoreTab = () => {
            const hash = window.location.hash;
            const saved = localStorage.getItem(tabStorageKey);
            const targetId = hash && hash.startsWith('#tab-') ? hash : saved;
            if (!targetId) {
                return;
            }

            const targetButton = document.querySelector('[data-bs-target="' + targetId + '"]');
            if (!targetButton || typeof bootstrap === 'undefined' || !bootstrap.Tab) {
                return;
            }

            bootstrap.Tab.getOrCreateInstance(targetButton).show();
        };

        tabButtons.forEach(function (button) {
            button.addEventListener('shown.bs.tab', function (event) {
                const targetId = event.target.getAttribute('data-bs-target');
                if (!targetId) {
                    return;
                }

                localStorage.setItem(tabStorageKey, targetId);
                history.replaceState(null, '', targetId);
            });
        });

        restoreTab();

        function bindLivePreview(imageUrlInput, imageFileInput, preview, placeholder) {
            if (!imageUrlInput || !imageFileInput || !preview) {
                return;
            }

            const fallbackSrc = preview.getAttribute('src') || '';
            const publicBasePath = preview.getAttribute('data-public-base-path') || '';

            const resolvePreviewSrc = (raw) => {
                const value = String(raw || '').trim();
                if (value === '') return fallbackSrc;
                if (/^(https?:)?\/\//i.test(value) || value.startsWith('data:') || value.startsWith('blob:')) return value;
                if (publicBasePath !== '' && value.startsWith(publicBasePath + '/')) return value;
                if (value.startsWith('/')) return publicBasePath + value;
                return publicBasePath + '/' + value.replace(/^\/+/, '');
            };

        const syncPlaceholder = (srcValue) => {
            if (!placeholder) return;
            const emptySrc = srcValue === '' || srcValue.startsWith('data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
            placeholder.classList.toggle('d-none', !emptySrc);
        };

        imageUrlInput.addEventListener('input', function () {
            const nextSrc = resolvePreviewSrc(imageUrlInput.value);
            preview.src = nextSrc;
            syncPlaceholder(nextSrc);
        });

            imageFileInput.addEventListener('change', function () {
                const file = imageFileInput.files && imageFileInput.files[0];
            if (!file) {
                const nextSrc = resolvePreviewSrc(imageUrlInput.value);
                preview.src = nextSrc;
                syncPlaceholder(nextSrc);
                return;
            }

                const localUrl = URL.createObjectURL(file);
                preview.src = localUrl;
                syncPlaceholder(localUrl);
            });

            syncPlaceholder(preview.getAttribute('src') || '');
        }

        bindLivePreview(
            document.getElementById('senior-image-url'),
            document.getElementById('senior-image-file'),
            document.getElementById('senior-image-preview'),
            document.querySelector('[data-preview-placeholder="senior"]')
        );

        document.querySelectorAll('[data-preview-target]').forEach(function (preview) {
            const key = preview.getAttribute('data-preview-target');
            if (!key) {
                return;
            }

            const imageUrlInput = document.querySelector('[data-preview-url="' + key + '"]');
            const imageFileInput = document.querySelector('[data-preview-file="' + key + '"]');
            const placeholder = document.querySelector('[data-preview-placeholder="' + key + '"]');
            bindLivePreview(imageUrlInput, imageFileInput, preview, placeholder);
        });
    })();
</script>
<?php endif; ?>
</body>
</html>
