(() => {
  'use strict';
  const root = document.getElementById('xc-assistant');
  if (!root) return;
  const el = id => document.getElementById('xc-ai-' + id);
  const ar = document.documentElement.lang.startsWith('ar') || document.documentElement.dir === 'rtl';
  const t = (a, e) => ar ? a : e;
  let csrf = '', busy = false, token = '';
  el('panel').dir = ar ? 'rtl' : 'ltr';
  el('title').textContent = t('مساعد XC', 'XC Assistant');
  el('subtitle').textContent = t('مساعد محلي • يعمل حسب صلاحياتك', 'Local AI • Your permissions apply');
  el('input').placeholder = t('اسأل أو اطلب إجراءً محددًا داخل البانل…', 'Ask a question or request a specific panel action…');
  el('send').textContent = t('إرسال', 'Send');
  el('reset').textContent = t('محادثة جديدة', 'New conversation');
  let installationActive = false, installationTimer = null, installationGeneration = 0;
  function message(text, user = false) {
    const node = document.createElement('div'); node.className = 'xc-ai-message' + (user ? ' user' : '');
    node.dir = 'auto'; node.textContent = text; el('feed').append(node); el('feed').scrollTop = el('feed').scrollHeight;
  }
  function setBusy(value) {
    root.classList.toggle('xc-install-active', installationActive);
    busy = value; el('send').disabled = value || installationActive; el('input').disabled = installationActive; el('reset').disabled = value || installationActive;
    if (el('install')) el('install').disabled = value || installationActive;
    el('pending').querySelectorAll('button').forEach(button => { button.disabled = value; });
    el('status').textContent = value ? t('جارٍ المعالجة…', 'Working…') : '';
  }
  const errors = {
    INSTALL_EXISTING_SERVER: t('هذا العنوان مسجل بالفعل أو يخص MAIN. التنصيب الجديد فقط متاح.', 'This address is already registered or belongs to MAIN. Only fresh installations are supported.'),
    INSTALL_TARGET_BUSY: t('يوجد تنصيب قيد التنفيذ أو نتيجة غير مؤكدة لهذا العنوان؛ راجعها أولًا.', 'An installation is pending or uncertain for this address; review it first.'),
    INSTALL_CREDENTIALS: t('بيانات التنصيب غير متاحة. ابدأ خطة جديدة.', 'Installation credentials are unavailable. Start a new plan.'),
    PROXY_RESTART_UNAVAILABLE: t('إعادة التشغيل غير متاحة: تحقق من تحديث الوكيل واتصاله والتفعيل واكتمال تطبيق الإعداد.', 'Restart unavailable: check agent version, connection, enabled state and configuration acknowledgement.'),
    PROXY_RESTART_NOT_INDICATED: t('التشخيص لا يثبت عطلًا يناسب إعادة التشغيل. راجع السبب والخطوة المقترحة.', 'Diagnosis does not show a restart-relevant fault. Review the findings and next step.'),
    PROXY_OPERATION_PENDING: t('يوجد طلب إعادة تشغيل أو فحص متابعة قيد الانتظار. انتظر نتيجته أولًا.', 'A restart or follow-up check is pending. Wait for its result first.'),
    PROFILE_NOT_CONFIGURED: t('البروكسي يحتاج إعداد ملفه أولًا من صفحة البروكسي.', 'Configure this proxy profile on the proxy page first.'),
    INVALID_PROXY_CONFIGURATION: t('إعداد البروكسي غير صالح. راجع المنافذ والسيرفرات والقيم المطلوبة.', 'Invalid proxy configuration. Review ports, upstreams and requested values.'),
    FORBIDDEN: t('ليس لديك صلاحية لهذا الإجراء.', 'You do not have permission for this action.'),
    STALE_PROPOSAL: t('انتهى التأكيد أو تغيرت البيانات. اطلب الإجراء مرة أخرى.', 'Confirmation expired or the data changed. Request it again.'),
    ACTIVATION_REQUIRED: t('هذا الخط يحتاج استرداد كود التفعيل أولًا.', 'This line requires activation code redemption first.'),
    AI_UNAVAILABLE: t('خدمة المساعد غير متاحة حاليًا. حاول لاحقًا.', 'The AI service is unavailable. Try again later.'),
    BUSY: t('انتظر انتهاء الطلب الحالي.', 'Wait for the current request.'),
    RATE_LIMIT: t('انتظر دقيقة قبل إرسال المزيد.', 'Wait a minute before sending more requests.'),
    CSRF_FAILED: t('حدّث الصفحة ثم حاول مجددًا.', 'Refresh the page and try again.'),
    SESSION_CHANGED: t('تغيرت الجلسة. حدّث الصفحة.', 'Your session changed. Refresh the page.'),
    INSUFFICIENT_CREDITS: t('الرصيد لا يكفي لتنفيذ العملية.', 'Insufficient credits.'),
    CREATION_REFUSED: t('تعذر الإنشاء بقواعد البانل الحالية ولم يُحفظ الطلب. راجع الباقة والمجموعة والرصيد.', 'Creation was refused by the panel rules and rolled back. Check the package, group and balance.'),
    USERNAME_EXISTS: t('اسم المستخدم موجود بالفعل. اختر اسمًا آخر.', 'That username already exists. Choose another name.'),
    PACKAGE_RENEWAL_REFUSED: t('تعذر تجديد الاشتراك بقواعد الباقة الحالية. راجع الباقة والصلاحيات.', 'The package renewal was refused. Check package rules and permissions.'),
    CONTROL_REFUSED: t('خادم البث رفض الطلب أو لم يؤكد استلامه. راجع حالة القناة.', 'The stream backend refused or did not acknowledge the request. Check channel status.'),
    PAIRED_LINE_UNSUPPORTED: t('هذا الخط مرتبط بجهاز أو خط آخر. عدّله من صفحة الاشتراك.', 'This subscription is paired. Use the subscription page.'),
    UNLIMITED_EXPIRY: t('هذا الاشتراك بلا تاريخ انتهاء؛ لا يحتاج إضافة أيام.', 'This subscription has no expiry to extend.'),
    INVALID_ARGUMENTS: t('قيم الطلب غير صالحة. وضّح المبلغ أو الهدف المطلوب.', 'Invalid arguments. Specify the exact target and values.')
  };
  async function request(data) {
    const response = await fetch(root.dataset.endpoint, {credentials: 'same-origin', cache: 'no-store',
      method: data ? 'POST' : 'GET', headers: data ? {'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest'} : {'X-Requested-With': 'XMLHttpRequest'},
      body: data ? JSON.stringify(data) : undefined});
    const result = await response.json();
    if (result.result === false) throw new Error(errors.SESSION_CHANGED);
    if (!response.ok || result.error) throw new Error(errors[result.error] || t('تعذر إكمال الطلب. تحقق من الحالة قبل إعادة المحاولة.', 'Request failed. Check the current state before retrying.'));
    return result;
  }
  let followupTimer = null, followupGeneration = 0;
  const followupKey = 'xc-proxy-followup:' + root.dataset.endpoint;
  function stopFollowup(clear = false) { followupGeneration++; clearTimeout(followupTimer); if (clear) { try { sessionStorage.removeItem(followupKey); } catch (_) {} } }
  function watchProxy(proxyId, operationId) {
    stopFollowup(); const generation = followupGeneration, started = Date.now(); let failures = 0;
    try { sessionStorage.setItem(followupKey, JSON.stringify({proxyId, operationId})); } catch (_) {}
    async function poll() {
      if (generation !== followupGeneration) return;
      try {
        const result = await request({operation:'proxy_followup', proxy_id:proxyId, operation_id:operationId});
        if (generation !== followupGeneration) return;
        failures = 0; const verification = result.operation?.verification;
        if (verification?.status === 'complete') {
          stopFollowup(true); message((ar ? verification.report_ar : verification.report_en) + '\n' + t('وقت الفحص: ', 'Checked at: ') + new Date(verification.checked_at * 1000).toLocaleString());
          if (verification.followup_available) {
            const button = document.createElement('button'); button.type = 'button'; button.textContent = t('عرض إعادة الفحص للتأكيد', 'Review another check for confirmation');
            button.onclick = async () => { if (busy) return; setBusy(true); button.disabled = true;
              try { proposal(await request({operation:'proxy_next_step',proxy_id:proxyId,operation_id:operationId})); }
              catch (error) { message(error.message); } finally { setBusy(false); button.disabled = false; }
            }; el('feed').append(button);
          }
          return;
        }
      } catch (error) { if (generation !== followupGeneration) return; if (++failures >= 3) { stopFollowup(); message(t('تعذر جلب نتيجة المتابعة. الفحص يعمل في الخلفية؛ أعد فتح الشات أو اطلب حالة البروكسي.', 'Could not fetch follow-up. Verification continues in the background; reopen chat or ask for proxy status.')); return; } }
      if (Date.now() - started > 300000) { stopFollowup(); message(t('لم تصل نتيجة نهائية بعد. اطلب حالة البروكسي لمراجعة المتابعة؛ لا نعتبر المشكلة محلولة.', 'No final result yet. Ask for proxy status; the issue is not assumed resolved.')); return; }
      followupTimer = setTimeout(poll, 5000);
    }
    followupTimer = setTimeout(poll, 4000);
  }
  function clearPending() { token = ''; el('pending').replaceChildren(); el('pending').hidden = true; }

  const installationQuestions = {
    kind: t('ما نوع السيرفر الذي تريد تنصيبه؟', 'Which server type do you want to install?'),
    name: t('ما الاسم الذي سيظهر للسيرفر في البانل؟', 'What display name should the server have?'),
    ip: t('اكتب عنوان IPv4 العام للسيرفر الجديد. لا تستخدم عنوان البانل أو سيرفرًا مسجلًا.', 'Enter the fresh server public IPv4 address. Do not use MAIN or a registered server.'),
    ssh_port: t('ما منفذ SSH؟', 'What is the SSH port?'),
    fingerprint: t('اكتب بصمة مفتاح SSH بصيغة SHA1 من مصدر موثوق. يمكن عرضها من كونسول مزود السيرفر بالأمر: ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha1', 'Enter the expected SHA1 SSH host-key fingerprint from a trusted source. Provider console command: ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha1'),
    parent_id: t('اختر السيرفر الأصل الذي سيخدمه البروكسي.', 'Choose the upstream server for this proxy.'),
    tls_mode: t('كيف تريد إعداد شهادة HTTPS للبروكسي؟', 'How should proxy HTTPS be configured?'),
    capacity: t('ما الحد الأقصى للاتصالات؟ من 1 إلى 100000.', 'What is the connection limit? From 1 to 100000.'),
    fresh: t('هل السيرفر جديد ومخصص للتنصيب؟ Ubuntu 22.04 x86_64، وللبروكسي يمكن 24.04 أيضًا. المنافذ 80 و443 و8080 و18080 يجب ألا تستخدمها خدمة أخرى.', 'Is this a fresh dedicated Ubuntu 22.04 x86_64 server? Proxy also supports 24.04. Ports 80, 443, 8080 and 18080 must be unused.'),
    password: t('أدخل كلمة مرور SSH للمستخدم root في الحقل المحمي. لن تُرسل للموديل أو تظهر في سجل الشات.', 'Enter the root SSH password in the protected field. It is not sent to the model or shown in chat history.')
  };
  const installationPhases = {
    queued:t('ينتظر عامل التنصيب', 'Waiting for installation worker'), preflight:t('فحص SSH والبصمة ونظام السيرفر', 'Checking SSH, host key and fresh host'),
    registering:t('تسجيل السيرفر', 'Registering server'), installing:t('تنصيب الحزم والخدمات', 'Installing packages and services'),
    verifying:t('التحقق من اتصال الوكيل والمنافذ', 'Verifying agent connectivity and ports'), tls:t('إصدار شهادة HTTPS وإعداد التجديد', 'Issuing HTTPS certificate and configuring renewal'),
    worker_interrupted:t('انقطعت المتابعة؛ النتيجة غير مؤكدة', 'Worker interrupted; result is unknown')
  };
  const installationResults = {
    node_heartbeat_verified:t('اكتمل التنصيب ووصل نبض موثق حديث من العقدة. توزيع القنوات واختبار مشاهدة العميل خطوة منفصلة.', 'Installed and a fresh authenticated node heartbeat was verified. Channel assignment and viewer playback testing remain separate.'),
    proxy_ports_verified:t('اكتمل التنصيب وتحقق مسار الأصل عبر HTTP وHTTPS. هذا ليس اختبار مشاهدة عميل.', 'Installed and the upstream path was verified over HTTP and HTTPS. This is not a viewer playback test.'),
    proxy_installed_check_tls_firewall:t('البروكسي اتصل وطبق الإعداد، لكن فحص المنافذ لم ينجح بالكامل. راجع حالة التفعيل وتطبيق الإعداد وشهادة TLS وجدار مزود الخدمة قبل تشغيل العملاء.', 'Proxy connected and applied configuration, but not all port checks passed. Review the enabled and applied state, TLS and provider firewall before customer use.'),
    public_tls_failed:t('تعذر إصدار شهادة عامة؛ قد يكون البروكسي منصبًا بشهادة محلية. راجع منفذ 80 لدى المزود وإعداد TLS، ولا تعد التنصيب تلقائيًا.', 'Public TLS issuance failed; the proxy may be installed with local TLS. Review provider port 80 and TLS before any retry.'),
    fresh_server_required:t('توقف قبل التنصيب: نظام غير مدعوم أو تثبيت موجود أو منافذ مشغولة. اختر سيرفرًا جديدًا مناسبًا.', 'Stopped before installation: unsupported OS, existing installation or occupied ports. Choose a suitable fresh server.'),
    ssh_connection:t('تعذر الاتصال بمنفذ SSH. راجع العنوان والمنفذ وجدار الخدمة.', 'SSH connection failed. Check address, port and firewall.'),
    ssh_fingerprint:t('بصمة SSH لا تطابق البصمة المؤكدة؛ لم يبدأ التنصيب.', 'SSH host key does not match the confirmed fingerprint; installation did not begin.'),
    ssh_authentication:t('رفض السيرفر بيانات دخول SSH؛ لم يبدأ التنصيب.', 'SSH authentication failed; installation did not begin.'),
    permission_revoked:t('توقف الطلب لأن صلاحية الأدمن تغيرت.', 'Stopped because administrator permission changed.'),
    existing_server:t('العنوان أصبح مسجلًا؛ تم رفض التنصيب.', 'Address is now registered; installation refused.'),
    node_install_failed:t('فشل مثبت العقدة أو تحقق النبض. قد تبقى حزم أو سجل سيرفر؛ راجع صفحة السيرفر قبل أي محاولة جديدة.', 'Node installer or heartbeat verification failed. Packages or a server record may remain; review the server before retrying.'),
    proxy_sync_failed:t('لم تصل مزامنة موثقة حديثة من البروكسي. راجع اتصاله بـMAIN وخدمة الوكيل.', 'Fresh authenticated proxy sync was not received. Check MAIN connectivity and the agent service.')
  };
  function showInstallation(result) {
    const job=result.installation; if (!job) return;
    clearTimeout(installationTimer); const generation=++installationGeneration;
    clearPending(); installationActive=['draft','review','queued','running'].includes(job.state);
    el('pending').hidden=false;
    const box=document.createElement('div');box.className='xc-ai-installation';
    const text=document.createElement('div');box.append(text);
    const submit=async(operation,value) => {
      if(busy)return;setBusy(true);
      try { showInstallation(await request({operation,job_id:job.id,token:job.token,...(value===undefined?{}:{value})})); }
      catch(error){message(error.message);}
      finally {setBusy(false);}
    };
    const button=(label,handler)=>{const b=document.createElement('button');b.type='button';b.textContent=label;b.onclick=handler;box.append(b);return b;};
    if(job.state==='draft') {
      text.textContent=installationQuestions[job.field];
      const choices=Object.entries(job.choices||{});const input=document.createElement(choices.length?'select':'input');
      input.setAttribute('aria-label',installationQuestions[job.field]);input.id='xc-ai-install-value';
      if(choices.length){const placeholder=document.createElement('option');placeholder.value='';placeholder.textContent=t('اختر…','Choose…');input.append(placeholder);for(const [value,label] of choices){const option=document.createElement('option');option.value=value;option.textContent=label;input.append(option);}}
      else {input.type=job.field==='password'?'password':'text';input.autocomplete='off';input.maxLength=job.field==='password'?512:160;if(job.field==='ssh_port')input.value='22';if(job.field==='capacity')input.inputMode='numeric';}
      box.append(input);
      const next=()=>{const value=input.value;if(!value)return;if(job.field==='password')input.value='';submit('install_step',value);};
      input.onkeydown=e=>{if(e.key==='Enter'){e.preventDefault();next();}};
      button(t('متابعة','Continue'),next);button(t('إلغاء التنصيب','Cancel installation'),()=>submit('install_cancel'));
      setTimeout(()=>input.focus(),0);
    } else if(job.state==='review') {
      text.textContent=t('راجع خطة التنصيب قبل التنفيذ:','Review the installation plan:')+'\n'+Object.entries(job.plan).map(([k,v])=>({kind:t('النوع','Type'),name:t('الاسم','Name'),ip:t('العنوان','Address'),ssh_port:t('منفذ SSH','SSH port'),fingerprint:t('بصمة SSH','SSH fingerprint'),parent_id:t('رقم السيرفر الأصل','Upstream ID'),tls_mode:t('شهادة HTTPS','HTTPS certificate'),capacity:t('حد الاتصالات','Connection limit'),fresh:t('سيرفر جديد','Fresh server')}[k]||k)+': '+({proxy:t('بروكسي','Proxy'),stream:t('سيرفر بث','Streaming node'),lb:t('لود بالانسر','Load balancer'),public_ip:t('شهادة IP عامة وتجديد آلي','Public IP TLS and renewal'),later:t('إعداد لاحق','Configure later'),true:t('نعم','Yes')}[String(v)]||v)).join('\n')+'\nSSH: root | HTTP: 80 | HTTPS: 443\n\n'+job.notes.join('\n\n');
      button(t('تأكيد خطة التنصيب والتنفيذ','Confirm plan and install'),()=>submit('install_confirm'));
      button(t('إلغاء','Cancel'),()=>submit('install_cancel'));
    } else {
      const terminal=!['queued','running'].includes(job.state);
      text.textContent=t('طلب التنصيب: ','Installation: ')+job.id+'\n'+(job.plan.name||'')+' — '+(job.plan.ip||'')+'\n'+
        (job.server_id?t('رقم السيرفر: ','Server ID: ')+job.server_id+'\n':'')+
        (installationPhases[job.phase]||job.state)+'\n'+(installationResults[job.result]||
          (job.state==='unknown'?t('لا نعتبر التنصيب ناجحًا ولا نعيده تلقائيًا. راجع حالة السيرفر قبل أي إجراء.', 'Success is unknown; no automatic retry. Review the server before another action.'):
          job.state==='expired'?t('انتهت صلاحية الخطة وحُذفت بيانات الدخول.', 'Plan expired and credentials were removed.'):
          job.state==='cancelled'?t('أُلغي الطلب وحُذفت بيانات الدخول.', 'Cancelled and credentials removed.'):
          job.state==='failed'?t('فشل التنفيذ؛ قد يكون جزئيًا. راجع حالة السيرفر ولا تعِد التنصيب تلقائيًا.', 'Execution failed and may be partial. Review server state; do not reinstall automatically.'):
          t('التنفيذ في الخلفية؛ يمكنك إغلاق الشات والعودة للمتابعة.', 'Running in the background; you may close the chat and return.')));
      if(job.state==='queued')button(t('إلغاء قبل بدء التنفيذ','Cancel before execution starts'),()=>submit('install_cancel'));
      if(!terminal) {
        installationTimer=setTimeout(async()=>{try{const answer=await request({operation:'install_status',job_id:job.id});if(generation===installationGeneration)showInstallation(answer);}catch(error){if(generation===installationGeneration){message(error.message);installationActive=false;setBusy(false);button(t('إعادة متابعة الحالة','Refresh progress'),()=>submit('install_status'));}}},5000);
      }
    }
    el('pending').append(box);setBusy(busy);el('pending').scrollTop=0;
  }

  function proposal(result) {
    clearPending(); token = result.token;
    const p = result.proposal;
    const text = t(p.enabled ? 'تأكيد تفعيل الخط' : 'تأكيد إيقاف الخط', p.enabled ? 'Confirm enabling subscription' : 'Confirm disabling subscription');
    const titles = {
      repair_playback: t('تأكيد إصلاح التشغيل', 'Confirm playback repair'),
      repair_import_queue: t('تأكيد إصلاح مجلد الاستيراد', 'Confirm import queue repair'),
      create_panel_backup: t('تأكيد طلب نسخة احتياطية', 'Confirm backup request'),
      recheck_proxy_after_restart: t('تأكيد فحص البروكسي مجددًا', 'Confirm another proxy diagnosis'),
      restart_proxy: t('تأكيد إعادة تشغيل البروكسي', 'Confirm proxy restart'),
      set_proxy_enabled: t('تغيير حالة البروكسي', 'Change proxy state'), set_proxy_limit: t('تعديل حدود البروكسي', 'Change proxy limit'),
      set_proxy_ports: t('تعديل منافذ البروكسي', 'Change proxy ports'), set_proxy_parents: t('تعديل سيرفرات البروكسي', 'Change proxy upstreams'),
      create_subscriptions_bulk: t('إنشاء اشتراكات بالجملة', 'Create subscription batch'),
      create_subscription: t('إنشاء اشتراك جديد', 'Create subscription'), create_reseller: t('إنشاء ريسيلر جديد', 'Create reseller'),
      extend_line_days: t('تمديد صلاحية اشتراك', 'Extend subscription expiry'), set_line_connections: t('تعديل عدد الاتصالات', 'Change connection limit'),
      control_stream: t('التحكم في قناة بث', 'Control live stream'), set_reseller_enabled: t('تغيير حالة ريسيلر', 'Change reseller status'),
      adjust_reseller_credits: t('تعديل رصيد ريسيلر', 'Adjust reseller credits'), renew_line_package: t('تجديد اشتراك بباقة مدفوعة', 'Renew subscription with a paid package')
    };
    const description = document.createElement('div');
    description.textContent = p.title ? [titles[p.title] || p.title, p.target, ...(p.details || [])].join('\n') : `${text}\n${p.username} (#${p.line_id})`;
    const confirm = document.createElement('button'); confirm.type = 'button'; confirm.textContent = t('تأكيد التنفيذ', 'Confirm action');
    const cancel = document.createElement('button'); cancel.type = 'button'; cancel.textContent = t('إلغاء', 'Cancel');
    for (const [button, operation] of [[confirm, 'confirm'], [cancel, 'cancel']]) button.onclick = async () => {
      if (busy) return; setBusy(true);
      try {
        const answer = await request({operation, token});
        clearPending();
        message(operation === 'cancel' ? t('تم إلغاء الطلب.', 'Request cancelled.') :
          answer.execution.recovery_report ? (ar ? answer.execution.recovery_report.ar : answer.execution.recovery_report.en) :
          answer.execution.proxy_followup_report ? (ar ? answer.execution.proxy_followup_report.ar : answer.execution.proxy_followup_report.en) :
          answer.execution.proxy_restart_queued ? t('تم إرسال طلب إعادة التشغيل، ولم تتأكد النتيجة بعد. سأتابع نتيجة الوكيل وأفحص المنافذ تلقائيًا وأعرض النتيجة هنا.', 'Restart queued; outcome is not confirmed yet. I will follow the agent result, verify the ports automatically and show the outcome here.') :
          answer.execution.proxy_pending && answer.execution.signal_pending ? t('تم حفظ إعداد البروكسي، لكن إشعار التحديث تعذر. راجع حالة البروكسي قبل إعادة الطلب.', 'Proxy configuration saved, but refresh notification failed. Check proxy status before retrying.') :
          answer.execution.proxy_pending ? t('تم حفظ إعداد البروكسي وتسجيله. ينتظر تأكيد التطبيق من البروكسي؛ افحص حالته قبل اعتبار التغيير مطبقًا.', 'Proxy configuration saved and audited. Awaiting proxy acknowledgement; check its status before treating the change as applied.') :
          answer.execution.queued ? t('خادم البث قبل الطلب. افحص حالة القناة للتأكد من النتيجة.', 'The stream backend accepted the request. Check channel status for the outcome.') :
          answer.execution.signal_pending ? t('تم حفظ التغيير، لكن تعذر إرسال إشعار التحديث. راجع الحالة.', 'Change saved, but the update notification failed. Check the current state.') :
          t('تم تنفيذ التغيير وتسجيل العملية بنجاح.', 'Change saved and audited successfully.'));
        if (operation === 'confirm' && answer.execution.proxy_restart_queued) watchProxy(answer.execution.proxy_id, answer.execution.operation.id);
        if (operation === 'confirm' && answer.execution.created_batch) {
          const batch = answer.execution.created_batch;
          message(t('تم إنشاء ', 'Created ') + batch.length + t(' اشتراك. إجمالي الخصم: ', ' subscriptions. Total debit: ') + answer.execution.total_debit + '\n' + t('رصيدك الحالي: ', 'Your balance: ') + answer.execution.credits_after);
          const details = document.createElement('details'); details.className = 'xc-ai-message';
          const summary = document.createElement('summary'); summary.textContent = t('عرض بيانات دخول الاشتراكات', 'Show subscription credentials');
          const body = document.createElement('div'); body.textContent = batch.map(c => '#' + c.id + ' | ' + c.credentials.username + ' | ' + c.credentials.password).join('\n');
          const download = document.createElement('button'); download.type = 'button'; download.textContent = t('تنزيل بيانات الدخول CSV', 'Download credentials CSV');
          download.onclick = () => {
            const csv = [['id', 'username', 'password'], ...batch.map(c => [c.id, c.credentials.username, c.credentials.password])].map(row => row.map(v => '"' + String(v).replaceAll('"', '""') + '"').join(',')).join('\r\n');
            const url = URL.createObjectURL(new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8'}));
            const link = document.createElement('a'); link.href = url; link.download = 'subscriptions.csv'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
          };
          details.append(summary, download, body); el('feed').append(details); el('feed').scrollTop = el('feed').scrollHeight;
        }
        if (operation === 'confirm' && answer.execution.created) {
          const c = answer.execution.created;
          message([t('تم الإنشاء برقم: ', 'Created ID: ') + c.id,
            t('اسم المستخدم: ', 'Username: ') + c.credentials.username,
            t('كلمة المرور: ', 'Password: ') + c.credentials.password,
            t('رصيدك الحالي: ', 'Your balance: ') + answer.execution.credits_after,
            t('احفظ بيانات الدخول. لا تُرسل للموديل ولا تُحفظ في سجل المحادثة.', 'Save these credentials. They are not sent to the model or stored in chat history.')].join('\n'));
        }
      } catch (error) { clearPending(); message(error.message); }
      finally { setBusy(false); }
    };
    el('pending').append(description, confirm, cancel); el('pending').hidden = false; el('pending').scrollTop = 0;
  }
  el('launch').onclick = async () => {
    el('panel').hidden = !el('panel').hidden; el('launch').setAttribute('aria-expanded', String(!el('panel').hidden));
    if (el('panel').hidden) return;
    el('input').focus();
    if (!csrf) {
      setBusy(true);
      try { const result = await request(); csrf = result.csrf; if(el('install')) {el('install').hidden=!result.installation_enabled;el('install').textContent=t('تنصيب سيرفر خطوة بخطوة','Install a server step by step');} if(result.installation)showInstallation(result); try { const saved = JSON.parse(sessionStorage.getItem(followupKey)); if (saved && Number.isInteger(saved.proxyId) && /^[a-f0-9]{32}$/.test(saved.operationId)) watchProxy(saved.proxyId, saved.operationId); } catch (_) {} if (!result.enabled) throw new Error(errors.AI_UNAVAILABLE);
        message(t('أساعدك في الاشتراكات والبث والريسيلرز والأرصدة والتقارير حسب صلاحياتك. حدّد الهدف والقيم المطلوبة؛ سأعرض تفاصيل أي تغيير لتأكيدها قبل التنفيذ.', 'I can help with subscriptions, streaming, resellers, credits and reports within your permissions. Specify the target and values; review each change before confirming.'));
      } catch (error) { message(error.message); } finally { setBusy(false); }
    }
  };
  el('close').onclick = () => { el('panel').hidden = true; el('launch').setAttribute('aria-expanded', 'false'); el('launch').focus(); };
  root.addEventListener('keydown', event => { if (event.key === 'Escape') el('close').click(); });
  el('form').onsubmit = async event => {
    event.preventDefault(); const text = el('input').value.trim(); if (busy || !text || !csrf) return;
    clearPending(); message(text, true); el('input').value = ''; setBusy(true);
    try { const result = await request({operation: 'chat', message: text}); if (result.installation) showInstallation(result); else if (result.proposal) proposal(result); else message(result.message); }
    catch (error) { message(error.message); } finally { setBusy(false); el('input').focus(); }
  };
  if(el('install'))el('install').onclick=async()=>{if(busy||!csrf)return;setBusy(true);try{showInstallation(await request({operation:'install_begin'}));}catch(error){message(error.message);}finally{setBusy(false);}};
  el('reset').onclick = async () => {
    if (busy || !csrf) return; setBusy(true);
    try { await request({operation: 'reset'}); stopFollowup(true); clearPending(); el('feed').replaceChildren(); }
    catch (error) { message(error.message); } finally { setBusy(false); }
  };
})();
