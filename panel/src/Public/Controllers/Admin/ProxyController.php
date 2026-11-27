<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;

/**
 * ProxyController — Edit Proxy.
 *
 * @renders Views/admin/proxy.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ProxyController extends BaseAdminController {
	public function index() {
		global $rProxyServers;

		$this->requirePermission();
		if (defined('XCVM_INDEPENDENT_LAB')) {
			$id=(int)RequestManager::get('id');
			$this->requireAdvPermission('adv',$id?'edit_server':'add_server');
			$profiles=\XcVm\Core\Proxy\ProxyProfiles::app();$row=$id?$profiles->row($id):null;
			if($id&&!$row){$this->redirect('proxies');return;}
			$doc=$id?$profiles->document($id):[];
			$_SESSION['horus_proxy_csrf']??=bin2hex(random_bytes(32));
			$this->setTitle('Horus Proxy');
			$this->render('horus_proxy',['proxyId'=>$id,'profile'=>$doc['profile']??$profiles->defaults($row),'profileRevision'=>$doc['revision']??'','proxyParents'=>$profiles->parents()]);return;
		}

		if (RequestManager::has('id') && isset($rProxyServers[RequestManager::get('id')])) {
			// The proxy as stored: the form escapes what it prints and posts it back,
			// so text read through the row cleaner ('&lt;' for '<') would be saved escaped.
			$GLOBALS['db']->query('SELECT * FROM `servers` WHERE `id` = ?;', intval(RequestManager::get('id')));
			$rServerArr = $GLOBALS['db']->get_raw_row();
			if (($rServerArr['server_type'] ?? null) != 1) {
				$this->redirect('proxies');
				return;
			}
		} else {
			$this->redirect('proxies');
			return;
		}

		$this->setTitle('Edit Proxy');
		$this->render('proxy', ['rServerArr' => $rServerArr]);
	}
}
