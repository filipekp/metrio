<header class="app-topbar">
    <div class="topbar-left">
        <button type="button" class="menu-toggle" id="menu-toggle"
                aria-label="Otevřít nebo zavřít nabídku" aria-controls="app-sidebar"
                aria-expanded="false"><?= ui_icon('menu') ?></button>
        <span class="topbar-location">Evidence energií</span>
        <span class="topbar-separator">/</span>
        <span class="topbar-page"><?= html($title) ?></span>
    </div>
    <div class="topbar-right">
        <span class="topbar-role"><?= html(role_label($user['role'])) ?></span>
        <span class="user-avatar" aria-hidden="true"><?= html($initial) ?></span>
        <div class="topbar-user">
            <strong><?= html($user['name']) ?></strong>
            <small><?= html(role_label($user['role'])) ?></small>
        </div>
        <form method="post" action="?page=logout" class="logout-form">
            <?= post_token() ?>
            <button type="submit" class="logout-button" title="Odhlásit se"
                    aria-label="Odhlásit se"><?= ui_icon('logout') ?></button>
        </form>
    </div>
</header>
