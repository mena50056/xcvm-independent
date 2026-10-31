<?php
use XcVm\Core\Util\LayoutRenderer;
$escapeUpdate = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$updateState = $updateStatus['state'] ?? 'pending';
$updateLabels = ['pending' => 'في انتظار أول فحص', 'checking' => 'جارٍ فحص GitHub', 'available' => 'يتوفر تحديث جديد',
    'current' => 'أنت تستخدم أحدث إصدار متاح', 'updating' => 'جارٍ تثبيت التحديث', 'updated' => 'تم التحديث بنجاح', 'error' => 'تعذر إكمال الفحص أو التحديث'];
?>
<div class="card mb-4" dir="rtl">
  <div class="card-body">
    <h4>تحديثات البانل من GitHub</h4>
    <p>الإصدار الحالي: <strong><?= $escapeUpdate($updateStatus['current'] ?? '—') ?></strong></p>
    <p><?= $escapeUpdate($updateLabels[$updateState] ?? $updateState) ?></p>
    <?php if (isset($_GET['queued'])): ?><div class="alert alert-info">تم إرسال الطلب. ستعالجه الخدمة خلال دقيقة؛ حدّث الصفحة لمتابعة النتيجة.</div><?php endif; ?>
    <?php if ($updateError || !empty($updateStatus['error'])): ?><div class="alert alert-danger"><?= $escapeUpdate($updateError ?: $updateStatus['error']) ?></div><?php endif; ?>
    <?php if (!empty($updateStatus['checked_at'])): ?><p>آخر فحص: <?= $escapeUpdate(date('Y-m-d H:i:s', (int) $updateStatus['checked_at'])) ?></p><?php endif; ?>
    <p>الفحص تلقائي كل ساعة. التنصيب يبدأ عند الضغط على «تحديث الآن»، بعد حفظ نسخة احتياطية من الملفات. قد تتوقف واجهة الإدارة لثوانٍ أثناء إعادة تشغيلها.</p>
    <form method="post" action="independent_updates" class="d-flex gap-2">
      <input type="hidden" name="csrf" value="<?= $escapeUpdate($_SESSION['independent_update_csrf']) ?>">
      <input type="hidden" name="version" value="<?= $escapeUpdate($updateStatus['latest'] ?? '') ?>">
      <button class="btn btn-outline-primary" name="update_action" value="check" <?= in_array($updateState, ['checking','updating']) ? 'disabled' : '' ?>>فحص الآن</button>
      <?php if ($updateState === 'available'): ?><button class="btn btn-primary" name="update_action" value="apply">تحديث الآن إلى <?= $escapeUpdate($updateStatus['latest']) ?></button><?php endif; ?>
    </form>
    <?php if (!empty($updateStatus['url']) && preg_match('~^https://github\.com/mena50056/xcvm-independent/releases/tag/v[0-9A-Za-z.-]+$~D', $updateStatus['url'])): ?>
      <p class="mt-3"><a href="<?= $escapeUpdate($updateStatus['url']) ?>" target="_blank" rel="noopener">تفاصيل الإصدار على GitHub</a></p>
    <?php endif; ?>
    <?php if (!empty($updateStatus['notes'])): ?><pre class="mt-3" style="white-space:pre-wrap;max-height:24rem;overflow:auto"><?= $escapeUpdate($updateStatus['notes']) ?></pre><?php endif; ?>
  </div>
</div>
<?php LayoutRenderer::renderFooter('admin'); ?>
