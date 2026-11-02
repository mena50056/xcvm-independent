<?php
if (count(get_included_files()) === 1) { exit(); }
$hmAr = \XcVm\Core\Localization\Translator::isRtl();
$hmText = static fn($ar, $en) => $hmAr ? $ar : $en;
?>
<section class="horus-monitor" data-monitor-page="<?= htmlspecialchars($hmPage, ENT_QUOTES) ?>">
<header class="horus-monitor-hero">
 <div class="horus-monitor-heading">
  <div><span class="horus-monitor-eyebrow">HORUS · <?= $hmText('مركز المراقبة', 'OBSERVABILITY') ?></span>
   <h4><?= $language::get($hmTitle) ?></h4>
   <p class="horus-monitor-caption"><?= $hmText('تابع النشاط، ابحث في السجلات وافحص التفاصيل من مكان واحد.', 'Follow activity, search records and inspect details in one workspace.') ?></p>
  </div>
  <div class="horus-monitor-controls">
   <button class="btn btn-primary" type="button" data-monitor-refresh><i class="ti tabler-refresh me-1"></i><?= $language::get('refresh') ?></button>
   <button class="btn btn-label-secondary" type="button" data-monitor-pause aria-pressed="false" hidden><?= $hmText('إيقاف التحديث', 'Pause updates') ?></button>
  </div>
 </div>
 <div class="horus-monitor-metrics">
  <div class="horus-monitor-metric"><small><?= $hmText('نتائج الفلاتر', 'Matching records') ?></small><strong data-monitor-filtered>—</strong></div>
  <div class="horus-monitor-metric"><small><?= $hmText('معروض في الصفحة', 'On this page') ?></small><strong data-monitor-shown>—</strong></div>
  <div class="horus-monitor-metric"><small><?= $hmText('آخر تحديث', 'Last updated') ?></small><strong data-monitor-time>—</strong><div data-monitor-status role="status" aria-live="polite"><?= $hmText('جارٍ التحميل…', 'Loading…') ?></div></div>
 </div>
 <div class="horus-monitor-search"><label for="horus-monitor-search"><?= $language::get('search') ?></label><input id="horus-monitor-search" data-monitor-search type="search" class="form-control" autocomplete="off" placeholder="<?= $hmText('ابحث في النتائج…', 'Search records…') ?>"></div>
</header>
