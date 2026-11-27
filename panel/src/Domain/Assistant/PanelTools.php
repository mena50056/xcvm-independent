<?php
namespace XcVm\Domain\Assistant;

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;

/** Narrow, server-owned capabilities. No model-generated SQL or shell commands. */
final class PanelTools {
    public function __construct(private string $scope) {
        if (!in_array($scope, ['admin', 'reseller'], true)) {
            throw new \RuntimeException('FORBIDDEN');
        }
    }

    public function allowed(bool $write = false): bool {
        if ($this->scope === 'admin') {
            return Authorization::check('adv', $write ? 'edit_user' : 'users');
        }
        return Authorization::hasResellerPermissions('create_line');
    }

    private function owners(): array {
        global $rUserInfo, $rPermissions;
        return array_values(array_unique(array_filter(array_map('intval', array_merge(
            [(int) $rUserInfo['id']], (array) ($rPermissions['all_reports'] ?? [])
        )), static fn($id) => $id > 0)));
    }

    private function scopeSql(): string {
        // Match the Lines page, excluding MAG and Enigma subscriptions.
        return '`is_mag` = 0 AND `is_e2` = 0' . ($this->scope === 'reseller'
            ? ' AND `member_id` IN (' . implode(',', $this->owners()) . ')' : '');
    }

    public function definitions(): array {
        $tools = [];
        if ($this->allowed()) {
            $tools[] = $this->definition('line_summary', 'Count visible subscriptions by status. No credentials.', []);
            $tools[] = $this->definition('find_lines', 'Find up to 10 visible subscriptions by username or exact numeric ID. Never guess an ID.', [
                'query' => ['type' => 'string', 'description' => 'Username fragment or numeric subscription ID']
            ]);
        }
        if ($this->allowed() && $this->allowed(true)) {
            $tools[] = $this->definition('set_line_enabled', 'PROPOSE enabling or disabling one subscription. Requires a separate human confirmation; does not execute now. Use the ID returned by find_lines.', [
                'line_id' => ['type' => 'integer', 'minimum' => 1],
                'enabled' => ['type' => 'boolean']
            ]);
        }
        return array_merge($tools, (new ManagementTools($this->scope))->definitions(), (new SubscriptionTools($this->scope))->definitions(), (new CreationTools($this->scope))->definitions(), (new PlaybackTools($this->scope))->definitions());
    }

    private function definition(string $name, string $description, array $properties): array {
        return ['type' => 'function', 'function' => ['name' => $name, 'description' => $description,
            'parameters' => ['type' => 'object', 'properties' => $properties ?: new \stdClass(),
                'required' => array_keys($properties), 'additionalProperties' => false]]];
    }

    public function call(string $name, array $arguments): array {
        if ($name === 'diagnose_playback') { return (new PlaybackTools($this->scope))->call($name, $arguments); }
        global $db;
        if (in_array($name, ['creation_options', 'create_subscription', 'create_subscriptions_bulk', 'create_reseller'], true)) { return (new CreationTools($this->scope))->call($name, $arguments); }
        if (in_array($name, ['list_packages', 'renew_line_package'], true)) { return (new SubscriptionTools($this->scope))->call($name, $arguments); }
        if (!in_array($name, ['line_summary', 'find_lines', 'set_line_enabled'], true)) {
            return (new ManagementTools($this->scope))->call($name, $arguments);
        }
        $names = array_column(array_column($this->definitions(), 'function'), 'name');
        if (!in_array($name, $names, true)) {
            throw new \RuntimeException('FORBIDDEN');
        }
        if ($name === 'line_summary') {
            if ($arguments) { throw new \RuntimeException('INVALID_ARGUMENTS'); }
            $this->query('SELECT COUNT(*) AS total, COALESCE(SUM(`enabled` = 1),0) AS enabled, COALESCE(SUM(`enabled` = 0),0) AS disabled, COALESCE(SUM(`admin_enabled` = 0),0) AS banned, COALESCE(SUM(`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP()),0) AS expired FROM `lines` WHERE ' . $this->scopeSql());
            return ['summary' => $db->get_row()];
        }
        if ($name === 'find_lines') {
            if (array_keys($arguments) !== ['query'] || !is_string($arguments['query']) || strlen(trim($arguments['query'])) < 1 || strlen($arguments['query']) > 80) {
                throw new \RuntimeException('INVALID_ARGUMENTS');
            }
            $query = trim($arguments['query']);
            $this->query('SELECT `id`, `username`, `enabled`, `admin_enabled`, `exp_date`, `max_connections` FROM `lines` WHERE ' . $this->scopeSql()
                . ' AND (`id` = ? OR LOCATE(?, `username`) > 0) ORDER BY `id` LIMIT 10', ctype_digit($query) ? (int) $query : 0, $query);
            return ['lines' => $db->get_rows(), 'limit' => 10];
        }
        if (count($arguments) !== 2 || !isset($arguments['line_id'], $arguments['enabled']) || !is_int($arguments['line_id']) || $arguments['line_id'] < 1 || !is_bool($arguments['enabled'])) {
            throw new \RuntimeException('INVALID_ARGUMENTS');
        }
        $line = $this->line($arguments['line_id']);
        if ($arguments['enabled'] && ActiveCodeService::lineAwaitsRedemption($line)) {
            throw new \RuntimeException('ACTIVATION_REQUIRED');
        }
        return ['proposal' => ['line_id' => (int) $line['id'], 'username' => (string) $line['username'],
            'member_id' => (int) $line['member_id'], 'before' => (int) $line['enabled'], 'enabled' => $arguments['enabled']]];
    }

    private function line(int $id, bool $lock = false): array {
        global $db;
        if (!$this->allowed() || !$this->allowed(true)) { throw new \RuntimeException('FORBIDDEN'); }
        $this->query('SELECT `id`, `username`, `member_id`, `enabled`, `admin_enabled`, `is_activecode`, `exp_date` FROM `lines` WHERE `id` = ? AND ' . $this->scopeSql() . ($lock ? ' FOR UPDATE' : ''), $id);
        $line = $db->get_row();
        if (!$line) { throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN'); }
        return $line;
    }

    public function execute(array $proposal): array {
        global $db, $rUserInfo;
        if (($proposal['kind'] ?? '') === 'creation') { return (new CreationTools($this->scope))->execute($proposal); }
        if (($proposal['kind'] ?? '') === 'subscription') { return (new SubscriptionTools($this->scope))->execute($proposal); }
        if (($proposal['kind'] ?? '') === 'management') { return (new ManagementTools($this->scope))->execute($proposal); }
        if (!$db->beginTransaction()) { throw new \RuntimeException('DATABASE_ERROR'); }
        try {
            // Re-read both permissions and ownership on the confirmation request.
            $line = $this->line((int) $proposal['line_id'], true);
            if ((int) $line['member_id'] !== $proposal['member_id'] || (int) $line['enabled'] !== $proposal['before'] || $line['username'] !== $proposal['username']) {
                error_log('XC assistant confirmation refused: line snapshot changed ' . json_encode([
                    'id' => (int) $proposal['line_id'], 'owner_matches' => (int) $line['member_id'] === $proposal['member_id'],
                    'state_matches' => (int) $line['enabled'] === $proposal['before'], 'name_matches' => $line['username'] === $proposal['username'],
                    'expected_owner_type' => gettype($proposal['member_id']), 'expected_state_type' => gettype($proposal['before'])]));
                throw new \RuntimeException('STALE_PROPOSAL');
            }
            if ($proposal['enabled'] && ActiveCodeService::lineAwaitsRedemption($line)) { throw new \RuntimeException('ACTIVATION_REQUIRED'); }
            $this->query('UPDATE `lines` SET `enabled` = ? WHERE `id` = ? AND ' . $this->scopeSql(), (int) $proposal['enabled'], $proposal['line_id']);
            // Audit deliberately contains no passwords or full line dump.
            $this->query("INSERT INTO `users_logs` (`owner`,`type`,`action`,`log_id`,`package_id`,`cost`,`credits_after`,`date`,`deleted_info`) VALUES (?, 'line', ?, ?, NULL, 0, ?, ?, ?)",
                (int) $rUserInfo['id'], $proposal['enabled'] ? 'enable' : 'disable', $proposal['line_id'],
                $rUserInfo['credits'] ?? 0, time(), json_encode(['source' => 'ai_assistant', 'before' => $proposal['before'], 'enabled' => (int) $proposal['enabled']]));
            if (!$db->commit()) { throw new \RuntimeException('DATABASE_ERROR'); }
        } catch (\Throwable $error) {
            $db->rollback();
            throw $error;
        }
        // Match existing panel controls: publish the line invalidation after commit.
        try { LineService::updateLineSignal($proposal['line_id']); }
        catch (\Throwable $error) { return ['saved' => true, 'signal_pending' => true]; }
        return ['saved' => true, 'signal_pending' => false];
    }

    private function query(string $sql, mixed ...$args): void {
        global $db;
        if (!$db->query($sql, ...$args)) { throw new \RuntimeException('DATABASE_ERROR'); }
    }
}
