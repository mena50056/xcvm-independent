<?php
declare(strict_types=1);

namespace XcVm\Core\Cache;

/** Read-only summary of the serving PHP process. No script paths or reset actions. */
final class PhpCacheStatus {
    public static function snapshot(): array {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $memory = $status['memory_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];
        return [
            'enabled' => (bool) ($status['opcache_enabled'] ?? false),
            'full' => (bool) ($status['cache_full'] ?? false),
            'used_mb' => round(($memory['used_memory'] ?? 0) / 1048576, 1),
            'total_mb' => (int) ini_get('opcache.memory_consumption'),
            'hit_rate' => round((float) ($stats['opcache_hit_rate'] ?? 0), 1),
            'scripts' => (int) ($stats['num_cached_scripts'] ?? 0),
            'revalidate_seconds' => (int) ini_get('opcache.revalidate_freq'),
            'validates_changes' => (bool) ini_get('opcache.validate_timestamps'),
        ];
    }
}
