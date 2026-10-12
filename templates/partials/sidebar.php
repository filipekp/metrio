<aside class="app-sidebar" id="app-sidebar" aria-label="Hlavní nabídka">
    <a href="?page=dashboard" class="sidebar-brand">
        <span class="brand-mark"><?= ui_icon('bolt') ?></span>
        <span class="brand-text"><strong>Metrio</strong><small>Evidence energií</small></span>
    </a>
    <div class="sidebar-content">
        <?php foreach ($sections as $heading => $items): ?>
            <div class="nav-section">
                <p class="nav-section-title"><?= html($heading) ?></p>
                <nav class="side-nav" aria-label="<?= html($heading) ?>">
                    <?php foreach ($items as $key => $item): ?>
                        <?php $active = ($current === $key || ($current === 'photo_review' && $key === 'readings')); ?>
                        <a href="?page=<?= html($key) ?>"
                           class="side-link<?= $active ? ' active' : '' ?>"
                           <?= $active ? 'aria-current="page"' : '' ?>>
                            <?= ui_icon($item[1]) ?><span><?= html($item[0]) ?></span>
                            <?php if ($active): ?><span class="active-dot"></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="sidebar-foot">
        <div class="sidebar-foot-kicker">SPRÁVA SPOTŘEBY</div>
        <p>Mějte všechny energie pod kontrolou.</p>
        <span class="sidebar-version">Verze <?= html($version) ?></span>
    </div>
</aside>
