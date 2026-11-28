<?php
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\CategoryService;

// Reuse each option list once. Every select still carries all of its saved values for ordinary form submission.
$rGenrePools = [];
foreach (['movie' => 'category_name', 'series' => 'category_name', 'bouquets' => 'bouquet_name'] as $rPool => $rLabelKey) {
    $rRows = $rPool === 'bouquets' ? BouquetService::getAll() : CategoryService::getAllByType($rPool);
    $rGenrePools[$rPool] = $rPool === 'bouquets' ? [] : [['id' => '0', 'text' => $language::get('do_not_use')]];
    foreach ($rRows as $rOption) {
        $rGenrePools[$rPool][] = ['id' => (string) (int) $rOption['id'], 'text' => (string) $rOption[$rLabelKey]];
    }
}
$rGenreLabels = array_map(static fn(array $rOptions): array => array_column($rOptions, 'text', 'id'), $rGenrePools);
$rSelectedOptions = static function (string $rPool, array $rSelected) use ($rGenreLabels): void {
    $rLabels = $rGenreLabels[$rPool];
    if ($rPool !== 'bouquets' && !in_array(0, $rSelected, true)) {
        echo '<option value="0">'.htmlspecialchars($rLabels[0], ENT_QUOTES).'</option>';
    }
    foreach (array_unique($rSelected) as $rValue) {
        echo '<option value="'.(int) $rValue.'" selected>'.htmlspecialchars($rLabels[(int) $rValue] ?? ('#'.(int) $rValue), ENT_QUOTES).'</option>';
    }
};
$rGenreGroups = [
    1 => ['movie', 'genre_', 'bouquet_', 'genre_mapping_movies'],
    2 => ['series', 'genretv_', 'bouquettv_', 'genre_mapping_series'],
];
?>
<p class="text-body-secondary"><?= $language::get('genre_mapping_help') ?></p>
<?php foreach ($rGenreGroups as $rGenreType => [$rPool, $rCategoryPrefix, $rBouquetPrefix, $rHeading]): ?>
<h5 class="card-title mb-4 mt-4"><?= $language::get($rHeading) ?></h5>
<?php foreach (CategoryService::getGenreMap($rGenreType) as $rGenre):
    $rGenreID = (int) $rGenre['genre_id'];
    $rSelectedBouquets = array_map('intval', (array) json_decode((string) $rGenre['bouquets'], true));
?>
<div class="form-group row mb-4">
    <label class="col-md-2 col-form-label" for="<?= $rCategoryPrefix.$rGenreID ?>"><?= htmlspecialchars((string) $rGenre['genre'], ENT_QUOTES) ?></label>
    <div class="col-md-4">
        <select name="<?= $rCategoryPrefix.$rGenreID ?>" id="<?= $rCategoryPrefix.$rGenreID ?>" class="form-control" data-toggle="select2" data-horus-options="<?= $rPool ?>"><?php $rSelectedOptions($rPool, [(int) $rGenre['category_id']]); ?></select>
    </div>
    <label class="col-md-2 col-form-label" for="<?= $rBouquetPrefix.$rGenreID ?>"><?= $language::get('bouquets') ?></label>
    <div class="col-md-4">
        <select name="<?= $rBouquetPrefix.$rGenreID ?>[]" id="<?= $rBouquetPrefix.$rGenreID ?>" class="form-control" data-toggle="select2" data-horus-options="bouquets" multiple data-placeholder="<?= htmlspecialchars($language::get('choose'), ENT_QUOTES) ?>"><?php $rSelectedOptions('bouquets', $rSelectedBouquets); ?></select>
    </div>
</div>
<?php endforeach; endforeach; ?>
<script type="application/json" id="horus-settings-options"><?= json_encode($rGenrePools, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script defer src="./assets/horus/settings-options.js?v=<?= filemtime(MAIN_HOME.'Public/assets/admin/horus/settings-options.js') ?>"></script>
