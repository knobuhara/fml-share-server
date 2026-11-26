<?php
/**
 * Video Download API Endpoint
 * GET /api/videos/download/{videoId}
 */
header('Content-Type: application/octet-stream');
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/ResponseHandler.php';
require_once __DIR__ . '/../../lib/FtpsClient.php';
require_once __DIR__ . '/../../api/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../config/Config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json');
    ResponseHandler::error('Method not allowed', 405);
    exit;
}

try {
    // Verify authentication and get user payload
    $authPayload = AuthMiddleware::verify();
    $user = is_array($authPayload) ? $authPayload : null;

    // Get video ID from path or query parameter
    $videoId = $_GET['videoId'] ?? null;
    
    // If not in query params, try to extract from URL path
    if (!$videoId && isset($_SERVER['REQUEST_URI'])) {
        // Extract ID from path like /api/videos/download/99227f8de96c1e7c6816d2c03559f191
        if (preg_match('~api/videos/download/([a-z0-9]+)~i', $_SERVER['REQUEST_URI'], $matches)) {
            $videoId = $matches[1];
        }
    }

    if (!$videoId || !preg_match('~^[a-z0-9]+$~i', $videoId)) {
        header('Content-Type: application/json');
        ResponseHandler::error('Invalid video ID', 400);
        exit;
    }

    $db = Database::getInstance();

    // Get video from database
    $video = $db->fetch(
        'SELECT id, file_path, title FROM videos WHERE id = ? LIMIT 1',
        [$videoId]
    );

    if (!$video) {
        header('Content-Type: application/json');
        ResponseHandler::error('Video not found', 404);
        exit;
    }

    // Record download for non-admin users
    if ($user && $user['role'] !== 'admin') {
        try {
            $sessions = $db->fetchAll(
                'SELECT id, downloaded_videos FROM user_sessions 
                 WHERE user_id = ? AND logout_at IS NULL 
                 ORDER BY login_at DESC LIMIT 1',
                [$user['id']]
            );
            
            if ($sessions && count($sessions) > 0) {
                $session = $sessions[0];
                $videos = json_decode($session['downloaded_videos'], true) ?? [];
                
                // Remove existing entry for this video if it exists
                $videos = array_filter($videos, function($v) use ($video) {
                    return $v['title'] !== $video['title'];
                });
                
                // Add new entry with title and timestamp
                $videos[] = [
                    'title' => $video['title'],
                    'downloaded_at' => date('Y-m-d H:i:s')
                ];
                
                // Re-index array
                $videos = array_values($videos);
                
                $db->update('user_sessions', 
                    ['downloaded_videos' => json_encode($videos)],
                    ['id' => $session['id']]
                );
                error_log('✓ Download recorded for user ' . $user['id'] . ': ' . $video['title']);
            }
        } catch (Exception $e) {
            error_log('⚠ Failed to record download: ' . $e->getMessage());
            // Continue even if download recording fails
        }
    }

    $filePath = $video['file_path'];
    // Split on the separator without interpreting filename bytes in the PHP locale.
    $namePath = DIRECTORY_SEPARATOR === '\\' ? str_replace('\\', '/', $filePath) : $filePath;
    $lastSlash = strrpos($namePath, '/');
    $fileName = $lastSlash === false ? $namePath : substr($namePath, $lastSlash + 1);
    if ($fileName === '' || $fileName === '.' || $fileName === '..' || strpos($fileName, "\0") !== false ||
        (DIRECTORY_SEPARATOR === '\\' && strpos($fileName, ':') !== false)) {
        throw new RuntimeException('Invalid stored video file path');
    }

    $asciiFileName = str_replace(['"', '\\'], '_', preg_replace('/[^\x20-\x7E]/', '_', $fileName));
    $contentDisposition = 'Content-Disposition: attachment; filename="' . $asciiFileName .
        '"; filename*=UTF-8\'\'' . rawurlencode($fileName);

    // Check if file exists locally
    $localPath = Config::get('XSERVER_VIDEO_LOCAL_PATH', '');
    if ($localPath !== '' && is_dir($localPath)) {
        $fullPath = rtrim($localPath, '/') . '/' . $fileName;
        
        if (!file_exists($fullPath)) {
            error_log('Local video download: file_exists returned false ' . json_encode([
                'video_id' => $videoId,
                'path' => $fullPath,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            header('Content-Type: application/json');
            ResponseHandler::error('Video file not found on disk', 404);
            exit;
        }

        // Stream file for download
        $fileSize = filesize($fullPath);
        
        header('Content-Type: application/octet-stream');
        header($contentDisposition);
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Stream the file in chunks
        $chunkSize = 1024 * 1024; // 1MB chunks
        $handle = fopen($fullPath, 'rb');
        
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, $chunkSize);
                flush();
            }
            fclose($handle);
        }
        exit;
    }

    // Download from FTPS if no local path
    $ftpsClient = new FtpsClient();
    $videoPath = Config::get('XSERVER_VIDEO_PATH');
    
    if (!$videoPath) {
        header('Content-Type: application/json');
        ResponseHandler::error('Video storage not configured', 500);
        exit;
    }

    // Get file content from FTPS
    $fileContent = $ftpsClient->downloadFile($filePath);
    $fileSize = strlen($fileContent);

    header('Content-Type: application/octet-stream');
    header($contentDisposition);
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $fileContent;
    exit;

} catch (Exception $e) {
    header('Content-Type: application/json');
    error_log('Failed to download video: ' . $e->getMessage());
    ResponseHandler::error('Failed to download video: ' . $e->getMessage(), 500);
    exit;
}
