<?php
namespace XcVm\Domain\Assistant;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Proxy\{ProxyProfiles,ProxyProfile,ProxyMonitor};

/** Uses the same validated, revision-locked configuration and audit history as the proxy page. */
final class ProxyTools {
    public function __construct(private string $scope, private ?ProxyProfiles $store=null) {}
    private function allowed(bool $write=false): bool {
        return defined('XCVM_INDEPENDENT_LAB') && $this->scope==='admin' && Authorization::check('adv','servers') && (!$write || Authorization::check('adv','edit_server'));
    }
    private function store(): ProxyProfiles { return $this->store??=ProxyProfiles::app(); }
    public function definitions(): array {
        if (!$this->allowed()) { return []; }
        $id=['type'=>'integer','minimum'=>1];
        $catalog=[
            'list_proxies'=>['List up to 20 proxy IDs and names, with configured and applied enabled state. Use an exact ID; never guess.',[]],
            'proxy_status'=>['Read a proxy configuration and latest monitor snapshot. Missing or stale telemetry is not proof of health. No network probes or changes.',['proxy_id'=>$id]],
            'proxy_parents'=>['List up to 100 available upstream server IDs for proxy routing. Ask the user to choose; never invent targets.',[]],
        ];
        if ($this->allowed(true)) {
            $catalog+= [
                'set_proxy_enabled'=>['PROPOSE enabling or disabling one proxy. Requires separate human confirmation and may interrupt viewers; application is asynchronous.',['proxy_id'=>$id,'enabled'=>['type'=>'boolean']]],
                'set_proxy_limit'=>['PROPOSE one exact numeric limit or timeout requested by the user. Never pick a value yourself. Zero disables per-IP or request-rate limiting. Confirmation required.',['proxy_id'=>$id,'setting'=>['type'=>'string','enum'=>array_keys(ProxyProfile::LIMITS)],'value'=>['type'=>'integer','minimum'=>0]]],
                'set_proxy_ports'=>['PROPOSE replacing both HTTP and HTTPS port lists with user-selected lists. First read current status and retain ports the user did not ask to change. Confirmation required; existing URLs may stop working.',['proxy_id'=>$id,'http_ports'=>['type'=>'array','items'=>$id,'maxItems'=>32],'https_ports'=>['type'=>'array','items'=>$id,'maxItems'=>32]]],
                'set_proxy_parents'=>['PROPOSE replacing the upstream IDs and default upstream with explicit user choices from proxy_parents. Confirmation required; may change routing.',['proxy_id'=>$id,'parent_ids'=>['type'=>'array','items'=>$id,'minItems'=>1,'maxItems'=>32],'default_parent_id'=>$id]],
            ];
        }
        $out=[];foreach($catalog as $name=>[$description,$properties]) {$out[]=['type'=>'function','function'=>['name'=>$name,'description'=>$description,'parameters'=>['type'=>'object','properties'=>$properties?:new \stdClass(),'required'=>array_keys($properties),'additionalProperties'=>false]]];}return $out;
    }
    private function validate(string $name,array $args): void {
        $definition=null;foreach($this->definitions() as $item) {if($item['function']['name']===$name){$definition=$item['function']['parameters'];break;}}
        if (!$definition) { throw new \RuntimeException('FORBIDDEN'); }
        $properties=(array)$definition['properties'];
        if(count($args)!==count($properties)||array_diff(array_keys($args),array_keys($properties))) {throw new \RuntimeException('INVALID_ARGUMENTS');}
        foreach($properties as $key=>$spec) {
            $value=$args[$key];$valid=match($spec['type']) {'integer'=>is_int($value)&&$value>=($spec['minimum']??0),'boolean'=>is_bool($value),'string'=>is_string($value)&&in_array($value,$spec['enum'],true),'array'=>is_array($value)&&array_is_list($value)&&count($value)<=($spec['maxItems']??32)&&count($value)>=($spec['minItems']??0),default=>false};
            if(!$valid)throw new \RuntimeException('INVALID_ARGUMENTS');
            if($spec['type']==='array')foreach($value as $id)if(!is_int($id)||$id<1)throw new \RuntimeException('INVALID_ARGUMENTS');
        }
        if($name==='set_proxy_limit'){[$min,$max]=ProxyProfile::LIMITS[$args['setting']];if($args['value']<$min||$args['value']>$max)throw new \RuntimeException('INVALID_ARGUMENTS');}
    }
    private function document(int $id): array {
        if(!$this->store()->row($id))throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN');
        $doc=$this->store()->document($id);
        if(!isset($doc['profile'],$doc['revision']))throw new \RuntimeException('PROFILE_NOT_CONFIGURED');return $doc;
    }
    private function safeProfile(array $profile): array {
        return array_intersect_key($profile,array_flip(array_merge(['name','enabled','parent_ids','default_parent_id','http_ports','https_ports','prefer_https'],array_keys(ProxyProfile::LIMITS))));
    }
    private function status(int $id): array {
        $doc=$this->document($id);$snapshot=(new ProxyMonitor($this->store()))->snapshot($id);
        // Do not forward last_error, IPs, certificates, tokens or arbitrary raw agent payloads.
        $ports=[];foreach(array_slice($snapshot['ports'],0,32) as $port){$ports[]=array_intersect_key($port,array_flip(['scheme','port','state','code','http_status','tcp_ms','tls_ms','total_ms','certificate_expires_at','checked_at']));}
        $alerts=[];foreach(array_slice($snapshot['alerts'],0,20) as $alert){$alerts[]=array_intersect_key($alert,array_flip(['state','severity','code','opened_at','resolved_at','acknowledged_at']));}
        return ['id'=>$id,'profile'=>$this->safeProfile($doc['profile']),'applied_enabled'=>$snapshot['enabled'],'state'=>$snapshot['status']['state'],'pending'=>$snapshot['pending'],'last_sync'=>$snapshot['status']['last_sync'],'monitor_stale'=>$snapshot['stale'],'last_checked_at'=>$snapshot['last_checked_at'],'metrics'=>$snapshot['metrics'],'ports'=>$ports,'alerts'=>$alerts,'note'=>'Stored snapshot, not a new probe. Saved configuration is not applied until the proxy acknowledges it.'];
    }
    public function call(string $name,array $args): array {
        $this->validate($name,$args);
        try {
            if($name==='list_proxies'){
                $all=$this->store()->proxies();$list=[];
                foreach(array_slice($all,0,20) as $row){$doc=$this->store()->document((int)$row['id']);$list[]=['id'=>(int)$row['id'],'name'=>$row['server_name'],'applied_enabled'=>(bool)$row['enabled'],'configured_enabled'=>$doc['profile']['enabled']??null];}
                return ['proxies'=>$list,'limit'=>20,'total'=>count($all)];
            }
            if($name==='proxy_parents'){$all=$this->store()->parents();return ['parents'=>array_map(static fn($row)=>['id'=>(int)$row['id'],'name'=>$row['server_name'],'enabled'=>(bool)$row['enabled']],array_slice($all,0,100)),'limit'=>100];}
            if($name==='proxy_status')return $this->status($args['proxy_id']);
            return ['proposal'=>$this->propose($name,$args)];
        } catch(\InvalidArgumentException $e){throw new \RuntimeException('INVALID_PROXY_CONFIGURATION');}
    }
    private function propose(string $name,array $args): array {
        $doc=$this->document($args['proxy_id']);$profile=$doc['profile'];
        $changes=match($name){'set_proxy_enabled'=>['enabled'=>$args['enabled']],'set_proxy_limit'=>[$args['setting']=>$args['value']],'set_proxy_ports'=>['http_ports'=>$args['http_ports'],'https_ports'=>$args['https_ports']],'set_proxy_parents'=>['parent_ids'=>$args['parent_ids'],'default_parent_id'=>$args['default_parent_id']]};
        $next=ProxyProfile::normalize(array_replace($profile,$changes),array_map(static fn($row)=>(int)$row['id'],$this->store()->parents()));
        $details=[];foreach($changes as $key=>$value){$details[]=$key.': '.json_encode($profile[$key]??null).' → '.json_encode($next[$key]);}
        $details[]='قد يؤثر على المشاهدين وروابط التشغيل / May affect viewers and playback URLs';
        $details[]='يُحفظ أولًا وينتظر تأكيد التطبيق من البروكسي / Saved first; awaits proxy acknowledgement';
        return ['kind'=>'proxy','tool'=>$name,'arguments'=>$args,'revision'=>$doc['revision'],'public'=>['title'=>$name,'target'=>$profile['name'].' (#'.$args['proxy_id'].')','details'=>$details]];
    }
    public function execute(array $proposal): array {
        global $rUserInfo;
        $name=$proposal['tool']??'';$args=$proposal['arguments']??[];$this->validate($name,$args);
        if(!str_starts_with($name,'set_proxy_')||!$this->allowed(true))throw new \RuntimeException('FORBIDDEN');
        try {
            $fresh=$this->propose($name,$args);
            if(!hash_equals($fresh['revision'],(string)($proposal['revision']??'')))throw new \RuntimeException('STALE_PROPOSAL');
            $doc=$this->document($args['proxy_id']);
            if(!hash_equals($doc['revision'],$fresh['revision']))throw new \RuntimeException('STALE_PROPOSAL');
            $changes=match($name){'set_proxy_enabled'=>['enabled'=>$args['enabled']],'set_proxy_limit'=>[$args['setting']=>$args['value']],'set_proxy_ports'=>['http_ports'=>$args['http_ports'],'https_ports'=>$args['https_ports']],'set_proxy_parents'=>['parent_ids'=>$args['parent_ids'],'default_parent_id'=>$args['default_parent_id']]};
            $saved=$this->store()->save($args['proxy_id'],array_replace($doc['profile'],$changes),$proposal['revision'],['id'=>(int)$rUserInfo['id'],'name'=>(string)$rUserInfo['username'] ,'source'=>'assistant'],'assistant');
        }catch(\InvalidArgumentException $e){throw new \RuntimeException('INVALID_PROXY_CONFIGURATION');}
        catch(\RuntimeException $e){if($e->getMessage()==='PROFILE_CONFLICT')throw new \RuntimeException('STALE_PROPOSAL');throw $e;}
        $pending=false;
        try {ProxyProfiles::refreshRouting();\XcVm\Core\Events\EventDispatcher::dispatch(new \XcVm\Core\Events\Server\ServerSavedEvent([]));}catch(\Throwable $e){$pending=true;}
        return ['saved'=>true,'proxy_pending'=>true,'signal_pending'=>$pending,'proxy_id'=>$args['proxy_id'],'revision'=>$saved['revision']];
    }
}
