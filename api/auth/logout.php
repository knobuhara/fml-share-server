<?php
/**
 * Logout API Endpoint
 * POST /api/auth/logout
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/ResponseHandler.php';
require_once __DIR__ . '/../../api/middleware/AuthMiddleware.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHandler::error('Method not allowed', 405);
}

try {
    // Verify authentication and get user payload
    $user = AuthMiddleware::verify();

    if (!$user) {
        ResponseHandler::error('User not authenticated', 401);
    }

    // Record logout for non-admin users
    if ($user['role'] !== 'admin') {
        try {
            $db = Database::getInstance();
            
            // Update the latest active session to record logout time
            $db->query(
                'UPDATE user_sessions 
                 SET logout_at = NOW() 
                 WHERE user_id = ? AND logout_at IS NULL 
                 ORDER BY login_at DESC LIMIT 1',
                [$user['id']]
            );
            error_log('✓ Logout session recorded for user: ' . $user['id']);
        } catch (Exception $e) {
            error_log('⚠ Failed to record logout session: ' . $e->getMessage());
            // Continue even if session recording fails
        }
    }

    ResponseHandler::success(null, 'Logout successful', 200);

} catch (Exception $e) {
    ResponseHandler::error('Logout error: ' . $e->getMessage(), 500);
}
