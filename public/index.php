<?php
/**
 * Router - Main entry point
 * Handles URL routing to API endpoints
 */

// CORS handling
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Parse URL
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = preg_replace('~^/app(?=/api(?:/|$))~', '', $uri);
$uri = str_replace('/fml-share-server', '', $uri);
$uri = trim($uri, '/');

// Route requests
$apiDir = dirname(__DIR__) . '/api';
$routes = [
    // Auth routes
    'api/auth/login' => $apiDir . '/auth/login.php',
    'api/auth/register' => $apiDir . '/auth/register.php',
    
    // Video routes
    'api/videos/list' => $apiDir . '/videos/list.php',
    'api/videos/metadata' => $apiDir . '/videos/metadata.php',
    'api/videos/playback' => $apiDir . '/videos/playback.php',
];

// Check if route exists
if (isset($routes[$uri])) {
    require $routes[$uri];
} elseif (preg_match('~^api/videos/download/([a-z0-9]+)$~i', $uri, $matches)) {
    $_GET['videoId'] = $matches[1];
    $_GET['PATH_INFO'] = $uri;
    require $apiDir . '/videos/download.php';
} else {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Route not found',
        'requested_uri' => $uri
    ]);
}
