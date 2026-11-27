<?php
namespace XcVm\Core\Proxy;

/** Runs independently of chat; service acknowledgement and observed health stay separate. */
final class ProxyPostcheck {
    public function __construct(private ProxyProfiles $profiles) {}
    public static function compare(array $before,array $ports,int $expected,bool $fresh,bool $sameRevision): array {
        $faults=['CONNECT_FAILED','TIMEOUT','TLS_FAILED','UPSTREAM_FAILED','HTTP_REJECTED','INVALID_RESPONSE','ORIGIN_MISMATCH','REVISION_MISMATCH','APPLY_FAILED'];
        $codes=array_values(array_unique(array_column($ports,'code')));
        $unknown=!$fresh||!$sameRevision||$expected<1||count($ports)!==$expected||count(array_filter($ports,static fn($p)=>!in_array($p['state']??'', ['healthy','failed'],true)))>0;
        $failed=count(array_intersect($codes,$faults))>0;
        if(!$failed&&count(array_filter($ports,static fn($p)=>($p['state']??'')==='healthy'))!==$expected)$unknown=true;
        $outcome=$unknown?'inconclusive':($failed?'unresolved':(array_intersect($before,$faults)?'resolved':'healthy'));
        $next=match(true){
            !$sameRevision=>'configuration',!$fresh=>'agent',in_array('TLS_FAILED',$codes,true)=>'tls',
            count(array_intersect($codes,['UPSTREAM_FAILED','ORIGIN_MISMATCH','REVISION_MISMATCH','HTTP_REJECTED']))>0=>'upstream',
            count(array_intersect($codes,['CONNECT_FAILED','TIMEOUT']))>0=>'network',default=>'diagnostics'};
        return ['outcome'=>$outcome,'previous_codes'=>$before,'current_codes'=>$codes,'next_step'=>$next];
    }
    public static function describe(array $result,string $restartState,int $id): array {
        $ar=['resolved'=>'الفحوص الجديدة لم تعد ترصد الأعطال السابقة. هذا لا يثبت أن كل مشكلات العملاء انتهت.', 'healthy'=>'الفحوص الجديدة سليمة؛ لم يكن هناك عطل مؤكد سابقًا لنعلن إصلاحه.', 'unresolved'=>'ما زالت الفحوص ترصد مشكلة. إعادة التشغيل لم تؤدِ إلى نتيجة سليمة في الفحص.', 'inconclusive'=>'لا يمكن الجزم بالنتيجة: الفحص غير مكتمل، أو الاتصال غير حديث، أو الإعداد تغير.'];
        $en=['resolved'=>'Fresh probes no longer observe the previous faults. This does not prove every customer issue is fixed.', 'healthy'=>'Fresh probes are healthy; there was no confirmed previous fault to claim repaired.', 'unresolved'=>'Fresh probes still observe a problem. A healthy result has not been established.', 'inconclusive'=>'Outcome is inconclusive: probes are incomplete, sync is stale, or configuration changed.'];
        $steps=[
            'tls'=>['راجع صلاحية الشهادة واسم النطاق وسلسلة الثقة؛ إعادة التشغيل وحدها لا تصلح شهادة.','Review certificate validity, hostname and trust chain; restarting does not repair a certificate.'],
            'upstream'=>['راجع السيرفر الخلفي ومسار التوجيه والإعداد المطبق.','Review the upstream server, routing and applied configuration.'],
            'network'=>['راجع الخدمة المستمعة على المنفذ والجدار الناري ومسار الشبكة.','Review the listening service, firewall and network path.'],
            'agent'=>['راجع اتصال الوكيل بالبانل وحالة خدمته.','Review the agent service and its connection to MAIN.'],
            'configuration'=>['الإعداد تغير؛ ابدأ تشخيصًا جديدًا للإعداد الحالي.','Configuration changed; start a fresh diagnosis of the current configuration.'],
            'diagnostics'=>['راجع توفر أداة الفحص وبيانات المراقبة، ثم أعد الفحص.','Review probe availability and telemetry, then check again.'],
        ];
        $follow=in_array($result['outcome'],['unresolved','inconclusive'],true);[$sa,$se]=$steps[$result['next_step']];
        return $result+['restart_state'=>$restartState,'followup_available'=>$follow,
            'report_ar'=>'نتيجة المتابعة للبروكسي #'.$id.' — حالة أمر إعادة التشغيل: '.$restartState."\n".$ar[$result['outcome']].($follow?"\nالخطوة التالية: ".$sa."\nيمكن عرض إعادة الفحص للتأكيد؛ لن يتم تعديل إعداد أو تكرار إعادة التشغيل تلقائيًا.":''),
            'report_en'=>'Proxy #'.$id.' follow-up — restart command state: '.$restartState."\n".$en[$result['outcome']].($follow?"\nNext step: ".$se."\nYou can review a new check for confirmation. No configuration changes or automatic restart retries.":'')];
    }
    public function run(int $id,?callable $probe=null,?int $now=null): ?array {
        $now??=time();$ops=new ProxyOperations($this->profiles);$lock=fopen($this->profiles->directory().'/'.$id.'.postcheck.lock','c');if(!$lock)throw new \RuntimeException('POSTCHECK_STORAGE_FAILED');chmod($this->profiles->directory().'/'.$id.'.postcheck.lock',0600);
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return null;}
        try {
            $op=$ops->verificationCandidate($id);if(!$op)return null;
            $previous=$op['verification']??[];
            if(($previous['status']??'')==='complete'||($previous['checked_at']??0)>$now-15)return $previous?:null;
            if($op['state']==='queued'&&$op['expires_at']>=$now)return null;
            if(isset($op['finished_at'])&&$op['finished_at']>$now-3)return null;
            $ports=[];$expected=0;$fresh=false;$same=false;
            try {
                $wire=$this->profiles->applied($id);$status=$this->profiles->status($id);$doc=$this->profiles->document($id);$row=$this->profiles->row($id);
                $same=$wire!==null&&hash_equals($op['revision'],$wire['revision'])&&$status['desired_revision']===$op['revision']&&!empty($row['enabled'])&&!empty($doc['profile']['enabled']);
                $fresh=($status['last_sync']??0)>=$now-30;
                if($same){$expected=count(ProxyDiagnostics::targets($wire));$ports=$probe?$probe($wire):ProxyDiagnostics::run($wire,3,(string)($doc['profile']['monitor_ca']??''));}
                $latest=$this->profiles->status($id);$same=$same&&$latest['desired_revision']===$op['revision']&&$latest['applied_revision']===$op['revision'];$fresh=($latest['last_sync']??0)>=$now-30;
            }catch(\Throwable){$fresh=false;}
            $result=self::compare($op['evidence']['codes']??[],$ports,$expected,$fresh,$same);
            $attempt=(int)($previous['attempts']??0)+1;
            // Two fresh samples, separated in time, avoid reporting a transient recovery as success.
            if($attempt>=2&&($previous['outcome']??'')!==$result['outcome'])$result['outcome']='inconclusive';
            $restart=$op['state']==='queued'?'expired_unconfirmed':$op['state'];
            $result=self::describe($result,$restart,$id)+['status'=>$attempt<2?'verifying':'complete','attempts'=>$attempt,'checked_at'=>$now];
            $ops->recordVerification($id,$op['id'],$result);return $result;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
