<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\Response;
use XcVm\Core\Diagnostics\SystemVersions;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Streaming\Codec\FfmpegBinaries;

/**
 * SettingsController — General Settings (admin/settings.php).
 *
 * GET /settings
 * Massive multi-tab form with ~80+ switchery toggles, select2 dropdowns,
 * 9 tabs (General, Security, API, Streaming, MAG, Web Player, Logs, Info, Database).
 *
 * @renders Views/admin/settings.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SettingsController extends BaseAdminController {
	public function index(): void {
		$this->requirePermission();
        if (defined('XCVM_INDEPENDENT_LAB') && \XcVm\Core\Auth\Authorization::isFullAdmin()) \XcVm\Core\Updates\IndependentUpdates::csrfToken();
		Response::noCache();

		$rSettings = SettingsManager::getAll();
		$rStreamArguments = StreamConfigRepository::getStreamArguments();

		$rUpdate = json_decode((string) ($rSettings['update_data'] ?? ''), true) ?: [];
        $rHorusVersions = SystemVersions::collect();

        $this->setTitle('Settings');
        $this->render('settings', ['rSettings' => $rSettings, 'rStreamArguments' => $rStreamArguments,
            'rUpdate' => $rUpdate, 'rHorusVersions' => $rHorusVersions,
            'clusterInfo' => ClusterOverview::infoRows($rSettings)] + $this->ffmpegBuilds($rSettings));
	}

	/**
	 * The FFmpeg builds for the version selects and the builds table: what this
	 * node has, plus a stored id it has no build of (an older 4.0 or 8.0, or one
	 * missing here), which stays selectable.
	 *
	 * @return array{rFfmpegBuilds: array, rFfmpegGpuBuilds: array, rFfmpegLabels: array<string, string>, rCpuOpts: list<string>, rGpuOpts: list<string>}
	 */
	private function ffmpegBuilds(array $rSettings): array {
		$rBuilds = FfmpegBinaries::available();
		// Newest first by the version the binary reports, not the folder name (4.0 holds 4.4.5).
		$rVersion = static fn(string $rId): string => preg_match('/^ffmpeg version n?(\d+(?:\.\d+)*)/', (string) ($rBuilds[$rId]['banner'] ?? ''), $rM) ? $rM[1] : $rId;
		uksort($rBuilds, static fn($a, $b): int => version_compare($rVersion((string) $b), $rVersion((string) $a)));
		$rGpuBuilds = FfmpegBinaries::gpuCapable();
		$rCpuOpts = array_keys($rBuilds);
		$rGpuOpts = array_keys($rGpuBuilds);
		$rCpu = (string) ($rSettings['ffmpeg_cpu'] ?? '');
		if ($rCpu !== '' && !in_array($rCpu, $rCpuOpts, true)) {
			$rCpuOpts[] = $rCpu;
		}
		$rGpu = (string) ($rSettings['ffmpeg_gpu'] ?? '');
		if ($rGpu !== '' && !in_array($rGpu, $rGpuOpts, true)) {
			$rGpuOpts[] = $rGpu;
		}
		$rLabels = [];
		foreach (array_unique([...$rCpuOpts, ...$rGpuOpts]) as $rId) {
			$rLabels[$rId] = self::ffmpegLabel((string) $rId, $rBuilds[$rId]['banner'] ?? null);
		}
		return ['rFfmpegBuilds' => $rBuilds, 'rFfmpegGpuBuilds' => $rGpuBuilds, 'rFfmpegLabels' => $rLabels, 'rCpuOpts' => $rCpuOpts, 'rGpuOpts' => $rGpuOpts];
	}

	/**
	 * A build labelled by what its binary says, not by its folder: the version as
	 * `ffmpeg -version` prints it ("ffmpeg version 4.4.5-XCVM" reads "v4.4.5-XCVM",
	 * a distro's "6.1.1-3ubuntu5" stays as is, no guessing which part is a
	 * builder). A version with no suffix of its own takes the folder's (6.1-my
	 * reads "v6.1 (MY)"); a banner with no version keeps the folder id. An id
	 * with no build of its own ($rBanner null) is "auto". Plain text: the view
	 * escapes it.
	 */
	public static function ffmpegLabel(string $rId, ?string $rBanner): string {
		if ($rBanner === null) {
			return 'v' . $rId . ' (' . Translator::get('ffmpeg_build_auto') . ')';
		}
		if (!preg_match('/^ffmpeg version n?(\d\S*)/', $rBanner, $rM)) {
			return 'v' . $rId;
		}
		$rFolder = preg_match('/-([a-z0-9]+)$/', $rId, $rSuffix) && !str_contains($rM[1], '-') ? ' (' . strtoupper($rSuffix[1]) . ')' : '';
		return 'v' . $rM[1] . $rFolder;
	}

}
