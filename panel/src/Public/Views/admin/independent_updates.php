<?php
use XcVm\Core\Localization\Translator;
use XcVm\Core\Updates\IndependentUpdates;
$updateStatus ??= IndependentUpdates::status();
$escapeUpdate = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$updateText = static fn(string $key): string => $escapeUpdate(Translator::get($key));
$updateState = $updateStatus['state'] ?? 'pending';
$updateAvailable = $updateState === 'available';
$updateBusy = in_array($updateState, ['checking', 'updating'], true);
$updateStateKeys = ['pending'=>'queued', 'checking'=>'please_wait', 'available'=>'update_available', 'current'=>'complete', 'updating'=>'updating', 'updated'=>'complete', 'error'=>'error'];
?>
<div id="panel-update" class="card <?= $updateAvailable ? 'bg-info text-white ' : '' ?>mb-4">
  <div class="card-body py-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h6 class="<?= $updateAvailable ? 'text-white ' : '' ?>mb-0"><i class="icon-base ti tabler-cloud-download me-1"></i><?= $updateText($updateAvailable ? 'update_available' : 'updates') ?><?= $updateAvailable ? ' — ' . $escapeUpdate($updateStatus['latest']) : '' ?></h6>
      <div class="d-flex align-items-center gap-2">
        <?php if (!empty($updateStatus['url']) && preg_match('~^https://github\.com/mena50056/xcvm-independent/releases/tag/v[0-9A-Za-z.-]+$~D', $updateStatus['url'])): ?>
          <a href="<?= $escapeUpdate($updateStatus['url']) ?>" class="btn btn-sm <?= $updateAvailable ? 'btn-outline-light' : 'btn-outline-primary' ?>" target="_blank" rel="noopener"><?= $updateText('release_thread') ?> <i class="icon-base ti tabler-arrow-right"></i></a>
        <?php endif; ?>
        <button type="button" class="btn btn-sm <?= $updateAvailable ? 'btn-outline-light' : 'btn-outline-primary' ?>" onclick="XcIndependentUpdate('check')" <?= $updateBusy ? 'disabled' : '' ?>><?= $updateText('module_check_updates') ?></button>
        <?php if ($updateAvailable): ?><button type="button" class="btn btn-sm btn-light" onclick="UpdateServer()"><?= $updateText('update_server') ?></button><?php endif; ?>
      </div>
    </div>
    <div class="small mt-2"><?= $updateText('version') ?>: <strong><?= $escapeUpdate($updateStatus['current'] ?? '—') ?></strong> · <?= $updateText($updateStateKeys[$updateState] ?? 'queued') ?></div>
    <?php if (!empty($updateStatus['checked_at'])): ?><div class="small mt-1"><?= $updateText('last_updated') ?>: <?= $escapeUpdate(date('Y-m-d H:i:s', (int) $updateStatus['checked_at'])) ?></div><?php endif; ?>
    <?php if (isset($_GET['update_queued'])): ?><div class="alert alert-info mt-3 mb-0"><?= $updateText('queued') ?></div><?php endif; ?>
    <?php if (isset($_GET['update_failed'])): ?><div class="alert alert-danger mt-3 mb-0"><?= $updateText('module_request_failed') ?></div><?php endif; ?>
    <?php if (!empty($updateStatus['error'])): ?><div class="alert alert-danger mt-3 mb-0"><?= $updateText('error') ?><div class="small mt-1"><?= $escapeUpdate($updateStatus['error']) ?></div></div><?php endif; ?>
    <?php if (!empty($updateStatus['notes'])): ?><pre class="small mt-2 mb-0" style="white-space:pre-wrap;max-height:150px;overflow:auto;color:inherit"><?= $escapeUpdate($updateStatus['notes']) ?></pre><?php endif; ?>
  </div>
</div>
<script>
window.XcIndependentUpdate = function(action) {
    // Separate form avoids nesting inside the existing settings form.
    var form = document.createElement('form'); form.method = 'post'; form.action = './independent_updates';
    var values = <?= json_encode(['csrf' => $_SESSION['independent_update_csrf'] ?? '', 'version' => $updateStatus['latest'] ?? ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    values.update_action = action;
    Object.keys(values).forEach(function(name) { var input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = values[name]; form.appendChild(input); });
    document.body.appendChild(form); form.submit();
};
</script>
