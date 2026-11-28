<?php
declare(strict_types=1);
namespace MaxMind\Db {
    final class Reader {
        private object $metadata;
        public function __construct(string $path) {
            $data=json_decode(file_get_contents($path));
            if (!is_object($data) || !isset($data->databaseType,$data->buildEpoch)) throw new \RuntimeException('Invalid fixture');
            $this->metadata=$data;
        }
        public function metadata(): object { return $this->metadata; }
        public function close(): void {}
    }
}
namespace {
    require dirname(__DIR__).'/panel/src/Cli/CommandInterface.php';
    require dirname(__DIR__).'/panel/src/Cli/Commands/YtDlpCommand.php';
    require dirname(__DIR__).'/panel/src/Core/GeoIP/MaxMindUpdater.php';
    use XcVm\Cli\Commands\YtDlpCommand;
    use XcVm\Core\GeoIP\MaxMindUpdater;
    function checkComponent(bool $ok,string $reason): void { if (!$ok) throw new RuntimeException($reason); }
    checkComponent(YtDlpCommand::releaseAsset('Linux','x86_64')==='yt-dlp_linux','Ubuntu must use a standalone executable independent of its Python version');
    checkComponent(YtDlpCommand::releaseAsset('Linux','aarch64')==='yt-dlp_linux_aarch64','Match the actual architecture');
    checkComponent(YtDlpCommand::releaseAsset('Linux','mips')===null&&YtDlpCommand::releaseAsset('Windows','x86_64')===null,'No incompatible fallback asset');
    $directory=sys_get_temp_dir().'/horus-components-'.bin2hex(random_bytes(6));mkdir($directory,0700);
    try {
        $dest=$directory.'/country.mmdb';$source=$directory.'/candidate.mmdb';file_put_contents($dest,'existing database');
        file_put_contents($source,'not an mmdb');
        checkComponent(MaxMindUpdater::installDatabase($source,$dest,'GeoLite2-Country')===null&&file_get_contents($dest)==='existing database','Corrupt candidate must preserve the database in use');
        $build=1795737600;
        file_put_contents($source,json_encode(['databaseType'=>'GeoIP2-ISP','buildEpoch'=>$build]));
        checkComponent(MaxMindUpdater::installDatabase($source,$dest,'GeoLite2-Country')===null&&file_get_contents($dest)==='existing database','A different edition cannot replace country data');
        file_put_contents($source,json_encode(['databaseType'=>'GeoLite2-Country','buildEpoch'=>$build]));
        checkComponent(MaxMindUpdater::installDatabase($source,$dest,'GeoLite2-Country')===$build&&!file_exists($source),'Valid edition is atomically installed');
        $versions=MaxMindUpdater::editionVersions(['geolite2_version'=>'country edition'],'GeoLite2-City',$build);
        checkComponent($versions['geolite2_version']==='country edition'&&$versions['geolite2c_version']===gmdate('Y-m-d',$build),'City must not rewrite the country edition; record the database build date');
        checkComponent(in_array('GeoIP2-ISP',array_keys(MaxMindUpdater::availableEditions()),true),'Licensed ISP edition is selectable');
        echo "PASS: standalone yt-dlp targets, safe GeoIP replacement, edition identity and independent build dates\n";
    } finally { foreach(glob($directory.'/*') as $file)unlink($file);rmdir($directory); }
}
