<?php
declare(strict_types=1);
// Compatibility entrypoint: web deployment remains protected by admin login + CSRF in index.php.
header('Location: index.php?page=update',true,302);
