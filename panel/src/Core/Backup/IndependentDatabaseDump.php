<?php
declare(strict_types=1);
namespace XcVm\Core\Backup;

/** Local transactional SQL exports for the independent runtime. */
final class IndependentDatabaseDump {
    private static function connection(array $config): array {
        $dsn=(string)($config['dsn']??'');$parts=[];
        if($dsn!=='') {
            if(!str_starts_with($dsn,'mysql:'))throw new \InvalidArgumentException('Unsupported database driver');
            foreach(explode(';',substr($dsn,6)) as $part){$pair=explode('=',$part,2);if(count($pair)===2)$parts[$pair[0]]=$pair[1];}
        }
        $name=(string)($parts['dbname']??$config['name']??'');
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new \InvalidArgumentException('Invalid database name');
        if(isset($config['name']) && $name!==$config['name'])throw new \InvalidArgumentException('Database identity mismatch');
        $values=['user'=>(string)($config['user']??''),'password'=>(string)($config['pass']??''),'default-character-set'=>'utf8mb4'];
        if(!empty($parts['unix_socket'])) {
            if(!str_starts_with($parts['unix_socket'],'/'))throw new \InvalidArgumentException('Invalid local socket');
            $values['socket']=$parts['unix_socket'];$values['protocol']='socket';
        } else {
            $host=(string)($parts['host']??$config['host']??'localhost');
            if(!in_array($host,['localhost','127.0.0.1','::1'],true))throw new \InvalidArgumentException('Independent backups require a local database');
            $port=(int)($parts['port']??$config['port']??3306);
            if($port<1||$port>65535)throw new \InvalidArgumentException('Invalid database port');
            $values['host']=$host;$values['port']=(string)$port;
        }
        $options="[client]\n";
        foreach($values as $key=>$value){
            if(str_contains($value,"\0"))throw new \InvalidArgumentException('Invalid database option');
            $value=strtr($value,["\\"=>"\\\\",'"'=>'\\"',"\n"=>'\\n',"\r"=>'\\r',"\t"=>'\\t']);
            $options.=$key.'="'.$value."\"\n";
        }
        return [$name,$options];
    }

    private static function destination(string $filename,string $directory): string {
        if(is_link($directory))throw new \InvalidArgumentException('Backup directory cannot be a symlink');
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new \RuntimeException('Cannot create backup directory');
        $real=realpath($directory);
        if($real===false||realpath(dirname($filename))!==$real||is_link($filename)||file_exists($filename)||!preg_match('/^[A-Za-z0-9_.:-]+\.sql$/D',basename($filename)))throw new \InvalidArgumentException('Refusing backup destination');
        return $real;
    }

    private static function run(array $argv,string $output,string $error): void {
        $proc=proc_open($argv,[0=>['file','/dev/null','r'],1=>['file',$output,'a'],2=>['file',$error,'w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($proc)||proc_close($proc)!==0)throw new \RuntimeException('Database dump process failed');
    }

    public static function create(array $config,string $filename,array $ignore,string $directory): bool {
        $stage=$options=$error=null;
        try {
            $directory=self::destination($filename,$directory);
            [$name,$clientOptions]=self::connection($config);
            $options=tempnam($directory,'.db-client-');$stage=tempnam($directory,'.sql-stage-');$error=tempnam($directory,'.dump-error-');
            foreach([$options,$stage,$error] as $file){if($file===false)throw new \RuntimeException('Cannot stage backup');chmod($file,0600);}
            if(file_put_contents($options,$clientOptions)!==strlen($clientOptions))throw new \RuntimeException('Cannot stage database client options');
            $argv=['/usr/bin/mariadb-dump','--defaults-extra-file='.$options,'--single-transaction','--quick','--skip-lock-tables','--skip-add-locks','--hex-blob','--no-tablespaces','--default-character-set=utf8mb4'];
            foreach($ignore as $table){
                if(!is_string($table)||!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new \InvalidArgumentException('Invalid excluded table');
                $argv[]='--ignore-table-data='.$name.'.'.$table;
            }
            self::run([...$argv,$name],$stage,$error);
            clearstatcache(true,$stage);
            if(!filesize($stage)||file_put_contents($stage,"\n-- HORUS_XC_BACKUP_COMPLETE\n",FILE_APPEND)===false)throw new \RuntimeException('Empty database dump');
            if(!rename($stage,$filename))throw new \RuntimeException('Cannot publish completed backup');
            $stage=null;clearstatcache(true,$filename);return true;
        } catch(\Throwable $e) {
            // Never log the DSN, credentials, command arguments or client stderr.
            error_log('Independent database backup failed ('.get_class($e).').');return false;
        } finally {
            foreach([$stage,$options,$error] as $file)if(is_string($file)&&is_file($file))@unlink($file);
        }
    }
}
