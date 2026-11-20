<?php
/**
 * Signed video playback endpoint
 * GET|HEAD /api/videos/playback
 */
require_once __DIR__ . '/../../lib/ResponseHandler.php';
require_once __DIR__ . '/../../lib/FtpsClient.php';
require_once __DIR__ . '/../../config/Config.php';

function videoContentType(string $fileName): string {
    $types = [
        'avi' => 'video/x-msvideo',
        'flv' => 'video/x-flv',
        'm4v' => 'video/mp4',
        'mkv' => 'video/x-matroska',
        'mov' => 'video/quicktime',
        'mp4' => 'video/mp4',
        'ogv' => 'video/ogg',
        'webm' => 'video/webm',
        'wmv' => 'video/x-ms-wmv',
    ];
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return $types[$extension] ?? 'application/octet-stream';
}

function parseVideoRange(string $header, int $fileSize) {
    if (!preg_match('/\Abytes=(\d*)-(\d*)\z/', trim($header), $matches) ||
        ($matches[1] === '' && $matches[2] === '') || $fileSize <= 0) {
        return false;
    }

    if ($matches[1] === '') {
        $suffixLength = (int)$matches[2];
        if ($suffixLength <= 0) {
            return false;
        }
        $start = max(0, $fileSize - $suffixLength);
        $end = $fileSize - 1;
    } else {
        $start = (int)$matches[1];
        $end = $matches[2] === '' ? $fileSize - 1 : (int)$matches[2];
        if ($start >= $fileSize || $end < $start) {
            return false;
        }
        $end = min($end, $fileSize - 1);
    }

    return [$start, $end];
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    ResponseHandler::error('Method not allowed', 405);
}

$fileName = $_GET['file'] ?? '';
$sizeValue = $_GET['size'] ?? '';
$expiresValue = $_GET['expires'] ?? '';
$signature = $_GET['signature'] ?? '';
$jwtSecret = Config::get('JWT_SECRET');

if (!is_string($fileName) || $fileName === '' || $fileName === '.' || $fileName === '..' ||
    strpbrk($fileName, "/\\\0") !== false || preg_match('/[\x00-\x1F\x7F]/', $fileName) ||
    !is_string($sizeValue) || ($sizeValue !== '' && !ctype_digit($sizeValue)) ||
    !is_string($expiresValue) || !ctype_digit($expiresValue) ||
    !is_string($signature) || !$jwtSecret) {
    ResponseHandler::error('Invalid playback request', 400);
}

$expires = filter_var($expiresValue, FILTER_VALIDATE_INT);
$fileSize = $sizeValue === '' ? null : filter_var($sizeValue, FILTER_VALIDATE_INT);
$allowedExtensions = array_map('trim', explode(',', strtolower(
    Config::get('ALLOWED_EXTENSIONS', 'mp4,mkv,avi,mov,flv,wmv,webm')
)));
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($expires === false || $expires < time() || $fileSize === false ||
    $fileSize !== null && $fileSize < 0 ||
    $extension === '' || !in_array($extension, $allowedExtensions, true)) {
    ResponseHandler::error('Playback link is invalid or expired', 403);
}

$expectedSignature = hash_hmac(
    'sha256',
    $fileName . "\n" . ($sizeValue ?? '') . "\n" . $expiresValue,
    $jwtSecret
);
if (!hash_equals($expectedSignature, $signature)) {
    ResponseHandler::error('Playback link is invalid or expired', 403);
}

$videoPath = Config::get('XSERVER_VIDEO_PATH');
if (!$videoPath) {
    ResponseHandler::error('Video storage is not configured', 500);
}

$localPath = Config::get('XSERVER_VIDEO_LOCAL_PATH', '');
$fullPath = null;
if ($localPath !== '') {
    $localRoot = realpath($localPath);
    $fullPath = $localRoot === false ? false : $localRoot . DIRECTORY_SEPARATOR . $fileName;
    if ($fullPath === false || is_link($fullPath) || !is_file($fullPath)) {
        ResponseHandler::error('Video file not found', 404);
    }
    $localFileSize = filesize($fullPath);
    if ($localFileSize === false) {
        ResponseHandler::error('Unable to read video file', 500);
    }
    $fileSize = $localFileSize;
}

$range = null;
$rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
if ($rangeHeader !== null && $fileSize !== null) {
    $range = parseVideoRange($rangeHeader, $fileSize);
    if ($range === false) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        header('Accept-Ranges: bytes');
        exit;
    }
}

$statusCode = $range === null ? 200 : 206;
$start = $range === null ? 0 : $range[0];
$end = $range === null ? ($fileSize === null ? null : $fileSize - 1) : $range[1];
$contentLength = $fileSize === null ? null : ($end - $start + 1);

http_response_code($statusCode);
header('Content-Type: ' . videoContentType($fileName));
header('Accept-Ranges: ' . ($fileSize === null ? 'none' : 'bytes'));
header('Content-Disposition: inline; filename="' .
    str_replace(['"', '\\'], '_', preg_replace('/[^\x20-\x7E]/', '_', $fileName)) .
    '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if ($contentLength !== null) {
    header('Content-Length: ' . $contentLength);
}
if ($range !== null) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
}

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

set_time_limit(0);
try {
    if ($fullPath !== null) {
        $handle = fopen($fullPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open video file');
        }
        if (fseek($handle, $start) !== 0) {
            fclose($handle);
            throw new RuntimeException('Unable to seek video file');
        }

        $remaining = $contentLength;
        while ($remaining === null || $remaining > 0) {
            $chunk = fread($handle, $remaining === null ? 1024 * 1024 : min(1024 * 1024, $remaining));
            if ($chunk === false) {
                fclose($handle);
                throw new RuntimeException('Unable to read video file');
            }
            if ($chunk === '') {
                break;
            }
            echo $chunk;
            if ($remaining !== null) {
                $remaining -= strlen($chunk);
            }
            flush();
        }
        fclose($handle);
        if ($remaining !== null && $remaining > 0) {
            throw new RuntimeException('Video file ended before the requested range');
        }
        exit;
    }

    $remoteFilePath = rtrim($videoPath, '/') . '/' . $fileName;
    $remoteRange = $range === null ? null : $start . '-' . $end;
    (new FtpsClient())->streamFile($remoteFilePath, $remoteRange);
} catch (RuntimeException $e) {
    error_log('Failed to stream video: ' . $e->getMessage());
    if (headers_sent()) {
        exit;
    }
    ResponseHandler::error($e->getMessage(), 502);
}
