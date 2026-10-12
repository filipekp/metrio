<?php
declare(strict_types=1);
namespace Energy;
use RuntimeException;
use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/** GitHub Release updater; use from CLI or authenticated admin POST only. */
final class Updater {
    private string $repo;
    public function __construct(private string $root) {
        $this->root=rtrim($root,'/');
        $this->repo=\config('GITHUB_REPOSITORY');
        if(!preg_match('~^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$~D',$this->repo) || str_starts_with($this->repo,'YOUR_'))
            throw new RuntimeException('Nastavte GITHUB_REPOSITORY v .env (např. uzivatel/evidence-energii).');
    }
    public function installed(): string { return trim((string)@file_get_contents($this->root.'/VERSION')); }
    private function fetch(string $url, ?string $target=null): string {
        if(!function_exists('curl_init')) throw new RuntimeException('Je potřeba rozšíření PHP cURL.');
        $host=(string)parse_url($url,PHP_URL_HOST);
        if(parse_url($url,PHP_URL_SCHEME)!=='https' || !in_array($host,['api.github.com','github.com'],true))
            throw new RuntimeException('Neplatný zdroj aktualizace.');
        $ch=curl_init($url);
        $fh=$target===null?null:fopen($target,'wb');
        if($target!==null && $fh===false) throw new RuntimeException('Nelze vytvořit stažený soubor.');
        curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_RETURNTRANSFER=>$target===null,
            CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>120,CURLOPT_HTTPHEADER=>['User-Agent: Energie-Updater','Accept: application/vnd.github+json'],
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS]);
        if($fh) curl_setopt($ch,CURLOPT_FILE,$fh);
        $out=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);
        curl_close($ch);if($fh) fclose($fh);
        if($out===false || $status<200 || $status>=300) throw new RuntimeException('GitHub požadavek selhal: HTTP '.$status.' '.$error);
        return is_string($out)?$out:'';
    }
    public function latest(): array {
        $data=json_decode($this->fetch('https://api.github.com/repos/'.$this->repo.'/releases/latest'),true);
        if(!is_array($data) || !preg_match('/^v?\d+\.\d+\.\d+$/D',(string)($data['tag_name']??'')))
            throw new RuntimeException('Neplatná verze posledního GitHub Release.');
        $tag=(string)$data['tag_name'];$zip=null;$checksum=null;
        foreach(($data['assets']??[]) as $asset) {
            if(($asset['name']??'')==='evidence-energii-'.$tag.'.zip') $zip=$asset;
            if(($asset['name']??'')==='evidence-energii-'.$tag.'.zip.sha256') $checksum=$asset;
        }
        if(!$zip || !$checksum) throw new RuntimeException('Release neobsahuje ZIP a kontrolní součet vytvořený CI workflow.');
        foreach([$zip,$checksum] as $asset) {
            $url=(string)($asset['browser_download_url']??'');
            if(!str_starts_with($url,'https://github.com/'.$this->repo.'/releases/download/'.rawurlencode($tag).'/'))
                throw new RuntimeException('Release obsahuje neočekávanou adresu balíčku.');
        }
        return ['tag'=>$tag,'version'=>ltrim($tag,'v'),'zip'=>$zip,'checksum'=>$checksum,
                'notes'=>(string)($data['body']??'')];
    }
    public function isNewer(array $release): bool {
        $version=ltrim($this->installed(),'v');
        if(!preg_match('/^\d+\.\d+\.\d+$/D',$version)) return true;
        return version_compare($release['version'],$version,'>');
    }
    public function deploy(): array {
        if(!class_exists(ZipArchive::class)) throw new RuntimeException('Chybí PHP ZipArchive.');
        $lockPath=$this->root.'/storage/deploy.lock';$lock=fopen($lockPath,'c');
        if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Již probíhá aktualizace.');
        $run=$this->root.'/storage/deploy-backups/'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
        $stage=$run.'/stage';$backup=$run.'/previous';$changed=[];$created=[];
        try {
            $release=$this->latest();
            if(!$this->isNewer($release)) return ['status'=>'unchanged','tag'=>$release['tag']];
            $this->ensure($stage);$this->ensure($backup);
            $zipPath=$run.'/release.zip';
            $this->fetch((string)$release['zip']['browser_download_url'],$zipPath);
            $checksumText=$this->fetch((string)$release['checksum']['browser_download_url']);
            $digest=hash_file('sha256',$zipPath);
            if(!preg_match('/^([a-f0-9]{64})\s+/i',trim($checksumText),$matches) || !hash_equals(strtolower($matches[1]),$digest))
                throw new RuntimeException('SHA-256 release balíčku nesouhlasí.');
            $apiDigest=(string)($release['zip']['digest']??'');
            if($apiDigest!=='' && !hash_equals('sha256:'.$digest,$apiDigest)) throw new RuntimeException('GitHub digest nesouhlasí.');
            $zip=new ZipArchive(); if($zip->open($zipPath)!==true) throw new RuntimeException('Neplatný ZIP.');
            try { $this->extractSafe($zip,$stage); } finally { $zip->close(); }
            foreach(['public/index.php','src/bootstrap.php','migrations/001_initial.sql','bin/migrate.php','VERSION'] as $required)
                if(!is_file($stage.'/'.$required)) throw new RuntimeException('Balíčku chybí soubor '.$required);
            if(trim((string)file_get_contents($stage.'/VERSION'))!==$release['tag']) throw new RuntimeException('VERSION neodpovídá tagu.');
            // Neprovádíme synchronizační mazání: konfigurace a nahrané fotografie zůstávají netknuté.
            $paths=['public','src','migrations','bin','VERSION','README.md','.env.example'];
            foreach($paths as $path) {
                if(is_dir($stage.'/'.$path)) {
                    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage.'/'.$path,FilesystemIterator::SKIP_DOTS)) as $file) {
                        if(!$file->isFile() || $file->isLink()) throw new RuntimeException('Balíček obsahuje nepovolený soubor.');
                        $rel=substr($file->getPathname(),strlen($stage)+1);
                        $this->copyWithBackup($file->getPathname(),$rel,$backup,$changed,$created);
                    }
                } elseif(is_file($stage.'/'.$path)) $this->copyWithBackup($stage.'/'.$path,$path,$backup,$changed,$created);
            }
            // DDL migrace mohou být v MariaDB nevratné: před produkční aktualizací zálohujte také DB.
            $migrated=(new Migrations(\db(),$this->root.'/migrations'))->run();
            file_put_contents($run.'/result.json',json_encode(['tag'=>$release['tag'],'migrated'=>$migrated,'time'=>date(DATE_ATOM)],JSON_PRETTY_PRINT));
            return ['status'=>'updated','tag'=>$release['tag'],'migrations'=>$migrated,'backup'=>$backup];
        } catch (\Throwable $e) {
            // Vrátíme soubory; databázové migrace z důvodu DDL mohou vyžadovat obnovu ze zálohy.
            foreach(array_reverse($created) as $rel) @unlink($this->root.'/'.$rel);
            foreach(array_reverse($changed) as $rel) {
                $dst=$this->root.'/'.$rel;$src=$backup.'/'.$rel;
                if(is_file($src)) @copy($src,$dst);
            }
            throw $e;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    private function ensure(string $dir): void {
        if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Nelze vytvořit: '.$dir);
    }
    private function extractSafe(ZipArchive $zip,string $stage): void {
        if($zip->numFiles>2500) throw new RuntimeException('Příliš mnoho souborů v balíčku.');
        $total=0;
        for($i=0;$i<$zip->numFiles;$i++) {
            $s=$zip->statIndex($i);$path=(string)$s['name'];$size=(int)$s['size'];$total+=$size;
            if($total>100*1024*1024 || $size>20*1024*1024) throw new RuntimeException('Balíček je příliš velký.');
            if(str_contains($path,'\\') || str_contains($path,'..') || str_starts_with($path,'/') || str_contains($path,':') || str_contains($path,"\0"))
                throw new RuntimeException('Nebezpečná cesta v ZIP.');
            if(!preg_match('~^(?:public/|src/|migrations/|bin/|VERSION$|README\.md$|\.env\.example$)~D',$path))
                throw new RuntimeException('Neočekávaný soubor balíčku: '.$path);
            $opsys=0;$attributes=0;
            if($zip->getExternalAttributesIndex($i,$opsys,$attributes)) {
                $mode=($attributes>>16)&0170000;
                if($mode===0120000) throw new RuntimeException('Symlinky nejsou povoleny.');
            }
            $dest=$stage.'/'.$path;
            if(str_ends_with($path,'/')) { $this->ensure($dest);continue; }
            $this->ensure(dirname($dest));
            $stream=$zip->getStream($path);$out=fopen($dest,'wb');
            if(!$stream || !$out) throw new RuntimeException('Nejde rozbalit '.$path);
            stream_copy_to_stream($stream,$out);fclose($stream);fclose($out);
            if(filesize($dest)!==$size) throw new RuntimeException('Nekompletní archiv: '.$path);
        }
    }
    private function copyWithBackup(string $from,string $rel,string $backup,array &$changed,array &$created): void {
        $target=$this->root.'/'.$rel;
        $this->ensure(dirname($target));
        if(is_file($target)) {
            $this->ensure(dirname($backup.'/'.$rel));
            if(!copy($target,$backup.'/'.$rel)) throw new RuntimeException('Nelze zálohovat '.$rel);
            $changed[]=$rel;
        } else $created[]=$rel;
        if(!copy($from,$target)) throw new RuntimeException('Nelze aktualizovat '.$rel);
    }
}
