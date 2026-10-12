<?php
declare(strict_types=1);

// Controller: načtení dat a kontrola vstupů před vykreslením šablony.
return ['buildings'=>all('SELECT * FROM buildings ORDER BY name')];
