<?php
declare(strict_types=1);
namespace XcVm\Domain\Backup;

/** The independent core's identity and keys must travel together, inside the encrypted bundle only. */
final class IndependentRecovery {
    public static function files(string $state, string $config): array {
        $files = []; $total = 0; $roots = [realpath($state), realpath($config)];
        if (in_array(false, $roots, true)) throw new \RuntimeException('INVALID_RECOVERY_DIRECTORY');
        $add = static function (string $source, string $name, bool $required = false) use (&$files, &$total,$roots): void {
            if (!file_exists($source) && !$required) return;
            $real=realpath($source);
            if ($real === false || !array_filter($roots,static fn($root)=>str_starts_with($real,$root.DIRECTORY_SEPARATOR))) throw new \RuntimeException('INVALID_RECOVERY_PATH');
            if (!is_file($source) || is_link($source) || !is_readable($source) || filesize($source) > 8 * 1024 * 1024) throw new \RuntimeException('RECOVERY_FILE_UNREADABLE');
            $bytes = file_get_contents($source);
            if ($bytes === false || ($total += strlen($bytes)) > 32 * 1024 * 1024) throw new \RuntimeException('RECOVERY_BUNDLE_LIMIT');
            $files[$name] = $bytes;
        };
        if (is_link($state) || is_link($config)) throw new \RuntimeException('INVALID_RECOVERY_DIRECTORY');
        foreach (['master.key', 'identity.json', 'config.enc', 'root.enc', 'panel.enc'] as $name) $add($state . '/' . $name, 'independent/state/' . $name, in_array($name, ['master.key','identity.json','config.enc'], true));
        if (strlen($files['independent/state/master.key']) !== 32) throw new \RuntimeException('INVALID_RECOVERY_MASTER_KEY');
        foreach (['proxy_secret', 'proxy_main_url', 'assistant.json', 'modules.php'] as $name) $add($config . '/' . $name, 'independent/config/' . $name);
        $proxy = $config . '/horus_proxy';
        if (is_link($proxy)) throw new \RuntimeException('INVALID_RECOVERY_DIRECTORY');
        foreach (glob($proxy . '/*.json') ?: [] as $path) {
            // Pending restart operations and live monitor samples are deliberately not replayed on recovery.
            if (preg_match('/^[1-9][0-9]*(?:\.applied)?\.json$/D', basename($path))) $add($path, 'independent/config/horus_proxy/' . basename($path));
        }
        foreach (glob($proxy . '/history/*/*.json') ?: [] as $path) {
            if (preg_match('/^[1-9][0-9]*$/D', basename(dirname($path))) && preg_match('/^[a-zA-Z0-9_-]+\.json$/D', basename($path))) $add($path, 'independent/config/horus_proxy/history/' . basename(dirname($path)) . '/' . basename($path));
        }
        $manifest = ['version'=>1, 'kind'=>'horus-independent-main', 'files'=>array_map(static fn($bytes)=>hash('sha256',$bytes), $files),
            'restore'=>['state'=>'XCVM_LAB_STATE', 'config'=>'MAIN_HOME/config', 'database'=>'Restore the paired SQL export into an isolated database first.', 'tls'=>'Provision TLS and services separately; verify proxy origin trust before switching traffic.']];
        $files['independent/manifest.json'] = json_encode($manifest, JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        return $files;
    }
}
