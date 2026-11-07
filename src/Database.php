<?php
declare(strict_types=1);
namespace HomeAlone;
use PDO;
final class Database {
    public PDO $pdo;
    private int $depth = 0;
    private bool $mysql = false;
    public function __construct(string|PDO $path) {
        if($path instanceof PDO){
            if($path->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new \InvalidArgumentException('Expected MySQL PDO.');
            $this->mysql=true;
            $this->pdo=$path;
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
            $this->pdo->exec("SET time_zone='+00:00'");
            $this->run('SET @rendora_today=?',[self::today()]);
            return;
        }
        if ($path !== ':memory:' && !is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
        $this->pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $this->pdo->sqliteCreateFunction('business_today',static fn()=>self::today(),0);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000; PRAGMA journal_mode=DELETE;');
    }
    public function all(string $sql, array $args=[]): array { $s=$this->prepare($sql); $s->execute($args); return $s->fetchAll(); }
    public function one(string $sql, array $args=[]): ?array { return $this->all($sql,$args)[0]??null; }
    public function run(string $sql, array $args=[]): int { $s=$this->prepare($sql); $s->execute($args); return $s->rowCount(); }
    private function prepare(string $sql): \PDOStatement {
        if($this->mysql)$sql=str_replace('business_today()','@rendora_today',$sql);
        return $this->pdo->prepare($sql);
    }
    public function engine(): string { return $this->mysql?'mysql':'sqlite'; }
    public function insert(string $table,array $data): array {
        $data=['id'=>self::id(),...$data]; $cols=array_keys($data);
        $this->run('INSERT INTO '.$table.' ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')',array_values($data)); return $data;
    }
    public function transaction(callable $fn): mixed {
        if($this->depth>0) return $fn();
        $this->pdo->exec($this->mysql?'START TRANSACTION':'BEGIN IMMEDIATE'); $this->depth++;
        try {
            // Mirror SQLite's single-writer transaction while the MySQL trial is isolated.
            if($this->mysql&&!$this->one('SELECT id FROM trial_writer_lock WHERE id=1 FOR UPDATE')){
                throw new \RuntimeException('MySQL trial writer lock is missing.');
            }
            $value=$fn(); $this->pdo->exec('COMMIT'); return $value; } catch(\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; } finally { $this->depth--; }
    }
    public function migrate(): void {
        if($this->mysql)throw new \LogicException('Apply the MySQL trial schema separately.');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        foreach(glob(dirname(__DIR__).'/database/migrations/*.sql') as $path) {
            if($this->one('SELECT name FROM migrations WHERE name=?',[basename($path)])) continue;
            $this->transaction(function()use($path){$this->pdo->exec(file_get_contents($path));$this->run('INSERT INTO migrations(name) VALUES(?)',[basename($path)]);});
        }
    }
    public static function id(): string { return bin2hex(random_bytes(12)); }
    public static function today(): string { return (new \DateTimeImmutable('now',new \DateTimeZone('Europe/Lisbon')))->format('Y-m-d'); }
}
