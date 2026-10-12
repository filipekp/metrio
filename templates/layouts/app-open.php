<div class="layout-shell" id="layout-shell">
    <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
    <?php require APP_ROOT . '/templates/partials/sidebar.php'; ?>
    <div class="app-workspace">
        <?php require APP_ROOT . '/templates/partials/topbar.php'; ?>
        <main id="main-content" class="workspace-content">
            <?php require APP_ROOT . '/templates/partials/page-heading.php'; ?>
            <?= flash() ?>
