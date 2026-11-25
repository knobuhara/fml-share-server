<?php
/**
 * Video Duration Extractor
 * Extracts duration from video files by reading metadata atoms
 */

class VideoDurationExtractor {
    /**
     * Extract duration from video file (seconds)
     * Supports MP4, MOV, M4V formats
     */
    public static function getDurationInSeconds(string $filePath): ?int {
        if (!file_exists($filePath)) {
            throw new RuntimeException('ファイルが見つかりません: ' . $filePath);
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if (in_array($extension, ['mp4', 'mov', 'm4v'], true)) {
            return self::getMP4Duration($filePath);
        }

        // For other formats, return null (duration unknown)
        return null;
    }

    /**
     * Extract duration from MP4/MOV file
     * Reads the mvhd (movie header) atom
     */
    private static function getMP4Duration(string $filePath): ?int {
        try {
            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                return null;
            }

            // Read entire file (or at least first 1MB for large files)
            $fileSize = filesize($filePath);
            $readSize = min($fileSize, 1048576); // Read up to 1MB
            $data = fread($handle, $readSize);
            fclose($handle);

            if ($data === false || strlen($data) < 50) {
                return null;
            }

            // Look for 'mvhd' atom (movie header)
            $mvhdPos = strpos($data, 'mvhd');
            if ($mvhdPos === false) {
                return null;
            }

            // Parse mvhd atom
            // After 'mvhd', we have:
            // version (1 byte) + flags (3 bytes) = 4 bytes
            // creation_time (4 bytes)
            // modification_time (4 bytes)
            // timescale (4 bytes)
            // duration (4 bytes for version 0, 8 bytes for version 1)

            $offset = $mvhdPos + 4; // Skip 'mvhd'
            
            if ($offset + 20 > strlen($data)) {
                return null;
            }

            // Read version
            $version = ord($data[$offset]);
            $offset += 4; // Skip version and flags

            // Skip creation and modification times
            $offset += 8;

            // Read timescale (4 bytes, big-endian)
            $timescaleBytes = substr($data, $offset, 4);
            if (strlen($timescaleBytes) < 4) {
                return null;
            }
            $timescale = unpack('N', $timescaleBytes)[1];
            $offset += 4;

            // Read duration based on version
            if ($version === 1) {
                // Version 1: 8-byte duration
                $durationBytes = substr($data, $offset, 8);
                if (strlen($durationBytes) < 8) {
                    return null;
                }
                // For simplicity, treat as 32-bit (truncate if needed)
                $duration = unpack('N', substr($durationBytes, 4, 4))[1];
            } else {
                // Version 0: 4-byte duration
                $durationBytes = substr($data, $offset, 4);
                if (strlen($durationBytes) < 4) {
                    return null;
                }
                $duration = unpack('N', $durationBytes)[1];
            }

            if ($timescale === 0 || $duration === 0) {
                return null;
            }

            // Convert to seconds (round to nearest second)
            $durationInSeconds = (int)round($duration / $timescale);

            return $durationInSeconds > 0 ? $durationInSeconds : null;
        } catch (Exception $e) {
            error_log('Failed to extract duration: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Convert seconds to minutes (rounded)
     */
    public static function secondsToMinutes(int $seconds): int {
        return (int)round($seconds / 60);
    }
}

