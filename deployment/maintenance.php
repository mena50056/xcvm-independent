<?php
require dirname(__DIR__).'/runtime/bootstrap.php';
require dirname(__DIR__).'/panel/src/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI);
exit((new \XcVm\Cli\CronJobs\CacheCronJob())->execute([]));
