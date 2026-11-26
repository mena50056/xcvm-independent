<?php
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Backup\BackupIntegrity;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Util\LayoutRenderer;
if (!Authorization::check('adv', 'database')) { echo '<div class="alert alert-danger">' . $language::get('dashboard_no_permissions') . '</div>'; LayoutRenderer::renderFooter('admin'); return; }
$_SESSION['horus_backup_csrf'] ??= bin2hex(random_bytes(32));
$bt = static fn($ar, $en) => Translator::isRtl() ? $ar : $en;
$schedule = (string) ($rSettings['automatic_backups'] ?? 'off');
$latest = BackupService::newestBackup();
$periods = ['off'=>$bt('متوقف','Off'),'hourly'=>$bt('كل ساعة','Hourly'),'daily'=>$bt('يوميًا','Daily'),'weekly'=>$bt('أسبوعيًا','Weekly'),'monthly'=>$bt('شهريًا','Monthly')];
?>
<section class="horus-backups" data-csrf="<?= htmlspecialchars($_SESSION['horus_backup_csrf'], ENT_QUOTES) ?>" data-ar="<?= Translator::isRtl() ? '1' : '0' ?>">
 <header class="hb-header">
  <div><h4><?= $bt('النسخ الاحتياطي', 'Backups') ?></h4><p><?= $bt('نسخ قاعدة البيانات والتحقق منها واستعادتها.', 'Database backups, verification and recovery.') ?></p></div>
  <button type="button" class="btn btn-primary" id="create-backup"><?= $language::get('create_backup_now') ?></button>
 </header>
 <div class="hb-stats">
  <div class="card"><small><?= $bt('آخر نسخة محلية', 'Latest local backup') ?></small><strong><?= $latest ? date('Y-m-d H:i', $latest['timestamp']) : $bt('لا توجد نسخة', 'No backup yet') ?></strong></div>
  <div class="card"><small><?= $bt('الجدولة', 'Schedule') ?></small><strong id="hb-schedule"><?= $periods[$schedule] ?? $periods['off'] ?></strong></div>
  <div class="card"><small><?= $bt('حالة آخر تشغيل', 'Last run') ?></small><strong id="hb-status" role="status" aria-live="polite">—</strong><small id="hb-time"></small></div>
 </div>
 <div class="card mb-4"><div class="card-body">
  <form id="backup-settings" autocomplete="off">
   <div class="row g-3">
    <div class="col-md-6"><label for="automatic_backups" class="form-label"><?= $bt('موعد النسخ التلقائي','Automatic backup schedule') ?></label><select name="automatic_backups" id="automatic_backups" class="form-select"><?php foreach ($periods as $key=>$label): ?><option value="<?= $key ?>" <?= $schedule === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6"><label for="backups_to_keep" class="form-label"><?= $bt('عدد النسخ الدورية المحفوظة','Scheduled backups to retain') ?></label><input type="number" min="0" max="1000" class="form-control" id="backups_to_keep" name="backups_to_keep" value="<?= (int) ($rSettings['backups_to_keep'] ?? 7) ?>"><small><?= $bt('صفر للاحتفاظ بكل النسخ. نسخة الأمان قبل الاستعادة تُحفظ بشكل مستقل.','Zero keeps all backups. Safety copies taken before a restore are retained separately.') ?></small></div>
   </div>
   <details class="mt-3"><summary><?= $bt('إعدادات Dropbox','Dropbox settings') ?></summary><div class="row g-3 mt-1">
    <div class="col-md-6"><label class="form-check"><input name="dropbox_remote" type="checkbox" value="1" class="form-check-input" <?= !empty($rSettings['dropbox_remote']) ? 'checked' : '' ?>><?= $bt('تفعيل Dropbox','Enable Dropbox') ?></label></div>
    <div class="col-md-6"><label for="dropbox_keep" class="form-label"><?= $bt('عدد النسخ البعيدة','Remote copies to retain') ?></label><input type="number" min="0" max="1000" name="dropbox_keep" id="dropbox_keep" value="<?= (int) ($rSettings['dropbox_keep'] ?? 0) ?>" class="form-control"></div>
    <div class="col-12"><label for="dropbox_token" class="form-label">Dropbox Token</label><input type="password" name="dropbox_token" id="dropbox_token" autocomplete="new-password" class="form-control" placeholder="<?= $bt('اتركه فارغًا للاحتفاظ بالقيمة الحالية','Leave blank to keep the current token') ?>"></div>
   </div></details>
   <button type="submit" class="btn btn-primary mt-3" id="save-settings"><?= $language::get('save_changes') ?></button>
  </form>
 </div></div>
 <div class="card mb-4"><div class="card-header"><h5><?= $bt('النسخ المتاحة','Available backups') ?></h5><p class="mb-0"><?= $bt('النسخ تشمل بيانات البانل. سجلات الاستخدام والاتصالات وحالة التشغيل المؤقتة مستثناة. الاستعادة تنشئ نسخة أمان أولًا.','Backups include panel data. Usage logs, connections and temporary runtime state are excluded. A safety copy is created before restoring.') ?></p></div>
 <div class="table-responsive"><table id="backups-table" class="table"><thead><tr><th><?= $language::get('date') ?></th><th><?= $language::get('filename') ?></th><th><?= $language::get('filesize') ?></th><th><?= $bt('التحقق','Verification') ?></th><th><?= $bt('نسخة بعيدة','Remote copy') ?></th><th><?= $language::get('actions') ?></th></tr></thead><tbody></tbody></table></div></div>
 <details class="hb-advanced"><summary><?= $bt('وجهات النسخ الخارجية وحزمة الاسترداد واختبار الاستعادة','Off-site targets, recovery bundle and restore test') ?></summary><div class="row g-4 mt-1"><?php require MAIN_HOME . 'Public/Views/admin/backups_offsite.php'; ?></div></details>
</section>
<?php LayoutRenderer::renderFooter('admin'); ?>
</body></html>
