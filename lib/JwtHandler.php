<?php
/**
 * JWT Handler
 * Generate and verify JWT tokens
 */
class JwtHandler {
    private $secret;
    private $algorithm = 'HS256';
    private $expiresIn = 86400; // 24 hours

    public function __construct() {
        require_once __DIR__ . '/../config/Config.php';
        $this->secret = Config::get('JWT_SECRET');
        
        if (empty($this->secret)) {
            throw new Exception('JWT_SECRET not configured in .env');
        }
    }

    /**
     * Generate JWT token
     */
    public function generate($payload) {
        $header = [
            'alg' => $this->algorithm,
            'typ' => 'JWT'
        ];

        $payload['iat'] = time();
        $payload['exp'] = time() + $this->expiresIn;

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac(
            'sha256',
            "$headerEncoded.$payloadEncoded",
            $this->secret,
            true
        );
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    /**
     * Verify and decode JWT token
     */
    public function verify($token) {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Verify signature
        $signature = hash_hmac(
            'sha256',
            "$headerEncoded.$payloadEncoded",
            $this->secret,
            true
        );
        $expectedSignature = $this->base64UrlDecode($signatureEncoded);

        if ($signature !== $expectedSignature) {
            return false;
        }

        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);

        // Check expiration
        if ($payload['exp'] < time()) {
            return false;
        }

        return $payload;
    }

    private function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 4 - strlen($data) % 4));
    }

    /**
     * Extract token from Authorization header
     */
    public static function extractToken() {
        $headers = getallheaders();
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? array_change_key_case($headers, CASE_LOWER)['authorization'] ?? '';

        if (preg_match('/Bearer\s+(.*)/', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
