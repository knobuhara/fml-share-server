<?php
/**
 * Emergency Cache Clear Script
 * Access: https://bears.xsrv.jp/app/clear-cache.php?token=emergency-clear
 */

// Show errors
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Simple token-based security
$requiredToken = getenv('CLEAR_CACHE_TOKEN') ?: 'emergency-clear';
$providedToken = $_GET['token'] ?? '';

if ($providedToken !== $requiredToken) {
    http_response_code(403);
    echo "❌ Unauthorized - invalid token\n";
    exit(1);
}

echo "🧹 Cache Clear Script\n";
echo "=====================\n\n";

// Clear opache cache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ Opache cache cleared\n";
} else {
    echo "⚠️  Opache not enabled or not available\n";
}

// Clear APCu cache if available
if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    echo "✅ APCu cache cleared\n";
} else {
    echo "⚠️  APCu not enabled or not available\n";
}

// Force stat cache clear
clearstatcache(true);
echo "✅ Stat cache cleared\n";

echo "\n📝 Configuration Test\n";
echo "=====================\n";

// Debug: Show current directory and config paths
echo "Current Dir (__DIR__): " . __DIR__ . "\n";
$configPath = __DIR__ . '/../../fml-share-api/config/Config.php';
echo "Looking for config at: $configPath\n";
echo "File exists: " . (file_exists($configPath) ? 'YES' : 'NO') . "\n\n";

// Try to load config
if (file_exists($configPath)) {
    try {
        require_once $configPath;
        
        echo "DB_HOST: " . Config::get('DB_HOST', 'NOT SET') . "\n";
        echo "DB_PORT: " . Config::get('DB_PORT', 'NOT SET') . "\n";
        echo "DB_NAME: " . Config::get('DB_NAME', 'NOT SET') . "\n";
        echo "DB_USER: " . Config::get('DB_USER', 'NOT SET') . "\n";
        echo "\n✅ Config loaded successfully\n";
    } catch (Exception $e) {
        echo "❌ Error loading config: " . $e->getMessage() . "\n";
    }
} else {
    echo "❌ Config.php not found\n";
    echo "\nSearching for .env files...\n";
    // List all .env files in the system
    $files = glob(__DIR__ . '/../../**/.env', GLOB_BRACE);
    foreach ($files as $file) {
        echo "  - $file\n";
    }
}

echo "\n✅ Cache clear script completed.\n";
?>
