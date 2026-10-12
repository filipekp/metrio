<?php
declare(strict_types=1);

// Jediný veřejný vstupní bod aplikace.
require dirname(__DIR__) . '/src/bootstrap.php';
require APP_ROOT . '/src/Http/Router.php';
metrio_dispatch();
