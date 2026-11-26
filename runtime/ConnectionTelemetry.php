<?php
declare(strict_types=1);

use XcVm\Core\Cluster\StoredConnections;

/** MAIN's viewer counters, from the configured connection store. Null means unavailable, never zero. */
final class IndependentConnectionTelemetry {
    public static function sample(PDO $database, bool $redisEnabled, ?object $redis): ?array {
        $servers = [];
        foreach ($database->query('SELECT id FROM servers')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $servers[(int) $id] = ['connections' => 0, 'users' => 0];
        }
        $users = $perServer = [];
        $add = static function (array $record, int $count = 1) use (&$servers, &$users, &$perServer): void {
            $server = (int) ($record['server_id'] ?? 0);
            if (!isset($servers[$server])) return;
            $identity = StoredConnections::identity($record);
            $users[$identity] = true;
            foreach (array_unique([$server, (int) ($record['proxy_id'] ?? 0)]) as $id) {
                if (!isset($servers[$id])) continue;
                $servers[$id]['connections'] += $count;
                $perServer[$id][$identity] = true;
            }
        };
        if ($redisEnabled) {
            if ($redis === null) return null;
            try {
                $seen = [];
                for ($offset = 0; ; $offset += 1000) {
                    $keys = $redis->zRange('LIVE', $offset, $offset + 999);
                    if (!is_array($keys)) return null;
                    if ($keys === []) break;
                    $records = $redis->mGet($keys);
                    if (!is_array($records) || count($records) !== count($keys)) return null;
                    foreach ($records as $index => $raw) {
                        $record = is_string($raw) ? @igbinary_unserialize($raw) : null;
                        $uuid = (string) $keys[$index];
                        if (!is_array($record) || ($record['uuid'] ?? '') !== $uuid || !empty($record['hls_end']) || isset($seen[$uuid])) continue;
                        $seen[$uuid] = true;
                        $add($record);
                    }
                    if (count($keys) < 1000) break;
                }
            } catch (Throwable) {
                return null;
            }
        } else {
            // Group in SQL so multiple sessions from one owner do not require loading every row.
            $rows = $database->query('SELECT server_id, proxy_id, user_id, hmac_id, hmac_identifier, COUNT(*) AS connections FROM lines_live WHERE hls_end = 0 GROUP BY server_id, proxy_id, user_id, hmac_id, hmac_identifier');
            while ($record = $rows->fetch(PDO::FETCH_ASSOC)) $add($record, (int) $record['connections']);
        }
        foreach ($servers as $id => &$counts) $counts['users'] = count($perServer[$id] ?? []);
        unset($counts);
        return ['servers' => $servers, 'total_users' => count($users)];
    }
}
