<?php
namespace XcVm\Domain\Assistant;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\ApiClient;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\UserCredits;

/** All queries, operations and confirmation descriptions are owned by the server. */
final class ManagementTools {
    public function __construct(private string $scope) {}

    private function admin(string $permission): bool {
        return $this->scope === 'admin' && Authorization::check('adv', $permission);
    }

    private function reseller(string $permission): bool {
        return $this->scope === 'reseller' && Authorization::hasResellerPermissions($permission);
    }

    public function permitted(string $name): bool {
        $lines = $this->admin('users') || $this->reseller('create_line');
        $users = $this->admin('mng_regusers') || $this->reseller('create_sub_resellers');
        return match ($name) {
            'line_details', 'expiring_lines' => $lines,
            'extend_line_days', 'set_line_connections' => $lines && $this->admin('edit_user'),
            'find_streams' => $this->admin('streams'),
            'stream_status' => $this->admin('streams') && $this->admin('servers'),
            'control_stream' => $this->admin('streams') && $this->admin('servers') && $this->admin('edit_stream'),
            'server_health' => $this->admin('servers'),
            'find_resellers' => $users,
            'set_reseller_enabled', 'adjust_reseller_credits' => $users && ($this->admin('edit_reguser') || $this->reseller('create_sub_resellers')),
            'credit_report' => $this->admin('credits_log') || $this->reseller('create_sub_resellers'),
            default => false,
        };
    }

    public function definitions(): array {
        $id = ['type' => 'integer', 'minimum' => 1];
        $query = ['type' => 'string', 'minLength' => 1, 'maxLength' => 80];
        $catalog = [
            'line_details' => ['Read one accessible subscription without its password.', ['line_id' => $id]],
            'expiring_lines' => ['Report up to 20 accessible subscriptions expiring within the next days.', ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90]]],
            'extend_line_days' => ['PROPOSE admin extension of one subscription from its current expiry or now, whichever is later. Does not charge credits. Never use for reseller purchases.', ['line_id' => $id, 'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 730]]],
            'set_line_connections' => ['PROPOSE changing the connection limit of one subscription (admin only).', ['line_id' => $id, 'connections' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]],
            'find_streams' => ['Search live channel names or exact IDs. Returns no source URLs or credentials.', ['query' => $query]],
            'stream_status' => ['Read stored live-channel state on assigned servers. Telemetry is a snapshot, not a playback guarantee.', ['stream_id' => $id]],
            'control_stream' => ['PROPOSE start, stop or restart of a live channel on one assigned server. Confirmation is required; backend acceptance is not proof of playback.', ['stream_id' => $id, 'server_id' => $id, 'operation' => ['type' => 'string', 'enum' => ['start', 'stop', 'restart']]]],
            'server_health' => ['Read up to 50 server health snapshots, without SSH credentials or secrets.', []],
            'find_resellers' => ['Search visible reseller accounts by username or exact ID. Never returns passwords or API keys.', ['query' => $query]],
            'set_reseller_enabled' => ['PROPOSE enabling/disabling a reseller. Cannot target your own account or administrators.', ['user_id' => $id, 'enabled' => ['type' => 'boolean']]],
            'adjust_reseller_credits' => ['PROPOSE a signed credit adjustment. Admins add/remove credits; resellers transfer to/from their managed sub-resellers. Negative means remove from target. Requires a nonzero amount and explicit confirmation.', ['user_id' => $id, 'amount' => ['type' => 'number', 'minimum' => -100000, 'maximum' => 100000], 'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160]]],
            'credit_report' => ['Read totals and up to 20 most recent credit movements within the permitted account scope. Does not expose free-text notes.', ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90]]],
        ];
        $tools = [];
        foreach ($catalog as $name => [$description, $properties]) {
            if ($this->permitted($name)) {
                $tools[] = ['type' => 'function', 'function' => ['name' => $name, 'description' => $description,
                    'parameters' => ['type' => 'object', 'properties' => $properties ?: new \stdClass(), 'required' => array_keys($properties), 'additionalProperties' => false]]];
            }
        }
        return $tools;
    }

    private function validate(string $name, array $args): void {
        $definition = null;
        foreach ($this->definitions() as $tool) { if ($tool['function']['name'] === $name) { $definition = $tool['function']['parameters']; } }
        if (!$definition) { throw new \RuntimeException('FORBIDDEN'); }
        $properties = (array) $definition['properties'];
        if (count($args) !== count($properties) || array_diff(array_keys($args), array_keys($properties))) { throw new \RuntimeException('INVALID_ARGUMENTS'); }
        foreach ($properties as $key => $rule) {
            $value = $args[$key] ?? null;
            $valid = match ($rule['type']) {
                'integer' => is_int($value), 'number' => (is_float($value) || is_int($value)) && is_finite((float) $value),
                'boolean' => is_bool($value), 'string' => is_string($value) && strlen(trim($value)) >= ($rule['minLength'] ?? 1) && strlen($value) <= ($rule['maxLength'] ?? 200),
                default => false,
            };
            if (!$valid || (isset($rule['minimum']) && $value < $rule['minimum']) || (isset($rule['maximum']) && $value > $rule['maximum'])
                || (isset($rule['enum']) && !in_array($value, $rule['enum'], true))) { throw new \RuntimeException('INVALID_ARGUMENTS'); }
        }
    }

    private function owners(): string {
        global $rUserInfo, $rPermissions;
        return implode(',', array_unique(array_map('intval', array_merge([(int) $rUserInfo['id']], (array) ($rPermissions['all_reports'] ?? [])))));
    }

    private function lineScope(): string {
        return '`is_mag` = 0 AND `is_e2` = 0' . ($this->scope === 'reseller' ? ' AND `member_id` IN (' . $this->owners() . ')' : '');
    }

    private function userScope(): string {
        return 'g.`is_reseller` = 1 AND COALESCE(g.`is_admin`,0) = 0' . ($this->scope === 'reseller' ? ' AND u.`owner_id` IN (' . $this->owners() . ')' : '');
    }

    public function rows(string $sql, mixed ...$args): array {
        global $db;
        if (!$db->query($sql, ...$args)) { throw new \RuntimeException('DATABASE_ERROR'); }
        return $db->get_rows();
    }

    private function query(string $sql, mixed ...$args): void {
        global $db;
        if (!$db->query($sql, ...$args)) { throw new \RuntimeException('DATABASE_ERROR'); }
    }

    private function line(int $id, bool $lock = false): array {
        $rows = $this->rows('SELECT `id`,`username`,`member_id`,`enabled`,`admin_enabled`,`exp_date`,`max_connections`,`package_id`,`is_activecode`,`pair_id` FROM `lines` WHERE `id` = ? AND ' . $this->lineScope() . ($lock ? ' FOR UPDATE' : ''), $id);
        if (!$rows) { throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN'); }
        return $rows[0];
    }

    private function user(int $id, bool $lock = false): array {
        global $rUserInfo;
        if ($id === (int) $rUserInfo['id']) { throw new \RuntimeException('FORBIDDEN'); }
        $rows = $this->rows('SELECT u.`id`,u.`username`,u.`owner_id`,u.`member_group_id`,u.`status`,u.`credits` FROM `users` u JOIN `users_groups` g ON g.`group_id`=u.`member_group_id` WHERE u.`id` = ? AND ' . $this->userScope() . ($lock ? ' FOR UPDATE' : ''), $id);
        if (!$rows) { throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN'); }
        return $rows[0];
    }

    private function stream(int $id, int $server, bool $lock = false): array {
        $rows = $this->rows('SELECT s.`id`,s.`stream_display_name`,s.`type`,ss.`server_id`,ss.`parent_id` FROM `streams` s JOIN `streams_types` t ON t.`type_id`=s.`type` JOIN `streams_servers` ss ON ss.`stream_id`=s.`id` WHERE s.`id`=? AND ss.`server_id`=? AND t.`live`=1 AND t.`type_key`=\'live\'' . ($lock ? ' FOR UPDATE' : ''), $id, $server);
        if (!$rows) { throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN'); }
        return $rows[0];
    }

    private function revision(array $row): string {
        return hash('sha256', json_encode(array_map(static fn($value) => $value === null ? null : (string) $value, $row), JSON_THROW_ON_ERROR));
    }

    public function call(string $name, array $args): array {
        $this->validate($name, $args);
        if ($name === 'line_details') { return ['line' => $this->line($args['line_id'])]; }
        if ($name === 'expiring_lines') {
            return ['lines' => $this->rows('SELECT `id`,`username`,`exp_date`,`enabled`,`admin_enabled` FROM `lines` WHERE ' . $this->lineScope() . ' AND `exp_date` >= ? AND `exp_date` <= ? ORDER BY `exp_date`,`id` LIMIT 20', time(), time() + $args['days'] * 86400), 'limit' => 20];
        }
        if ($name === 'find_streams') {
            return ['streams' => $this->rows('SELECT s.`id`,s.`stream_display_name`,s.`type` FROM `streams` s JOIN `streams_types` t ON t.`type_id`=s.`type` WHERE t.`live`=1 AND t.`type_key`=\'live\' AND (s.`id`=? OR LOCATE(?,s.`stream_display_name`)>0) ORDER BY s.`id` LIMIT 10', ctype_digit($args['query']) ? (int) $args['query'] : 0, $args['query'])];
        }
        if ($name === 'stream_status') {
            return ['snapshot' => $this->rows('SELECT s.`id`,s.`stream_display_name`,ss.`server_id`,ss.`stream_status`,ss.`pid`,ss.`stream_started`,ss.`on_demand` FROM `streams` s JOIN `streams_types` t ON t.`type_id`=s.`type` JOIN `streams_servers` ss ON ss.`stream_id`=s.`id` WHERE s.`id`=? AND t.`live`=1 AND t.`type_key`=\'live\' ORDER BY ss.`server_id` LIMIT 50', $args['stream_id'])];
        }
        if ($name === 'server_health') {
            return ['snapshot' => $this->rows('SELECT `id`,`server_name`,`server_type`,`enabled`,`status`,`last_check_ago`,`total_clients` AS `connection_capacity`,`connections` AS `active_connections` FROM `servers` ORDER BY `id` LIMIT 50')];
        }
        if ($name === 'find_resellers') {
            return ['resellers' => $this->rows('SELECT u.`id`,u.`username`,u.`owner_id`,u.`status`,u.`credits` FROM `users` u JOIN `users_groups` g ON g.`group_id`=u.`member_group_id` WHERE ' . $this->userScope() . ' AND (u.`id`=? OR LOCATE(?,u.`username`)>0) ORDER BY u.`id` LIMIT 10', ctype_digit($args['query']) ? (int) $args['query'] : 0, $args['query'])];
        }
        if ($name === 'credit_report') {
            $filter = $this->scope === 'admin' ? '' : ' AND l.`target_id` IN (SELECT u.`id` FROM `users` u JOIN `users_groups` g ON g.`group_id`=u.`member_group_id` WHERE ' . $this->userScope() . ') AND l.`admin_id` IN (' . $this->owners() . ')';
            $since = time() - $args['days'] * 86400;
            return ['totals' => $this->rows('SELECT COUNT(*) AS movements,COALESCE(SUM(l.`amount`),0) AS net_credits FROM `users_credits_logs` l WHERE l.`date`>=?' . $filter, $since)[0],
                'recent' => $this->rows('SELECT l.`target_id`,l.`admin_id`,l.`amount`,l.`date` FROM `users_credits_logs` l WHERE l.`date`>=?' . $filter . ' ORDER BY l.`date` DESC,l.`id` DESC LIMIT 20', $since), 'days' => $args['days']];
        }
        return ['proposal' => $this->propose($name, $args)];
    }

    private function propose(string $name, array $args): array {
        global $rUserInfo;
        $details = [];
        if (in_array($name, ['extend_line_days', 'set_line_connections'], true)) {
            $row = $this->line($args['line_id']);
            if (!empty($row['pair_id']) || $this->rows('SELECT `id` FROM `lines` WHERE `pair_id`=? LIMIT 1', $args['line_id'])) { throw new \RuntimeException('PAIRED_LINE_UNSUPPORTED'); }
            if (ActiveCodeService::lineAwaitsRedemption($row)) { throw new \RuntimeException('ACTIVATION_REQUIRED'); }
            if ($name === 'extend_line_days' && $row['exp_date'] === null) { throw new \RuntimeException('UNLIMITED_EXPIRY'); }
            $target = $row['username'] . ' (#' . $row['id'] . ')';
            $next = $name === 'extend_line_days' ? max(time(), (int) $row['exp_date']) + $args['days'] * 86400 : $args['connections'];
            $details = $name === 'extend_line_days'
                ? ['Expiry UTC / الانتهاء UTC: ' . gmdate('Y-m-d H:i', (int) $row['exp_date']) . ' → ' . gmdate('Y-m-d H:i', $next), 'Days / الأيام: ' . $args['days'], 'Admin edit; no credits charged / تعديل إداري بدون خصم رصيد']
                : ['Connections / الاتصالات: ' . $row['max_connections'] . ' → ' . $next];
        } elseif ($name === 'control_stream') {
            $row = $this->stream($args['stream_id'], $args['server_id']);
            $target = $row['stream_display_name'] . ' (#' . $row['id'] . ')';
            $details = ['Server / السيرفر: #' . $args['server_id'], 'Operation / العملية: ' . $args['operation'], 'May interrupt viewers / قد يؤثر على المشاهدين'];
        } else {
            $row = $this->user($args['user_id']);
            $target = $row['username'] . ' (#' . $row['id'] . ')';
            if ($name === 'adjust_reseller_credits') {
                $amount = round((float) $args['amount'], 4);
                if ($amount == 0 || abs($amount - $args['amount']) > 0.0000001 || ($this->scope === 'reseller' && floor($amount) != $amount)) { throw new \RuntimeException('INVALID_ARGUMENTS'); }
                $actor = $this->rows('SELECT `id`,`credits` FROM `users` WHERE `id`=?', (int) $rUserInfo['id'])[0];
                $next = round((float) $row['credits'] + $amount, 4);
                if ($next < 0 || ($this->scope === 'reseller' && (float) $actor['credits'] - $amount < 0)) { throw new \RuntimeException('INSUFFICIENT_CREDITS'); }
                $details = ['Target credits / رصيد المستهدف: ' . $row['credits'] . ' → ' . number_format($next, 4, '.', ''), 'Change / التغيير: ' . ($amount > 0 ? '+' : '') . $amount,
                    'Reason / السبب: ' . $args['reason']];
                if ($this->scope === 'reseller') { $details[] = 'Your credits / رصيدك: ' . $actor['credits'] . ' → ' . number_format((float) $actor['credits'] - $amount, 4, '.', ''); }
            } else {
                $details = ['Status / الحالة: ' . (int) $row['status'] . ' → ' . (int) $args['enabled']];
            }
        }
        return ['kind' => 'management', 'tool' => $name, 'arguments' => $args, 'revision' => $this->revision($row),
            'next' => $next ?? null, 'actor_balance' => isset($actor) ? (string) $actor['credits'] : null,
            'public' => ['title' => $name, 'target' => $target, 'details' => $details]];
    }

    public function execute(array $proposal): array {
        global $db, $rUserInfo;
        $name = $proposal['tool']; $args = $proposal['arguments'];
        $this->validate($name, $args);
        if (!$db->beginTransaction()) { throw new \RuntimeException('DATABASE_ERROR'); }
        try {
            // For money lock actor and target in stable ID order, before reading either balance.
            if ($name === 'adjust_reseller_credits') {
                $this->rows('SELECT `id` FROM `users` WHERE `id` IN (?,?) ORDER BY `id` FOR UPDATE', (int) $rUserInfo['id'], $args['user_id']);
            }
            $row = match ($name) {
                'extend_line_days', 'set_line_connections' => $this->line($args['line_id'], true),
                'control_stream' => $this->stream($args['stream_id'], $args['server_id'], true),
                default => $this->user($args['user_id'], true),
            };
            if (!hash_equals($proposal['revision'], $this->revision($row))) { throw new \RuntimeException('STALE_PROPOSAL'); }
            if ($name === 'adjust_reseller_credits') {
                $actor = $this->rows('SELECT `credits` FROM `users` WHERE `id`=?', (int) $rUserInfo['id'])[0];
                if ((string) $actor['credits'] !== $proposal['actor_balance']) { throw new \RuntimeException('STALE_PROPOSAL'); }
                $amount = (float) $args['amount'];
                $changed = $this->scope === 'reseller' ? UserCredits::transfer((int) $rUserInfo['id'], $args['user_id'], $amount)
                    : ($amount < 0 ? UserCredits::debit($args['user_id'], -$amount) : UserCredits::credit($args['user_id'], $amount));
                if (!$changed) { throw new \RuntimeException('INSUFFICIENT_CREDITS'); }
                $this->query('INSERT INTO `users_credits_logs` (`target_id`,`admin_id`,`amount`,`date`,`reason`) VALUES (?,?,?,?,?)', $args['user_id'], (int) $rUserInfo['id'], $amount, time(), '[AI] ' . $args['reason']);
            } elseif ($name === 'set_reseller_enabled') {
                $this->query('UPDATE `users` SET `status`=? WHERE `id`=?', (int) $args['enabled'], $args['user_id']);
            } elseif ($name === 'extend_line_days' || $name === 'set_line_connections') {
                // Regenerate the proposal checks without changing the reviewed target expiry.
                $this->propose($name, $args);
                $column = $name === 'extend_line_days' ? 'exp_date' : 'max_connections';
                $this->query('UPDATE `lines` SET `' . $column . '`=? WHERE `id`=?', $proposal['next'], $args['line_id']);
            }
            $this->query("INSERT INTO `users_logs` (`owner`,`type`,`action`,`log_id`,`package_id`,`cost`,`credits_after`,`date`,`deleted_info`) VALUES (?,?,?,?,NULL,0,?,?,?)",
                (int) $rUserInfo['id'], 'assistant', $name, (int) $row['id'], UserCredits::balance((int) $rUserInfo['id']), time(),
                json_encode(['source' => 'ai_assistant', 'arguments' => $args, 'phase' => $name === 'control_stream' ? 'requested' : 'committed']));
            if (!$db->commit()) { throw new \RuntimeException('DATABASE_ERROR'); }
        } catch (\Throwable $error) { $db->rollback(); throw $error; }
        if ($name === 'control_stream') {
            // Same native backend as the existing stream buttons, never a shell command.
            $answer = json_decode((string) ApiClient::request(['action' => 'stream', 'sub' => $args['operation'] === 'restart' ? 'start' : $args['operation'], 'stream_ids' => [$args['stream_id']], 'servers' => [$args['server_id']]]), true);
            $accepted = is_array($answer) && ($answer['result'] ?? false) === true;
            $this->query("INSERT INTO `users_logs` (`owner`,`type`,`action`,`log_id`,`cost`,`credits_after`,`date`,`deleted_info`) VALUES (?, 'assistant', ?, ?, 0, ?, ?, ?)",
                (int) $rUserInfo['id'], $accepted ? 'stream_accepted' : 'stream_refused', $args['stream_id'], UserCredits::balance((int) $rUserInfo['id']), time(), json_encode(['source'=>'ai_assistant','server_id'=>$args['server_id'],'operation'=>$args['operation']]));
            if (!$accepted) { throw new \RuntimeException('CONTROL_REFUSED'); }
            return ['saved' => true, 'signal_pending' => false, 'queued' => true];
        }
        $signal = false;
        if (isset($args['line_id'])) { try { LineService::updateLineSignal($args['line_id']); } catch (\Throwable $error) { $signal = true; } }
        return ['saved' => true, 'signal_pending' => $signal];
    }
}
