<?php
namespace XcVm\Domain\Assistant;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Redis\RedisManager;

/** Read-only playback triage. No media URLs, passwords, IPs or raw log payloads. */
final class PlaybackTools {
    public function __construct(private string $scope) {}
    private function allowed(string $permission): bool { return $this->scope==='admin' && Authorization::check('adv',$permission); }
    public function definitions(): array {
        if (!$this->allowed('users')) { return []; }
        return [['type'=>'function','function'=>['name'=>'diagnose_playback','description'=>'Read-only diagnosis of a customer playback problem: expiry, activation, restrictions, connection telemetry, recent refusal codes and optional live-channel entitlement/server state. First find the exact subscription ID. Use stream_id=0 when no specific channel is known; do not guess. Symptoms do not prove a cause. This tool never fixes or changes anything. Follow-up fixes require separate user confirmation.',
            'parameters'=>['type'=>'object','properties'=>['line_id'=>['type'=>'integer','minimum'=>1],'stream_id'=>['type'=>'integer','minimum'=>0],'symptom'=>['type'=>'string','enum'=>['no_playback','buffering','single_channel','unknown']]],'required'=>['line_id','stream_id','symptom'],'additionalProperties'=>false]]]];
    }
    private function rows(string $sql,mixed ...$args): array {
        global $db;
        if (!$db->query($sql,...$args)) { throw new \RuntimeException('DATABASE_ERROR'); }
        return $db->get_raw_rows();
    }
    private function ids(mixed $json): array { return array_values(array_unique(array_filter(array_map('intval',json_decode((string)$json,true)?:[]),static fn($id)=>$id>0))); }

    /** A failed Redis read must remain unknown, never fall back to stale SQL or zero. */
    private function connections(int $id): array {
        if (!$this->allowed('live_connections')) { return ['available'=>false,'reason'=>'permission']; }
        try {
            if (!SettingsManager::get('redis_handler')) {
                $rows=$this->rows('SELECT COUNT(*) AS total FROM `lines_live` WHERE `user_id`=? AND `hls_end`=0',$id);
                return ['available'=>true,'count'=>(int)$rows[0]['total'],'source'=>'mysql_snapshot'];
            }
            $redis=RedisManager::instance();
            if (!$redis || !function_exists('igbinary_unserialize')) { return ['available'=>false,'reason'=>'store_unavailable']; }
            $size=$redis->zCard('LINE#'.$id);
            if (!is_int($size) || $size>500) { return ['available'=>false,'reason'=>$size>500?'too_many_records':'store_unavailable']; }
            $keys=$redis->zRange('LINE#'.$id,0,499);
            if (!is_array($keys)) { return ['available'=>false,'reason'=>'store_unavailable']; }
            if (!$keys) { return ['available'=>true,'count'=>0,'source'=>'redis_snapshot']; }
            $values=$redis->mGet($keys);
            if (!is_array($values) || count($values)!==count($keys)) { return ['available'=>false,'reason'=>'store_unavailable']; }
            $count=0;
            foreach ($values as $index=>$raw) {
                if ($raw===false || $raw===null) { continue; } // A stale key is not a live session.
                $record=is_string($raw)?igbinary_unserialize($raw):null;
                if (!is_array($record)) { return ['available'=>false,'reason'=>'invalid_record']; }
                if (($record['uuid']??null)===$keys[$index] && (int)($record['user_id']??0)===$id && (int)($record['hls_end']??0)===0) { $count++; }
            }
            return ['available'=>true,'count'=>$count,'source'=>'redis_snapshot'];
        } catch (\Throwable $error) { return ['available'=>false,'reason'=>'store_unavailable']; }
    }

    public function call(string $name,array $args): array {
        if ($name!=='diagnose_playback' || !$this->allowed('users')) { throw new \RuntimeException('FORBIDDEN'); }
        if (count($args)!==3 || array_diff(array_keys($args),['line_id','stream_id','symptom']) || !is_int($args['line_id']??null) || $args['line_id']<1 || !is_int($args['stream_id']??null) || $args['stream_id']<0 || !in_array($args['symptom']??null,['no_playback','buffering','single_channel','unknown'],true)) { throw new \RuntimeException('INVALID_ARGUMENTS'); }
        if ($args['stream_id'] && (!$this->allowed('streams') || !$this->allowed('servers'))) { throw new \RuntimeException('FORBIDDEN'); }
        $line=$this->rows('SELECT `id`,`username`,`enabled`,`admin_enabled`,`exp_date`,`is_activecode`,`max_connections`,`bouquet`,`allowed_outputs`,`allowed_ips`,`allowed_ua`,`is_isplock`,`forced_country`,`force_server_id` FROM `lines` WHERE `id`=? AND `is_mag`=0 AND `is_e2`=0',$args['line_id'])[0]??null;
        if (!$line) { throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN'); }
        $findings=[];
        $add=static function(string $code,string $ar,string $en,string $kind='observation') use (&$findings):void { $findings[]=['code'=>$code,'kind'=>$kind,'ar'=>$ar,'en'=>$en]; };
        $pending=ActiveCodeService::lineAwaitsRedemption($line);
        if ($pending) { $add('activation_pending','الاشتراك ينتظر استرداد كود التفعيل. تفعيله يدويًا لا يبدأ المدة.','Subscription awaits activation-code redemption; toggling enabled does not start its term.','confirmed_blocker'); }
        if (!(int)$line['admin_enabled']) { $add('admin_banned','الاشتراك محظور إداريًا. راجع سبب الحظر قبل رفعه من صفحة الاشتراك.','Subscription is administratively banned. Review the ban before removing it in the subscription page.','confirmed_blocker'); }
        if (!(int)$line['enabled']) { $add('disabled','مفتاح تفعيل الاشتراك مغلق. يمكن طلب تفعيله بعد مراجعة السبب؛ يلزم تأكيد منفصل.','Subscription is disabled. Enabling can be requested after review and needs separate confirmation.','confirmed_blocker'); }
        if ($line['exp_date']!==null && (int)$line['exp_date']<=time()) { $add('expired','انتهت الصلاحية: '.gmdate('Y-m-d H:i',(int)$line['exp_date']).' UTC. يمكن طلب تمديد بمدة محددة للتأكيد.','Expired at '.gmdate('Y-m-d H:i',(int)$line['exp_date']).' UTC. Request an explicit extension duration for confirmation.','confirmed_blocker'); }
        if (!$pending && (int)$line['enabled'] && (int)$line['admin_enabled'] && ($line['exp_date']===null || (int)$line['exp_date']>time())) { $add('account_ready','حالة التفعيل والصلاحية تسمح بالتشغيل؛ هذا لا يثبت سلامة مسار الفيديو.','Activation and expiry allow playback; this does not prove that video delivery works.'); }
        $connections=$this->connections($args['line_id']);
        if ($connections['available']) {
            $limit=(int)$line['max_connections'];
            $add('connections_snapshot','الاتصالات المسجلة: '.$connections['count'].'؛ الحد المضبوط: '.$limit.'.','Recorded connections: '.$connections['count'].'; configured limit: '.$limit.'.');
            if ($limit>0 && $connections['count']>=$limit) { $add('at_connection_limit','العدد المسجل بلغ حد الاتصالات. جرّب إغلاق التشغيل على الأجهزة الأخرى وإعادة المحاولة؛ لا نعتبر الجلسات عالقة دون تحقق.','Recorded sessions reach the limit. Stop playback on other devices and retry; this does not establish that sessions are stuck.','possible_cause'); }
        } else { $add('connections_unknown','لم يمكن فحص الاتصالات الحالية: '.($connections['reason']==='permission'?'صلاحية الاتصالات غير متاحة.':'بيانات الاتصالات غير متاحة، وليس معناها صفر اتصالات.'),'Connections could not be checked: '.($connections['reason']==='permission'?'missing live-connections permission.':'telemetry is unavailable, not zero.'),'unknown'); }
        $restrictions=[];
        if (json_decode((string)$line['allowed_ips'],true)) { $restrictions[]='IP'; }
        if (json_decode((string)$line['allowed_ua'],true)) { $restrictions[]='User-Agent'; }
        if (!empty($line['is_isplock'])) { $restrictions[]='ISP'; }
        if (!empty($line['forced_country']) && $line['forced_country']!=='ALL') { $restrictions[]='Country'; }
        if ($restrictions) { $add('restrictions_configured','توجد قيود '.implode(', ',$restrictions).'. وجودها لا يثبت رفض العميل؛ طابقها من صفحة الاشتراك ولا تلغها تلقائيًا.','Configured restrictions: '.implode(', ',$restrictions).'. Their presence does not prove a mismatch; check them in the subscription page, do not disable them automatically.','possible_cause'); }
        $outputs=$this->ids($line['allowed_outputs']);
        if (!$outputs) { $add('no_output_formats','لا توجد صيغ تشغيل مسموحة في إعداد الاشتراك. راجع صيغ HLS/TS المسموحة.','No output formats are configured as allowed. Review the allowed HLS/TS formats.','confirmed_blocker'); }
        else { $add('formats','الصيغ المسموحة حسب الإعداد: '.implode(', ',array_map(static fn($id)=>[1=>'HLS',2=>'TS',3=>'RTMP'][$id]??('#'.$id),$outputs)).'.','Configured allowed formats: '.implode(', ',array_map(static fn($id)=>[1=>'HLS',2=>'TS',3=>'RTMP'][$id]??('#'.$id),$outputs)).'.'); }

        $stream=null;$instances=[];
        if ($args['stream_id']) {
            $stream=$this->rows('SELECT `id`,`stream_display_name`,`direct_source` FROM `streams` WHERE `id`=? AND `type`=1',$args['stream_id'])[0]??null;
            if (!$stream) { throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN'); }
            $bouquets=$this->ids($line['bouquet']);$entitled=false;
            if (count($bouquets)>500) { $add('entitlement_unknown','قائمة الباقات أكبر من حد التشخيص؛ راجع وصول القناة يدويًا.','Bouquet list exceeds diagnosis bounds; review channel access manually.','unknown'); }
            else {
                if ($bouquets) { foreach ($this->rows('SELECT `bouquet_channels` FROM `bouquets` WHERE `id` IN ('.implode(',',$bouquets).')') as $bouquet) { if (in_array($args['stream_id'],$this->ids($bouquet['bouquet_channels']),true)) { $entitled=true;break; } } }
                $add($entitled?'channel_allowed':'channel_not_allowed',$entitled?'القناة موجودة ضمن باقات الاشتراك.':'القناة ليست ضمن باقات الاشتراك. راجع الباقة المخصصة بدل إعادة تشغيل القناة.',$entitled?'The channel belongs to the assigned bouquets.':'The channel is outside the assigned bouquets. Review the subscription package rather than restarting the stream.',$entitled?'observation':'confirmed_blocker');
            }
            if ((int)$stream['direct_source']) { $add('direct_source','القناة تستخدم مصدرًا مباشرًا؛ حالة عملية البث المحلية لا تكفي للحكم على المصدر.','This is a direct-source channel; local producer state cannot establish source availability.','unknown'); }
            else {
                $rows=$this->rows('SELECT `server_id`,`pid`,`stream_status`,`on_demand`,`to_analyze` FROM `streams_servers` WHERE `stream_id`=? ORDER BY `server_id` LIMIT 10',$args['stream_id']);
                try { $servers=ServerRepository::getAll(); } catch (\Throwable $error) { $servers=[]; }
                if (!$rows) { $add('no_stream_servers','لا توجد سيرفرات مسندة لهذه القناة. راجع توزيع القناة.','No servers are assigned to this channel. Review stream assignment.','confirmed_blocker'); }
                foreach ($rows as $row) {
                    $sid=(int)$row['server_id'];$server=$servers[$sid]??null;
                    $state=(int)$row['pid']>0 && (int)$row['stream_status']===0 && !(int)$row['to_analyze']?'running':((int)$row['on_demand'] && (int)$row['pid']<=0 && (int)$row['stream_status']===0 && !(int)$row['to_analyze']?'on_demand_idle':((int)$row['pid']<=0 && (int)$row['stream_status']!==0?'reported_down':'not_ready'));
                    $instances[]=['server_id'=>$sid,'state'=>$state,'server_online'=>$server===null?null:(!empty($server['enabled'])&&!empty($server['server_online']))];
                    $ar=['running'=>'توجد عملية بث جاهزة حسب السجل، وليس اختبار مشاهدة','on_demand_idle'=>'ينتظر طلب تشغيل (On-demand)، وليس عطلًا مؤكدًا','reported_down'=>'السجل يشير إلى توقف عملية البث','not_ready'=>'الحالة غير جاهزة أو قيد التحليل؛ يلزم فحص'][ $state ];
                    $add('stream_'.$state,'السيرفر #'.$sid.': '.$ar.'.','Server #'.$sid.': '.$state.' (stored state, not a playback test).','observation');
                    if ($server===null) { $add('server_unknown','حالة السيرفر #'.$sid.' غير متاحة.','Server #'.$sid.' health is unavailable.','unknown'); }
                    elseif (empty($server['enabled']) || empty($server['server_online'])) { $add('server_unavailable','السيرفر #'.$sid.' معطل أو غير متاح حسب حالة البانل. افحصه قبل إعادة تشغيل القناة.','Server #'.$sid.' is disabled or unavailable according to panel health. Check it before restarting the stream.','possible_cause'); }
                    elseif ($state==='reported_down' && $this->allowed('edit_stream')) { $add('restart_candidate','يمكن طلب إعادة تشغيل القناة #'.$args['stream_id'].' على السيرفر #'.$sid.' بعد مراجعة السبب؛ يلزم التأكيد وقد يتأثر المشاهدون.','A restart of channel #'.$args['stream_id'].' on server #'.$sid.' can be proposed after review. Confirmation is required and viewers may be interrupted.','next_step'); }
                }
                if (count($rows)===10) { $add('bounded_servers','التقرير يعرض أول ١٠ سيرفرات فقط.','Only the first 10 assigned servers are shown.','observation'); }
                if (count($rows)<10 && (int)$line['force_server_id']>0 && !in_array((int)$line['force_server_id'],array_column($rows,'server_id'))) { $add('forced_server_unassigned','الاشتراك مثبت على سيرفر غير موجود ضمن توزيع هذه القناة. راجع إعداد السيرفر الإجباري.','The forced server is not assigned to this channel. Review the forced-server setting.','possible_cause'); }
            }
        } else { $add('channel_not_selected','لم تُحدد قناة بعينها. لو المشكلة في قناة واحدة، اذكر اسمها أو رقمها لفحص الباقة وحالة البث.','No specific channel was selected. For a single-channel issue, provide its name or ID to check entitlement and stream state.','next_step'); }
        $recent=[];
        if ($this->allowed('client_request_log')) {
            try {
                $known=['USER_EXPIRED','USER_BAN','USER_DISABLED','USER_ALREADY_CONNECTED','AUTH_FAILED','LB_TOKEN_INVALID'];
                foreach ($this->rows('SELECT `client_status`,`date` FROM `lines_logs` WHERE `user_id`=? AND `date`>=? ORDER BY `id` DESC LIMIT 10',$args['line_id'],time()-86400) as $log) {
                    if (in_array($log['client_status'],$known,true)) { $recent[]=['code'=>$log['client_status'],'at_utc'=>gmdate('Y-m-d H:i',(int)$log['date'])]; }
                }
                if ($recent) { $text=implode('; ',array_map(static fn($log)=>$log['code'].' @ '.$log['at_utc'].' UTC',array_slice($recent,0,5)));$add('recent_refusals','أكواد رفض سابقة خلال ٢٤ ساعة: '.$text.'. السجل تاريخي؛ USER_ALREADY_CONNECTED قد ينتج عن حد الاتصالات أو عدم توفر المسار.','Historical refusal codes within 24h: '.$text.'. USER_ALREADY_CONNECTED can represent a connection limit or unavailable routing; it is not a definitive current diagnosis.'); }
            } catch (\Throwable $error) { $add('logs_unknown','سجل طلبات التشغيل غير متاح حاليًا.','Playback request logs are currently unavailable.','unknown'); }
        }
        if ($args['symptom']==='buffering') { $add('buffering_checks','للتقطيع: قارن نفس القناة على جهاز آخر وشبكة أخرى، ثم جرّب HLS أو TS إذا كانت الصيغة مسموحة. بيانات البانل وحدها لا تقيس شبكة العميل.','For buffering, compare the same channel on another device and network, then try HLS or TS if permitted. Panel data alone does not measure the customer network.','next_step'); }
        $add('client_evidence','لو استمرت المشكلة: اذكر رسالة الخطأ، اسم المشغّل، هل المشكلة في كل القنوات أم قناة واحدة، ووقت حدوثها. لا ترسل كلمة المرور أو رابطًا يحتويها.','If it persists, provide the error message, player name, all channels versus one channel, and occurrence time. Do not send a password or a URL containing one.','next_step');
        $identity=(string)preg_replace('/[\x00-\x1F\x7F]/u',' ',(string)$line['username']).' (#'.$line['id'].')';
        return ['line_id'=>$args['line_id'],'stream_id'=>$args['stream_id'],'checked_at_utc'=>gmdate('c'),'findings'=>$findings,'connections'=>$connections,'instances'=>$instances,'recent_refusals'=>$recent,
            'report_ar'=>'تشخيص التشغيل: '.$identity."\n".implode("\n",array_map(static fn($f)=>'• '.$f['ar'],$findings))."\nلم يتم تغيير أي إعداد. أي إصلاح يحتاج طلبًا وتأكيدًا منفصلين.",
            'report_en'=>'Playback diagnosis: '.$identity."\n".implode("\n",array_map(static fn($f)=>'• '.$f['en'],$findings))."\nNo settings were changed. Any fix needs a separate request and confirmation."];
    }
}
