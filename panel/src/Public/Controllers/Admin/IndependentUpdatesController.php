<?php
declare(strict_types=1);
namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Bootstrap\Stage\SessionStage;
use XcVm\Core\Updates\IndependentUpdates;
use XcVm\Core\Localization\Translator;

final class IndependentUpdatesController extends BaseAdminController {
    public function index(): void {
        if (!defined('XCVM_INDEPENDENT_LAB') || !Authorization::isFullAdmin()) {
            http_response_code(403);
            echo Translator::get('error');
            return;
        }
        header('Cache-Control: no-store');
        IndependentUpdates::csrfToken();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['independent_update_csrf'], $_POST['csrf'])) {
                http_response_code(403);
                echo Translator::get('error');
                return;
            }
            try {
                $action = is_string($_POST['update_action'] ?? null) ? $_POST['update_action'] : '';
                $version = is_string($_POST['version'] ?? null) ? $_POST['version'] : '';
                IndependentUpdates::queue($action, $version);
                header('Location: settings?update_queued=1#panel-update', true, 303);
                return;
            } catch (\Throwable $exception) {
                if (session_status() === PHP_SESSION_NONE) SessionStage::startSession();
                $_SESSION['independent_update_error'] = $exception->getMessage();
                session_write_close();
                header('Location: settings?update_failed=1#panel-update', true, 303);
                return;
            }
        }
        header('Location: settings#panel-update', true, 302);
    }
}
