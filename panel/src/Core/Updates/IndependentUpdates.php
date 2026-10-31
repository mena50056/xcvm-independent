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
        $nodes=IndependentNodeUpdates::pending();
        if(self::available($status))return ['version'=>ltrim($status['latest'],'v'),'url'=>$status['url']??'','panel_url'=>'settings#panel-update','count'=>1+count($nodes)];
        if($nodes){$item=reset($nodes);return ['version'=>ltrim($item['version'],'v'),'url'=>$item['url']??'','panel_url'=>'servers','count'=>count($nodes)];}
        return [];
    }

    public static function newer(string $latest, string $current): bool {
        $pattern = '/^v\d+\.\d+\.\d+(?:-lab\.\d+)?$/D';
        return preg_match($pattern, $latest) && preg_match($pattern, $current)
            && version_compare(substr($latest, 1), substr($current, 1), '>');
    }

    public static function available(array $status): bool {
        return ($status['state'] ?? '') === 'available'
            && self::newer((string) ($status['latest'] ?? ''), (string) ($status['current'] ?? ''));
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
        if ($action === 'apply') {
            $status = self::status();
            if (!self::available($status) || $version !== $status['latest']) throw new \RuntimeException('No newer compatible update is available');
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
