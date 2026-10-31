<?php
declare(strict_types=1);
namespace XcVm\Core\Auth {
    final class Authorization { public static bool $admin = true; public static function isFullAdmin(): bool { return self::$admin; } }
}
namespace XcVm\Core\Bootstrap\Stage {
    final class SessionStage { public static function startSession(): void { session_start(); } }
}
namespace XcVm\Core\Util {
    final class LayoutRenderer { public static function renderFooter(string $scope): void {} }
}
namespace XcVm\Public\Controllers\Admin {
    class BaseAdminController {
        public array $data = [];
        protected function setTitle(string $title): void {}
        protected function render(string $view, array $data = []): void { $this->data = $data; }
    }
}
namespace {
    define('XCVM_INDEPENDENT_LAB', true);
    require dirname(__DIR__) . '/panel/src/Core/Updates/IndependentUpdates.php';
    require dirname(__DIR__) . '/panel/src/Core/Localization/Translator.php';
    $updateLangDir = dirname(__DIR__) . '/panel/src/Core/Localization/lang';
    \XcVm\Core\Localization\Translator::init($updateLangDir);
    require dirname(__DIR__) . '/panel/src/Public/Controllers/Admin/IndependentUpdatesController.php';
    function checkUpdateTest(bool $ok, string $label): void { if (!$ok) throw new \RuntimeException($label); }
    $controller = new \XcVm\Public\Controllers\Admin\IndependentUpdatesController();
    \XcVm\Core\Auth\Authorization::$admin = false;
    ob_start(); $controller->index(); $body = ob_get_clean();
    checkUpdateTest(http_response_code() === 403, 'Restricted admins must be denied');
    \XcVm\Core\Auth\Authorization::$admin = true;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $controller->index();
    $token = $_SESSION['independent_update_csrf'];
    $_SESSION = [];
    session_start();
    checkUpdateTest(($_SESSION['independent_update_csrf'] ?? '') === $token, 'CSRF token must persist after bootstrap closed session');
    session_write_close();
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['csrf' => 'invalid'];
    ob_start(); $controller->index(); $body = ob_get_clean();
    checkUpdateTest(http_response_code() === 403, 'Invalid CSRF must be refused');
    $_POST = ['csrf' => $token, 'update_action' => 'run-shell-command'];
    $controller->index();
    checkUpdateTest($_SESSION['independent_update_error'] === 'Invalid update action', 'Only explicit queue actions may be used');
    $updateStatus = ['state' => 'available', 'current' => 'v0.1.0-lab.4', 'latest' => 'v0.1.0-lab.5', 'notes' => '<script>alert(1)</script>'];
    $updateError = ''; $_GET = [];
    ob_start(); require dirname(__DIR__) . '/panel/src/Public/Views/admin/independent_updates.php'; $html = ob_get_clean();
    checkUpdateTest(!str_contains($html, '<script>alert') && str_contains($html, '&lt;script&gt;'), 'Release notes must be escaped');
    checkUpdateTest(str_contains($html, 'onclick="UpdateServer()"') && str_contains($html, 'v0.1.0-lab.5'), 'Use the original Update Server button');
    foreach (['en', 'ar', 'fr'] as $lang) {
        $_COOKIE['lang'] = $lang;
        \XcVm\Core\Localization\Translator::init($updateLangDir);
        ob_start(); require dirname(__DIR__) . '/panel/src/Public/Views/admin/independent_updates.php'; $html = ob_get_clean();
        checkUpdateTest(str_contains($html, htmlspecialchars(\XcVm\Core\Localization\Translator::get('update_server'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 'Button follows language ' . $lang);
        checkUpdateTest(!str_contains($html, 'dir="rtl"') && !str_contains($html, '<form'), 'Inherit direction and avoid nested settings forms');
    }
    echo "PASS: native update button, en/ar/fr translations, authorization, persistent CSRF and escaped UI\n";
}
