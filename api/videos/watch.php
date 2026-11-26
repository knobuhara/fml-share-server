<?php
/**
 * Video Watch/Play History Recording API Endpoint
 * POST /api/videos/watch - Record video playback
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/ResponseHandler.php';
require_once __DIR__ . '/../../api/middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHandler::error('Method not allowed', 405);
}

try {
    // Verify authentication
    AuthMiddleware::verify();
    
    // Get authenticated user info
    $authData = AuthMiddleware::getAuthData();
    $user = $authData ? json_decode($authData, true) : null;

    if (!$user) {
        ResponseHandler::error('User not authenticated', 401);
    }

    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['videoId']) || !isset($data['videoTitle'])) {
        ResponseHandler::error('videoId and videoTitle are required', 400);
    }

    $videoId = $data['videoId'];
    $videoTitle = $data['videoTitle'];

    // Record watch history for non-admin users
    if ($user['role'] !== 'admin') {
        try {
            $db = Database::getInstance();
            
            // Get the latest active session
            $sessions = $db->query(
                'SELECT id, watched_videos FROM user_sessions 
                 WHERE user_id = ? AND logout_at IS NULL 
                 ORDER BY login_at DESC LIMIT 1',
                [$user['id']]
            );
            
            if ($sessions && count($sessions) > 0) {
                $session = $sessions[0];
                $videos = json_decode($session['watched_videos'], true) ?? [];
                
                // Remove existing entry for this video if it exists
                $videos = array_filter($videos, function($v) use ($videoTitle) {
                    return $v['title'] !== $videoTitle;
                });
                
                // Add new entry with title and timestamp
                $videos[] = [
                    'title' => $videoTitle,
                    'watched_at' => date('Y-m-d H:i:s')
                ];
                
                // Re-index array
                $videos = array_values($videos);
                
                $db->update('user_sessions', 
                    ['watched_videos' => json_encode($videos)],
                    ['id' => $session['id']]
                );
                error_log('✓ Watch history recorded for user ' . $user['id'] . ': ' . $videoTitle);
            }
        } catch (Exception $e) {
            error_log('⚠ Failed to record watch history: ' . $e->getMessage());
            // Continue even if recording fails
        }
    }

    ResponseHandler::success(null, 'Watch history recorded', 200);

} catch (Exception $e) {
    ResponseHandler::error('Watch history error: ' . $e->getMessage(), 500);
}
