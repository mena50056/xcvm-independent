<?php
declare(strict_types=1);

/** Public assets only. Unversioned URLs revalidate; filemtime versions cache for a year. */
final class HorusStaticAssets {
    public static function serve(string $directory, string $relative): void {
        if (str_contains(rawurldecode($relative), chr(0))) { http_response_code(404); header('Cache-Control: no-store'); return; }
        $base = realpath($directory);
        $asset = realpath($directory . '/' . rawurldecode($relative));
        $mime = ['css'=>'text/css', 'js'=>'application/javascript', 'json'=>'application/json',
            'svg'=>'image/svg+xml', 'png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg',
            'webp'=>'image/webp', 'avif'=>'image/avif', 'gif'=>'image/gif', 'ico'=>'image/x-icon',
            'woff'=>'font/woff', 'woff2'=>'font/woff2', 'ttf'=>'font/ttf', 'otf'=>'font/otf', 'eot'=>'application/vnd.ms-fontobject'];
        $ext = strtolower(pathinfo($asset ?: '', PATHINFO_EXTENSION));
        if (!$base || !$asset || !str_starts_with($asset, $base . DIRECTORY_SEPARATOR) || !is_file($asset) || !is_readable($asset) || !isset($mime[$ext])) {
            header('Cache-Control: no-store');
            http_response_code(404);
            return;
        }
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            header('Cache-Control: no-store');
            http_response_code(405);
            return;
        }
        $mtime = filemtime($asset);
        $etag = '"' . hash_file('sha256', $asset) . '"';
        $version = $_GET['v'] ?? null;
        $versioned = is_string($version) && $version === (string) $mtime;
        header('Content-Type: ' . $mime[$ext]);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($versioned ? 'public, max-age=31536000, immutable' : 'public, no-cache'));
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

        // If-None-Match takes precedence. Weak validators from gzip are valid for GET/HEAD.
        $matches = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
        $notModified = false;
        if ($matches !== null) {
            foreach (explode(',', $matches) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || preg_replace('~^W/~', '', $candidate) === $etag) {
                    $notModified = true;
                    break;
                }
            }
        } elseif (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            $notModified = $since !== false && $since >= $mtime;
        }
        if ($notModified) {
            http_response_code(304);
            return;
        }
        header('Content-Length: ' . filesize($asset));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($asset);
    }
}
