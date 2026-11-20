<?php
require_once __DIR__ . '/../config/Config.php';

class FtpsClient {
    public function listFiles(string $path): array {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP cURL 拡張が有効になっていません。');
        }

        $host = Config::get('XSERVER_HOST');
        $port = filter_var(Config::get('XSERVER_PORT', '21'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $username = Config::get('XSERVER_USERNAME');
        $password = Config::get('XSERVER_PASSWORD');
        if (!$host || !preg_match('/\A[a-z0-9.-]+\z/i', $host) ||
            $port === false || !$username || $password === null || $password === '') {
            throw new RuntimeException('FTPS 接続設定を確認してください。');
        }

        $segments = array_map('rawurlencode', explode('/', trim($path, '/')));
        $directory = implode('/', $segments);
        // An encoded leading slash makes libcurl use an absolute FTP path.
        $remote = $path[0] === '/' ? '%2F' . $directory : $directory;
        $url = "ftp://$host:$port/" . ($remote === '' ? '' : "$remote/");
        $curl = curl_init($url);
        $options = [
            CURLOPT_USERNAME => $username,
            CURLOPT_PASSWORD => $password,
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CUSTOMREQUEST => 'MLSD',
            CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_SINGLECWD,
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_PROXY => '',
        ];
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($curl, $options);
        $listing = curl_exec($curl);
        if ($listing === false) {
            $code = curl_errno($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            error_log("Xserver FTPS MLSD failed: cURL=$code FTP=$status");
            if ($status === 421) {
                throw new RuntimeException('FTPS サーバーが接続を閉じました（FTP 421）。少し待って再試行してください。');
            }
            if ($status === 530) {
                throw new RuntimeException('FTPS にログインできません。FTP アカウント設定を確認してください。');
            }
            if ($status === 550) {
                throw new RuntimeException('FTPS の動画フォルダを読み取れません。フォルダのパスと権限を確認してください。');
            }
            if ($code === 60) {
                throw new RuntimeException('FTPS の証明書を検証できません。FTP ホスト名と CA 設定を確認してください。');
            }
            if ($code === 28) {
                throw new RuntimeException("FTPS がタイムアウトしました（cURL $code / FTP $status）。");
            }
            throw new RuntimeException("FTPS から動画一覧を取得できません（cURL $code / FTP $status）。");
        }

        return self::parseListing($listing);
    }

    private static function parseListing(string $listing): array {
        $files = [];
        foreach (preg_split('/\r?\n/', $listing) as $line) {
            if ($line === '') {
                continue;
            }
            if (!preg_match('/\A((?:[^;=\s]+=[^;]*;)+) (.+)\z/D', $line, $entry)) {
                throw new RuntimeException('FTPS の動画フォルダ一覧を解析できません。');
            }

            $facts = [];
            foreach (explode(';', rtrim($entry[1], ';')) as $fact) {
                [$key, $value] = explode('=', $fact, 2);
                $facts[strtolower($key)] = $value;
            }
            $name = $entry[2];
            if (strtolower($facts['type'] ?? '') !== 'file' ||
                $name === '.' || $name === '..' || strpbrk($name, "/\\\0") !== false) {
                continue;
            }

            $modified = null;
            if (isset($facts['modify']) && preg_match('/\A(\d{14})(?:\.\d+)?\z/', $facts['modify'], $timestamp)) {
                $date = DateTimeImmutable::createFromFormat('!YmdHis', $timestamp[1], new DateTimeZone('UTC'));
                $modified = $date ? $date->format(DateTimeInterface::ATOM) : null;
            }
            $files[] = [
                'name' => $name,
                'size' => isset($facts['size']) && ctype_digit($facts['size']) ? (int)$facts['size'] : null,
                'modified' => $modified,
            ];
        }

        return $files;
    }

    /**
     * Download file stream from FTPS
     */
    public function downloadFile(string $filePath): string {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP cURL 拡張が有効になっていません。');
        }

        $host = Config::get('XSERVER_HOST');
        $port = filter_var(Config::get('XSERVER_PORT', '21'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $username = Config::get('XSERVER_USERNAME');
        $password = Config::get('XSERVER_PASSWORD');
        
        if (!$host || !preg_match('/\A[a-z0-9.-]+\z/i', $host) ||
            $port === false || !$username || $password === null || $password === '') {
            throw new RuntimeException('FTPS 接続設定を確認してください。');
        }

        $segments = array_map('rawurlencode', explode('/', trim($filePath, '/')));
        $file = implode('/', $segments);
        $remote = $filePath[0] === '/' ? '%2F' . $file : $file;
        $url = "ftp://$host:$port/$remote";
        
        $curl = curl_init($url);
        $options = [
            CURLOPT_USERNAME => $username,
            CURLOPT_PASSWORD => $password,
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_SINGLECWD,
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 300, // 5 minutes for file download
            CURLOPT_PROXY => '',
        ];
        
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        
        curl_setopt_array($curl, $options);
        $data = curl_exec($curl);
        
        if ($data === false) {
            $code = curl_errno($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            error_log("Xserver FTPS download failed: cURL=$code FTP=$status");
            
            if ($status === 530) {
                throw new RuntimeException('FTPS にログインできません。');
            }
            if ($status === 550) {
                throw new RuntimeException('動画ファイルが見つかりません。');
            }
            if ($code === 28) {
                throw new RuntimeException('ファイルダウンロードがタイムアウトしました。');
            }
            throw new RuntimeException("ファイルダウンロードに失敗しました（cURL $code / FTP $status）。");
        }
        
        curl_close($curl);
        return $data;
    }

    /**
     * Stream a remote video file, optionally requesting a byte range.
     */
    public function streamFile(string $filePath, ?string $range = null): void {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP cURL 拡張が有効になっていません。');
        }

        $host = Config::get('XSERVER_HOST');
        $port = filter_var(Config::get('XSERVER_PORT', '21'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        $username = Config::get('XSERVER_USERNAME');
        $password = Config::get('XSERVER_PASSWORD');
        if (!$host || !preg_match('/\A[a-z0-9.-]+\z/i', $host) ||
            $port === false || !$username || $password === null || $password === '') {
            throw new RuntimeException('FTPS 接続設定を確認してください。');
        }

        $segments = array_map('rawurlencode', explode('/', trim($filePath, '/')));
        $file = implode('/', $segments);
        $remote = $filePath[0] === '/' ? '%2F' . $file : $file;
        $curl = curl_init("ftp://$host:$port/$remote");
        $options = [
            CURLOPT_USERNAME => $username,
            CURLOPT_PASSWORD => $password,
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FTP_FILEMETHOD => CURLFTPMETHOD_SINGLECWD,
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function($curl, string $chunk): int {
                $length = strlen($chunk);
                echo $chunk;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                return $length;
            },
        ];
        if ($range !== null) {
            $options[CURLOPT_RANGE] = $range;
        }
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }

        curl_setopt_array($curl, $options);
        $result = curl_exec($curl);
        if ($result === false) {
            $code = curl_errno($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            error_log("Xserver FTPS playback failed: cURL=$code FTP=$status");
            if ($status === 530) {
                throw new RuntimeException('FTPS にログインできません。FTP アカウント設定を確認してください。');
            }
            if ($status === 550) {
                throw new RuntimeException('FTPS の動画ファイルを読み取れません。ファイルのパスと権限を確認してください。');
            }
            if ($code === 60) {
                throw new RuntimeException('FTPS の証明書を検証できません。FTP ホスト名と CA 設定を確認してください。');
            }
            if ($code === 28) {
                throw new RuntimeException('FTPS からの動画取得がタイムアウトしました。');
            }
            throw new RuntimeException("FTPS から動画を取得できません（cURL $code / FTP $status）。");
        }

        curl_close($curl);
    }
}
