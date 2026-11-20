<?php
/**
 * Register API Endpoint
 * POST /api/auth/register
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/ResponseHandler.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ResponseHandler::error('Method not allowed', 405);
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    $required = ['email', 'password', 'name'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            ResponseHandler::error("$field is required", 400);
        }
    }

    $email = trim($data['email']);
    $password = $data['password'];
    $name = trim($data['name']);

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Invalid email format', 400);
    }

    // Validate password length
    if (strlen($password) < 8) {
        ResponseHandler::error('Password must be at least 8 characters', 400);
    }

    $db = Database::getInstance();

    // Check if email already exists
    $existing = $db->fetch(
        'SELECT id FROM users WHERE email = ?',
        [$email]
    );

    if ($existing) {
        ResponseHandler::error('Email already registered', 409);
    }

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    // Let the database set the timestamp defaults.
    $userId = $db->insert('users', [
        'email' => $email,
        'password' => $hashedPassword,
        'name' => $name,
        'role' => 'user'
    ]);

    ResponseHandler::created([
        'id' => $userId,
        'email' => $email,
        'name' => $name,
        'role' => 'user'
    ], 'Registration successful');

} catch (Exception $e) {
    ResponseHandler::error('Registration error: ' . $e->getMessage(), 500);
}
