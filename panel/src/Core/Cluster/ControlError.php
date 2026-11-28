<?php
declare(strict_types=1);
namespace XcVm\Core\Cluster;

/** Only documented control codes may cross the node boundary; never raw process output. */
final class ControlError {
    public static function fromBody(string $body): ?string {
        if(strlen($body)>4096)return null;
        $data=json_decode($body,true);$code=is_array($data)?($data['error']??null):null;
        return is_string($code)&&in_array($code,[
            'INVALID_STREAM_ID','STREAM_NOT_ASSIGNED','REPLICA_UNAVAILABLE','NO_RUNTIME_DIRECTIVE',
            'UNSUPPORTED_STREAM_RUNTIME','CONTROL_STATE_WRITE_FAILED','SUPERVISOR_STOP_FAILED',
            'STREAM_REMOVE_FAILED','STATE_REPORT_FAILED','SUPERVISOR_START_FAILED',
            'STREAM_REGISTER_FAILED','STREAM_START_FAILED','UNSUPPORTED_WITHOUT_PHP',
        ],true)?$code:null;
    }
}
