<?php
declare(strict_types=1);
namespace XcVm\Domain\Stream {
 class StreamService { public static array $calls=[]; public static function process($data){self::$calls[]=$data;return ['status'=>1,'data'=>['insert_id'=>20]];} }
}
namespace XcVm\Domain\Vod {
 class MovieService extends \XcVm\Domain\Stream\StreamService {}
 class SeriesService extends \XcVm\Domain\Stream\StreamService {}
 class EpisodeService extends \XcVm\Domain\Stream\StreamService {}
}
namespace {
 require __DIR__.'/../panel/src/Core/Auth/Authorization.php';
 require __DIR__.'/../panel/src/Domain/Assistant/MediaTools.php';
 use XcVm\Domain\Assistant\MediaTools;
 use XcVm\Domain\Stream\StreamService;
 define('STATUS_SUCCESS',1);
 function check($ok,$why){if(!$ok)throw new \RuntimeException($why);}
 function refused($fn,$code){try{$fn();}catch(\RuntimeException $e){check($e->getMessage()===$code,'Wrong refusal: '.$e->getMessage());return;}throw new \RuntimeException('Missing refusal: '.$code);}
 $db=new class {
  public PDO $pdo;private PDOStatement $s;
  public function __construct(){
   $this->pdo=new PDO('sqlite::memory:');$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
   $this->pdo->sqliteCreateFunction('LOCATE',fn($a,$b)=>strpos($b,$a)===false?0:strpos($b,$a)+1);
   $this->pdo->sqliteCreateFunction('GET_LOCK',fn()=>1);$this->pdo->sqliteCreateFunction('RELEASE_LOCK',fn()=>1);
   $this->pdo->exec("CREATE TABLE servers(id INT,server_name TEXT,enabled INT,status INT,server_type INT); INSERT INTO servers VALUES(1,'Main',1,1,0),(2,'Proxy',1,1,1),(3,'Offline',1,0,0);
    CREATE TABLE streams_categories(id INT,category_name TEXT,category_type TEXT); INSERT INTO streams_categories VALUES(1,'Live','live'),(2,'Movies','movie'),(3,'Series','series');
    CREATE TABLE bouquets(id INT,bouquet_name TEXT); INSERT INTO bouquets VALUES(1,'Family');
    CREATE TABLE streams_series(id INT,title TEXT,category_id TEXT); INSERT INTO streams_series VALUES(1,'Series one','[3]');
    CREATE TABLE streams(id INT,stream_display_name TEXT,type INT); INSERT INTO streams VALUES(1,'Existing',1);
    CREATE TABLE streams_episodes(stream_id INT,series_id INT,season_num INT,episode_num INT); INSERT INTO streams_episodes VALUES(1,1,1,1);
    CREATE TABLE users_logs(owner INT,type TEXT,action TEXT,log_id INT,package_id INT,cost INT,credits_after INT,date INT,deleted_info TEXT);");
  }
  public function query($sql,...$args){$this->s=$this->pdo->prepare($sql);return $this->s->execute($args);}
  public function beginTransaction(){return $this->pdo->beginTransaction();}
  public function commit(){return $this->pdo->commit();}
  public function rollback(){return $this->pdo->rollBack();}
  public function get_raw_rows(){return $this->s->fetchAll(PDO::FETCH_ASSOC);}
 };
 $rUserInfo=['id'=>1,'member_group_id'=>3,'credits'=>0];$rPermissions=['is_admin'=>1,'advanced'=>[]];$tools=new MediaTools('admin');
 check((new MediaTools('reseller'))->definitions()===[],'Reseller tools');
 refused(fn()=>(new MediaTools('reseller'))->call('media_options',['query'=>'']),'FORBIDDEN');
 $options=$tools->call('media_options',['query'=>'']);check(count($options['servers'])===1,'Proxy/offline allowed');
 $base=['type'=>'live','name'=>'New live','source_url'=>'https://provider.example/user/SECRET/channel?token=PRIVATE','category_id'=>1,'bouquet_id'=>1,'server_id'=>1,'series_id'=>0,'season'=>0,'episode'=>0];
 $p=$tools->call('create_media',$base)['proposal'];check(StreamService::$calls===[],'Proposal executed');
 check(!str_contains(json_encode($p['public']),'SECRET')&&!str_contains(json_encode($p['public']),'PRIVATE'),'Preview leaked credentials');
 foreach([['server_id'=>2],['server_id'=>3],['category_id'=>2],['bouquet_id'=>999]] as $bad)refused(fn()=>$tools->call('create_media',array_replace($base,$bad)),'NOT_FOUND_OR_FORBIDDEN');
 foreach(['file:///etc/passwd','concat:https://x|file:///etc/passwd',"https://x/\nfoo",''] as $url)refused(fn()=>$tools->call('create_media',array_replace($base,['source_url'=>$url])),'INVALID_ARGUMENTS');
 refused(fn()=>$tools->call('create_media',array_replace($base,['name'=>'Existing'])),'MEDIA_EXISTS');
 refused(fn()=>$tools->call('create_media',$base+['restart_on_edit'=>1]),'INVALID_ARGUMENTS');
 $db->pdo->exec("UPDATE bouquets SET bouquet_name='Changed' WHERE id=1");refused(fn()=>$tools->execute($p),'STALE_PROPOSAL');
 $p=$tools->call('create_media',$base)['proposal'];$rPermissions['advanced']=['add_movie'];refused(fn()=>$tools->execute($p),'FORBIDDEN');$rPermissions['advanced']=[];
 foreach(['live','movie','series','episode'] as $type){
  $a=array_replace($base,['type'=>$type,'name'=>'New '.$type,'category_id'=>['live'=>1,'movie'=>2,'series'=>3,'episode'=>0][$type]]);
  if($type==='series'){$a['source_url']='';$a['server_id']=0;}
  if($type==='episode'){$a['series_id']=1;$a['season']=1;$a['episode']=2;$a['bouquet_id']=0;}
  $proposal=$tools->call('create_media',$a)['proposal'];$result=$tools->execute($proposal);
  check($result['media_created']['type']===$type&&!$result['playback_verified'],'Wrong result');
  $sent=end(StreamService::$calls);check(!isset($sent['edit'])&&!isset($sent['restart_on_edit'])&&!isset($sent['direct_source']),'Unexpected native operation');
  check($sent['stream_source']===($type==='live'?[$a['source_url']]:$a['source_url']),'Source contract');
 }
 $episode=array_replace($base,['type'=>'episode','series_id'=>1,'season'=>1,'episode'=>1,'category_id'=>0,'bouquet_id'=>0]);
 refused(fn()=>$tools->call('create_media',$episode),'MEDIA_EXISTS');
 check((int)$db->pdo->query('SELECT COUNT(*) FROM users_logs')->fetchColumn()===4,'Missing audit');
 echo "assistant media: permissions, choices, duplicates, stale confirmation, privacy and native contracts passed\n";
}
