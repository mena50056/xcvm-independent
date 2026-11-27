<?php
declare(strict_types=1);

// Isolate the two connection stores; exercise the real daemon read and orphan guard.
namespace XcVm\Core\Cluster {
    final class NodeRole { public static function refusesConnects(): bool { return false; } }
}
namespace XcVm\Infrastructure\Redis {
    final class RedisManager {
        public static ?object $store = null;
        public static function ensureConnected(): bool { return self::$store !== null; }
        public static function instance(): ?object { return self::$store; }
    }
}
namespace XcVm\Infrastructure\Database {
    final class DatabaseFactory { public static int $reads = 0; public static function connect(): void { self::$reads++; } }
}
namespace XcVm\Domain\Stream {
    final class ConnectionTracker { public static function records(object $store, array $keys): array { return $store->rows; } }
}
namespace {
    $root = dirname(__DIR__) . '/panel/src/';
    foreach (['Core/Config/SettingsManager', 'Cli/CommandInterface', 'Cli/DaemonTrait', 'Cli/Commands/FanoutSyncCommand'] as $file) require $root . $file . '.php';
    use XcVm\Cli\Commands\FanoutSyncCommand;
    use XcVm\Core\Config\SettingsManager;
    use XcVm\Infrastructure\Redis\RedisManager;
    use XcVm\Infrastructure\Database\DatabaseFactory;
    define('SERVER_ID', 1);
    function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
    $row = ['uuid' => 'kept', 'hls_end' => 0];
    $redis = new class($row) {
        public array $rows;
        public function __construct(array $row) { $this->rows = [$row]; }
        public function zRangeByScore($key, $start, $end): array { check($key === 'SERVER#1', 'Wrong server index'); return ['kept']; }
    };
    $db = new class {
        public function query($sql, $server): bool { check($server === 1, 'Wrong SQL server'); return true; }
        public function get_rows(): array { return [['uuid' => 'sql-viewer', 'hls_end' => 0]]; }
    };
    $sync = new FanoutSyncCommand();
    $read = new ReflectionMethod($sync, 'daemonConnections');
    $rSettings = ['redis_handler' => 0];
    SettingsManager::set(['redis_handler' => 1]);
    RedisManager::$store = $redis;
    $rows = $read->invoke($sync);
    check($rows === [$row] && $rSettings['redis_handler'] === 1 && DatabaseFactory::$reads === 0, 'Live MySQL-to-Redis switch read the former store');
    $dropped = [];
    $drop = static function ($uuid) use (&$dropped): void { $dropped[] = $uuid; };
    ob_start();
    $sync->dropOrphans(['kept', 'orphan'], $rows, 1000, $drop);
    $sync->dropOrphans(['kept', 'orphan'], $rows, 1020, $drop);
    ob_end_clean();
    check($dropped === ['orphan'], 'Registered viewer was dropped or real orphan survived');
    RedisManager::$store = null;
    check($read->invoke($sync) === null && DatabaseFactory::$reads === 0, 'Redis outage fell back to stale SQL instead of skipping reconciliation');
    SettingsManager::set(['redis_handler' => 0]);
    check($read->invoke($sync) === [['uuid' => 'sql-viewer', 'hls_end' => 0]] && $rSettings['redis_handler'] === 0 && DatabaseFactory::$reads === 1, 'Live Redis-to-MySQL switch ignored refreshed settings');
    echo "Fanout handler switches, unavailable-store guard and orphan enforcement passed\n";
}
