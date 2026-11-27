<?php
namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Assistant\AssistantClient;
use XcVm\Domain\Assistant\PanelTools;
use XcVm\Domain\Assistant\InstallationWizard;

class AssistantController {
    protected string $scope = 'admin';

    private function reply(array $data, int $status = 200): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public function index(): never {
        global $rUserInfo, $rPermissions;
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        // Reject legacy reseller URLs before metadata, inference or pending execution.
        unset($_SESSION['xc_assistant_reseller']);
        if ($this->scope !== 'admin') { $this->reply(['error'=>'FORBIDDEN'], 403); }
        // Admin bootstrap validates credentials, group membership and 2FA first.
        $identityKey = 'hash';
        if (empty($rUserInfo['id']) || (int) ($_SESSION[$identityKey] ?? 0) !== (int) $rUserInfo['id']
            || empty($rPermissions['is_admin'])) {
            $this->reply(['error' => 'UNAUTHENTICATED'], 401);
        }
        $owner = $this->scope . ':' . $rUserInfo['id'];
        $key = 'xc_assistant_' . $this->scope;
        $fingerprint = hash('sha256', json_encode([$owner, $rUserInfo['member_group_id'],
            array_intersect_key($rPermissions, array_flip(['is_admin', 'is_reseller', 'advanced', 'create_line', 'create_sub_resellers', 'subresellers', 'create_sub_resellers_price', 'allow_change_username', 'minimum_username_length', 'all_reports']))]));
        if (($_SESSION[$key]['fingerprint'] ?? '') !== $fingerprint) {
            $_SESSION[$key] = ['fingerprint' => $fingerprint, 'csrf' => bin2hex(random_bytes(32)), 'history' => []];
        }
        $state =& $_SESSION[$key];
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {

            $installEnabled = defined('XCVM_INDEPENDENT_LAB') && InstallationWizard::allowed();
            $resume=[];
            if ($installEnabled && isset($state['install_job'])) {
                try {$resume=InstallationWizard::app()->handle(['operation'=>'install_status','job_id'=>$state['install_job']]);} catch (\Throwable) {unset($state['install_job']);}
            }
            $this->reply(['enabled' => AssistantClient::enabled(), 'csrf' => $state['csrf'], 'installation_enabled'=>$installEnabled]+$resume);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { $this->reply(['error' => 'METHOD_NOT_ALLOWED'], 405); }
        if (!hash_equals($state['csrf'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            $this->reply(['error' => 'CSRF_FAILED'], 403);
        }
        $raw = file_get_contents('php://input', false, null, 0, 8193);
        $input = strlen($raw) <= 8192 ? json_decode($raw, true) : null;
        if (!is_array($input)) { $this->reply(['error' => 'INVALID_REQUEST'], 400); }
        $operation = $input['operation'] ?? '';
        $tools = new PanelTools($this->scope);
        try {
            if ($operation === 'reset') {
                $state['history'] = [];
                unset($state['pending'], $state['busy'], $state['conversation'], $state['postcheck_context'], $state['last_postcheck']);
                $this->reply(['ok' => true]);
            }
            if (!AssistantClient::enabled()) { throw new \RuntimeException('AI_UNAVAILABLE'); }
            if (in_array($operation,['install_begin','install_step','install_confirm','install_cancel','install_status'],true)) {
                $result=InstallationWizard::app()->handle($input);
                $state['install_job']=$result['installation']['id'];
                unset($state['pending']);
                $this->reply(['ok'=>true]+$result);
            }
            if ($operation==='chat' && isset($state['install_job'])) {
                $resume=InstallationWizard::app()->handle(['operation'=>'install_status','job_id'=>$state['install_job']]);
                if(in_array($resume['installation']['state'],['draft','review'],true))$this->reply(['ok'=>true]+$resume);
            }
            if (in_array($operation,['proxy_followup','proxy_next_step'],true)) {
                $result=$tools->call($operation==='proxy_followup'?'proxy_followup':'recheck_proxy_after_restart',['proxy_id'=>$input['proxy_id']??null,'operation_id'=>$input['operation_id']??null]);
                if($operation==='proxy_followup'){
                    $v=$result['operation']['verification']??[];
                    if(($v['status']??'')==='complete'&&($state['last_postcheck']??'')!==$input['operation_id']){
                        $state['last_postcheck']=$input['operation_id'];
                        $state['postcheck_context']='Automatic verification at '.gmdate('c',(int)$v['checked_at']).': '.$v['report_en'];
                    }
                    $this->reply(['ok'=>true]+$result);
                }
                $state['pending']=['token'=>bin2hex(random_bytes(32)),'expires'=>time()+120,'proposal'=>base64_encode(json_encode($result['proposal'],JSON_THROW_ON_ERROR))];
                $this->reply(['ok'=>true,'proposal'=>$result['proposal']['public'],'token'=>$state['pending']['token']]);
            }
            if ($operation === 'confirm' || $operation === 'cancel') {
                $pending = $state['pending'] ?? null;
                if (!$pending || !is_string($input['token'] ?? null) || !hash_equals($pending['token'], $input['token'])
                    || $pending['expires'] < time()) {
                    error_log('XC assistant confirmation refused: missing, expired or mismatched session proposal');
                    throw new \RuntimeException('STALE_PROPOSAL');
                }
                unset($state['pending']);
                if ($operation === 'cancel') { $this->reply(['ok' => true]); }
                // Persist consumption before starting any mutation: retries cannot execute again.
                session_write_close();
                // Legacy bootstrap converts session scalars into strings. An opaque
                // envelope preserves the types and exact reviewed snapshot.
                $proposal = json_decode((string) base64_decode((string) $pending['proposal'], true), true);
                if (!is_array($proposal)) { throw new \RuntimeException('STALE_PROPOSAL'); }
                $result = $tools->execute($proposal);
                $this->reply(['ok' => true, 'execution' => $result]);
            }
            if ($operation !== 'chat' || !is_string($input['message'] ?? null) || strlen(trim($input['message'])) < 1 || strlen($input['message']) > 3000) {
                $this->reply(['error' => 'INVALID_REQUEST'], 400);
            }
            if (($state['busy']['until'] ?? 0) > time()) { $this->reply(['error' => 'BUSY'], 429); }
            $state['requests'] = array_values(array_filter($state['requests'] ?? [], static fn($time) => $time > time() - 60));
            if (count($state['requests']) >= 6) { $this->reply(['error' => 'RATE_LIMIT'], 429); }
            $state['requests'][] = time();
            unset($state['pending']);
            $requestId = bin2hex(random_bytes(16));
            $state['busy'] = ['id' => $requestId, 'until' => time() + 150];
            $history = isset($state['conversation']) ? json_decode((string) base64_decode($state['conversation'], true), true) : [];
            $messages = array_slice(is_array($history) ? $history : [], -8);
            if(isset($state['postcheck_context'])){$messages[]=['role'=>'assistant','content'=>$state['postcheck_context']];unset($state['postcheck_context']);}
            $messages[] = ['role' => 'user', 'content' => trim($input['message'])];
            // Avoid blocking navigation and header polling during CPU inference.
            session_write_close();
            $response = null;
            $failure = null;
            try { $response = (new AssistantClient())->chat($messages, $tools); }
            catch (\Throwable $error) { $failure = $error; }
            session_start();
            if ((int) ($_SESSION[$identityKey] ?? 0) !== (int) $rUserInfo['id']
                || ($_SESSION[$key]['fingerprint'] ?? '') !== $fingerprint
                || ($_SESSION[$key]['busy']['id'] ?? '') !== $requestId) {
                $this->reply(['error' => 'SESSION_CHANGED'], 409);
            }
            $state =& $_SESSION[$key];
            unset($state['busy']);
            if ($failure) { throw $failure; }
            $state['conversation'] = base64_encode(json_encode($messages, JSON_THROW_ON_ERROR));
            if (!empty($response['begin_installation'])) {
                $result=InstallationWizard::app()->handle(['operation'=>'install_begin']);
                $state['install_job']=$result['installation']['id'];
                $this->reply(['ok'=>true]+$result);
            }
            if ($response['proposal']) {
                $state['pending'] = ['token' => bin2hex(random_bytes(24)), 'expires' => time() + 300,
                    'proposal' => base64_encode(json_encode($response['proposal'], JSON_THROW_ON_ERROR))];
                $proposal = $response['proposal']['public'] ?? $response['proposal'];
                unset($proposal['member_id']);
                $this->reply(['ok' => true, 'proposal' => $proposal, 'token' => $state['pending']['token']]);
            }
            $message = substr($response['message'], 0, 12000);
            $messages[] = ['role' => 'assistant', 'content' => $message];
            $state['conversation'] = base64_encode(json_encode($messages, JSON_THROW_ON_ERROR));
            $this->reply(['ok' => true, 'message' => $message]);
        } catch (\Throwable $error) {
            $code = $error->getMessage();
            $known = ['INSTALL_EXISTING_SERVER','INSTALL_TARGET_BUSY','INSTALL_CREDENTIALS','PROXY_RESTART_UNAVAILABLE', 'PROXY_RESTART_NOT_INDICATED', 'PROXY_OPERATION_PENDING', 'PROFILE_NOT_CONFIGURED', 'INVALID_PROXY_CONFIGURATION', 'FORBIDDEN', 'NOT_FOUND_OR_FORBIDDEN', 'STALE_PROPOSAL', 'ACTIVATION_REQUIRED', 'AI_UNAVAILABLE', 'TOO_MANY_TOOLS', 'INVALID_ARGUMENTS', 'INSUFFICIENT_CREDITS', 'PAIRED_LINE_UNSUPPORTED', 'UNLIMITED_EXPIRY', 'CONTROL_REFUSED', 'PACKAGE_RENEWAL_REFUSED', 'CREATION_REFUSED', 'USERNAME_EXISTS'];
            $this->reply(['error' => in_array($code, $known, true) ? $code : 'REQUEST_FAILED'], $code === 'FORBIDDEN' ? 403 : 409);
        }
    }
}
