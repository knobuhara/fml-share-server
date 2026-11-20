<?php
/**
 * Authentication Middleware
 */
class AuthMiddleware {
    public static function verify() {
        require_once __DIR__ . '/../../lib/JwtHandler.php';
        require_once __DIR__ . '/../../lib/ResponseHandler.php';

        $token = JwtHandler::extractToken();

        if (!$token) {
            ResponseHandler::unauthorized('Token not provided');
        }

        $jwt = new JwtHandler();
        $payload = $jwt->verify($token);

        if (!$payload) {
            ResponseHandler::unauthorized('Invalid or expired token');
        }

        return $payload;
    }

    public static function verifyAdmin($payload) {
        if ($payload['role'] !== 'admin') {
            ResponseHandler::forbidden('Admin access required');
        }
    }
}
