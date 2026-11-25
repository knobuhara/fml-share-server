<?php
/**
 * Video Delete API Endpoint
 * DELETE /api/videos/{id} - Delete a video (admin only)
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();

header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/ResponseHandler.php';
require_once __DIR__ . '/../../lib/FtpsClient.php';
require_once __DIR__ . '/../../api/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../config/Config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    ob_end_clean();
    ResponseHandler::error('Method not allowed', 405);
}

try {
    // Verify authentication
    $user = AuthMiddleware::verify();

    // Check admin role
    if (!isset($user['role']) || $user['role'] !== 'admin') {
        ResponseHandler::error('削除権限がありません。管理者のみが動画を削除できます。', 403);
    }

    // Extract video ID from URL path
    // Expected format: /api/videos/delete.php?id=123 or /api/videos/{id}
    $videoId = $_GET['id'] ?? null;
    
    if (!$videoId || !is_numeric($videoId)) {
        ResponseHandler::error('動画IDが無効です', 400);
    }

    $db = Database::getInstance();

    // Get video information before deletion
    $video = $db->fetch(
        'SELECT id, file_path, title FROM videos WHERE id = ? LIMIT 1',
        [$videoId]
    );

    if (!$video) {
        ResponseHandler::error('動画が見つかりません', 404);
    }

    // Delete from FTP
    $deleted_from_ftp = false;
    $ftp_error = null;
    if ($video['file_path']) {
        try {
            $ftpsClient = new FtpsClient();
            $ftpsClient->deleteFile($video['file_path']);
            $deleted_from_ftp = true;
            error_log('Video deleted from FTP: ' . $video['file_path']);
        } catch (Exception $e) {
            $ftp_error = $e->getMessage();
            error_log('Failed to delete file from FTP: ' . $ftp_error);
            // Continue with DB deletion even if FTP deletion fails
        }
    }

    // Delete from database
    $result = $db->delete('videos', 'id = ?', [$videoId]);

    if (!$result) {
        throw new RuntimeException('データベースからのレコード削除に失敗しました');
    }

    // Also delete local file if configured
    $localPath = Config::get('XSERVER_VIDEO_LOCAL_PATH', '');
    if ($localPath !== '' && is_dir($localPath) && $video['file_path']) {
        // Extract filename from file_path
        $fileName = basename($video['file_path']);
        $localFilePath = rtrim($localPath, '/') . '/' . $fileName;
        if (file_exists($localFilePath)) {
            if (!unlink($localFilePath)) {
                error_log('Failed to delete local file: ' . $localFilePath);
            }
        }
    }

    // Return success response
    ob_end_clean();
    
    $message = '動画が削除されました';
    if (!$deleted_from_ftp && $ftp_error) {
        $message .= ' (FTP削除失敗: ' . $ftp_error . ')';
    }
    
    ResponseHandler::success(
        [
            'id' => $videoId,
            'title' => $video['title'],
            'file_path' => $video['file_path'],
            'deleted_from_ftp' => $deleted_from_ftp,
            'ftp_error' => $ftp_error,
        ],
        $message,
        200
    );

} catch (Exception $e) {
    error_log('Failed to delete video: ' . $e->getMessage());
    ob_end_clean();
    ResponseHandler::error('動画の削除に失敗しました: ' . $e->getMessage(), 500);
}
