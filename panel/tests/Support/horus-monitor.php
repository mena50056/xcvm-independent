<?php
namespace XcVm\Core\Database {
    class DatabaseHandler {
        public array $rows = [];
        public bool $fail = false;
        public function query($sql) {
            if (!preg_match('/^\s*SELECT\b/i', $sql)) throw new \RuntimeException('Export attempted to modify records');
            return !$this->fail;
        }
        public function get_rows() { return $this->rows; }
    }
}
namespace {
    require __DIR__.'/../../src/Infrastructure/Database/DatabaseAware.php';
    require __DIR__.'/../../src/Core/Diagnostics/DiagnosticsService.php';
    require __DIR__.'/../../src/Core/Cluster/NodeRpc.php';
    function check($ok, $name) {if (!$ok) throw new \RuntimeException($name);}
    $db = new \XcVm\Core\Database\DatabaseHandler();
    \XcVm\Core\Diagnostics\DiagnosticsService::setDb($db);
    $db->rows = [['type'=>'php','log_message'=>'<error>','date'=>1700000000,'line'=>12]];
    $first = \XcVm\Core\Diagnostics\DiagnosticsService::downloadPanelLogs();
    $second = \XcVm\Core\Diagnostics\DiagnosticsService::downloadPanelLogs();
    check(count($first['errors'])===1 && $first===$second, 'Repeated exports must preserve records');
    check($first['errors'][0]['message']==='&lt;error&gt;', 'Escaped export message');
    $db->rows=[];check(\XcVm\Core\Diagnostics\DiagnosticsService::downloadPanelLogs()['errors']===[], 'Empty export');
    $db->fail=true;
    try {\XcVm\Core\Diagnostics\DiagnosticsService::downloadPanelLogs();throw new \RuntimeException('Missing error');}
    catch (\Exception $e) {check($e->getMessage()==='Failed to process panel logs','Query failures must propagate');}
    \XcVm\Core\Cluster\NodeRpc::useTransport(static fn()=>json_encode([
        'USER PID CPU MEM VSZ RSS TTY STAT TIME ELAPSED COMMAND',
        'xcvm_lab 101 1.0 0.2 2000 1024 ? S 00:01:00 01:00:00 php worker.php',
        'xc_vm 102 2.0 0.3 3000 2048 ? S 00:02:00 1-01:00:00 ffmpeg',
        'root 1 0 0 100 10 ? S 00:00:00 00:10:00 init',
        'malformed row'
    ]));
    $processes=\XcVm\Core\Diagnostics\DiagnosticsService::getPIDs(1);
    check(count($processes)===2 && $processes[0]['pid']==='101', 'Independent service processes must be visible');
    check($processes[0]['etime']===3600 && $processes[1]['etime']===90000, 'Process durations');
    \XcVm\Core\Cluster\NodeRpc::useTransport(null);
    echo "PASS: non-destructive exports, empty logs, escaped text, database failures, service users and process durations\n";
}
