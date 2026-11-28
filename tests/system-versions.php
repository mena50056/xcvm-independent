<?php
declare(strict_types=1);
require dirname(__DIR__).'/panel/src/Infrastructure/Database/DatabaseAware.php';
require dirname(__DIR__).'/panel/src/Domain/Assistant/AssistantClient.php';
require dirname(__DIR__).'/panel/src/Core/Diagnostics/SystemVersions.php';
use XcVm\Core\Diagnostics\SystemVersions;
function verifyVersion(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
final class VersionFixtureLanguage { public static function get(string $key): string { return $key; } }
$root = sys_get_temp_dir().'/horus-versions-'.bin2hex(random_bytes(6));
$home = $root.'/panel/src/';
foreach (['config','bin/maxmind','Public/Views/admin','bin/xc_agent','bin/xc_fanout'] as $dir) mkdir($home.$dir,0700,true);
try {
    file_put_contents($root.'/installed.json',json_encode(['version'=>'v0.1.0-lab.11']));
    file_put_contents($home.'config/assistant.json',json_encode(['enabled'=>true,'url'=>'PRIVATE_ENDPOINT','password'=>'SECRET_SENTINEL']));
    file_put_contents($home.'Public/Views/admin/settings.php','custom interface');
    file_put_contents($home.'bin/maxmind/version.json',json_encode(['geolite2_version'=>'stale-placeholder']));
    $facts=['php'=>'8.3.36','mysql'=>'10.6.23-MariaDB','redis'=>'6.0.16','os'=>'Ubuntu 22.04',
        'core'=>['available'=>true,'ext_version'=>'independent-lab-0.1','api'=>1],
        'agent'=>['value'=>'0.16.0-independent.1','state'=>'detected'],'nginx'=>['value'=>'1.18.0','state'=>'detected']];
    $rows=SystemVersions::describe($root,$facts);$map=array_column($rows,null,'id');
    verifyVersion($map['release']['value']==='v0.1.0-lab.11','Installed release must override seed repository metadata');
    verifyVersion($map['core']['state']==='detected'&&str_contains($map['core']['value'],'independent-lab-0.1'),'An independent core is available without xcvm_core');
    verifyVersion($map['nginx']['value']==='1.18.0'&&$map['mysql']['value']==='10.6.23-MariaDB','Actual host versions survive rendering');
    verifyVersion($map['geo-country']['state']==='not_installed'&&$map['geo-country']['value']==='','Missing GeoIP files must not display stale edition metadata');
    verifyVersion($map['assistant']['value']===\XcVm\Domain\Assistant\AssistantClient::MODEL&&$map['assistant']['state']==='configured','Model name is configuration, not a connectivity or release verdict');
    verifyVersion(!str_contains(json_encode($rows),'SECRET_SENTINEL')&&!str_contains(json_encode($rows),'PRIVATE_ENDPOINT'),'Private configuration never enters the inventory');
    file_put_contents($home.'Public/Views/admin/settings.php','new custom interface');
    $changed=array_column(SystemVersions::describe($root,$facts),null,'id');
    verifyVersion($changed['interface']['value']!==$map['interface']['value'],'Fingerprint must track installed modifications');
    unlink($root.'/installed.json');
    verifyVersion(array_column(SystemVersions::describe($root,$facts),null,'id')['release']['state']==='unknown','Do not invent a release when metadata is absent');
    $agent=$home.'bin/xc_agent/xc_agent';
    file_put_contents($agent,"#!/bin/sh\n[ \"\$1\" = version ] || exit 2\nprintf '0.16.0-independent.1\\nfeatures: ignored\\n'\n");chmod($agent,0700);
    $cache=$root.'/cache.json';$probe=SystemVersions::binaries($home,$cache);
    verifyVersion($probe['agent']['value']==='0.16.0-independent.1','Probe agent with its version subcommand');
    verifyVersion($probe['yt-dlp']['state']==='not_installed','Absent optional executable is explicit');
    $cacheTime=filemtime($cache);verifyVersion(SystemVersions::binaries($home,$cache)===$probe&&filemtime($cache)===$cacheTime,'Warm probes reuse the cache');
    file_put_contents($agent,"#!/bin/sh\nprintf '0.17.0-independent.123\\n'\n");clearstatcache();
    verifyVersion(SystemVersions::binaries($home,$cache)['agent']['value']==='0.17.0-independent.123','Replacing an executable invalidates the cache');
    file_put_contents($home.'bin/xc_fanout/xc_fanout',"#!/bin/sh\nexec sleep 10\n");chmod($home.'bin/xc_fanout/xc_fanout',0700);$start=microtime(true);
    verifyVersion(SystemVersions::binaries($home,null)['fanout']['state']==='unknown'&&microtime(true)-$start<3,'A hung version probe has a deadline');
    define('MAIN_HOME',dirname(__DIR__).'/panel/src/');$language=VersionFixtureLanguage::class;
    $rHorusVersions=[['id'=>'php','label'=>'PHP','value'=>'<script>alert(1)</script>','help'=>'versions_runtime_help','icon'=>'code','state'=>'unknown']];
    ob_start();require MAIN_HOME.'Public/Views/admin/settings_versions.php';$html=ob_get_clean();
    verifyVersion(str_contains($html,'&lt;script&gt;alert(1)&lt;/script&gt;')&&!str_contains($html,'<script>alert'),'Version strings render as inert text');
    echo "PASS: real installed metadata, independent core, private fields, interface fingerprint, optional tools, probe command, caching and timeout\n";
} finally {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($root);
}
