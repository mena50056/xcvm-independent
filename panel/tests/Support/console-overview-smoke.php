<?php
namespace XcVm\Core\Auth {
    final class Authorization { public static bool $allow = true; public static function check(...$args): bool { return self::$allow; } }
}
namespace XcVm\Core\Config {
    final class DashboardAlerts { public static function options(array $settings): array { return ['latest_enabled' => $settings['latest_enabled'] ?? true]; } }
}
namespace {
    final class ConsoleFixtureLanguage { public static function get(string $key): string { return $key; } }
    function renderOverview(array $settings, bool $allow): string {
        \XcVm\Core\Auth\Authorization::$allow = $allow;
        $language = ConsoleFixtureLanguage::class;
        $rSettings = $settings;
        $rUserInfo = ['username' => '<script>fixture</script>'];
        $rConnectionMap = [['name' => '<img src=x onerror=fixture>', 'count' => 5]];
        $rConnectionCount = 0;
        ob_start();
        require dirname(__DIR__, 2) . '/src/Public/Views/admin/partials/console_overview.php';
        return ob_get_clean();
    }
    $cases = [
        ['save_closed_connection' => true, 'dashboard_map' => true],
        ['save_closed_connection' => false, 'dashboard_map' => true],
        ['save_closed_connection' => true, 'dashboard_map' => false, 'latest_enabled' => false],
    ];
    foreach ($cases as $settings) foreach ([true, false] as $allow) {
        $html = renderOverview($settings, $allow);
        if (str_contains($html, '<script>fixture') || str_contains($html, '<img src=x')) throw new \RuntimeException('Unescaped data');
        if (preg_match('/(?:width|aria-valuenow)="?[^"<>]*(?:INF|NAN)/', $html)) throw new \RuntimeException('Invalid ratio');
        $shouldShowCountries = !empty($settings['save_closed_connection']) && !empty($settings['dashboard_map']);
        if (str_contains($html, '&lt;img') !== $shouldShowCountries) throw new \RuntimeException('Country visibility mismatch');
        $shouldHideAlerts = !$allow || ($settings['latest_enabled'] ?? true) === false;
        if (str_contains($html, 'xc-console-no-alerts') !== $shouldHideAlerts) throw new \RuntimeException('Alert permission mismatch');
    }
    echo "PASS: 6 overview cases (escaping, visibility, alert permissions, zero denominator)\n";
}
