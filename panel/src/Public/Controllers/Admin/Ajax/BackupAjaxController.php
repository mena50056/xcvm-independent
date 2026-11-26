<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Backup\BackupService;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Public\Controllers\Admin\TableController;

/**
 * Admin-ajax controller for the "Backups, Logs & Reports" group.
 *
 * Actions: clear_logs, backup, report, download_panel_logs.
 *
 * `report` is opened via full-page navigation (window.location), not XHR, so
 * {@see self::report()} does not call requireXhr(); it streams a CSV/JSON
 * download by dispatching {@see TableController} in-process.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BackupAjaxController extends BaseAjaxController {
	/** The log tables clear_logs empties, each with the permission of the page that lists it. */
	private const LOG_TABLES = [
		'lines_logs' => 'client_request_log',
		'lines_activity' => 'connection_logs',
		'streams_errors' => 'stream_errors',
		'users_credits_logs' => 'credits_log',
		'users_logs' => 'reg_userlog',
		'panel_logs' => 'panel_logs',
	];

	/** action=clear_logs — delete/truncate a log table over an optional date range. */
	public function clearLogs(): never {
		$this->requireXhr();

		$rType = RequestManager::get('type');

		if (!is_string($rType) || !isset(self::LOG_TABLES[$rType])) {
			$this->fail();
		}

		$this->gate('adv', self::LOG_TABLES[$rType]);

		global $db;

		if (strlen(RequestManager::get('from')) == 0) {
			$rStartTime = null;
		} else {
			$rStartTime = strtotime(RequestManager::get('from') . ' 00:00:00');

			if (!$rStartTime) {
				$this->fail();
			}
		}

		if (strlen(RequestManager::get('to')) == 0) {
			$rEndTime = null;
		} else {
			$rEndTime = strtotime(RequestManager::get('to') . ' 23:59:59');

			if (!$rEndTime) {
				$this->fail();
			}
		}

		if (in_array(RequestManager::get('type'), ['lines_logs', 'streams_errors', 'lines_activity', 'users_credits_logs', 'users_logs', 'panel_logs'])) {
			$rColumn = (RequestManager::get('type') == 'lines_activity') ? 'date_start' : 'date';
			$rTable = QueryHelper::prepareColumn(RequestManager::get('type'));

			if ($rStartTime && $rEndTime) {
				$db->query('DELETE FROM ' . $rTable . ' WHERE `' . $rColumn . '` >= ? AND `' . $rColumn . '` <= ?;', $rStartTime, $rEndTime);
			} elseif ($rStartTime) {
				$db->query('DELETE FROM ' . $rTable . ' WHERE `' . $rColumn . '` >= ?;', $rStartTime);
			} elseif ($rEndTime) {
				$db->query('DELETE FROM ' . $rTable . ' WHERE `' . $rColumn . '` <= ?;', $rEndTime);
			} else {
				$db->query('TRUNCATE ' . $rTable . ';');
			}
		}

		$this->ok();
	}

	/** action=backup — delete/restore a DB backup or trigger a new one (local + Dropbox). */
    public function backup(): never {
        $this->gate('adv', 'database');
        $sub = (string) RequestManager::get('sub');
        if ($sub === 'download') {
            $file = $this->localBackup((string) RequestManager::get('filename'));
            if ($file === null) { http_response_code(404); exit(); }
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: ' . filesize($file));
            readfile($file); exit();
        }
        $this->requireXhr();
        if ($sub === 'status') {
            $this->ok(['status'=>\XcVm\Core\Backup\BackupIntegrity::status()]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals((string) ($_SESSION['horus_backup_csrf'] ?? ''), (string) RequestManager::get('csrf')) || empty($_SESSION['horus_backup_csrf'])) $this->fail();
        if ($sub === 'verify') {
            $file = $this->localBackup((string) RequestManager::get('filename'));
            $this->ok(['integrity'=>$file ? \XcVm\Core\Backup\BackupIntegrity::inspect($file) : 'failed']);
        }
        if ($sub === 'delete') {
            global $rSettings;
            $name = (string) RequestManager::get('filename');
            if ($name !== basename($name) || !preg_match('/^[A-Za-z0-9_.:-]+\.sql$/D', $name)) $this->fail();
            $file = $this->localBackup((string) RequestManager::get('filename'));
            $remote = (string) ($rSettings['dropbox_token'] ?? '') !== '';
            if ($file === null && !$remote) $this->fail();
            $lock = \XcVm\Core\Backup\BackupIntegrity::lock();
            if (!$lock) $this->fail(['error'=>'busy']);
            $deleted = $file !== null ? unlink($file) : false;
            if ($remote) { BackupService::deleteRemote('/' . $name); if ($file === null) $deleted = true; }
            if ($deleted && $file !== null) foreach (['.sha256.json', '.error', '.uploading'] as $suffix) @unlink($file . $suffix);
            flock($lock, LOCK_UN); fclose($lock);
            $this->json(['result'=>$deleted]);
        }
        if ($sub === 'restore') {
            global $rSettings;
            $name = (string) RequestManager::get('filename');
            if ($name !== basename($name) || !preg_match('/^[A-Za-z0-9_.:-]+\.sql$/D', $name)) $this->fail();
            $file = $this->localBackup($name);
            $temp = null;
            if ($file === null && (string) ($rSettings['dropbox_token'] ?? '') !== '') {
                $temp = tempnam(MAIN_HOME . 'tmp/', 'horus-remote-restore-');
                if ($temp && BackupService::downloadRemote('/' . $name, $temp)) $file = $temp;
            }
            if ($file === null) { if ($temp) @unlink($temp); $this->fail(); }
            try { $result = BackupService::restore($file); }
            finally { if ($temp) @unlink($temp); }
            $this->json(['result'=>$result === true, 'error'=>$result === null ? 'safety_dump' : null]);
        }
        if ($sub !== 'backup') $this->fail();
        shell_exec(escapeshellarg(PHP_BIN) . ' ' . escapeshellarg(MAIN_HOME . 'console.php') . ' cron:backups 1 > /dev/null 2>/dev/null &');
        $this->ok();
    }

    private function localBackup(string $name): ?string {
        if ($name !== basename($name) || !preg_match('/^[A-Za-z0-9_.:-]+\.sql$/D', $name)) return null;
        $file = MAIN_HOME . 'backups/' . $name;
        return is_file($file) && !is_link($file) ? $file : null;
    }

	/**
	 * action=report — stream a DataTables result as a CSV/JSON download.
	 *
	 * Opened via full-page navigation (no XHR header), so it does NOT call
	 * requireXhr(). The DataTables handler is dispatched in-process (the old
	 * loopback curl is unreachable under the Front Controller); its echoed JSON
	 * is captured from a shutdown hook and converted to the requested format.
	 */
	public function report(): never {
		$this->gate('adv', 'database');

		global $rUserInfo;
		set_time_limit(60);
		ini_set('memory_limit', '-1');
		$rParams = json_decode(RequestManager::get('params'), true) ?: [];
		$rParams['report'] = true;
		$rParams['start'] = 0;
		$rParams['length'] = 100000;
		$rParams['draw'] = 0;

		$rReportName = preg_replace('/[^A-Za-z0-9 ]/', '', ($rParams['id'] ?? 'report')) . '_' . date('YmdHis');
		$rWantJson = RequestManager::get('format') === 'json';

		register_shutdown_function(static function () use ($rReportName, $rWantJson) {
			$rJson = ob_get_level() > 0 ? ob_get_clean() : '';
			$rDecoded = json_decode((string) $rJson, true);
			$rRows = (is_array($rDecoded) && !empty($rDecoded['data'])) ? $rDecoded['data'] : [];

			if ($rWantJson) {
				header('Content-Type: application/json; charset=utf-8');
				header('Content-Disposition: attachment; filename=report_' . $rReportName . '.json');
				echo json_encode($rRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

				return;
			}

			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename=report_' . $rReportName . '.csv');

			if (0 < count($rRows)) {
				echo file_get_contents(AdminHelpers::convertToCSV($rRows));
			}
		});

		// Authenticate the in-process dispatch via the loopback + api_user_id branch
		// in TableController::index() (avoids its session/functions.php include path).
		$rParams['api_user_id'] = $rUserInfo['id'];
		RequestManager::set(array_merge(RequestManager::getAll(), $rParams));
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		ob_start();
		(new TableController())->index();

		exit();
	}

	/** action=download_panel_logs — export recent panel error-log rows without deleting them. */
	public function downloadPanelLogs(): never {
		$this->requireXhr();
		$this->gate('adv', 'panel_logs');

		$this->ok(['data' => DiagnosticsService::downloadPanelLogs()]);
	}
}
