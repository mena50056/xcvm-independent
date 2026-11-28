<?php
declare(strict_types=1);
/** Network-free regression: publish native assignments before dispatch and redact failures. */
$root=dirname(__DIR__);$harness=<<<'PHP'
namespace XcVm\Core\Cluster {
 class StreamVersions {public static function bump($ids){$GLOBALS['order'][]=['publish',$ids];return $GLOBALS['publish'];}}
 class NodeRpc {public static function request($id,$payload,$timeout){$GLOBALS['order'][]=['rpc',$id,$payload];return $GLOBALS['reply'];}}
}
namespace XcVm\Core\Logging {class FileLogger{public static function log(...$args){}}}
namespace XcVm\Domain\Server {class ServerRepository {public static function getAll(){return [1=>['server_online'=>true],8=>['server_online'=>true,'serve_only'=>$GLOBALS['native']]];}}}
namespace XcVm\Infrastructure\Database {class DatabaseFactory{public static function get(){return new class{public function query(...$args){return true;}public function get_rows(){return [['server_id'=>8,'stream_id'=>7]];}};}}}
namespace {
 define('SERVER_ID',1);$ask=json_decode(stream_get_contents(STDIN),true);$GLOBALS['order']=[];$GLOBALS['native']=$ask['native']??true;$GLOBALS['publish']=$ask['publish']??1;$GLOBALS['reply']=$ask['reply']??'{"result":true}';
 require ERROR_FILE;require DISPATCHER_FILE;
 $result=\XcVm\Domain\Stream\StreamControlDispatcher::request(['action'=>'stream','sub'=>$ask['sub']??'start','stream_ids'=>[7,999],'servers'=>[8]]);
 echo json_encode(['result'=>$result,'order'=>$GLOBALS['order']]);
}
PHP;
$harness=str_replace(['ERROR_FILE','DISPATCHER_FILE'],[var_export($root.'/panel/src/Core/Cluster/ControlError.php',true),var_export($root.'/panel/src/Domain/Stream/StreamControlDispatcher.php',true)],$harness);
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$run=function(array $input=[])use($harness){$process=proc_open([PHP_BINARY,'-r',$harness],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);fwrite($pipes[0],json_encode($input));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);check(proc_close($process)===0,$error);return json_decode($out,true,32,JSON_THROW_ON_ERROR);};
$result=$run();check($result['order'][0]===['publish',[7]]&&$result['order'][1][0]==='rpc','Start overtook publication or published an unassigned stream');check($result['result']['accepted']===[['server_id'=>8,'stream_id'=>7]],'Accepted unrelated work');
$result=$run(['publish'=>0]);check(count($result['order'])===1&&$result['result']['errors'][1]['code']==='STREAM_REPLICA_PUBLISH_FAILED','Queued start after publication failure');
$result=$run(['native'=>false]);check(count($result['order'])===1&&$result['order'][0][0]==='rpc','Changed classic node control');
$result=$run(['sub'=>'stop']);check(count($result['order'])===1&&$result['order'][0][0]==='rpc','Stop publishes or restarts streams');
$result=$run(['reply'=>'{"result":false,"error":"STREAM_NOT_ASSIGNED"}']);check($result['result']['errors'][1]['code']==='STREAM_NOT_ASSIGNED','Discarded actionable node failure');
$result=$run(['reply'=>'{"result":false,"error":"https://private.example/?password=secret"}']);check($result['result']['errors'][1]['code']==='REMOTE_CONTROL_FAILED'&&!str_contains(json_encode($result['result']),'password'),'Exposed private process output');
echo "Native stream publication, dispatch ordering, selected assignments, safe errors and classic/stop regression checks passed\n";
