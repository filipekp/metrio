<?php
declare(strict_types=1);
namespace Energy;
use PDO;
use RuntimeException;

final class Migrations {
    /** @var PDO */
    private $db;
    /** @var string */
    private $dir;
    public function __construct(PDO $db, string $dir) {
        $this->db = $db;
        $this->dir = $dir;
    }
    public function status(): array {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (filename VARCHAR(190) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $rows=$this->db->query('SELECT filename,checksum,applied_at FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
        $done=array_column($rows,null,'filename'); $result=[];
        foreach ($this->files() as $file) {
            $name=basename($file); $hash=hash_file('sha256',$file);
            $row=$done[$name]??null;
            $result[]=['name'=>$name,'status'=>!$row?'pending':(hash_equals((string)$row['checksum'],(string)$hash)?'applied':'CHANGED'),'at'=>$row['applied_at']??null];
        }
        return $result;
    }
    /**
     * Zaznam v schema_migrations znamena, ze migrace jiz probehla.
     * Pozdejsi zmena SQL souboru se muze zobrazit v --status, ale nesmi
     * znovu spustit migraci ani zablokovat aktualizaci. Pro skutecne zmeny
     * schematu je nutne vytvorit novou migraci s novym cislem.
     */
    public function run(): array {
        $lock=fopen(sys_get_temp_dir().'/energie_migration_'.sha1($this->dir).'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Migrace již probíhají.');
        $applied=[];
        try {
            // Stav overujeme az pod zamkem, aby soubezne spusteni neprovedlo
            // stejnou cekajici migraci dvakrat.
            $status=$this->status();
            foreach($status as $row) {
                // 'applied' i 'CHANGED' jsou jiz zapsane v schema_migrations.
                // Jejich SQL uz znovu nikdy nespoustime.
                if($row['status']!=='pending') continue;
                $path=$this->dir.'/'.$row['name']; $data=file_get_contents($path);
                if($data===false) throw new RuntimeException('Nelze číst migraci: '.$path);
                // Jednoduché DDL migrace: žádné procedury ani středníky uvnitř SQL literálů.
                $data=preg_replace('/^\s*--.*$/m','',$data);
                try {
                    foreach (preg_split('/;\s*(?:\r?\n|$)/', (string)$data) ?: [] as $statement) {
                        if(trim($statement)!=='') $this->db->exec(trim($statement));
                    }
                    $stmt=$this->db->prepare('INSERT INTO schema_migrations (filename,checksum) VALUES (?,?)');
                    $stmt->execute([$row['name'],hash_file('sha256',$path)]);
                    $applied[]=$row['name'];
                } catch (\Throwable $e) {
                    throw new RuntimeException('Migrace '.$row['name'].' selhala. MariaDB DDL nelze vždy vrátit zpět; ověřte stav databáze. '.$e->getMessage(),0,$e);
                }
            }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
        return $applied;
    }
    private function files(): array { $files=glob($this->dir.'/[0-9]*_*.sql') ?: []; sort($files,SORT_STRING); return $files; }
}
