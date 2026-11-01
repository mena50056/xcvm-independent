<?php
/** Native overview; consumes the controller's existing, permission-scoped data. */
if (count(get_included_files()) === 1) { exit(); }
$xcConsoleName = (string) ($rUserInfo['username'] ?? '');
$xcConsoleAlerts = \XcVm\Core\Auth\Authorization::check('adv', 'streams')
    && !empty(\XcVm\Core\Config\DashboardAlerts::options($rSettings)['latest_enabled']);
$xcConsoleCountries = !empty($rSettings['save_closed_connection']) && !empty($rSettings['dashboard_map'])
    ? array_slice((array) ($rConnectionMap ?? []), 0, 9) : [];
?>
<section class="xc-console-overview <?= $xcConsoleAlerts ? '' : 'xc-console-no-alerts'; ?>" aria-label="<?= htmlspecialchars($language::get('dashboard'), ENT_QUOTES); ?>">
    <div class="xc-console-profile-stack"><article class="card xc-console-profile">
        <div class="xc-console-account">
            <span class="xc-console-avatar" aria-hidden="true"><i class="icon-base ti tabler-user"></i></span>
            <div><small><?= $language::get('user_profile'); ?></small><strong><?= htmlspecialchars($xcConsoleName, ENT_QUOTES); ?></strong><small><?= htmlspecialchars((string) ($rUserInfo['email'] ?? 'حورس'), ENT_QUOTES); ?></small></div>
        </div>
        <div class="xc-console-account-links"><a href="edit_profile"><i class="icon-base ti tabler-settings me-1"></i><?= $language::get('user_profile'); ?></a></div>
    </article><article class="card xc-console-cluster"><div class="card-header"><h5 class="mb-0"><?= $language::get('servers'); ?></h5></div><div class="card-body"><div class="xc-console-cluster-number"><?= count(array_filter((array) ($rOrderedServers ?? []), static fn($server) => !empty($server['server_online']))); ?> / <?= count((array) ($rOrderedServers ?? [])); ?></div><small><?= $language::get('dashboard_online'); ?> / <?= $language::get('total'); ?></small><?php if (\XcVm\Core\Auth\Authorization::check('adv', 'servers')): ?><a class="btn btn-sm btn-success mt-2" href="servers"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></a><?php endif; ?></div></article></div>
    <div class="card" id="xc-console-alert-slot" hidden></div>
    <article class="card xc-console-location">
        <div class="card-header"><h5 class="mb-0"><?= $language::get('dashboard_connections_by_location'); ?></h5></div>
        <?php if ($xcConsoleCountries): ?>
            <div class="xc-console-country-grid">
                <?php foreach ($xcConsoleCountries as $xcConsoleCountry):
                    $xcConsoleCount = max(0, (int) ($xcConsoleCountry['count'] ?? 0));
                    $xcConsolePct = min(100, max(0, (int) round($xcConsoleCount / max(1, (int) ($rConnectionCount ?? 0)) * 100)));
                ?>
                    <div class="xc-console-country">
                        <small><?= htmlspecialchars((string) ($xcConsoleCountry['name'] ?? ''), ENT_QUOTES); ?></small>
                        <strong><?= number_format($xcConsoleCount); ?></strong>
                        <div class="progress" style="height:4px"><div class="progress-bar bg-info" role="progressbar" style="width:<?= $xcConsolePct; ?>%" aria-valuenow="<?= $xcConsolePct; ?>" aria-valuemin="0" aria-valuemax="100"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card-body text-body-secondary small"><?= $language::get('no_results_found'); ?></div>
        <?php endif; ?>
    </article>
</section>
<?php
$xcConsoleServers = (array) ($rOrderedServers ?? []);
$xcConsoleOnline = count(array_filter($xcConsoleServers, static fn($server) => !empty($server['server_online'])));
$xcConsoleGroups = [
    ['streams', 'dashboard_live_streams', 'streams', 'streams'],
    ['subscriptions', 'lines', 'lines', 'users'],
    ['connections', 'dashboard_connections', 'live_connections', 'live_connections'],
    ['servers', 'servers', 'servers', 'servers'],
];
?>
<div class="xc-console-summary" id="xc-console-summary" hidden>
    <?php foreach ($xcConsoleGroups as [$xcGroup, $xcLabel, $xcUrl, $xcPermission]): ?>
        <section class="card xc-console-summary-<?= $xcGroup; ?>">
            <div class="card-header">
                <h5 class="mb-0">
                    <?php if (\XcVm\Core\Auth\Authorization::check('adv', $xcPermission)): ?><a href="<?= $xcUrl; ?>"><?php endif; ?>
                    <?= htmlspecialchars($language::get($xcLabel), ENT_QUOTES); ?>
                    <?php if (\XcVm\Core\Auth\Authorization::check('adv', $xcPermission)): ?></a><?php endif; ?>
                </h5>
            </div>
            <div class="xc-console-summary-content"><div class="xc-console-summary-icon" aria-hidden="true"><i class="icon-base ti <?= ['streams'=>'tabler-device-tv','subscriptions'=>'tabler-users-group','connections'=>'tabler-world','servers'=>'tabler-server'][$xcGroup]; ?>"></i></div><div class="card-body row g-2" data-xc-console-group="<?= $xcGroup; ?>">
                <?php if ($xcGroup === 'servers'): ?>
                    <div class="xc-console-server-count"><?= $xcConsoleOnline; ?> / <?= count($xcConsoleServers); ?></div>
                    <small><?= $language::get('dashboard_online'); ?> / <?= $language::get('total'); ?></small>
                <?php endif; ?>
            </div></div>
        </section>
    <?php endforeach; ?>
</div>
