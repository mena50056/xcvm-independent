<?php
declare(strict_types=1);
namespace XcVm\Domain\Assistant;

use XcVm\Core\Auth\Authorization;
use XcVm\Cli\Commands\SshSession;
use XcVm\Domain\Server\InstallCredentials;

final class InstallationWizard {
    public function __construct(private InstallationStore $store, private \PDO $db) {}
    public static function app(): self {global $db;return new self(new InstallationStore(CONFIG_PATH.'assistant-install'),$db->dbh);}
    public static function allowed(): bool {return defined('XCVM_INDEPENDENT_LAB') && Authorization::isFullAdmin() && Authorization::check('adv','add_server');}
    /** A narrow shortcut only opens the wizard; it cannot submit values or approve installation. */
    public static function requested(string $message): bool {
        $text=trim($message);
        if(preg_match('/(?:لا\s+(?:تنصب|تثبت|تسطب)|do not|don.t install|cancel|إلغاء|الغاء)/iu',$text))return false;
        return preg_match('/^(?:عاوز|عايز|أريد|اريد|ابدأ|إبدأ|أبدأ|نصب|سطب|please|i want to|start|begin|install)\b/iu',$text)===1
            && preg_match('/(?:تنصيب|تثبيت|تسطيب|أنصب|انصب|نصب|سطب|install|set up)/iu',$text)===1
            && preg_match('/(?:بروكسي|proxy|سيرفر|server|لود|balancer|بث)/iu',$text)===1;
    }
    private function gate(): int {global $rUserInfo;if(!self::allowed())throw new \RuntimeException('FORBIDDEN');return (int)$rUserInfo['id'];}
    private function steps(array $data,int $version=1): array {return array_merge($version>=2?['kind','name','ip','ssh_port']:['kind','name','ip','ssh_port','fingerprint'],($data['kind']??'')==='proxy'?['parent_id','tls_mode']:[],['capacity','fresh','password']);}
    private function available(string $ip): void {
        $q=$this->db->prepare('SELECT id FROM servers WHERE server_ip=? OR private_ip=?');$q->execute([$ip,$ip]);
        if($q->fetchColumn())throw new \RuntimeException('INSTALL_EXISTING_SERVER');
        foreach($this->store->all() as $other)if(($other['data']['ip']??'')===$ip && in_array($other['state'],['queued','running','unknown'],true))throw new \RuntimeException('INSTALL_TARGET_BUSY');
    }
    public function handle(array $input): array {
        $actor=$this->gate();$op=$input['operation']??'';
        $lock=$this->store->lock('wizard');
        try {
            if($op==='install_begin') {
                foreach($this->store->all() as $old)if($old['actor']===$actor && in_array($old['state'],['draft','review','queued','running'],true)) { $old=$this->expire($old);if(in_array($old['state'],['draft','review','queued','running'],true))return $this->public($old); }
                $job=['id'=>bin2hex(random_bytes(16)),'actor'=>$actor,'version'=>2,'state'=>'draft','phase'=>'collecting','step'=>0,'data'=>[],'token'=>bin2hex(random_bytes(24)),'created_at'=>time(),'expires'=>time()+1800];$this->store->write($job);return $this->public($job);
            }
            $job=$this->expire($this->store->read((string)($input['job_id']??'')));
            if($job['actor']!==$actor)throw new \RuntimeException('FORBIDDEN');
            if($op==='install_status')return $this->public($job);
            if(!is_string($input['token']??null)||!hash_equals($job['token'],$input['token']))throw new \RuntimeException('STALE_PROPOSAL');
            if($op==='install_cancel' && in_array($job['state'],['draft','review','queued'],true)){$job['state']='cancelled';unset($job['secret']);$this->store->write($job);return $this->public($job);}
            if($op==='install_step' && $job['state']==='draft') {
                $field=$this->steps($job['data'],(int)($job['version']??1))[$job['step']];$value=$input['value']??null;
                if(!is_string($value))throw new \RuntimeException('INVALID_ARGUMENTS');
                if($field==='password') {
                    if(strlen($value)<1||strlen($value)>512||str_contains($value,"\0"))throw new \RuntimeException('INVALID_ARGUMENTS');
                    if(($job['version']??1)>=2) {
                        $ssh=new SshSession();
                        try {
                            if(!$ssh->connect($job['data']['ip'],$job['data']['ssh_port']))throw new \RuntimeException('INSTALL_SSH_UNAVAILABLE');
                            $key=InstallCredentials::normalizeHostKey($ssh->hostKey());
                            if($key===null)throw new \RuntimeException('INSTALL_SSH_UNAVAILABLE');
                            $job['data']['fingerprint']=$key;
                        } finally {$ssh->close();}
                    }
                    $job['secret']=$this->store->seal($value);
                }
                else { $job['data'][$field]=$this->validate($field,trim($value)); if($field==='ip')$job['data']['host']=trim($value); }
                $job['step']++;$job['token']=bin2hex(random_bytes(24));$job['expires']=time()+1800;
                if($job['step']===count($this->steps($job['data'],(int)($job['version']??1)))){$this->available($job['data']['ip']);$job['state']='review';$job['expires']=time()+300;}
            } elseif($op==='install_confirm' && $job['state']==='review') {
                $this->available($job['data']['ip']);if($job['data']['kind']==='proxy')$this->validate('parent_id',(string)$job['data']['parent_id']);
                if(empty($job['secret']))throw new \RuntimeException('INSTALL_CREDENTIALS');
                $job['state']='queued';$job['phase']='queued';$job['confirmed_at']=time();$job['expires']=time()+900;$job['token']=bin2hex(random_bytes(24));
            } else throw new \RuntimeException('STALE_PROPOSAL');
            $this->store->write($job);return $this->public($job);
        } finally {InstallationStore::unlock($lock);}
    }
    private function validate(string $field,string $v): mixed {
        switch($field) {
            case 'kind':if(in_array($v,['proxy','stream','lb'],true))return $v;break;
            case 'name':if(strlen($v)>=1&&strlen($v)<=80&&!preg_match('/[\x00-\x1F\x7F]/',$v))return $v;break;
            case 'ip':
                $ip=$v;
                if(!filter_var($v,FILTER_VALIDATE_IP) && strlen($v)<=253 && str_contains($v,'.') && filter_var($v,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)) {
                    $ips=array_values(array_unique(gethostbynamel($v)?:[]));
                    if(count($ips)!==1)break;
                    $ip=$ips[0];
                }
                if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)){$this->available($ip);return $ip;}break;
            case 'ssh_port':case 'capacity':$max=$field==='ssh_port'?65535:100000;if(ctype_digit($v)&&(int)$v>=1&&(int)$v<=$max)return (int)$v;break;
            case 'fingerprint':$key=InstallCredentials::normalizeHostKey($v);if($key!==null)return $key;break;
            case 'parent_id':if(ctype_digit($v)){ $q=$this->db->prepare('SELECT id FROM servers WHERE id=? AND server_type<>1 AND enabled=1 AND https_broadcast_port>0');$q->execute([(int)$v]);if($q->fetchColumn())return (int)$v;}break;
            case 'tls_mode':if(in_array($v,['public_ip','later'],true))return $v;break;
            case 'fresh':if($v==='yes')return true;break;
        }
        throw new \RuntimeException('INVALID_ARGUMENTS');
    }
    public function expire(array $job): array {
        if(in_array($job['state'],['draft','review','queued'],true)&&$job['expires']<time()){$job['state']='expired';unset($job['secret']);$this->store->write($job);}
        if($job['state']==='running' && ($job['started_at']??time())<time()-2700){$job['state']='unknown';$job['phase']='worker_interrupted';unset($job['secret']);$this->store->write($job);}
        return $job;
    }
    private function public(array $job): array {
        $out=array_intersect_key($job,array_flip(['id','state','phase','token','created_at','confirmed_at','started_at','finished_at','server_id','result']));
        $out['plan']=$job['data'];$out['ssh_user']='root';$out['http_port']=80;$out['https_port']=443;
        $out['notes']=[
            'تنصيب جديد فقط على Ubuntu 22.04 x86_64؛ البروكسي يدعم أيضًا 24.04. سيُرفض سيرفر مسجل أو تثبيت موجود أو منافذ مشغولة / Fresh Ubuntu 22.04 x86_64 only; proxy also supports 24.04. Existing installations, registered servers and occupied ports are refused.',
            'السيرفر الجديد يُفعّل ضمن البانل أثناء الإعداد والفحص. إذا فشل فحص البروكسي ستتم محاولة تعطيله ويجب مراجعة تأكيد تطبيق الإعداد / New server becomes enabled during setup and verification. A failed proxy check attempts to disable it; review the applied state.',
            'تثبيت الحزم والخدمات وتسجيل السيرفر في البانل؛ لا ترحيل لقنوات أو عملاء ولا تعديل تلقائي لجدار مزود الخدمة / Install packages and services and register the server; no automatic channel/customer migration or provider firewall changes.',
            'البث واللود بالانسر يستخدمان محرك العقد نفسه؛ توزيع القنوات خطوة منفصلة بعد نجاح التنصيب / Streaming and load balancing use the same node engine; channel assignment is a separate action.',
            'كلمة المرور مشفرة مؤقتًا ولا تُرسل للموديل، وتُحذف عند بدء التنفيذ أو الإلغاء أو انتهاء المهلة / Password is temporarily encrypted, never sent to the model, and removed on start, cancellation or expiry.',
            'لا تعاد محاولة التنصيب تلقائيًا بعد فشل جزئي؛ قد تبقى حزم أو سجل سيرفر يحتاج المراجعة / Partial failures are not retried automatically; packages or a server record may remain for review.'
        ];
        if(($job['version']??1)>=2)$out['notes'][]='بصمة SSH التُقطت تلقائيًا عند أول اتصال دون إرسال كلمة المرور. تأكيد الخطة يثق بهذه البصمة؛ يتوقف التنفيذ إذا تغيرت. العنوان المحلول مثبت لهذه العملية / SSH key captured automatically on first contact without sending the password. Confirming trusts this key; execution refuses a changed key. Resolved IP is pinned for this job.';
        if(($job['data']['tls_mode']??'')==='public_ip')$out['notes'][]='سيُطلب إصدار شهادة IP عامة والموافقة على شروط ACME وتفعيل التجديد؛ يجب إتاحة منفذ 80 لدى مزود السيرفر / Request public IP TLS, agree to ACME terms and enable renewal; provider port 80 must be reachable.';
        if(($job['data']['tls_mode']??'')==='later')$out['notes'][]='الشهادة الأولية محلية وغير موثوقة للمتصفحات؛ يلزم إعداد TLS قبل استخدام العملاء / Initial TLS is locally generated, not publicly trusted; configure trusted TLS before customer use.';
        if($job['state']==='draft') {
            $field=$this->steps($job['data'],(int)($job['version']??1))[$job['step']];$out['field']=$field;
            $out['choices']=match($field){'kind'=>['proxy'=>'بروكسي / Proxy','stream'=>'سيرفر بث / Streaming node','lb'=>'لود بالانسر / Load balancer'],'tls_mode'=>['public_ip'=>'شهادة IP عامة وتجديد آلي / Public IP TLS and renewal','later'=>'إعداد TLS لاحقًا / Configure TLS later'],'fresh'=>['yes'=>'نعم، سيرفر جديد مخصص للتنصيب / Yes, a fresh dedicated server'],default=>[]};
            if($field==='parent_id')foreach($this->db->query('SELECT id,server_name FROM servers WHERE server_type<>1 AND enabled=1 AND https_broadcast_port>0')->fetchAll(\PDO::FETCH_ASSOC) as $row)$out['choices'][(string)$row['id']]=$row['server_name'].' (#'.$row['id'].')';
        }
        return ['installation'=>$out];
    }
}
