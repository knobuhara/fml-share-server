<?php
/**
 * Video Upload API Endpoint
 * POST /api/videos/upload - Upload a video file
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/ResponseHandler.php';
require_once __DIR__ . '/../../lib/FtpsClient.php';
require_once __DIR__ . '/../../lib/VideoDurationExtractor.php';
require_once __DIR__ . '/../../api/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../config/Config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHandler::error('Method not allowed', 405);
}

try {
    // Verify authentication
    AuthMiddleware::verify();

    // Check if file was uploaded
    if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        $error = $_FILES['video']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'ファイルが大きすぎます（php.ini の制限）',
            UPLOAD_ERR_FORM_SIZE => 'ファイルが大きすぎます（フォーム制限）',
            UPLOAD_ERR_PARTIAL => 'ファイルが部分的にしかアップロードされません',
            UPLOAD_ERR_NO_FILE => 'ファイルが選択されていません',
            UPLOAD_ERR_NO_TMP_DIR => 'テンポラリディレクトリがありません',
            UPLOAD_ERR_CANT_WRITE => 'ファイルの書き込みに失敗しました',
            UPLOAD_ERR_EXTENSION => 'PHP 拡張によってアップロードが停止されました',
        ];
        ResponseHandler::error($errorMessages[$error] ?? 'アップロード中にエラーが発生しました', 400);
    }

    $file = $_FILES['video'];
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? null;

    // Validate title
    if (!$title || strlen($title) > 255) {
        ResponseHandler::error('タイトルは1～255文字で入力してください', 400);
    }

    // Validate file size
    $maxFileSize = Config::get('MAX_FILE_SIZE', 5368709120); // 5GB default
    if ($file['size'] > $maxFileSize) {
        ResponseHandler::error('ファイルサイズが制限を超えています', 400);
    }

    // Validate file extension
    $fileName = basename($file['name']);
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExtensions = array_map('trim', explode(',', strtolower(
        Config::get('ALLOWED_EXTENSIONS', 'mp4,mkv,avi,mov,flv,wmv,webm')
    )));

    if (!$extension || !in_array($extension, $allowedExtensions, true)) {
        ResponseHandler::error('許可されていないファイル形式です', 400);
    }

    // Check for duplicate file names
    $db = Database::getInstance();
    $existing = $db->fetch(
        'SELECT id FROM videos WHERE file_path LIKE ? LIMIT 1',
        ['%' . preg_replace('/[%_\\\\]/', '\\\\$0', $fileName)]
    );

    if ($existing) {
        ResponseHandler::error('同じ名前のファイルが既に存在します', 409);
    }

    // Read file content
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('不正なアップロードファイルです');
    }

    $fileContent = file_get_contents($file['tmp_name']);
    if ($fileContent === false) {
        throw new RuntimeException('ファイルの読み込みに失敗しました');
    }

    // Upload to FTPS
    $videoPath = Config::get('XSERVER_VIDEO_PATH');
    if (!$videoPath) {
        ResponseHandler::error('動画保存パスが設定されていません', 500);
    }

    $ftpsFilePath = rtrim($videoPath, '/') . '/' . $fileName;

    try {
        $ftpsClient = new FtpsClient();
        $ftpsClient->uploadFile($ftpsFilePath, $fileContent, $file['size']);
    } catch (Exception $e) {
        error_log('FTPS upload failed: ' . $e->getMessage());
        ResponseHandler::error($e->getMessage(), 502);
    }

    // Save metadata to database
    $dbVideoId = null;

    try {
        // Extract duration from uploaded file
        $durationInSeconds = null;
        $tmpFile = $file['tmp_name'];
        
        error_log('Attempting to extract duration from: ' . $tmpFile);
        error_log('Temp file exists: ' . (file_exists($tmpFile) ? 'YES' : 'NO'));
        error_log('Temp file size: ' . (file_exists($tmpFile) ? filesize($tmpFile) : 'N/A'));
        
        try {
            $durationInSeconds = VideoDurationExtractor::getDurationInSeconds($tmpFile);
            if ($durationInSeconds !== null) {
                error_log('✓ Duration extracted: ' . $durationInSeconds . ' seconds from ' . $fileName);
            } else {
                error_log('⚠ Duration extraction returned null for ' . $fileName);
            }
        } catch (Exception $e) {
            error_log('✗ Failed to extract duration from ' . $fileName . ': ' . $e->getMessage());
        }

        // Convert seconds to minutes (rounded)
        $durationInMinutes = $durationInSeconds !== null 
            ? VideoDurationExtractor::secondsToMinutes($durationInSeconds)
            : null;
        
        error_log('Final duration value for DB: ' . ($durationInMinutes !== null ? $durationInMinutes : 'NULL'));

        $dbVideoId = $db->insert('videos', [
            'title' => $title,
            'description' => $description ?: null,
            'file_path' => $ftpsFilePath,
            'file_size' => $file['size'],
            'duration' => $durationInMinutes,
            'thumbnail_url' => null,
        ]);

        if (!$dbVideoId) {
            throw new RuntimeException('ビデオレコードの作成に失敗しました');
        }
    } catch (Exception $e) {
        error_log('Database insert failed: ' . $e->getMessage());
        // DB insert failed, but file was uploaded to FTPS
        // Return error but file is uploaded
        ResponseHandler::error('ファイルはアップロードされましたがメタデータの保存に失敗しました', 500);
    }

    // Also save to local path if configured
    $localPath = Config::get('XSERVER_VIDEO_LOCAL_PATH', '');
    if ($localPath !== '' && is_dir($localPath)) {
        $localFilePath = rtrim($localPath, '/') . '/' . $fileName;
        if (!file_put_contents($localFilePath, $fileContent)) {
            error_log('Local file save failed: ' . $localFilePath);
        }
    }

    // Return success response
    $expires = time() + 86400;
    $jwtSecret = Config::get('JWT_SECRET');
    $signature = hash_hmac(
        'sha256',
        $fileName . "\n" . $file['size'] . "\n" . $expires,
        $jwtSecret
    );

    ResponseHandler::success([
        'id' => $dbVideoId,
        'title' => $title,
        'description' => $description,
        'file_name' => $fileName,
        'file_path' => $ftpsFilePath,
        'file_size' => $file['size'],
        'created_at' => gmdate(DateTimeInterface::ATOM),
        'playback_url' => 'api/videos/playback?file=' . rawurlencode($fileName) .
            '&size=' . $file['size'] .
            '&expires=' . $expires .
            '&signature=' . $signature,
    ], 'ファイルがアップロードされました', 201);

} catch (Exception $e) {
    error_log('Failed to upload video: ' . $e->getMessage());
    ResponseHandler::error('動画ファイルのアップロードに失敗しました', 500);
}
