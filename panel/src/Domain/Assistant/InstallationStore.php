<?php
declare(strict_types=1);
namespace XcVm\Domain\Assistant;

/** Durable private state. Passwords never appear in the public projection or model history. */
final class InstallationStore {
    public function __construct(public readonly string $directory) {
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('INSTALL_STORAGE');
    }
    public function lock(string $name) {
        if (!preg_match('/^[a-z0-9_-]{1,80}$/D',$name)) throw new \RuntimeException('INVALID_ARGUMENTS');
        $f=fopen($this->directory.'/'.$name.'.lock','c');if (!$f || !flock($f,LOCK_EX|LOCK_NB)) {if($f)fclose($f);throw new \RuntimeException('BUSY');}chmod($this->directory.'/'.$name.'.lock',0600);return $f;
    }
    public static function unlock($f): void {flock($f,LOCK_UN);fclose($f);}
    public function read(string $id): array {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \RuntimeException('INVALID_ARGUMENTS');
        $p=$this->directory.'/'.$id.'.json';
        if (!is_file($p)) throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN');
        return json_decode((string)file_get_contents($p),true,32,JSON_THROW_ON_ERROR);
    }
    public function write(array $job): void {
        $id=$job['id'];if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new \RuntimeException('INVALID_ARGUMENTS');
        $file=tempnam($this->directory,'.stage-');
        try {chmod($file,0600);$data=json_encode($job,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);if(file_put_contents($file,$data)!==strlen($data)||!rename($file,$this->directory.'/'.$id.'.json'))throw new \RuntimeException('INSTALL_STORAGE');}
        finally {if(is_file($file))unlink($file);}
    }
    public function all(): array {
        $result=[];foreach(glob($this->directory.'/*.json')?:[] as $path)if(preg_match('/^[a-f0-9]{32}\.json$/D',basename($path)))$result[]=$this->read(substr(basename($path),0,32));return $result;
    }
    private function key(): string {
        $lock=$this->lock('key');
        try {$p=$this->directory.'/.secret-key';if(!is_file($p)){ $f=fopen($p,'x');if(!$f)throw new \RuntimeException('INSTALL_STORAGE');chmod($p,0600);fwrite($f,random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));fclose($f);}$key=file_get_contents($p);if(strlen($key)!==SODIUM_CRYPTO_SECRETBOX_KEYBYTES)throw new \RuntimeException('INSTALL_STORAGE');return $key;}
        finally {self::unlock($lock);}
    }
    public function seal(string $password): string {$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);return base64_encode($nonce.sodium_crypto_secretbox($password,$nonce,$this->key()));}
    public function open(string $sealed): string {$data=base64_decode($sealed,true);if($data===false||strlen($data)<40)throw new \RuntimeException('INSTALL_CREDENTIALS');$value=sodium_crypto_secretbox_open(substr($data,24),substr($data,0,24),$this->key());if($value===false)throw new \RuntimeException('INSTALL_CREDENTIALS');return $value;}
}
