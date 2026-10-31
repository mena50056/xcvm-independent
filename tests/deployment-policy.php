<?php
require dirname(__DIR__).'/runtime/bootstrap.php';
$policy=\XcVm\Domain\Cluster\ClusterPolicy::current(
    ['cluster_transport'=>'https_required','cluster_main_host'=>'panel.example.com'],
    ['server_ip'=>'192.0.2.10','enable_https'=>1,'https_broadcast_port'=>443,'http_broadcast_port'=>18080]
);
if($policy['transport']!=='https_required' || $policy['main_urls']!==['https://panel.example.com:443/cluster/v1/'])throw new RuntimeException('MAIN must send the native node a usable HTTPS policy');
echo "PASS native HTTPS cluster endpoint policy\n";
$ipPolicy=\XcVm\Domain\Cluster\ClusterPolicy::current(
    ['cluster_transport'=>'https_required','cluster_main_host'=>''],
    ['server_ip'=>'192.0.2.10','domain_name'=>null,'enable_https'=>1,'https_broadcast_port'=>443,'http_broadcast_port'=>18080]
);
if($ipPolicy['main_urls']!==['https://192.0.2.10:443/cluster/v1/'])throw new RuntimeException('IP installation must publish an HTTPS IP endpoint');
echo "PASS native HTTPS IP endpoint policy\n";
