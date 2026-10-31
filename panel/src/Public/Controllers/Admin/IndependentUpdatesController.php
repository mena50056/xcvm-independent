<?php
declare(strict_types=1);
namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Bootstrap\Stage\SessionStage;
use XcVm\Core\Updates\IndependentUpdates;

final class IndependentUpdatesController extends BaseAdminController {
    public function index(): void {
        if (!defined('XCVM_INDEPENDENT_LAB') || !Authorization::isFullAdmin()) {
            http_response_code(403);
            echo 'Administrator access required';
            return;
        }
        header('Cache-Control: no-store');
        // AdminScopeBootstrap closes the session before dispatching controllers.
        if (session_status() === PHP_SESSION_NONE) SessionStage::startSession();
        $_SESSION['independent_update_csrf'] ??= bin2hex(random_bytes(32));
        session_write_close();
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['independent_update_csrf'], $_POST['csrf'])) {
                http_response_code(403);
                echo 'Invalid request token';
                return;
            }
            try {
                $action = is_string($_POST['update_action'] ?? null) ? $_POST['update_action'] : '';
                $version = is_string($_POST['version'] ?? null) ? $_POST['version'] : '';
                IndependentUpdates::queue($action, $version);
                header('Location: independent_updates?queued=1', true, 303);
                return;
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }
        $this->setTitle('GitHub Updates');
        $this->render('independent_updates', ['updateStatus' => IndependentUpdates::status(), 'updateError' => $error]);
    }
}
