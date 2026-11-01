<?php
require __DIR__ . '/../../src/vendor/autoload.php';
use XcVm\Core\Validation\InputValidator;
use XcVm\Core\Util\Topbar;
function seriesCheck($condition, $message) { if (!$condition) throw new RuntimeException($message); }
$single = ['series' => '3', 'season_num' => '1', 'episode' => '1', 'stream_display_name' => 'Horus episode', 'stream_source' => 'http://127.0.0.1/test.mp4'];
seriesCheck(InputValidator::validate('processEpisode', $single), 'Valid episode');
seriesCheck(InputValidator::validate('processEpisode', array_replace($single, ['season_num' => '0'])), 'Season zero supports specials');
foreach (['', '-1', '1.5', '1e2', []] as $value) {
    seriesCheck(!InputValidator::validate('processEpisode', array_replace($single, ['season_num' => $value])), 'Invalid season');
}
foreach (['', '0', '-1', '1.5'] as $value) {
    seriesCheck(!InputValidator::validate('processEpisode', array_replace($single, ['episode' => $value])), 'Invalid episode number');
}
foreach (['stream_display_name', 'stream_source'] as $field) seriesCheck(!InputValidator::validate('processEpisode', array_replace($single, [$field => ' '])), 'Blank name/source');
$multi = ['series' => '3', 'season_num' => '1', 'multi' => '', 'server' => '1', 'season_folder' => '/mnt/series', 'episode_1_name' => 'S01E01.mp4', 'episode_1_num' => '1', 'episode_2_name' => 'S01E02.mp4', 'episode_2_num' => '2'];
seriesCheck(InputValidator::validate('processEpisode', $multi), 'Valid season import');
seriesCheck(!InputValidator::validate('processEpisode', array_replace($multi, ['episode_2_num' => '1'])), 'Duplicate episode numbers');
seriesCheck(!InputValidator::validate('processEpisode', array_replace($multi, ['episode_1_name' => '../escape.mp4'])), 'Names remain inside the chosen folder');
$empty = $multi;foreach (['episode_1_name', 'episode_1_num', 'episode_2_name', 'episode_2_num'] as $key) unset($empty[$key]);
seriesCheck(!InputValidator::validate('processEpisode', $empty), 'Empty import must not report success');
$bar = Topbar::config(['rID' => 16, 'rSID' => 3, 'rStreamType' => 5])['stream_view'];
seriesCheck($bar['Edit Episode'] === ['episode?id=16&sid=3', 'edit_episode'], 'Episode edit uses its form and permission');
seriesCheck($bar['View Episodes'][0] === 'episodes?series=3', 'Episode list retains series');
seriesCheck(isset(Topbar::config(['rID' => 16])['stream_view']['Edit Stream']), 'Live-stream actions preserved');
echo "PASS: episode input validation, season imports and episode toolbar routing\n";
