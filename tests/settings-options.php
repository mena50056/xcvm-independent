<?php
declare(strict_types=1);

namespace XcVm\Domain\Bouquet {
    final class BouquetService {
        public static int $calls = 0;
        public static function getAll(): array {
            self::$calls++;
            return array_map(static fn(int $id): array => ['id' => $id, 'bouquet_name' => 'Bouquet '.$id], range(1, 100));
        }
    }
}

namespace XcVm\Domain\Stream {
    final class CategoryService {
        public static array $calls = [];
        public static function getAllByType(string $type): array {
            self::$calls[] = $type;
            return [['id' => 7, 'category_name' => '</script><b>Arabic & English</b>']];
        }
        public static function getGenreMap(int $type): array {
            return [
                ['genre_id' => 1, 'genre' => 'Action <test>', 'category_id' => 7, 'bouquets' => '[2,3]'],
                ['genre_id' => 2, 'genre' => 'Other', 'category_id' => 999, 'bouquets' => '[999]'],
                ['genre_id' => 3, 'genre' => 'Unused', 'category_id' => 0, 'bouquets' => '[]'],
            ];
        }
    }
}

namespace {
    final class SettingsOptionsLanguage { public static function get(string $key): string { return $key; } }
    function checkOptions(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
    define('MAIN_HOME', dirname(__DIR__).'/panel/src/');
    $language = SettingsOptionsLanguage::class;
    ob_start();
    require MAIN_HOME.'Public/Views/admin/settings_genres.php';
    $html = ob_get_clean();
    checkOptions(\XcVm\Domain\Bouquet\BouquetService::$calls === 1, 'Bouquets must be loaded once for the entire form');
    checkOptions(\XcVm\Domain\Stream\CategoryService::$calls === ['movie', 'series'], 'Category lists must be shared between genres');
    preg_match_all('/<select\b[^>]*name="([^"]+)"[^>]*>(.*?)<\/select>/s', $html, $matches, PREG_SET_ORDER);
    $selected = [];
    foreach ($matches as $match) {
        preg_match_all('/<option\b[^>]*value="([^"]+)"[^>]* selected(?:>|\s)/', $match[2], $values);
        $selected[$match[1]] = $values[1];
    }
    foreach (['genre_' => 'bouquet_', 'genretv_' => 'bouquettv_'] as $category => $bouquet) {
        checkOptions($selected[$category.'1'] === ['7'], 'Saved categories must submit without opening the dropdown');
        checkOptions($selected[$bouquet.'1[]'] === ['2', '3'], 'Multiple saved bouquets must submit without opening the dropdown');
        checkOptions($selected[$category.'2'] === ['999'] && $selected[$bouquet.'2[]'] === ['999'], 'Removed references must not be silently erased');
        checkOptions($selected[$category.'3'] === ['0'] && $selected[$bouquet.'3[]'] === [], 'Unused categories and empty bouquets retain their values');
    }
    checkOptions(substr_count($html, '<option ') < 30, 'Unselected pools must not be duplicated into every dropdown');
    preg_match('/<script type="application\/json" id="horus-settings-options">(.*?)<\/script>/s', $html, $pool);
    $pools = json_decode($pool[1], true, 512, JSON_THROW_ON_ERROR);
    checkOptions(count($pools['bouquets']) === 100 && $pools['movie'][1]['text'] === '</script><b>Arabic & English</b>', 'The shared pool preserves complete option labels');
    checkOptions(!str_contains($pool[1], '</script>') && str_contains($html, 'Action &lt;test&gt;'), 'Labels must remain inert in HTML and embedded JSON');
    echo "Settings shared option pools, saved form values, removed references and escaped labels passed\n";
}
