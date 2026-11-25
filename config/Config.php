<?php
/**
 * Environment Configuration Loader
 */
class Config {
    private static $env = [];
    private static $loaded = false;

    public static function load() {
        if (self::$loaded) return;

        $envFile = __DIR__ . '/../.env';
        if (!file_exists($envFile)) {
            throw new Exception('.env file not found. Please copy .env.example to .env');
        }

        // Always read fresh from disk (avoid opache caching issues)
        clearstatcache(true, $envFile);
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#') continue;

            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                self::$env[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get($key, $default = null) {
        if (!self::$loaded) self::load();
        return self::$env[$key] ?? $default;
    }

    public static function all() {
        if (!self::$loaded) self::load();
        return self::$env;
    }
}

Config::load();
