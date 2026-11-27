<?php
declare(strict_types=1);
// A harmless nonce echo through the complete edge -> origin route. No subscriber,
// admin session, stream startup or database write is involved. Direct clients fail closed.
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$peer=(string)($_SERVER['XC_PEER_ADDR']??'');$proxy=$proxyPeers[$peer]??[];
$origin=(int)\XcVm\Core\Config\ConfigReader::get('server_id');$parents=$proxy['parent_id']??[];
if(is_string($parents))$parents=json_decode($parents,true);
$nonce=$_GET['nonce']??'';$revision=$_SERVER['HTTP_X_HORUS_PROXY_REVISION']??'';
if(($_SERVER['REQUEST_METHOD']??'')!=='GET'||($proxy['server_type']??0)!=1||empty($proxy['enabled'])||!is_array($parents)||!in_array($origin,array_map('intval',$parents),true)
    ||!is_string($nonce)||!preg_match('/^[a-f0-9]{32}\z/',$nonce)||!is_string($revision)||!preg_match('/^[a-f0-9]{64}\z/',$revision)) {http_response_code(404);return;}
echo json_encode(['protocol'=>'horus-origin-v1','origin_id'=>$origin,'nonce'=>$nonce,'revision'=>$revision]);
