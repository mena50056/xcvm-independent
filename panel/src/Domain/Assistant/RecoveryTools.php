<?php
namespace XcVm\Domain\Assistant;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Backup\BackupIntegrity;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessRunner;

/** Fixed recovery actions only. A proposal cannot execute until the controller confirms it. */
final class RecoveryTools {
    public function __construct(private string $scope) {}
    private function allowed(string $permission): bool { return $this->scope === 'admin' && Authorization::check('adv', $permission); }
    public function definitions(): array {
        $catalog = [];
        if ($this->allowed('users')) $catalog['repair_playback'] = ['Diagnose one exact subscription and PROPOSE a supported repair, never execute. Only enable a disabled otherwise eligible line or restart one unambiguously down stream on an online server. Other causes require explicit details. Use stream_id=0 if unknown.', ['line_id'=>['type'=>'integer','minimum'=>1], 'stream_id'=>['type'=>'integer','minimum'=>0], 'symptom'=>['type'=>'string','enum'=>['no_playback','buffering','single_channel','unknown']]]];
        if ($this->allowed('database')) {
            $catalog['diagnose_panel'] = ['Read current import queue readiness, disk space and backup job status. No credentials or logs. Also use after requesting a backup; running or old success is not proof the new job completed.', []];
            $catalog['repair_import_queue'] = ['PROPOSE creating a missing import queue directory. Does not change permissions of existing files, retry imports or modify application code. Unsupported causes are reported.', []];
            $catalog['create_panel_backup'] = ['PROPOSE requesting a new backup using the existing scheduled backup engine and its configured retention/offsite policies. Requires confirmation. Does not restore or delete the live database. Queue acceptance is not backup success; check diagnose_panel afterwards.', []];
        }
        $result=[];
        foreach ($catalog as $name=>[$description,$properties]) $result[]=['type'=>'function','function'=>['name'=>$name,'description'=>$description,'parameters'=>['type'=>'object','properties'=>$properties ?: new \stdClass(),'required'=>array_keys($properties),'additionalProperties'=>false]]];
        return $result;
    }
    private function report(string $ar,string $en,array $extra=[]): array { return $extra+['report_ar'=>$ar,'report_en'=>$en]; }
    private function queueState(): string {
        $path=rtrim(WATCH_TMP_PATH,'/\\'); clearstatcache(true,$path);
        if (is_link($path)) return 'unsafe_link';
        if (!file_exists($path)) return is_dir(dirname($path)) && is_writable(dirname($path)) ? 'missing' : 'parent_unavailable';
        return is_dir($path) && is_writable($path) ? 'ready' : 'not_writable';
    }
    public function call(string $name,array $args): array {
        if (!in_array($name,array_column(array_column($this->definitions(),'function'),'name'),true)) throw new \RuntimeException('FORBIDDEN');
        if ($name==='repair_playback') {
            $diagnosis=(new PlaybackTools($this->scope))->call('diagnose_playback',$args);
            $action=$this->playbackAction($diagnosis);
            if ($action===null) return $diagnosis;
            $inner=(new PanelTools($this->scope))->call($action['name'],$action['args'])['proposal'];
            $details=$inner['public']['details']??[];
            array_unshift($details,$action['reason']);
            $details[]='إصلاح واحد فقط؛ سيُعاد فحص الحالة بعد التنفيذ، ولا يُعد ذلك اختبار مشاهدة / One repair only; state is checked afterwards, not a viewer playback test.';
            return ['proposal'=>['kind'=>'recovery','tool'=>$name,'arguments'=>$args,'action'=>$action,'inner'=>$inner,'public'=>['title'=>$name,'target'=>'Subscription #'.$args['line_id'].($args['stream_id']?' / Channel #'.$args['stream_id']:''),'details'=>$details]]];
        }
        if ($args) throw new \RuntimeException('INVALID_ARGUMENTS');
        if ($name==='diagnose_panel') {
            $queue=$this->queueState();$backup=BackupIntegrity::status();$space=@disk_free_space(MAIN_HOME);
            $state=in_array($backup['state']??'', ['running','success','failed','restore_failed'],true)?$backup['state']:'unknown';
            $at=(int)($backup['time']??0);$schedule=(string)SettingsManager::get('automatic_backups');
            $schedule=isset(BackupService::PERIODS[$schedule])?$schedule:'off';
            $messages=['ready'=>['مجلد الاستيراد جاهز للكتابة؛ هذا لا يثبت صلاحية المصدر أو نجاح الاستيراد.','Import queue is writable; source validity and import success are not established.'], 'missing'=>['مجلد الاستيراد مفقود؛ يمكن اقتراح إنشائه للتأكيد.','Import queue is missing; its creation can be proposed.'], 'parent_unavailable'=>['المجلد الأب غير متاح؛ يحتاج مراجعة إعداد السيرفر.','Parent directory is unavailable; server configuration needs review.'], 'not_writable'=>['مجلد الاستيراد غير قابل للكتابة أو مساره ملف؛ يحتاج مراجعة الملكية والصلاحيات.','Import queue is not writable or is a file; review ownership and permissions.'], 'unsafe_link'=>['مسار الاستيراد رابط رمزي؛ أوقفت الإصلاح الآلي لمراجعة المسار.','Import queue is a symbolic link; review its target before repair.']];
            $disk=$space===false?'unknown':(string)round($space/1073741824,2).' GiB';
            $date=$at?gmdate('c',$at):'unknown';
            return $this->report('فحص البانل: '.$messages[$queue][0]."\nالمساحة المتاحة: ".$disk."\nآخر حالة نسخ احتياطي: ".$state.' @ '.$date."\nالجدولة: ".$schedule."\nالحالة لا تثبت صلاحية الاستعادة أو وجود نسخة خارجية. لا يمكن إصلاح كود التطبيق أو إعداد وجهة خارجية دون بياناتها من الشات.",
                'Panel check: '.$messages[$queue][1]."\nFree space: ".$disk."\nLatest backup status: ".$state.' @ '.$date."\nSchedule: ".$schedule."\nThis does not verify restorability or an offsite copy. Code changes and offsite setup without destination details are unsupported.",['queue'=>$queue,'backup_state'=>$state,'backup_time'=>$at,'checked_at'=>time()]);
        }
        if ($name==='repair_import_queue' && $this->queueState()!=='missing') return $this->call('diagnose_panel',[]);
        $details=$name==='repair_import_queue'
            ? ['السبب: مجلد طابور الاستيراد مفقود / Cause: import queue directory is missing','الإجراء: إنشاء المجلد بصلاحيات 0770 فقط؛ لن تتم إعادة تشغيل أي استيراد / Create directory with mode 0770 only; no imports will be retried']
            : ['الإجراء: تشغيل مهمة النسخ الاحتياطي الحالية / Request the existing backup job','تُطبق سياسة الاحتفاظ والوجهات الخارجية المضبوطة؛ قد تُحذف النسخ القديمة وفق السياسة / Configured retention and offsite policies apply; old backups may be pruned','قبول المهمة لا يعني نجاح النسخة؛ افحص حالتها وتوقيتها بعد التنفيذ / Acceptance is not success; check status and timestamp afterwards'];
        return ['proposal'=>['kind'=>'recovery','tool'=>$name,'arguments'=>[],'public'=>['title'=>$name,'target'=>'MAIN panel','details'=>$details]]];
    }
    private function playbackAction(array $d): ?array {
        $codes=array_column($d['findings'],'code');
        $blockers=array_column(array_filter($d['findings'],static fn($f)=>$f['kind']==='confirmed_blocker'),'code');
        if ($blockers===['disabled'] && $this->allowed('edit_user')) return ['name'=>'set_line_enabled','args'=>['line_id'=>$d['line_id'],'enabled'=>true],'reason'=>'السبب: الاشتراك معطل بدون حظر أو انتهاء أو عائق مؤكد آخر؛ الإجراء: تفعيل الاشتراك فقط / Disabled subscription with no other confirmed blocker; enable only'];
        if ($blockers || !in_array('account_ready',$codes,true) || !in_array('channel_allowed',$codes,true) || !$this->allowed('edit_stream')) return null;
        // Do not choose a server or interrupt healthy/on-demand instances on the user's behalf.
        $instances=$d['instances'];
        if (count($instances)!==1 || $instances[0]['state']!=='reported_down' || $instances[0]['server_online']!==true) return null;
        return ['name'=>'control_stream','args'=>['stream_id'=>$d['stream_id'],'server_id'=>$instances[0]['server_id'],'operation'=>'restart'],'reason'=>'السبب: عملية القناة متوقفة حسب السجل على السيرفر الوحيد المتاح؛ الإجراء: طلب إعادة تشغيل هذه القناة فقط، وقد يتأثر المشاهدون / Stored stream state is down on its only online server; request this channel restart only, viewers may be affected'];
    }
    public function execute(array $proposal): array {
        $name=$proposal['tool']??'';$args=$proposal['arguments']??[];
        $fresh=$this->call($name,$args);
        if (!isset($fresh['proposal'])) throw new \RuntimeException('STALE_PROPOSAL');
        if ($name==='repair_playback') {
            if ($fresh['proposal']['action']!==($proposal['action']??null)) throw new \RuntimeException('STALE_PROPOSAL');
            $result=(new PanelTools($this->scope))->execute($proposal['inner']);
            try {
                $d=(new PlaybackTools($this->scope))->call('diagnose_playback',$args);
                $ar=(!empty($result['queued'])?'تم قبول طلب تشغيل القناة؛ النتيجة لم تتأكد بعد.':(!empty($result['signal_pending'])?'حُفظ التفعيل لكن إشعار التحديث تعذر.':'حُفظ تفعيل الاشتراك.'))."\n";
                $en=(!empty($result['queued'])?'Stream command accepted; outcome is pending.':(!empty($result['signal_pending'])?'Activation saved but notification failed.':'Activation saved.'))."\n";
                $d['report_ar']=str_replace('لم يتم تغيير أي إعداد. أي إصلاح يحتاج طلبًا وتأكيدًا منفصلين.','هذا فحص لحالة البانل وليس اختبار مشاهدة. لو استمر العطل اذكر الخطأ؛ أي إجراء إضافي يحتاج تأكيدًا جديدًا.',$d['report_ar']);
                $d['report_en']=str_replace('No settings were changed. Any fix needs a separate request and confirmation.','This checks panel state, not viewer playback. If the fault persists provide the error; another action needs new confirmation.',$d['report_en']);
                return $result+['recovery_report'=>['ar'=>$ar.$d['report_ar'],'en'=>$en.$d['report_en']]];
            } catch (\Throwable $e) { return $result+['recovery_report'=>['ar'=>'نُفذ الطلب لكن تعذر الفحص اللاحق. لا نعتبر المشكلة محلولة؛ اطلب تشخيص التشغيل مجددًا.','en'=>'Request executed but verification failed. Resolution is unknown; request playback diagnosis again.']]; }
        }
        if ($name==='repair_import_queue') {
            if (!@mkdir(rtrim(WATCH_TMP_PATH,'/\\'),0770) || $this->queueState()!=='ready') throw new \RuntimeException('CONTROL_REFUSED');
            $report=['ar'=>'تم إنشاء مجلد الاستيراد والتحقق أنه قابل للكتابة. لم يُعد تشغيل أي استيراد. إذا استمر الفشل، راجع المصدر وسجل المهمة قبل محاولة جديدة.','en'=>'Import queue created and verified writable. No import was retried. If failure persists, review the source and job log before retrying.'];
        } else {
            if (!ProcessRunner::start([PHP_BIN,MAIN_HOME.'console.php','cron:backups','1'])) throw new \RuntimeException('CONTROL_REFUSED');
            $report=['ar'=>'تم طلب النسخ الاحتياطي عند '.gmdate('c').'. لم يتأكد نجاح النسخة. اطلب «افحص البانل» وتحقق من حالة النسخ وتوقيتها، أو راجع صفحة النسخ الاحتياطي.','en'=>'Backup requested at '.gmdate('c').'. Completion is not confirmed. Request a panel check and compare backup state and timestamp, or inspect Backups.'];
        }
        global $rUserInfo;
        error_log('XC assistant recovery '.json_encode(['actor'=>(int)($rUserInfo['id']??0),'action'=>$name,'time'=>time()]));
        return ['recovery_report'=>$report];
    }
}
