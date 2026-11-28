<?php
declare(strict_types=1);
namespace XcVm\Core\Diagnostics;

use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Domain\Assistant\AssistantClient;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Redis\RedisManager;

/** Local inventory only. No release checks, downloads, inference or remote node probes. */
final class SystemVersions {
    use DatabaseAware;

    public static function collect(): array {
        $root = dirname(rtrim(MAIN_HOME, '/'), 2);
        $facts = self::binaries(MAIN_HOME, CACHE_TMP_PATH . 'horus-versions.json');
        $facts['php'] = PHP_VERSION;
        $os = @parse_ini_file('/etc/os-release');
        $facts['os'] = is_array($os) ? ($os['PRETTY_NAME'] ?? null) : null;
        try {
            $db = self::db();
            if ($db->query('SELECT VERSION() AS version')) $facts['mysql'] = $db->get_row()['version'] ?? null;
        } catch (\Throwable) { $facts['mysql'] = null; }
        try {
            $redis = RedisManager::instance();
            $facts['redis'] = $redis instanceof \Redis ? ($redis->info('server')['redis_version'] ?? null) : null;
        } catch (\Throwable) { $facts['redis'] = null; }
        try { $facts['core'] = ClusterCryptoFactory::status(); }
        catch (\Throwable) { $facts['core'] = ['available' => false]; }
        return self::describe($root, $facts);
    }

    /** Separate release metadata from a fingerprint of the installed Horus interface. */
    public static function describe(string $root, array $facts): array {
        $installed = self::json($root . '/installed.json');
        $release = isset($installed['version']) && preg_match('/^v\d+\.\d+\.\d+(?:-lab\.\d+)?$/D', (string) $installed['version'])
            ? $installed['version'] : null;
        $home = $root . '/panel/src/';
        $interface = [
            'Public/Views/admin/settings.php', 'Public/Views/admin/settings_versions.php',
            'Public/Controllers/Admin/SettingsController.php', 'Core/Localization/lang/ar.ini', 'Core/Localization/lang/en.ini',
            'Core/Diagnostics/SystemVersions.php', 'Public/assets/admin/horus/settings-versions.css',
            'Public/assets/admin/xcvm/console-native.css', 'Public/assets/admin/xcvm/console-native.js',
            'Public/assets/admin/xcvm/assistant.js', 'Public/assets/admin/horus/proxy-monitor.js',
        ];
        $hash = hash_init('sha256');
        $found = 0;
        foreach ($interface as $file) {
            if (is_file($home . $file)) {
                hash_update($hash, $file . "\0" . hash_file('sha256', $home . $file));
                $found++;
            }
        }
        $rows = [self::row('release', 'versions_release', $release, 'versions_release_help', 'package'),
            self::row('interface', 'versions_interface', $found ? substr(hash_final($hash), 0, 12) : null, 'versions_interface_help', 'fingerprint')];
        $core = $facts['core'] ?? [];
        $rows[] = self::row('core', 'Horus Core', !empty($core['available']) ? (($core['ext_version'] ?? '—') . ' · API ' . ($core['api'] ?? '—')) : null, 'versions_core_help', 'shield-check');
        foreach (['php' => ['PHP', 'code'], 'mysql' => ['MySQL / MariaDB', 'database'], 'redis' => ['Redis', 'bolt'], 'os' => ['versions_os', 'device-desktop']] as $id => [$label, $icon]) {
            $rows[] = self::row($id, $label, $facts[$id] ?? null, 'versions_runtime_help', $icon);
        }
        foreach (['nginx' => ['Nginx', 'world'], 'fanout' => ['Horus Streaming', 'broadcast'], 'agent' => ['Horus Agent', 'server'], 'yt-dlp' => ['yt-dlp', 'download']] as $id => [$label, $icon]) {
            $probe = $facts[$id] ?? [];
            $rows[] = self::row($id, $label, $probe['value'] ?? null, 'versions_binary_help', $icon, $probe['state'] ?? 'unknown');
        }
        $geo = self::json($home . 'bin/maxmind/version.json');
        foreach (['country' => ['GeoLite2 Country', 'GeoLite2-Country.mmdb', 'geolite2_version'], 'city' => ['GeoLite2 City', 'GeoLite2-City.mmdb', 'geolite2c_version'], 'isp' => ['GeoIP2 ISP', 'GeoIP2-ISP.mmdb', 'geoisp_version']] as $id => [$label, $file, $key]) {
            $present = is_file($home . 'bin/maxmind/' . $file);
            $rows[] = self::row('geo-' . $id, $label, $present ? ($geo[$key] ?? null) : null, 'versions_geo_help', 'map-pin', $present ? 'unknown' : 'not_installed');
        }
        // The public model identifier comes from the same constant used by inference.
        // Never include assistant.json's endpoint, credentials or other private fields.
        $assistant = self::json($home . 'config/assistant.json');
        $enabled = ($assistant['enabled'] ?? false) === true;
        $rows[] = self::row('assistant', 'Horus AI', $enabled ? AssistantClient::MODEL : null, 'versions_ai_help', 'sparkles', $enabled ? 'configured' : 'disabled');
        return $rows;
    }

    private static function row(string $id, string $label, mixed $value, string $help, string $icon, string $state = 'unknown'): array {
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value !== '' && $state === 'unknown') $state = 'detected';
        return compact('id', 'label', 'value', 'help', 'icon', 'state');
    }

    private static function json(string $path): array {
        if (!is_file($path) || !is_readable($path) || filesize($path) > 65536) return [];
        $value = json_decode((string) @file_get_contents($path), true);
        return is_array($value) ? $value : [];
    }

    /** Cache only binary probes; DB/Redis and loaded PHP are read afresh. */
    public static function binaries(string $home, ?string $cache): array {
        $nginx = is_executable('/usr/sbin/nginx') ? '/usr/sbin/nginx' : $home . 'bin/nginx/sbin/nginx';
        $commands = ['nginx' => [$nginx, '-v'], 'fanout' => [$home . 'bin/xc_fanout/xc_fanout', '-version'],
            'agent' => [$home . 'bin/xc_agent/xc_agent', 'version'], 'yt-dlp' => [$home . 'bin/yt-dlp', '--version']];
        $signature = [];
        foreach ($commands as $id => $argv) {
            clearstatcache(true, $argv[0]);
            $stat = @stat($argv[0]);
            $signature[$id] = [$argv, $stat['ino'] ?? null, $stat['mtime'] ?? null, $stat['ctime'] ?? null, $stat['size'] ?? null, is_executable($argv[0])];
        }
        $key = hash('sha256', json_encode($signature));
        $saved = $cache ? self::json($cache) : [];
        if (($saved['key'] ?? '') === $key && ($saved['at'] ?? 0) >= time() - 300 && is_array($saved['rows'] ?? null)) return $saved['rows'];
        $rows = [];
        foreach ($commands as $id => $argv) {
            if (!is_file($argv[0]) || !is_executable($argv[0])) {
                $rows[$id] = ['value' => null, 'state' => 'not_installed'];
                continue;
            }
            $output = self::probe($argv);
            if ($id === 'nginx') preg_match('~nginx/([\w.+-]+)~', (string) $output, $match);
            else preg_match('/^v?([0-9][A-Za-z0-9.+_-]*)\s*$/m', (string) $output, $match);
            $rows[$id] = ['value' => $match[1] ?? null, 'state' => isset($match[1]) ? 'detected' : 'unknown'];
            unset($match);
        }
        if ($cache && is_dir(dirname($cache)) && is_writable(dirname($cache))) {
            $temp = @tempnam(dirname($cache), '.versions-');
            if ($temp !== false) {
                if (@file_put_contents($temp, json_encode(['key' => $key, 'at' => time(), 'rows' => $rows])) !== false) {
                    @chmod($temp, 0640);
                    @rename($temp, $cache);
                }
                if (is_file($temp)) @unlink($temp);
            }
        }
        return $rows;
    }

    /** Fixed argv, bounded output and deadline: an optional tool cannot hang Settings. */
    private static function probe(array $argv): ?string {
        if (!function_exists('proc_open')) return null;
        $process = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) return null;
        stream_set_blocking($pipes[1], false);
        $output = ''; $deadline = microtime(true) + 1; $code = -1;
        do {
            $output .= (string) fread($pipes[1], 8192);
            $status = proc_get_status($process);
            if (!$status['running']) { $code = $status['exitcode']; break; }
            if (microtime(true) >= $deadline || strlen($output) > 8192) { proc_terminate($process, 9); break; }
            usleep(5000);
        } while (true);
        $output .= (string) stream_get_contents($pipes[1], 8192);
        fclose($pipes[1]); proc_close($process);
        return $code === 0 ? substr($output, 0, 8192) : null;
    }
}
