<?php
/** Regression: already applied migrations must not be re-run after file changes. PHP 7.3 compatible. */
declare(strict_types=1);
require dirname(__DIR__) . '/src/Migrations.php';

final class MigrationMemoryStatement extends PDOStatement {
    private $rows;
    private $callback;
    public function __construct(array $rows = [], $callback = null) {
        $this->rows = $rows;
        $this->callback = $callback;
    }
    public function fetchAll($mode = PDO::FETCH_BOTH, ...$args) {
        return $this->rows;
    }
    public function execute($params = null) {
        if ($this->callback !== null) call_user_func($this->callback, $params);
        return true;
    }
}

final class MigrationMemoryPDO extends PDO {
    public $stored = [];
    public $executed = [];
    public function __construct() {}
    public function exec($sql) {
        // schema_migrations is created by the migration runner; DDL fixtures are recorded.
        if (strpos($sql, 'CREATE TABLE IF NOT EXISTS schema_migrations') === false) {
            $this->executed[] = trim($sql);
        }
        return 0;
    }
    public function query($sql, ...$args) {
        if (strpos($sql, 'FROM schema_migrations') === false) throw new RuntimeException('Unknown query: ' . $sql);
        return new MigrationMemoryStatement(array_values($this->stored));
    }
    public function prepare($sql, $options = []) {
        if (strpos($sql, 'INSERT INTO schema_migrations') === false) throw new RuntimeException('Unknown query: ' . $sql);
        return new MigrationMemoryStatement([], function ($args) {
            $this->stored[$args[0]] = [
                'filename' => $args[0],
                'checksum' => $args[1],
                'applied_at' => '2026-10-12 10:00:00',
            ];
        });
    }
}

function assert_migration($ok, string $what): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $what);
    echo 'OK: ' . $what . PHP_EOL;
}

$dir = sys_get_temp_dir() . '/metrio-migrations-test-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create test directory');
try {
    file_put_contents($dir . '/001_initial.sql', "CREATE TABLE should_not_run (id INT);\n");
    file_put_contents($dir . '/002_new.sql', "CREATE TABLE should_run (id INT);\n");
    $db = new MigrationMemoryPDO();
    // The database remembers the original content, while the disk contains a changed SQL file.
    $originalHash = hash('sha256', 'original SQL version of 001');
    $db->stored['001_initial.sql'] = [
        'filename' => '001_initial.sql', 'checksum' => $originalHash,
        'applied_at' => '2026-10-10 10:00:00',
    ];

    $runner = new Energy\Migrations($db, $dir);
    $status = $runner->status();
    assert_migration($status[0]['status'] === 'CHANGED', 'Modified applied migration is identifiable');
    assert_migration($status[1]['status'] === 'pending', 'New migration is pending');

    $applied = $runner->run();
    assert_migration($applied === ['002_new.sql'], 'Only the new migration runs');
    assert_migration(count($db->executed) === 1 && strpos($db->executed[0], 'should_run') !== false,
        'SQL from an already applied migration is never executed again');
    assert_migration($db->stored['001_initial.sql']['checksum'] === $originalHash,
        'Original checksum stays unchanged for audit');
    assert_migration($db->stored['002_new.sql']['checksum'] === hash_file('sha256', $dir . '/002_new.sql'),
        'New migration checksum is recorded');

    $appliedSecondTime = $runner->run();
    assert_migration($appliedSecondTime === [] && count($db->executed) === 1,
        'A second update never repeats any applied migration');
} finally {
    @unlink($dir . '/001_initial.sql');
    @unlink($dir . '/002_new.sql');
    @rmdir($dir);
}
