<?php
declare(strict_types=1);
namespace XcVm\Domain\Line {
    final class ActiveCodeService { public static function lineAwaitsRedemption(array $line): bool { return (bool) $line['is_activecode']; } }
    final class LineService { public static array $signals = []; public static function updateLineSignal(int $id): void { self::$signals[] = $id; } }
}
namespace {
    require dirname(__DIR__) . '/panel/src/Core/Auth/Authorization.php';
    require dirname(__DIR__) . '/panel/src/Domain/Assistant/PanelTools.php';
    require dirname(__DIR__) . '/panel/src/Domain/Assistant/ManagementTools.php';
    require dirname(__DIR__) . '/panel/src/Domain/Assistant/SubscriptionTools.php';
    require dirname(__DIR__) . '/panel/src/Domain/Assistant/CreationTools.php';
    require dirname(__DIR__) . '/panel/src/Domain/Assistant/PlaybackTools.php';
    use XcVm\Domain\Assistant\PanelTools;
    function check(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
    function refused(callable $fn, string $code): void {
        try { $fn(); } catch (RuntimeException $error) { check($error->getMessage() === $code, 'Wrong refusal: ' . $error->getMessage()); return; }
        throw new RuntimeException('Expected refusal: ' . $code);
    }
    $db = new class {
        public PDO $pdo; private PDOStatement $statement; public bool $failAudit = false;
        public function __construct() {
            $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->sqliteCreateFunction('LOCATE', static fn($needle, $haystack) => strpos($haystack, $needle) === false ? 0 : strpos($haystack, $needle) + 1);
            $this->pdo->sqliteCreateFunction('UNIX_TIMESTAMP', static fn() => time());
            $this->pdo->exec('CREATE TABLE lines (id INTEGER PRIMARY KEY, username TEXT, password TEXT, member_id INTEGER, enabled INTEGER, admin_enabled INTEGER DEFAULT 1, is_mag INTEGER DEFAULT 0, is_e2 INTEGER DEFAULT 0, is_activecode INTEGER DEFAULT 0, exp_date INTEGER, max_connections INTEGER DEFAULT 1)');
            $this->pdo->exec('CREATE TABLE users_logs (owner INTEGER,type TEXT,action TEXT,log_id INTEGER,package_id INTEGER,cost INTEGER,credits_after INTEGER,date INTEGER,deleted_info TEXT)');
            $this->pdo->exec("INSERT INTO lines(id,username,password,member_id,enabled) VALUES(1,'own','secret-1',10,1),(2,'child','secret-2',11,1),(3,'foreign','secret-3',20,1)");
        }
        public function query(string $sql, ...$args): bool {
            if ($this->failAudit && str_contains($sql, 'INSERT INTO `users_logs`')) { return false; }
            $this->statement = $this->pdo->prepare(str_replace(' FOR UPDATE', '', $sql)); return $this->statement->execute($args);
        }
        public function get_row(): mixed { return $this->statement->fetch(PDO::FETCH_ASSOC); }
        public function get_rows(): array { return $this->statement->fetchAll(PDO::FETCH_ASSOC); }
        public function beginTransaction(): bool { return $this->pdo->beginTransaction(); }
        public function commit(): bool { return $this->pdo->commit(); }
        public function rollback(): bool { return $this->pdo->inTransaction() && $this->pdo->rollBack(); }
    };
    $rUserInfo = ['id' => 10, 'member_group_id' => 3, 'credits' => 10];
    $rPermissions = ['is_admin' => 0, 'is_reseller' => 1, 'create_line' => 1, 'all_reports' => [11], 'advanced' => []];
    $reseller = new PanelTools('reseller');
    $result = $reseller->call('find_lines', ['query' => 'o']);
    check(count($result['lines']) === 1 && $result['lines'][0]['username'] === 'own', 'Foreign line leaked');
    check(!str_contains(json_encode($result), 'secret'), 'Password leaked');
    check($reseller->call('line_summary', [])['summary']['total'] === 2, 'Summary ownership scope wrong');
    check(count($reseller->call('find_lines', ['query' => 'child'])['lines']) === 1, 'Report tree missing');
    refused(fn() => $reseller->call('set_line_enabled', ['line_id' => 3, 'enabled' => false]), 'NOT_FOUND_OR_FORBIDDEN');
    refused(fn() => $reseller->call('run_sql', ['query' => 'DROP TABLE lines']), 'FORBIDDEN');
    refused(fn() => $reseller->call('set_line_enabled', ['line_id' => '1', 'enabled' => false]), 'INVALID_ARGUMENTS');
    refused(fn() => $reseller->call('set_line_enabled', ['line_id' => 1, 'enabled' => 'false']), 'INVALID_ARGUMENTS');
    refused(fn() => $reseller->call('find_lines', ['query' => 'own', 'owner_id' => 20]), 'INVALID_ARGUMENTS');
    $proposal = $reseller->call('set_line_enabled', ['line_id' => 1, 'enabled' => false])['proposal'];
    check((int) $db->pdo->query('SELECT enabled FROM lines WHERE id=1')->fetchColumn() === 1, 'Proposal executed a mutation');
    $rPermissions['create_line'] = 0;
    refused(fn() => $reseller->execute($proposal), 'FORBIDDEN');
    $rPermissions['create_line'] = 1;
    $db->pdo->exec('UPDATE lines SET member_id=20 WHERE id=1');
    refused(fn() => $reseller->execute($proposal), 'NOT_FOUND_OR_FORBIDDEN');
    $db->pdo->exec('UPDATE lines SET member_id=11 WHERE id=1');
    refused(fn() => $reseller->execute($proposal), 'STALE_PROPOSAL');
    $db->pdo->exec('UPDATE lines SET member_id=10 WHERE id=1');
    $db->failAudit = true;
    refused(fn() => $reseller->execute($proposal), 'DATABASE_ERROR');
    check((int) $db->pdo->query('SELECT enabled FROM lines WHERE id=1')->fetchColumn() === 1, 'Audit failure did not roll back mutation');
    $db->failAudit = false;
    check($reseller->execute($proposal)['saved'], 'Allowed action failed');
    check((int) $db->pdo->query('SELECT enabled FROM lines WHERE id=1')->fetchColumn() === 0, 'Change not persisted');
    check((int) $db->pdo->query('SELECT COUNT(*) FROM users_logs')->fetchColumn() === 1, 'Missing audit');
    check(\XcVm\Domain\Line\LineService::$signals === [1], 'Invalidation not emitted');
    refused(fn() => $reseller->execute($proposal), 'STALE_PROPOSAL');
    $db->pdo->exec('UPDATE lines SET is_activecode=1 WHERE id=1');
    refused(fn() => $reseller->call('set_line_enabled', ['line_id' => 1, 'enabled' => true]), 'ACTIVATION_REQUIRED');
    $rPermissions = ['is_admin' => 1, 'advanced' => ['users'], 'all_reports' => []];
    $admin = new PanelTools('admin');
    check(!in_array('set_line_enabled', array_column(array_column($admin->definitions(), 'function'), 'name'), true), 'Read-only admin received mutation tool');
    refused(fn() => $admin->call('set_line_enabled', ['line_id' => 3, 'enabled' => false]), 'FORBIDDEN');
    check($admin->call('line_summary', [])['summary']['total'] === 3, 'Admin scope incorrect');
    $rPermissions['advanced'] = ['settings'];
    check($admin->definitions() === [], 'Unrelated permission exposed line tools');
    $rPermissions['advanced'] = [];
    check(in_array('control_stream', array_column(array_column($admin->definitions(), 'function'), 'name'), true), 'Full admin missing management tools');
    echo "PASS: scoped reads, no secrets, strict arguments, permission revocation, ownership changes, confirmation proposals, atomic audit, replay, activation guard and admin restrictions\n";
}
