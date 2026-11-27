<?php
namespace XcVm\Domain\Assistant;

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\ResellerAPI;

/** Package purchases use the existing reseller sale, pricing and ownership policy. */
final class SubscriptionTools {
    public function __construct(private string $scope) {}
    private function allowed(): bool { return $this->scope === 'reseller' && Authorization::hasResellerPermissions('create_line'); }
    public function definitions(): array {
        if (!$this->allowed()) { return []; }
        return [
            ['type'=>'function','function'=>['name'=>'list_packages','description'=>'List up to 30 official subscription packages available to your group, including your effective credit prices and duration.','parameters'=>['type'=>'object','properties'=>new \stdClass(),'required'=>[],'additionalProperties'=>false]]],
            ['type'=>'function','function'=>['name'=>'renew_line_package','description'=>'PROPOSE purchasing a package renewal for one accessible existing subscription. Deducts the native package price from YOUR reseller credits only after explicit confirmation. Changes package limits and bouquets according to native rules.','parameters'=>['type'=>'object','properties'=>['line_id'=>['type'=>'integer','minimum'=>1],'package_id'=>['type'=>'integer','minimum'=>1]],'required'=>['line_id','package_id'],'additionalProperties'=>false]]],
        ];
    }
    private function rows(string $sql, mixed ...$args): array {
        global $db;
        if (!$db->query($sql, ...$args)) { throw new \RuntimeException('DATABASE_ERROR'); }
        return $db->get_raw_rows();
    }
    private function actor(bool $lock=false): array {
        global $rUserInfo;
        $rows=$this->rows('SELECT `id`,`member_group_id`,`status`,`credits`,`override_packages` FROM `users` WHERE `id`=?'.($lock?' FOR UPDATE':''),(int)$rUserInfo['id']);
        if (!$rows || (int)$rows[0]['status']!==1) { throw new \RuntimeException('FORBIDDEN'); }
        return $rows[0];
    }
    private function package(int $id,array $actor,bool $lock=false): array {
        $rows=$this->rows('SELECT * FROM `users_packages` WHERE `id`=?'.($lock?' FOR UPDATE':''),$id);
        $package=$rows[0]??[];
        if (!$package || empty($package['is_line']) || empty($package['is_official']) || !in_array((int)$actor['member_group_id'],array_map('intval',json_decode($package['groups']??'[]',true)?:[]),true)) {
            throw new \RuntimeException('FORBIDDEN');
        }
        return $package;
    }
    private function price(array $package,array $actor): float {
        $overrides=json_decode($actor['override_packages']??'{}',true)?:[];
        $override=$overrides[$package['id']]['official_credits']??null;
        $amount=round((float)($override!==null && (string)$override!=='' ? $override : $package['official_credits']),4);
        if (!is_finite($amount)||$amount<0) { throw new \RuntimeException('INVALID_ARGUMENTS'); }
        return $amount;
    }
    private function line(int $id,bool $lock=false): array {
        global $rUserInfo,$rPermissions;
        $owners=array_unique(array_map('intval',array_merge([(int)$rUserInfo['id']],(array)($rPermissions['all_reports']??[]))));
        $rows=$this->rows('SELECT * FROM `lines` WHERE `id`=? AND `member_id` IN ('.implode(',',$owners).') AND `is_mag`=0 AND `is_e2`=0'.($lock?' FOR UPDATE':''),$id);
        if (!$rows) { throw new \RuntimeException('NOT_FOUND_OR_FORBIDDEN'); }
        $line=$rows[0];
        if (!empty($line['pair_id'])||$this->rows('SELECT `id` FROM `lines` WHERE `pair_id`=? LIMIT 1',$id)) { throw new \RuntimeException('PAIRED_LINE_UNSUPPORTED'); }
        if (ActiveCodeService::lineAwaitsRedemption($line)) { throw new \RuntimeException('ACTIVATION_REQUIRED'); }
        if ($line['exp_date']===null) { throw new \RuntimeException('UNLIMITED_EXPIRY'); }
        return $line;
    }
    private function revision(array $row): string {
        return hash('sha256',json_encode(array_map(static fn($v)=>$v===null?null:(string)$v,$row),JSON_THROW_ON_ERROR));
    }
    public function call(string $name,array $args): array {
        if (!$this->allowed()) { throw new \RuntimeException('FORBIDDEN'); }
        $actor=$this->actor();
        if ($name==='list_packages' && !$args) {
            $packages=$this->rows('SELECT * FROM `users_packages` WHERE `is_line`=1 AND `is_official`=1 AND JSON_CONTAINS(`groups`, ?, \'$\') ORDER BY `id` LIMIT 30',(string)$actor['member_group_id']);
            return ['packages'=>array_map(fn($p)=>['id'=>(int)$p['id'],'name'=>$p['package_name'],'credits'=>$this->price($p,$actor),'duration'=>(int)$p['official_duration'],'unit'=>$p['official_duration_in'],'max_connections'=>(int)$p['max_connections']],$packages),'your_credits'=>(float)$actor['credits']];
        }
        if ($name!=='renew_line_package' || count($args)!==2 || !is_int($args['line_id']??null) || !is_int($args['package_id']??null) || min($args['line_id'],$args['package_id'])<1) { throw new \RuntimeException('INVALID_ARGUMENTS'); }
        $line=$this->line($args['line_id']);$package=$this->package($args['package_id'],$actor);$price=$this->price($package,$actor);
        if ((float)$actor['credits']<$price) { throw new \RuntimeException('INSUFFICIENT_CREDITS'); }
        return ['proposal'=>['kind'=>'subscription','tool'=>$name,'arguments'=>$args,'line_revision'=>$this->revision($line),'package_revision'=>$this->revision($package),'actor_revision'=>$this->revision($actor),'price'=>$price,
            'public'=>['title'=>$name,'target'=>$line['username'].' (#'.$line['id'].')','details'=>[
                'Package / الباقة: '.$package['package_name'].' (#'.$package['id'].')',
                'Charge / الخصم: '.number_format($price,4,'.','').' credits',
                'Your balance / رصيدك: '.$actor['credits'].' → '.number_format((float)$actor['credits']-$price,4,'.',''),
                'Term / المدة: '.$package['official_duration'].' '.$package['official_duration_in'],
                'Connections / الاتصالات: '.$line['max_connections'].' → '.$package['max_connections'],
                'Bouquets / الباقات: '.$package['bouquets'],
                'Native package rules apply; incompatible packages restart the term from execution time / تطبق قواعد الباقة، والباقة غير المتوافقة تبدأ مدتها من وقت التنفيذ'
            ]]]];
    }
    public function execute(array $proposal): array {
        global $db,$rUserInfo;
        if (!$this->allowed() || ($proposal['tool']??'')!=='renew_line_package') { throw new \RuntimeException('FORBIDDEN'); }
        if (!$db->beginTransaction()) { throw new \RuntimeException('DATABASE_ERROR'); }
        try {
            $args=$proposal['arguments'];$actor=$this->actor(true);$package=$this->package($args['package_id'],$actor,true);$line=$this->line($args['line_id'],true);
            foreach (['actor'=>$actor,'package'=>$package,'line'=>$line] as $key=>$row) {
                if (!hash_equals($proposal[$key.'_revision'],$this->revision($row))) { throw new \RuntimeException('STALE_PROPOSAL'); }
            }
            if ($this->price($package,$actor)!==(float)$proposal['price']) { throw new \RuntimeException('STALE_PROPOSAL'); }
            ResellerAPI::init((int)$rUserInfo['id']);
            // Preserve unrelated form fields. Password never leaves this backend request.
            $form=['edit'=>(int)$line['id'],'package'=>(int)$package['id'],'username'=>$line['username'],'password'=>'',
                'member_id'=>$line['member_id'],'contact'=>$line['contact']??'','reseller_notes'=>$line['reseller_notes']??'',
                'allowed_ips'=>json_decode($line['allowed_ips']??'[]',true)?:[],'allowed_ua'=>json_decode($line['allowed_ua']??'[]',true)?:[],
                'bouquets_selected'=>json_decode($line['bouquet']??'[]',true)?:[],'isp_clear'=>'keep'];
            foreach (['is_isplock','bypass_ua'] as $flag) { if (!empty($line[$flag])) { $form[$flag]=1; } }
            $result=ResellerAPI::processLine($form);
            if (!is_array($result) || ($result['status']??null)!==STATUS_SUCCESS) { throw new \RuntimeException('PACKAGE_RENEWAL_REFUSED'); }
            if (!$db->query("INSERT INTO `users_logs` (`owner`,`type`,`action`,`log_id`,`package_id`,`cost`,`credits_after`,`date`,`deleted_info`) VALUES (?, 'assistant', 'renew_line_package', ?, ?, ?, ?, ?, ?)",
                (int)$rUserInfo['id'],(int)$line['id'],(int)$package['id'],$proposal['price'],\XcVm\Domain\User\UserCredits::balance((int)$rUserInfo['id']),time(),json_encode(['source'=>'ai_assistant','package_id'=>(int)$package['id'],'price'=>$proposal['price']]))) { throw new \RuntimeException('DATABASE_ERROR'); }
            if (!$db->commit()) { throw new \RuntimeException('DATABASE_ERROR'); }
        } catch (\Throwable $error) { $db->rollback();throw $error; }
        try { LineService::updateLineSignal((int)$line['id']); } catch (\Throwable $error) { return ['saved'=>true,'signal_pending'=>true]; }
        return ['saved'=>true,'signal_pending'=>false];
    }
}
