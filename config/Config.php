<?php
/**
 * Environment Configuration Loader
 * 
 * Force fresh reads from disk to avoid opache caching issues.
 * Never use static $loaded flag that persists across requests.
 */
class Config {
    private static $env = null;

    public static function load() {
        // Always reload from disk - no caching
        self::$env = [];

        $envFile = __DIR__ . "/../.env";
        if (!file_exists($envFile)) {
            throw new Exception(".env file not found. Please copy .env.example to .env");
        }

        // Force filesystem cache clear
        clearstatcache(true, $envFile);
        
        // Read file fresh each time
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        if ($lines === false) {
            throw new Exception("Failed to read .env file");
        }
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === "#") continue;

            $parts = explode("=", $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                self::$env[$key] = $value;
            }
        }
    }

    public static function get($key, $default = null) {
        // Always read fresh from disk
        self::load();
        return self::$env[$key] ?? $default;
    }

    public static function all() {
        self::load();
        return self::$env ?? [];
    }
}

// Initial load
Config::load();
