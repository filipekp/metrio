<div class="page-heading">
    <div>
        <span class="eyebrow">METRIO / <?= html(mb_strtoupper_fallback($title)) ?></span>
        <h1><?= html($title) ?></h1>
        <p class="page-subtitle"><?= html(page_description($current)) ?></p>
    </div>
    <div class="page-heading-aside">
        <span class="header-date"><?= ui_icon('calendar') ?> <?= html(date('d. m. Y')) ?></span>
    </div>
</div>
