<?php
/**
 * Response Handler
 * Unified response format
 */
class ResponseHandler {
    public static function success($data = null, $message = 'Success', $statusCode = 200) {
        self::setJsonHeader($statusCode);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }

    public static function error($message = 'Error', $statusCode = 400, $errors = null) {
        self::setJsonHeader($statusCode);
        $response = [
            'success' => false,
            'message' => $message
        ];
        
        if ($errors) {
            $response['errors'] = $errors;
        }

        echo json_encode($response);
        exit;
    }

    public static function created($data = null, $message = 'Created successfully') {
        self::success($data, $message, 201);
    }

    public static function unauthorized($message = 'Unauthorized') {
        self::error($message, 401);
    }

    public static function forbidden($message = 'Forbidden') {
        self::error($message, 403);
    }

    public static function notFound($message = 'Not found') {
        self::error($message, 404);
    }

    public static function serverError($message = 'Server error') {
        self::error($message, 500);
    }

    private static function setJsonHeader($statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
}
