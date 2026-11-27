<?php
declare(strict_types=1);
namespace XcVm\Cli\CronJobs;
use XcVm\Cli\{CommandInterface,CronTrait};
use XcVm\Core\Proxy\{ProxyMonitor,ProxyProfiles};

final class ProxyMonitorCronJob implements CommandInterface {
    use CronTrait;
    public function getName(): string {return 'cron:proxy_monitor';}
    public function getDescription(): string {return 'Probe confirmed proxy ports and update local incidents';}
    public function execute(array $args): int {
        if(!defined('XCVM_INDEPENDENT_LAB')||!$this->assertRunAsXcVm())return 1;
        $this->initCron('Horus[Proxy Monitor]');$profiles=ProxyProfiles::app();$monitor=new ProxyMonitor($profiles);$failed=false;$start=microtime(true);$rows=$profiles->proxies();
        foreach($rows as &$row)try{$row['checked']=$monitor->lastCheckedAt((int)$row['id']);}catch(\Throwable){$row['checked']=0;}unset($row);
        usort($rows,static fn($a,$b)=>$a['checked']<=>$b['checked']);
        foreach($rows as $row) {
            // Oldest first, so many unreachable edges cannot starve later rows.
            // A worst-case proxy adds at most 80s; stop starting new work after 90s.
            if(microtime(true)-$start>90)break;
            try{$monitor->run((int)$row['id']);}catch(\Throwable $error){$failed=true;fwrite(STDERR,'Proxy '.(int)$row['id'].': monitoring unavailable'.PHP_EOL);}
        }
        return $failed?1:0;
    }
}
