<?php

/**
 * Cache & Redis settings (Bootstrap 5). A live PHP cache summary plus two tabs — the
 * caching system (cron schedule + thread count + live cache counters) and the Redis
 * connection handler (server/auth status). Cron settings save via post.php?action=cache;
 * enable/disable/regenerate/clear actions run through ./api?action=<action> and reload.
 * Reached full-page in the new-UI shell.
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Core\Util\TimeUtils;
use XcVm\Domain\Server\ServerRepository;

$cacheText = static fn($ar, $en) => \XcVm\Core\Localization\Translator::isRtl() ? $ar : $en;
$phpCache = \XcVm\Core\Cache\PhpCacheStatus::snapshot();
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('cache_redis_settings'); ?></h4>
</div>

<form method="POST" id="cache-form">
    <section class="card mb-4" id="horus-cache-summary" data-horus-opcache="<?= htmlspecialchars(json_encode($phpCache), ENT_QUOTES); ?>">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h5 class="card-title mb-0"><?= $cacheText('كاش حورس — الحالة الفعلية', 'Horus cache — live status'); ?></h5>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (['Panel' => SettingsManager::get('enable_cache'), 'Redis' => SettingsManager::get('redis_handler'), 'OPcache' => $phpCache['enabled']] as $cacheLabel => $cacheEnabled): ?>
                        <span class="badge bg-label-<?= $cacheEnabled ? 'success' : 'warning'; ?>"><?= $cacheLabel; ?> · <?= $cacheEnabled ? $cacheText('مفعّل', 'Enabled') : $cacheText('متوقف', 'Disabled'); ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <p class="text-body-secondary small"><?= $cacheText('قراءة من خادم صفحة الإدارة وقت فتحها. نسبة إصابات OPcache تخص كود PHP منذ إعادة تشغيل الخدمة، وليست تقييمًا شاملًا لسرعة البانل.', 'Read from the serving admin process at page load. OPcache hits measure PHP bytecode reuse since service restart, not overall panel performance.'); ?></p>
            <div class="row g-3">
                <div class="col-6 col-lg-3"><small class="text-body-secondary d-block"><?= $cacheText('ذاكرة OPcache', 'OPcache memory'); ?></small><strong><bdi dir="ltr"><?= $phpCache['enabled'] ? $phpCache['used_mb'] . ' / ' . $phpCache['total_mb'] . ' MB' : '—'; ?></bdi></strong></div>
                <div class="col-6 col-lg-3"><small class="text-body-secondary d-block"><?= $cacheText('إصابات الكاش', 'Cache hits'); ?></small><strong><?= $phpCache['enabled'] ? $phpCache['hit_rate'] . '%' : '—'; ?></strong></div>
                <div class="col-6 col-lg-3"><small class="text-body-secondary d-block"><?= $cacheText('ملفات PHP المخزنة', 'Cached PHP scripts'); ?></small><strong><?= $phpCache['enabled'] ? number_format($phpCache['scripts']) : '—'; ?></strong></div>
                <div class="col-6 col-lg-3"><small class="text-body-secondary d-block"><?= $cacheText('فحص تحديث الملفات', 'File change checks'); ?></small><strong><?= $phpCache['enabled'] && $phpCache['validates_changes'] ? $phpCache['revalidate_seconds'] . ' ' . $cacheText('ثانية', 'seconds') : '—'; ?></strong></div>
            </div>
            <?php if (!$phpCache['enabled'] || $phpCache['full']): ?>
                <p class="text-warning mt-3 mb-0"><?= !$phpCache['enabled'] ? $cacheText('OPcache غير مفعّل في خدمة الويب الحالية.', 'OPcache is disabled in the current web service.') : $cacheText('ذاكرة OPcache ممتلئة؛ تحتاج مراجعة السعة.', 'OPcache is full; review its capacity.'); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <div class="card">
        <div class="card-body">
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#cache" role="tab"><i class="icon-base ti tabler-refresh me-1"></i><?= $cacheText('نظام كاش حورس', 'Horus content cache'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#connections" role="tab"><i class="icon-base ti tabler-plug-connected me-1"></i><?= $language::get('redis_connection_handler'); ?></button></li>
            </ul>
            <div class="tab-content p-4 border rounded">
                <!-- Caching system -->
                <div class="tab-pane fade show active" id="cache" role="tabpanel">
                    <?php if ($rSettings['enable_cache']): ?>
                        <?php
                        $db->query("SELECT `time` FROM `crontab` WHERE `filename` = 'cache_engine';");
                        [$rMinute, $rHour] = array_pad(explode(' ', (string) ($db->get_row()['time'] ?? '')), 2, '*');
                        $db->query('SELECT `id` FROM `lines`;');
                        $rLineCount = $db->result->rowCount();
                        // Every row of `streams` is cached, episodes and movies included: one tile per type so the total is not read as live channels only.
                        $rCachedIDs = array_flip(array_map(static fn(string $rPath): string => substr(basename($rPath), 7), glob(STREAMS_TMP_PATH . 'stream_*') ?: []));
                        $rStreamTypes = [1 => [0, 0], 2 => [0, 0], 5 => [0, 0]];
                        $db->query('SELECT `id`, `type` FROM `streams`;');
                        foreach ($db->get_rows() as $rRow) {
                            $rStreamTypes[(int) $rRow['type']] ??= [0, 0];
                            $rStreamTypes[(int) $rRow['type']][0] += (int) isset($rCachedIDs[$rRow['id']]);
                            $rStreamTypes[(int) $rRow['type']][1]++;
                        }
                        ksort($rStreamTypes);
                        $db->query('SELECT `id` FROM `streams_series`;');
                        $rSeriesCount = $db->result->rowCount();
                        $rLineCountR = count(glob(LINES_TMP_PATH . 'line_i_*'));
                        $rSeriesCountR = max(count(glob(SERIES_TMP_PATH . 'series_*')) - 2, 0);
                        $rFreeCache = 100 - (int) (disk_free_space(MAIN_HOME . 'tmp') / disk_total_space(MAIN_HOME . 'tmp') * 100);
                        ?>
                        <?php if ($rFreeCache >= 90): ?>
                            <div class="alert alert-danger" role="alert">Your cache tmpfs mount is <strong><?= $rFreeCache; ?>% full</strong>! This can stop new lines and streams from caching. <strong>Increase the tmpfs size in /etc/fstab and reboot.</strong></div>
                        <?php endif; ?>
                        <?php if (!file_exists(CACHE_TMP_PATH . 'cache_complete')): ?>
                            <div class="alert alert-warning" role="alert">Cache isn't complete yet. With many streams and lines the caching process can take a while; until it finishes, Player API and Playlist functionality is limited and users may be unable to connect.</div>
                        <?php endif; ?>
                        <h5 class="card-title"><?= $language::get('cache_cron_execution'); ?></h5>
                        <p class="text-body-secondary">Last complete run: <strong><?= date($rSettings['datetime_format'], $rSettings['last_cache']); ?></strong>. The default is every 5 minutes; as your Streams and Lines tables grow, tune the schedule for a balance between performance and data accuracy. <strong>Ensure the cron format is correct, otherwise it won't run.</strong></p>
                        <div class="row g-3 mb-3">
                            <div class="col-md-3"><label class="form-label" for="minute">Minute</label><input type="text" class="form-control" id="minute" name="minute" value="<?= htmlspecialchars((string) $rMinute, ENT_QUOTES); ?>"></div>
                            <div class="col-md-3"><label class="form-label" for="hour">Hour</label><input type="text" class="form-control" id="hour" name="hour" value="<?= htmlspecialchars((string) $rHour, ENT_QUOTES); ?>"></div>
                            <div class="col-md-3"><label class="form-label" for="cache_thread_count">Thread Count</label><input type="text" class="form-control" id="cache_thread_count" name="cache_thread_count" value="<?= (int) $rSettings['cache_thread_count']; ?>"></div>
                            <div class="col-md-3 d-flex align-items-end">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="cache_changes" name="cache_changes" value="1" <?= $rSettings['cache_changes'] == 1 ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="cache_changes">Update Changes Only</label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mb-4">
                            <?php
                            $rTypeTiles = [1 => ['tabler-player-play', 'live_streams'], 2 => ['tabler-movie', 'movies'], 3 => ['tabler-video-plus', 'created_channels'], 4 => ['tabler-radio', 'radio_stations'], 5 => ['tabler-list-numbers', 'episodes']];
                            $rTiles = [];
                            foreach ($rStreamTypes as $rType => [$rCached, $rTotal]) {
                                // A type outside streams_types is a leftover of a migration: no page lists it, so flag it.
                                $rTiles[] = isset($rTypeTiles[$rType])
                                    ? [$rTypeTiles[$rType][0], $language::get($rTypeTiles[$rType][1]), number_format($rCached) . ' <span class="text-body-secondary">/ ' . number_format($rTotal) . '</span>', 'info']
                                    : ['tabler-alert-triangle', 'Unknown type ' . $rType, number_format($rCached) . ' <span class="text-body-secondary">/ ' . number_format($rTotal) . '</span>', 'warning'];
                            }
                            array_push(
                                $rTiles,
                                ['tabler-users', 'Lines', number_format($rLineCountR) . ' <span class="text-body-secondary">/ ' . number_format($rLineCount) . '</span>', 'primary'],
                                ['tabler-device-tv', 'Series', number_format($rSeriesCountR) . ' <span class="text-body-secondary">/ ' . number_format($rSeriesCount) . '</span>', 'success'],
                                ['tabler-clock', 'Time Taken', TimeUtils::secondsToTime($rSettings['last_cache_taken']), 'secondary'],
                            );
                            foreach ($rTiles as $rTile):
                            ?>
                                <div class="col-6 col-lg-3">
                                    <div class="border rounded p-3 h-100 d-flex align-items-center gap-3">
                                        <span class="badge bg-label-<?= $rTile[3]; ?> rounded p-2"><i class="icon-base ti <?= $rTile[0]; ?>"></i></span>
                                        <div>
                                            <div class="text-body-secondary small"><?= $rTile[1]; ?></div>
                                            <div class="h6 mb-0"><?= $rTile[2]; ?></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-danger js-api" data-action="disable_cache"><?= $language::get('disable_cache'); ?></button>
                                <button type="button" class="btn btn-info js-api" data-action="regenerate_cache"><?= $language::get('regenerate_cache'); ?></button>
                            </div>
                            <button type="submit" class="btn btn-primary">Save Cron</button>
                        </div>
                    <?php else: ?>
                        <h5 class="card-title"><?= $language::get('cache_is_disabled'); ?></h5>
                        <p class="text-body-secondary">Content caching is disabled. Enable it below to generate the stream and subscriber cache.</p>
                        <button type="button" class="btn btn-success js-api" data-action="enable_cache"><?= $language::get('enable_cache'); ?></button>
                    <?php endif; ?>
                </div>
                <!-- Redis connection handler -->
                <div class="tab-pane fade" id="connections" role="tabpanel">
                    <h5 class="card-title"><?= $language::get('redis_connection_handler'); ?></h5>
                    <p class="text-body-secondary">The handler verifies and manages all client→load-balancer connections through Redis instead of MySQL. <strong>Disabling it disconnects active clients; enabling it moves live connections from MySQL to Redis without disconnects.</strong></p>
                    <?php if ($rSettings['redis_handler']): ?>
                        <?php
                        $rStatus = $rAuth = false;
                        try {
                            $rTestRedis = new \Redis();
                            $rStatus = $rTestRedis->connect(ServerRepository::getAll()[SERVER_ID]['server_ip'], 6379);
                            $rAuth = $rTestRedis->auth(SettingsManager::get('redis_password'));
                        } catch (\Exception $e) {
                            // status/auth stay false
                        }
                        ?>
                        <div class="row g-3 my-1">
                            <div class="col-md-6">
                                <div class="border rounded p-3 d-flex justify-content-between align-items-center">
                                    <span class="d-flex align-items-center gap-2"><i class="icon-base ti tabler-server text-body-secondary"></i>Server Status</span>
                                    <span class="badge bg-label-<?= $rStatus ? 'success' : 'danger'; ?>"><i class="icon-base ti tabler-<?= $rStatus ? 'circle-check' : 'circle-x'; ?> me-1"></i><?= $rStatus ? $language::get('online_btn') : $language::get('offline'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="border rounded p-3 d-flex justify-content-between align-items-center">
                                    <span class="d-flex align-items-center gap-2"><i class="icon-base ti tabler-key text-body-secondary"></i>Authentication</span>
                                    <span class="badge bg-label-<?= $rAuth ? 'success' : 'danger'; ?>"><i class="icon-base ti tabler-<?= $rAuth ? 'circle-check' : 'circle-x'; ?> me-1"></i><?= $rAuth ? $language::get('authenticated') : $language::get('invalid_password'); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-danger js-api" data-action="disable_handler"><?= $language::get('disable_handler'); ?></button>
                            <button type="button" class="btn btn-info js-api" data-action="clear_redis"><?= $language::get('clear_database'); ?></button>
                        </div>
                    <?php else: ?>
                        <p class="text-body-secondary"><strong>The Redis Connection Handler is disabled.</strong> Click below to re-enable it.</p>
                        <button type="button" class="btn btn-success js-api" data-action="enable_handler"><?= $language::get('enable_handler'); ?></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var toast = window.xcToast || function() {};

        // enable/disable/regenerate/clear actions.
        document.querySelectorAll('.js-api').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var action = this.getAttribute('data-action');
                var run = function() {
                    btn.disabled = true;
                    fetch('./api?action=' + encodeURIComponent(action), {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(d) {
                            if (d && d.result === true) {
                                location.reload();
                            } else {
                                btn.disabled = false;
                                toast((d && d.message) || errText, 'error');
                            }
                        })
                        .catch(function() {
                            btn.disabled = false;
                            toast(errText, 'error');
                        });
                };
                if (action.indexOf('disable') === 0 || action === 'clear_redis' || action === 'regenerate_cache') {
                    (window.xcConfirm ? window.xcConfirm('Are you sure?') : Promise.resolve(confirm('Are you sure?'))).then(function(ok) {
                        if (ok) {
                            run();
                        }
                    });
                } else {
                    run();
                }
            });
        });

        // Save cron settings.
        var form = document.getElementById('cache-form');
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            var fd = new FormData(form);
            fd.append('submit_settings', '1');
            fetch('post.php?action=cache', {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(txt) {
                    var d;
                    try {
                        d = JSON.parse(txt);
                    } catch (err) {
                        d = {
                            result: false
                        };
                    }
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(d && d.result !== false ? 'Cache & Redis settings updated.' : errText, d && d.result !== false ? 'success' : 'error');
                })
                .catch(function() {
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(errText, 'error');
                });
        });
    })();
</script>
</body>

</html>
