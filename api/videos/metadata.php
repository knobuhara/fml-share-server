<?php
/**
 * Video Metadata API Endpoint
 * GET /api/videos/metadata - Fetch videos from Xserver
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/ResponseHandler.php';
require_once __DIR__ . '/../../lib/FtpsClient.php';
require_once __DIR__ . '/../../api/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../config/Config.php';

function listLocalVideoFiles(string $directory): array {
    $files = [];
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isLink() || !$entry->isFile()) {
            continue;
        }

        $files[] = [
            'name' => $entry->getFilename(),
            'size' => $entry->getSize(),
            'modified' => gmdate(DateTimeInterface::ATOM, $entry->getMTime()),
        ];
    }

    return $files;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ResponseHandler::error('Method not allowed', 405);
}

try {
    // Verify authentication
    AuthMiddleware::verify();

    // Get video folder path from config
    $videoPath = Config::get('XSERVER_VIDEO_PATH');
    
    if (!$videoPath) {
        ResponseHandler::error('Xserver video path not configured', 500);
    }

    $baseUrl = rtrim(Config::get('XSERVER_VIDEO_BASE_URL', ''), '/');
    if ($baseUrl !== '' && (!filter_var($baseUrl, FILTER_VALIDATE_URL) ||
        strtolower(parse_url($baseUrl, PHP_URL_SCHEME) ?? '') !== 'https' ||
        array_intersect(['user', 'pass', 'query', 'fragment'], array_keys(parse_url($baseUrl))))) {
        ResponseHandler::error('動画の再生先には HTTPS のフォルダ URL を設定してください。', 500);
    }

    $localPath = Config::get('XSERVER_VIDEO_LOCAL_PATH', '');
    if ($localPath !== '') {
        try {
            $files = listLocalVideoFiles($localPath);
        } catch (RuntimeException $e) {
            error_log('Failed to read local video directory: ' . $e->getMessage());
            ResponseHandler::error('サーバー上の動画フォルダを読み取れません。実パスと読み取り権限を確認してください。', 500);
        }
    } else {
        try {
            $files = (new FtpsClient())->listFiles($videoPath);
        } catch (RuntimeException $e) {
            ResponseHandler::error($e->getMessage(), 502);
        }
    }

    $videoExtensions = array_map('trim', explode(',', strtolower(
        Config::get('ALLOWED_EXTENSIONS', 'mp4,mkv,avi,mov,flv,wmv,webm')
    )));
    $videoFiles = array_filter($files, function($file) use ($videoExtensions) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, $videoExtensions, true);
    });

    $videosMetadata = [];
    $db = $videoFiles ? Database::getInstance() : null;
    foreach ($videoFiles as $file) {
        $filePath = rtrim($videoPath, '/') . '/' . $file['name'];
        $cached = $db->fetch(
            'SELECT * FROM videos WHERE file_path = ? LIMIT 1',
            [$filePath]
        );

        $videosMetadata[] = array_merge([
            'id' => $cached['id'] ?? md5($filePath),
            'title' => pathinfo($file['name'], PATHINFO_FILENAME),
            'description' => null,
            'file_size' => $file['size'],
            'duration' => null,
            'thumbnail_url' => null,
            'created_at' => $file['modified'],
            'updated_at' => $file['modified'],
        ], $cached ?: [], [
            'file_path' => $filePath,
            'file_name' => $file['name'],
            'playback_url' => $baseUrl !== '' ? $baseUrl . '/' . rawurlencode($file['name']) : null,
            'from_cache' => (bool)$cached,
        ]);
    }

    ResponseHandler::success([
        'videos' => $videosMetadata,
        'total' => count($videosMetadata),
        'source' => 'xserver'
    ], 'Video metadata retrieved successfully');

} catch (Exception $e) {
    error_log('Failed to retrieve video metadata: ' . $e->getMessage());
    ResponseHandler::error('動画情報を取得できませんでした。', 500);
}
