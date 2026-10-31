<?php
declare(strict_types=1);
namespace XcVm\Core\Updates;

/** Read root-worker status; the web account can only queue check/apply requests. */
final class IndependentUpdates {
    public const STATE = '/var/lib/xcvm-independent-updates';

    public static function csrfToken(): string {
        if (session_status() === PHP_SESSION_NONE) \XcVm\Core\Bootstrap\Stage\SessionStage::startSession();
        $_SESSION['independent_update_csrf'] ??= bin2hex(random_bytes(32));
        $token = $_SESSION['independent_update_csrf'];
        session_write_close();
        return $token;
    }

    public static function badge(): array {
        $status = self::status();
        return ($status['state'] ?? '') === 'available' && !empty($status['latest'])
            ? ['version' => ltrim($status['latest'], 'v'), 'url' => $status['url'] ?? ''] : [];
    }

    public static function status(): array {
        $path = self::STATE . '/status.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        return is_array($data) ? $data : ['state' => 'pending', 'current' => '—'];
    }

    public static function queue(string $action, string $version = ''): void {
        if (!in_array($action, ['check', 'apply'], true)) throw new \RuntimeException('Invalid update action');
        if ($action === 'apply' && !preg_match('/^v\d+\.\d+\.\d+(?:-lab\.\d+)?$/D', $version)) {
            throw new \RuntimeException('Invalid update version');
        }
        $dir = self::STATE . '/requests';
        if (!is_dir($dir)) throw new \RuntimeException('Update service is not installed');
        $temp = tempnam($dir, '.request-');
        if ($temp === false) throw new \RuntimeException('Cannot queue update');
        try {
            if (file_put_contents($temp, json_encode(['action' => $action, 'version' => $version], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new \RuntimeException('Cannot write update request');
            }
            chmod($temp, 0600);
            if (!rename($temp, $dir . '/request.json')) throw new \RuntimeException('Cannot submit update request');
        } finally {
            if (is_file($temp)) unlink($temp);
        }
    }
}
