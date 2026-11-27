<?php
namespace XcVm\Domain\Assistant;

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Stream\StreamService;
use XcVm\Domain\Vod\MovieService;
use XcVm\Domain\Vod\SeriesService;
use XcVm\Domain\Vod\EpisodeService;

/** Single-item catalog creation through the panel's native services. */
final class MediaTools {
    public function __construct(private string $scope) {}
    private function allowed(string $type): bool {
        global $rPermissions;
        return $this->scope === 'admin' && !empty($rPermissions['is_admin'])
            && Authorization::check('adv', 'add_'.($type === 'live' ? 'stream' : $type));
    }
    public function definitions(): array {
        $types=array_values(array_filter(['live','movie','series','episode'],fn($t)=>$this->allowed($t)));
        if (!$types) return [];
        $id=['type'=>'integer','minimum'=>0];
        $defs=[
            'media_options'=>['Read existing categories, bouquets, eligible streaming servers and series. Use query to filter names (empty lists first 50). Never invent IDs.', ['query'=>['type'=>'string','maxLength'=>100]]],
            'create_media'=>['PROPOSE adding ONE live channel, movie, series or episode. Ask for missing data and explicit category/bouquet/server selections using media_options. Zero category/bouquet means explicitly uncategorized/no bouquet. Series: source_url empty and server_id/series_id/season/episode zero. Episode: choose existing series_id, season >=0 and episode >=1, category_id/bouquet_id zero (inherits series). Other types: series_id/season/episode zero. Source must be user-supplied HTTP(S). Saves without starting streaming or encoding; separate playback action afterwards. Never call until all relevant selections are supplied.', [
                'type'=>['type'=>'string','enum'=>$types], 'name'=>['type'=>'string','minLength'=>1,'maxLength'=>180],
                'source_url'=>['type'=>'string','maxLength'=>2000], 'category_id'=>$id,'bouquet_id'=>$id,'server_id'=>$id,'series_id'=>$id,'season'=>$id,'episode'=>$id,
            ]],
        ];
        $out=[];
        foreach($defs as $name=>[$description,$properties]) $out[]=['type'=>'function','function'=>['name'=>$name,'description'=>$description,'parameters'=>['type'=>'object','properties'=>$properties,'required'=>array_keys($properties),'additionalProperties'=>false]]];
        return $out;
    }
    private function rows(string $sql,mixed ...$args): array {
        global $db;
        if(!$db->query($sql,...$args)) throw new \RuntimeException('DATABASE_ERROR');
        return $db->get_raw_rows();
    }
    public function call(string $name,array $args): array {
        if(!$this->definitions()) throw new \RuntimeException('FORBIDDEN');
        if($name==='media_options') {
            if(array_keys($args)!==['query'] || !is_string($args['query']) || strlen($args['query'])>100) throw new \RuntimeException('INVALID_ARGUMENTS');
            $q=$args['query'];
            $types=[];foreach(['live'=>'live','movie'=>'movie','series'=>'series','episode'=>'series'] as $type=>$category)if($this->allowed($type))$types[]=$category;
            $categories=$this->rows('SELECT id,category_name,category_type FROM streams_categories WHERE LOCATE(?,category_name)>0 ORDER BY id LIMIT 50',$q);
            return ['categories'=>array_values(array_filter($categories,fn($c)=>in_array($c['category_type'],$types,true))),
                'bouquets'=>$this->rows('SELECT id,bouquet_name FROM bouquets WHERE LOCATE(?,bouquet_name)>0 ORDER BY id LIMIT 50',$q),
                'servers'=>$this->rows('SELECT id,server_name FROM servers WHERE enabled=1 AND status=1 AND server_type<>1 AND LOCATE(?,server_name)>0 ORDER BY id LIMIT 50',$q),
                'series'=>$this->allowed('episode')?$this->rows('SELECT id,title FROM streams_series WHERE LOCATE(?,title)>0 ORDER BY id LIMIT 50',$q):[],
                'limit'=>50,'note'=>'Select actual IDs. Zero category/bouquet only with explicit user consent. Proxy servers cannot host content.'];
        }
        if($name!=='create_media')throw new \RuntimeException('FORBIDDEN');
        return ['proposal'=>$this->propose($args)];
    }
    private function propose(array $a): array {
        $keys=['type','name','source_url','category_id','bouquet_id','server_id','series_id','season','episode'];
        if(count($a)!==count($keys)||array_diff(array_keys($a),$keys)||!in_array($a['type']??null,['live','movie','series','episode'],true))throw new \RuntimeException('INVALID_ARGUMENTS');
        if(!$this->allowed($a['type']))throw new \RuntimeException('FORBIDDEN');
        if(!is_string($a['name'])||trim($a['name'])===''||strlen($a['name'])>180||preg_match('/[\x00-\x1f\x7f]/',$a['name'])||!is_string($a['source_url'])||strlen($a['source_url'])>2000)throw new \RuntimeException('INVALID_ARGUMENTS');
        foreach(array_slice($keys,3) as $k)if(!is_int($a[$k])||$a[$k]<0||$a[$k]>2147483647)throw new \RuntimeException('INVALID_ARGUMENTS');
        $a['name']=trim($a['name']);$type=$a['type'];$selected=[];$details=['Type / النوع: '.$type];
        if($type==='series') {
            if($a['source_url']!==''||$a['server_id']!==0)throw new \RuntimeException('INVALID_ARGUMENTS');
        } else {
            $url=parse_url($a['source_url']);
            if(!filter_var($a['source_url'],FILTER_VALIDATE_URL)||!in_array(strtolower($url['scheme']??''),['http','https'],true)||empty($url['host'])||preg_match('/[\x00-\x20\x7f]/',$a['source_url']))throw new \RuntimeException('INVALID_ARGUMENTS');
            $server=$this->rows('SELECT id,server_name FROM servers WHERE id=? AND enabled=1 AND status=1 AND server_type<>1',$a['server_id'])[0]??null;
            if(!$server)throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN');
            $selected[]=$server;$details[]='Server / السيرفر: '.$server['server_name'].' (#'.$server['id'].')';
            $details[]='Source host / مضيف المصدر: '.$url['host'].' — URL fingerprint / بصمة الرابط: '.substr(hash('sha256',$a['source_url']),0,12);
        }
        if($type==='episode') {
            if(!$a['episode']||$a['category_id']||$a['bouquet_id'])throw new \RuntimeException('INVALID_ARGUMENTS');
            $series=$this->rows('SELECT id,title,category_id FROM streams_series WHERE id=?',$a['series_id'])[0]??null;
            if(!$series)throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN');
            if($this->rows('SELECT stream_id FROM streams_episodes WHERE series_id=? AND season_num=? AND episode_num=?',$a['series_id'],$a['season'],$a['episode']))throw new \RuntimeException('MEDIA_EXISTS');
            $selected[]=$series;$details[]='Series / المسلسل: '.$series['title'].' (#'.$series['id'].')';$details[]='Season / الموسم: '.$a['season'].'; Episode / الحلقة: '.$a['episode'];
            $details[]='Inherits series access / الوصول حسب باقات المسلسل';
        } else {
            if($a['series_id']||$a['season']||$a['episode'])throw new \RuntimeException('INVALID_ARGUMENTS');
            if($a['category_id']) {
                $row=$this->rows('SELECT id,category_name,category_type FROM streams_categories WHERE id=? AND category_type=?',$a['category_id'],$type)[0]??null;
                if(!$row)throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN');$selected[]=$row;$details[]='Category / التصنيف: '.$row['category_name'].' (#'.$row['id'].')';
            } else $details[]='No category / بدون تصنيف';
            if($a['bouquet_id']) {
                $row=$this->rows('SELECT id,bouquet_name FROM bouquets WHERE id=?',$a['bouquet_id'])[0]??null;
                if(!$row)throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN');$selected[]=$row;$details[]='Bouquet / الباقة: '.$row['bouquet_name'].' (#'.$row['id'].')';
            } else $details[]='No bouquet: not added to customer packages / بدون باقة: لن يضاف لباقات العملاء';
            $duplicate=$type==='series'?$this->rows('SELECT id FROM streams_series WHERE title=?',$a['name']):$this->rows('SELECT id FROM streams WHERE type=? AND stream_display_name=?',$type==='live'?1:2,$a['name']);
            if($duplicate)throw new \RuntimeException('MEDIA_EXISTS');
        }
        $details[]=$type==='series'?'Creates series metadata only; add episodes separately / إنشاء بيانات المسلسل فقط؛ أضف الحلقات بعدها':'Save only, no automatic start or encoding; playback not verified / حفظ فقط دون تشغيل أو تحويل تلقائي؛ التشغيل غير مُختبر';
        return ['kind'=>'media','arguments'=>$a,'revision'=>hash('sha256',json_encode($selected)), 'public'=>['title'=>'create_media','target'=>$a['name'],'details'=>$details]];
    }
    public function execute(array $proposal): array {
        global $db,$rUserInfo;
        // Serialize assistant creations across sessions; the controller consumes its token first.
        $lock=$this->rows("SELECT GET_LOCK('xc_assistant_media_create',5) AS acquired");
        if((int)($lock[0]['acquired']??0)!==1)throw new \RuntimeException('BUSY');
        $transaction=false;
        try {
            if(!$db->beginTransaction())throw new \RuntimeException('DATABASE_ERROR');
            $transaction=true;
            $fresh=$this->propose($proposal['arguments']);
            if(!hash_equals($fresh['revision'],(string)($proposal['revision']??'')))throw new \RuntimeException('STALE_PROPOSAL');
            $a=$fresh['arguments'];$type=$a['type'];
            $data=array_fill_keys(['cover','backdrop_path','tmdb_id','movie_image','episode_run_time','release_date','youtube_trailer','director','cast','plot','country','genre','rating','stream_icon'],'');
            $data+=['stream_display_name'=>$a['name'],'title'=>$a['name'],'stream_source'=>$type==='live'?[$a['source_url']]:$a['source_url'],
                'category_id'=>$a['category_id']?[$a['category_id']]:[],'bouquets'=>$a['bouquet_id']?[$a['bouquet_id']]:[],
                'server_tree_data'=>json_encode([['id'=>$a['server_id'],'parent'=>'source']]),'target_container'=>'mp4','transcode_profile_id'=>0,
                'series'=>$a['series_id'],'season_num'=>$a['season'],'episode'=>$a['episode']];
            $result=match($type){'live'=>StreamService::process($data),'movie'=>MovieService::process($data),'series'=>SeriesService::process($data),'episode'=>EpisodeService::process($data)};
            $id=(int)($result['data']['insert_id']??0);
            if(($result['status']??null)!==STATUS_SUCCESS||!$id)throw new \RuntimeException('MEDIA_SAVE_FAILED');
            $audit=$db->query("INSERT INTO users_logs (owner,type,action,log_id,package_id,cost,credits_after,date,deleted_info) VALUES (?, 'stream', 'create', ?, NULL, 0, ?, ?, ?)",(int)$rUserInfo['id'],$id,$rUserInfo['credits']??0,time(),json_encode(['source'=>'ai_assistant','media_type'=>$type]));
            if(!$audit || !$db->commit())throw new \RuntimeException('DATABASE_ERROR');
            $transaction=false;
            return ['saved'=>true,'media_created'=>['id'=>$id,'type'=>$type,'name'=>$a['name']],'audit_pending'=>!$audit,'playback_verified'=>false];
        } finally { if($transaction)$db->rollback(); $this->rows("SELECT RELEASE_LOCK('xc_assistant_media_create')"); }
    }
}
