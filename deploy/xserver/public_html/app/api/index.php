<?php
ini_set('display_errors', '0');

if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'HTTPS is required']);
    return;
}

$router = dirname(__DIR__, 3) . '/fml-share-api/public/index.php';
if (!is_file($router)) {
    error_log('fml-share: production API router not found outside the document root');
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'API is not configured']);
    return;
}

if (empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

require $router;
