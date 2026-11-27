<?php
namespace XcVm\Public\Controllers\Reseller;

// Keep old URLs explicit: the shared controller rejects this scope with HTTP 403.
class AssistantController extends \XcVm\Public\Controllers\Admin\AssistantController {
    protected string $scope = 'reseller';
}
