<?php
$rHorusVersions ??= \XcVm\Core\Diagnostics\SystemVersions::collect();
$escapeVersion = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$versionText = static fn(string $key): string => str_starts_with($key, 'versions_') ? $language::get($key) : $key;
?>
<link rel="stylesheet" href="./assets/horus/settings-versions.css?v=<?= filemtime(MAIN_HOME.'Public/assets/admin/horus/settings-versions.css') ?>">
<section class="horus-versions" aria-labelledby="horus-versions-title">
    <div class="horus-versions-heading">
        <span class="horus-versions-mark" aria-hidden="true"><i class="icon-base ti tabler-stack-2"></i></span>
        <div><h4 id="horus-versions-title"><?= $escapeVersion($language::get('versions_horus')) ?></h4>
            <p><?= $escapeVersion($language::get('versions_intro')) ?></p></div>
    </div>
    <div class="horus-versions-grid">
        <?php foreach ($rHorusVersions as $component):
            $state = in_array($component['state'], ['detected','configured','disabled','unknown','not_installed'], true) ? $component['state'] : 'unknown';
        ?>
        <article class="horus-version-card" data-version-id="<?= $escapeVersion($component['id']) ?>">
            <div class="horus-version-top"><i class="icon-base ti tabler-<?= $escapeVersion($component['icon']) ?>" aria-hidden="true"></i>
                <span class="horus-version-state is-<?= $state ?>"><?= $escapeVersion($language::get('versions_state_'.$state)) ?></span></div>
            <h5><?= $escapeVersion($versionText($component['label'])) ?></h5>
            <div class="horus-version-value" dir="auto"><?= $component['value'] !== '' ? $escapeVersion($component['value']) : $escapeVersion($language::get('versions_state_'.$state)) ?></div>
            <p><?= $escapeVersion($language::get($component['help'])) ?></p>
        </article>
        <?php endforeach; ?>
    </div>
</section>
