<?php
/**
 * Video Thumbnail Generator
 * Extracts the first frame from a video file and saves as JPEG thumbnail
 */

class ThumbnailGenerator {
    private const THUMBNAIL_WIDTH = 320;
    private const THUMBNAIL_HEIGHT = 180;
    private const THUMBNAIL_FORMAT = 'jpg';

    /**
     * Generate thumbnail from video file
     * @param string $videoPath Path to video file
     * @param string $thumbnailDir Directory to save thumbnail
     * @param string $fileName Base filename (without extension)
     * @return string|null Path to generated thumbnail or null if failed
     */
    public static function generate(string $videoPath, string $thumbnailDir, string $fileName): ?string {
        if (!file_exists($videoPath)) {
            throw new RuntimeException('ビデオファイルが見つかりません: ' . $videoPath);
        }

        // Create thumbnail directory if it doesn't exist
        if (!is_dir($thumbnailDir)) {
            if (!@mkdir($thumbnailDir, 0755, true)) {
                throw new RuntimeException('サムネイルディレクトリの作成に失敗しました: ' . $thumbnailDir);
            }
        }

        if (!is_writable($thumbnailDir)) {
            throw new RuntimeException('サムネイルディレクトリに書き込み権限がありません: ' . $thumbnailDir);
        }

        // Generate thumbnail filename
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $thumbnailFileName = $baseName . '.' . self::THUMBNAIL_FORMAT;
        $thumbnailPath = rtrim($thumbnailDir, '/') . '/' . $thumbnailFileName;

        // Check if FFmpeg is available
        if (!self::isFFmpegAvailable()) {
            error_log('FFmpeg is not installed or not in PATH');
            return null;
        }

        // Build FFmpeg command to extract first frame
        // -ss 00:00:00.000 : Start at the beginning
        // -vframes 1 : Extract only 1 frame
        // -vf scale=320:180 : Resize to 320x180
        // -q:v 5 : Quality (lower is better, 1-31)
        $command = sprintf(
            'ffmpeg -ss 00:00:00.000 -i %s -vframes 1 -vf scale=%d:%d -q:v 5 -y %s 2>&1',
            escapeshellarg($videoPath),
            self::THUMBNAIL_WIDTH,
            self::THUMBNAIL_HEIGHT,
            escapeshellarg($thumbnailPath)
        );

        error_log('Running FFmpeg command: ' . $command);

        // Execute FFmpeg command
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            error_log('FFmpeg failed with return code ' . $returnCode);
            error_log('FFmpeg output: ' . implode("\n", $output));
            return null;
        }

        // Verify thumbnail was created
        if (!file_exists($thumbnailPath)) {
            error_log('Thumbnail file was not created: ' . $thumbnailPath);
            return null;
        }

        error_log('Thumbnail generated successfully: ' . $thumbnailPath);
        
        // Return relative path or just the filename
        return $thumbnailFileName;
    }

    /**
     * Check if FFmpeg is available on the system
     */
    private static function isFFmpegAvailable(): bool {
        $output = [];
        $returnCode = 0;
        
        // Try to get FFmpeg version
        exec('ffmpeg -version 2>&1', $output, $returnCode);
        
        return $returnCode === 0 && !empty($output);
    }

    /**
     * Generate thumbnail URL for database storage
     * @param string $fileName Thumbnail filename
     * @param string $videoPath Video path pattern (from XSERVER_VIDEO_PATH)
     * @return string URL path for thumbnail
     */
    public static function getThumbnailUrl(string $fileName, string $videoPath = ''): string {
        // Return path like: /public_html/nobu_contents/thumbnail/video-name.jpg
        $baseVideoPath = rtrim($videoPath, '/');
        return $baseVideoPath . '/thumbnail/' . $fileName;
    }
}
