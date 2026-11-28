<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Backup\BackupIntegrity;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Server\ServerRepository;

/**
 * BackupsCronJob — backups cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BackupsCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:backups';
	}

	public function getDescription(): string {
		return 'Cron: automatic database backup (local + Dropbox)';
	}

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) return 1;
        $lock = BackupIntegrity::lock();
        if (!$lock) return 0;
        $due = !empty($rArgs[0]) || (isset(BackupService::PERIODS[SettingsManager::get('automatic_backups')]) &&
            (int) SettingsManager::get('last_backup') + BackupService::PERIODS[SettingsManager::get('automatic_backups')] <= time());
        try {
            if (!$due) \XcVm\Domain\Backup\OffsiteBackup::retry(time());
            if ($due) BackupIntegrity::writeJson(MAIN_HOME . 'backups/status.json', ['state'=>'running', 'time'=>time(), 'pid'=>getmypid()]);
            $result = $this->runBackup($rArgs);
            if ($due) {
                $offsite = \XcVm\Domain\Backup\OffsiteBackup::status();
                $state = $result === 0 ? (in_array($offsite['state']??'', ['failed','blocked'], true) ? 'partial' : 'success') : 'failed';
                BackupIntegrity::writeJson(MAIN_HOME . 'backups/status.json', ['state'=>$state, 'time'=>time(), 'file'=>BackupService::newestBackup()['filename'] ?? null]);
            }
            return ($state ?? '') === 'partial' ? 1 : $result;
        } catch (\Throwable $exception) {
            BackupIntegrity::writeJson(MAIN_HOME . 'backups/status.json', ['state'=>'failed', 'time'=>time()]);
            error_log('Horus backup failed; check storage and database availability.');
            return 1;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function runBackup(array $rArgs): int {

		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->registerShutdown();
		ini_set('display_errors', 1);
		ini_set('display_startup_errors', 1);
		error_reporting(32757);

		if (!ServerRepository::getAll()[SERVER_ID]['is_main']) {
			echo 'Please run on main server.' . "\n";
			return 1;
		}

		$this->setProcessTitle('XC_VM[Backups]');
		$this->acquireCronLock();

		global $db;

		$rForce = false;
		if (!empty($rArgs[0]) && intval($rArgs[0]) == 1) {
			$rForce = true;
		}

		$rBackups = SettingsManager::get('automatic_backups');
		$rLastBackup = intval(SettingsManager::get('last_backup'));
		$rPeriod = BackupService::PERIODS;


		$rCreated = null;
		if (isset($rPeriod[$rBackups]) || $rForce) {
			if ($rForce || $rLastBackup + $rPeriod[$rBackups] <= time()) {
				$db->close_mysql();
				$rFilename = MAIN_HOME . 'backups/backup_' . date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(3)) . '.sql';

				$rDumped = BackupService::create($rFilename);
				if (!$rDumped) { throw new \RuntimeException('Database backup failed; no completed backup was created.'); }
				if ($rDumped) {
					$rCreated = basename($rFilename);
					$db->db_connect();
					$db->query('UPDATE `settings` SET `last_backup` = ?;', time());
					if (SettingsManager::get('dropbox_remote')) {
						file_put_contents($rFilename . '.uploading', time());
						$rResponse = BackupService::uploadRemote(basename($rFilename), $rFilename);
						if (!isset($rResponse->error)) {
							$rResponse = json_decode(json_encode($rResponse, JSON_UNESCAPED_UNICODE), true);
							if (!isset($rResponse['size']) || intval($rResponse['size']) != filesize($rFilename)) {
								$rError = 'Failed to upload';
								file_put_contents($rFilename . '.error', $rError);
							}
						} else {
							try {
								$rError = json_decode(explode(', in apiCall', $rResponse->error->getMessage())[0], true)['error_summary'];
							} catch (\exception $e) {
								$rError = 'Unknown error';
							}
							file_put_contents($rFilename . '.error', $rError);
						}
						unlink($rFilename . '.uploading');
					}
				}
			}
		}

		// A restore's dump of the live database is its way back: neither counted nor removed here.
		$rBackups = array_values(array_filter(BackupService::getLocal(), static fn(array $rItem): bool => str_starts_with($rItem['filename'], 'backup_')));
		if ($rCreated !== null && intval(SettingsManager::get('backups_to_keep')) < count($rBackups) && 0 < intval(SettingsManager::get('backups_to_keep'))) {
			$rDelete = array_slice($rBackups, 0, count($rBackups) - intval(SettingsManager::get('backups_to_keep')));
			foreach ($rDelete as $rItem) {
				// Never the backup made in this run, wherever its time puts it in the list.
				if ($rItem['filename'] === $rCreated) {
					continue;
				}
				if (file_exists(MAIN_HOME . 'backups/' . $rItem['filename'])) {
					unlink(MAIN_HOME . 'backups/' . $rItem['filename']);
                    foreach (['.sha256.json', '.error', '.uploading', '.offsite.json'] as $suffix) @unlink(MAIN_HOME . 'backups/' . $rItem['filename'] . $suffix);
				}
			}
		}

		// Dropbox is asked for its list only after a backup was made, not every minute.
		if ($rCreated !== null && SettingsManager::get('dropbox_remote')) {
			$rRemoteBackups = BackupService::getRemote();
			if (intval(SettingsManager::get('dropbox_keep')) < count($rRemoteBackups) && 0 < intval(SettingsManager::get('dropbox_keep'))) {
				$rDelete = array_slice($rRemoteBackups, 0, count($rRemoteBackups) - intval(SettingsManager::get('dropbox_keep')));
				foreach ($rDelete as $rItem) {
					try {
						BackupService::deleteRemote($rItem['path']);
					} catch (\exception $e) {
					}
				}
			}
		}

		// The backup targets (Backups page) get the new dump, and once a day the recovery bundle.
		if ($rCreated !== null) {
			self::offsite(MAIN_HOME . 'backups/' . $rCreated, time());
		}

		@unlink($this->rIdentifier);

		return 0;
	}

	/**
	 * Copy a new dump to every enabled backup target, failures written beside
	 * it in its .error file (the dashboard's Backups row reads it); make the
	 * day's recovery bundle when a passphrase is set and copy it too; then
	 * keep each target's newest copies.
	 */
	private static function offsite(string $rDump, int $rNow): void {
		\XcVm\Domain\Backup\OffsiteBackup::run($rDump, $rNow);
	}
}
