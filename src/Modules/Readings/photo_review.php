<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
return ['p'=>photo_for((int)($_GET['id']??0),$u)];
