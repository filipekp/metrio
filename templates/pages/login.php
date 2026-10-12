<?php
    declare(strict_types=1);

// Šablona stránky login. Zobrazuje data připravená controllerem.
    echo '<section class="card narrow"><form method="post">' . post_token() . '<label>E-mail <input type="email" name="email" required autocomplete="username"></label><label>Heslo <input type="password" name="password" required autocomplete="current-password"></label><button>Přihlásit se</button></form></section>';
