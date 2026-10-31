<?php
final class IndependentLabNetwork {
    public static function databasesLocal(): bool {
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $file) {
            $lines = @file($file);
            if ($lines === false) return false;
            foreach (array_slice($lines, 1) as $line) {
                $v = preg_split('/\s+/', trim($line));
                if (($v[3] ?? '') !== '0A') continue;
                [$address, $port] = explode(':', $v[1]);
                if (!in_array(hexdec($port), [3306, 6379], true)) continue;
                if (strlen($address) === 8) {
                    if (substr($address, -2) !== '7F') return false;
                } elseif ($address !== '00000000000000000000000001000000') return false;
            }
        }
        return true;
    }
}
