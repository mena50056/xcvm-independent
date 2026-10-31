<?php
declare(strict_types=1);
namespace XcVm\Core\Updates;

final class IndependentNodeUpdates {
    private static function path(): string {
        $state = getenv('XCVM_LAB_STATE');
        if (!$state) throw new \RuntimeException('Independent MAIN state is required');
        return rtrim($state, '/') . '/independent-node-versions.json';
    }

    public static function records(): array {
        $path = self::path();
        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    public static function record(int $id, string $version): void {
        if ($id <= 0 || !IndependentUpdates::newer($version, 'v0.0.0-lab.0')) throw new \RuntimeException('Invalid node version');
        $path = self::path(); $lock = fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Node version lock failed');
        try {
            $data = self::records(); $data[$id] = ['version' => $version, 'verified_at' => time()];
            $temp = tempnam(dirname($path), '.node-versions-');
            if ($temp === false) throw new \RuntimeException('Node version write failed');
            try {
                if (file_put_contents($temp, json_encode($data, JSON_THROW_ON_ERROR)) === false || !rename($temp, $path)) throw new \RuntimeException('Node version write failed');
            } finally { if (is_file($temp)) unlink($temp); }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function catalog(): array {
        $path = IndependentUpdates::STATE . '/node-releases.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        return ($data['state'] ?? '') === 'ready' ? ($data['releases'] ?? []) : [];
    }

    public static function candidate(string $current, ?array $releases = null): ?array {
        $best = null;
        foreach ($releases ?? self::catalog() as $item) {
            if (!in_array($current, $item['from_versions'] ?? [], true) || !IndependentUpdates::newer((string) ($item['version'] ?? ''), $current)) continue;
            if ($best === null || IndependentUpdates::newer($item['version'], $best['version'])) $best = $item;
        }
        return $best;
    }

    public static function pending(): array {
        global $rServers;
        $releases = self::catalog(); $result = [];
        foreach(self::records() as $id=>$record){
            if(is_array($rServers) && (!isset($rServers[(int)$id]) || !empty($rServers[(int)$id]['is_main'])))continue;
            $item=self::candidate((string)($record['version']??''),$releases);
            if($item)$result[(int)$id]=$item;
        }
        return $result;
    }
}
