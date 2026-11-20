<?php
/**
 * Video List API Endpoint
 * GET /api/videos/list
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/ResponseHandler.php';
require_once __DIR__ . '/../../api/middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../config/Config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ResponseHandler::error('Method not allowed', 405);
}

try {
    // Verify authentication
    $user = AuthMiddleware::verify();

    // Get pagination parameters
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;

    // Validate parameters
    if ($page < 1 || $limit < 1 || $limit > 100) {
        ResponseHandler::error('Invalid pagination parameters', 400);
    }

    $db = Database::getInstance();

    // Get total count
    $countResult = $db->fetch('SELECT COUNT(*) as total FROM videos');
    $total = $countResult['total'];

    // Get paginated videos
    $videos = $db->fetchAll(
        'SELECT id, title, description, file_path, file_size, duration, thumbnail_url, created_at, updated_at 
         FROM videos 
         ORDER BY created_at DESC 
         LIMIT ? OFFSET ?',
        [$limit, $offset]
    );

    // Calculate pagination info
    $totalPages = ceil($total / $limit);

    ResponseHandler::success([
        'videos' => $videos,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => $totalPages
        ]
    ], 'Videos retrieved successfully');

} catch (Exception $e) {
    ResponseHandler::error('Failed to retrieve videos: ' . $e->getMessage(), 500);
}
