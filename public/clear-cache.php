<?php
/**
 * Emergency Cache Clear Script
 * Access: https://bears.xsrv.jp/app/clear-cache.php
 * 
 * This script clears PHP opache and forces configuration reload
 */

// Security: Only allow from CLI or localhost
$isCliOrLocalhost = php_sapi_name() === 'cli' || 
                    in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']);

if (!$isCliOrLocalhost) {
    http_response_code(403);
    echo "Forbidden";
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

// Reload config fresh
if (file_exists(__DIR__ . '/../config/Config.php')) {
    require_once __DIR__ . '/../config/Config.php';
    
    echo "DB_HOST: " . Config::get('DB_HOST', 'NOT SET') . "\n";
    echo "DB_PORT: " . Config::get('DB_PORT', 'NOT SET') . "\n";
    echo "DB_NAME: " . Config::get('DB_NAME', 'NOT SET') . "\n";
    echo "DB_USER: " . Config::get('DB_USER', 'NOT SET') . "\n";
    echo "\n✅ Config loaded successfully\n";
} else {
    echo "❌ Config.php not found\n";
}

echo "\n✅ All caches cleared. Restart your browser or wait 1-2 minutes.\n";
?>
