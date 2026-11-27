<?php
namespace XcVm\Domain\Assistant;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Proxy\{ProxyProfiles,ProxyProfile,ProxyMonitor,ProxyOperations};

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
                'diagnose_proxy'=>['Run bounded native proxy probes and explain observed faults, unknowns and recommended actions. No restart or configuration changes.',['proxy_id'=>$id]],
                'restart_proxy'=>['PROPOSE restarting only the proxy nginx service after diagnosis. Reason recovery requires an observed restart-relevant fault; maintenance is only for an explicit user restart request. Show evidence and viewer interruption warning; separate human confirmation required.',['proxy_id'=>$id,'reason'=>['type'=>'string','enum'=>['recovery','maintenance']]]],
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
        return ['id'=>$id,'profile'=>$this->safeProfile($doc['profile']),'applied_enabled'=>$snapshot['enabled'],'state'=>$snapshot['status']['state'],'pending'=>$snapshot['pending'],'last_sync'=>$snapshot['status']['last_sync'],'monitor_stale'=>$snapshot['stale'],'last_checked_at'=>$snapshot['last_checked_at'],'operation'=>(new ProxyOperations($this->store()))->state($id),'metrics'=>$snapshot['metrics'],'ports'=>$ports,'alerts'=>$alerts,'note'=>'Stored snapshot, not a new probe. Saved configuration is not applied until the proxy acknowledges it.'];
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
            if($name==='diagnose_proxy')return $this->diagnose($args['proxy_id']);
            if($name==='restart_proxy')return ['proposal'=>$this->restartProposal($args)];
            if($name==='proxy_status')return $this->status($args['proxy_id']);
            return ['proposal'=>$this->propose($name,$args)];
        } catch(\InvalidArgumentException $e){throw new \RuntimeException('INVALID_PROXY_CONFIGURATION');}
    }
    private function diagnose(int $id): array {
        $this->document($id);$probeFailed=false;
        try {(new ProxyMonitor($this->store()))->run($id,true);}catch(\Throwable $e){$probeFailed=true;}
        $status=$this->status($id);$findings=[];$codes=[];
        $add=static function($code,$ar,$en)use(&$findings,&$codes){$codes[]=$code;$findings[]=['code'=>$code,'ar'=>$ar,'en'=>$en];};
        if(!$status['profile']['enabled']||!$status['applied_enabled'])$add('DISABLED','البروكسي معطل؛ راجع التفعيل قبل إعادة التشغيل.','Proxy is disabled; review enabled state before restarting.');
        if(!$status['last_sync']||$status['last_sync']<time()-30)$add('OFFLINE','لا توجد مزامنة حديثة. افحص خدمة الوكيل واتصاله بالبانل؛ لا يمكن تأكيد وصول أمر إعادة التشغيل.','No recent sync. Check the agent and its connection to MAIN; a restart cannot be reliably delivered.');
        if($status['pending'])$add('PENDING','الإعداد المحفوظ لم يؤكد البروكسي تطبيقه. راجع الإعداد أو خطأ التطبيق أولًا.','Saved configuration is not acknowledged. Review configuration/application errors first.');
        if($status['state']==='failed')$add('APPLY_FAILED','البروكسي أبلغ بفشل تطبيق الإعداد؛ إعادة التشغيل لا تصلح إعدادًا غير صالح.','The proxy reported a configuration apply failure; restarting does not repair invalid configuration.');
        if($probeFailed||$status['monitor_stale']||!$status['ports']||count(array_filter($status['ports'],static fn($p)=>in_array($p['state'],['not_checked','disabled','stale'],true))))$add('UNKNOWN','الفحص الحالي غير مكتمل أو غير متاح؛ البيانات القديمة ليست إثباتًا لسلامة الخدمة.','Current probes are incomplete or unavailable; old telemetry is not proof of health.');
        $labels=[
            'TLS_FAILED'=>['فشل التحقق من TLS؛ راجع الشهادة واسم النطاق والثقة.','TLS verification failed; review certificate, hostname and trust.'],
            'CONNECT_FAILED'=>['تعذر الاتصال بالمنفذ؛ قد تكون الخدمة متوقفة أو المنفذ محجوبًا.','Port connection failed; the service may be stopped or the port blocked.'],
            'TIMEOUT'=>['انتهت مهلة الفحص؛ راجع الشبكة والحمل والخدمة.','Probe timed out; review network, load and service.'],
            'UPSTREAM_FAILED'=>['السيرفر الخلفي أعاد خطأ؛ افحص اتصاله وحالة البث.','Upstream returned an error; check upstream connectivity and streaming.'],
            'HTTP_REJECTED'=>['الطلب مرفوض؛ راجع سياسة الوصول والتوجيه.','Request rejected; review access policy and routing.'],
            'PROBE_UNAVAILABLE'=>['نقطة الفحص غير متاحة؛ قد يحتاج الوكيل تحديثًا.','Probe endpoint is unavailable; the agent may need updating.'],
            'ORIGIN_MISMATCH'=>['الرد من سيرفر خلفي مختلف؛ راجع التوجيه.','Response origin differs; review routing.'],
            'REVISION_MISMATCH'=>['نسخة الإعداد المستجيبة مختلفة؛ راجع التطبيق والمزامنة.','Responding configuration revision differs; review sync/application.'],
            'INVALID_RESPONSE'=>['رد غير متوقع؛ راجع الخدمة ومسار الاتصال.','Unexpected response; review the service and network path.'],
        ];
        $healthy=0;
        foreach($status['ports'] as $port){
            if($port['state']==='healthy'){$healthy++;continue;}
            if(!in_array($port['state'],['failed','unsupported'],true))continue;
            [$ar,$en]=$labels[$port['code']]??['الفحص لم ينجح؛ يلزم مراجعة الحالة.','Probe did not succeed; review its state.'];
            $add($port['code'],$port['scheme'].':'.$port['port'].' — '.$ar,$port['scheme'].':'.$port['port'].' — '.$en);
        }
        if(!$findings&&$healthy>0)$add('HEALTHY','الفحوص المتاحة سليمة الآن؛ هذا ليس اختبار مشاهدة لكل العملاء.','Available probes are healthy now; this is not a playback test for every customer.');
        if(!$status['operation']['supported'])$add('RESTART_UNAVAILABLE','إعادة التشغيل تتطلب وكيلًا محدثًا ومتصلًا يدعم أوامر الخدمة.','Restart requires a connected, updated agent supporting service operations.');
        $canRecover=!$probeFailed&&!$status['monitor_stale']&&count(array_intersect($codes,['CONNECT_FAILED','TIMEOUT','INVALID_RESPONSE']))>0;
        return ['proxy_id'=>$id,'findings'=>$findings,'codes'=>array_values(array_unique($codes)),'can_recover'=>$canRecover,'status'=>$status,'checked_at'=>time(),
            'report_ar'=>'تشخيص البروكسي #'.$id."\n".implode("\n",array_column($findings,'ar'))."\nلم تتم إعادة التشغيل. أي إجراء يحتاج تأكيدًا منفصلًا.",
            'report_en'=>'Proxy diagnosis #'.$id."\n".implode("\n",array_column($findings,'en'))."\nNo restart was performed. Any action requires separate confirmation."];
    }
    private function restartProposal(array $args): array {
        $diagnosis=$this->diagnose($args['proxy_id']);$s=$diagnosis['status'];$doc=$this->document($args['proxy_id']);
        if(!$s['operation']['supported']||!$s['profile']['enabled']||!$s['applied_enabled']||$s['pending']||!$s['last_sync']||$s['last_sync']<time()-30)throw new \RuntimeException('PROXY_RESTART_UNAVAILABLE');
        if($args['reason']==='recovery'&&!$diagnosis['can_recover'])throw new \RuntimeException('PROXY_RESTART_NOT_INDICATED');
        $reason=$args['reason']==='maintenance'?'إعادة تشغيل بناءً على طلبك الصريح؛ ليست إصلاحًا مضمونًا / Explicit maintenance request, not a guaranteed repair':'محاولة استعادة الخدمة بسبب فشل الاتصال أو الاستجابة / Recovery attempt after a connection/response failure';
        return ['kind'=>'proxy','tool'=>'restart_proxy','arguments'=>$args,'revision'=>$doc['revision'],'evidence'=>$diagnosis['codes'],'checked_at'=>$diagnosis['checked_at'],
            'public'=>['title'=>'restart_proxy','target'=>$doc['profile']['name'].' (#'.$args['proxy_id'].')','details'=>array_merge([$reason],array_map(static fn($f)=>$f['ar'].' / '.$f['en'],$diagnosis['findings']),['الإجراء: إعادة تشغيل خدمة nginx الخاصة بهذا البروكسي فقط / Restart this proxy nginx service only','قد تنقطع مشاهدة العملاء واتصالاتهم؛ لا يوجد ضمان أن العطل سيُحل / May disconnect viewers; no guarantee of repair','الطلب صالح لدقيقتين وينتظر نتيجة من الوكيل / Request expires in two minutes and awaits agent result'])]];
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
        if($name==='restart_proxy') {
            if(time()-(int)($proposal['checked_at']??0)>120)throw new \RuntimeException('STALE_PROPOSAL');
            $fresh=$this->restartProposal($args);
            if(!hash_equals($fresh['revision'],(string)($proposal['revision']??''))||$fresh['evidence']!==$proposal['evidence'])throw new \RuntimeException('STALE_PROPOSAL');
            $operation=(new ProxyOperations($this->store()))->queue($args['proxy_id'],$proposal['revision'],['id'=>$rUserInfo['id'],'name'=>$rUserInfo['username']],['reason'=>$args['reason'],'codes'=>$fresh['evidence']]);
            return ['proxy_restart_queued'=>true,'proxy_id'=>$args['proxy_id'],'operation'=>$operation];
        }
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
