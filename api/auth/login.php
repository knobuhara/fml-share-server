<?php
/**
 * Login API Endpoint
 * POST /api/auth/login
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/JwtHandler.php';
require_once __DIR__ . '/../../lib/ResponseHandler.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHandler::error('Method not allowed', 405);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['email']) || !isset($data['password'])) {
        ResponseHandler::error('Email and password required', 400);
    }

    $email = trim($data['email']);
    $password = $data['password'];

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Invalid email format', 400);
    }

    // Query database
    $db = Database::getInstance();
    $user = $db->fetch(
        'SELECT id, email, password, name, role FROM users WHERE email = ?',
        [$email]
    );

    if (!$user || !password_verify($password, $user['password'])) {
        ResponseHandler::error('Invalid email or password', 401);
    }

    // Generate JWT token
    $jwt = new JwtHandler();
    $token = $jwt->generate([
        'id' => $user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'role' => $user['role']
    ]);

    ResponseHandler::success([
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'role' => $user['role']
        ]
    ], 'Login successful', 200);

} catch (Exception $e) {
    ResponseHandler::error('Authentication error: ' . $e->getMessage(), 500);
}
