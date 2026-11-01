<?php
// Queue regressions found while testing Movies import on the isolated panel.
require __DIR__ . '/../../src/vendor/autoload.php';

$root = sys_get_temp_dir() . '/horus-movies-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
define('WATCH_TMP_PATH', $root . '/watch/');
function verifyMovieImport($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
$started = [];
try {
    $items = [['type' => 'movie', 'title' => 'Horus QA', 'file' => 'http://127.0.0.1/sample.mp4']];
    verifyMovieImport(\XcVm\Domain\Vod\VodItemImporter::queueBatch($items, function ($file) use (&$started) {
        $started[] = $file;
    }), 'A missing queue directory must be created');
    verifyMovieImport(count($started) === 1 && json_decode(trim(file_get_contents($started[0])), true) === $items[0], 'Queue must preserve the movie payload');
    unlink($started[0]);
    rmdir(WATCH_TMP_PATH);
    file_put_contents(rtrim(WATCH_TMP_PATH, '/'), 'not a directory');
    verifyMovieImport(!\XcVm\Domain\Vod\VodItemImporter::queueBatch($items, function () {
        throw new RuntimeException('A failed queue must not start a worker');
    }), 'Queue creation failure must be reported');
    echo "PASS: missing import queue, payload preservation and queue failure\n";
} finally {
    foreach ($started as $file) if (is_file($file)) unlink($file);
    if (is_dir(WATCH_TMP_PATH)) rmdir(WATCH_TMP_PATH);
    if (is_file(rtrim(WATCH_TMP_PATH, '/'))) unlink(rtrim(WATCH_TMP_PATH, '/'));
    rmdir($root);
}
