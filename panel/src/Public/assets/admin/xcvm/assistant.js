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
  function message(text, user = false) {
    const node = document.createElement('div'); node.className = 'xc-ai-message' + (user ? ' user' : '');
    node.dir = 'auto'; node.textContent = text; el('feed').append(node); el('feed').scrollTop = el('feed').scrollHeight;
  }
  function setBusy(value) {
    busy = value; el('send').disabled = value; el('reset').disabled = value;
    el('pending').querySelectorAll('button').forEach(button => { button.disabled = value; });
    el('status').textContent = value ? t('جارٍ المعالجة…', 'Working…') : '';
  }
  const errors = {
    FORBIDDEN: t('ليس لديك صلاحية لهذا الإجراء.', 'You do not have permission for this action.'),
    STALE_PROPOSAL: t('انتهى التأكيد أو تغيرت البيانات. اطلب الإجراء مرة أخرى.', 'Confirmation expired or the data changed. Request it again.'),
    ACTIVATION_REQUIRED: t('هذا الخط يحتاج استرداد كود التفعيل أولًا.', 'This line requires activation code redemption first.'),
    AI_UNAVAILABLE: t('خدمة المساعد غير متاحة حاليًا. حاول لاحقًا.', 'The AI service is unavailable. Try again later.'),
    BUSY: t('انتظر انتهاء الطلب الحالي.', 'Wait for the current request.'),
    RATE_LIMIT: t('انتظر دقيقة قبل إرسال المزيد.', 'Wait a minute before sending more requests.'),
    CSRF_FAILED: t('حدّث الصفحة ثم حاول مجددًا.', 'Refresh the page and try again.'),
    SESSION_CHANGED: t('تغيرت الجلسة. حدّث الصفحة.', 'Your session changed. Refresh the page.'),
    INSUFFICIENT_CREDITS: t('الرصيد لا يكفي لتنفيذ العملية.', 'Insufficient credits.'),
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
  function clearPending() { token = ''; el('pending').replaceChildren(); el('pending').hidden = true; }
  function proposal(result) {
    clearPending(); token = result.token;
    const p = result.proposal;
    const text = t(p.enabled ? 'تأكيد تفعيل الخط' : 'تأكيد إيقاف الخط', p.enabled ? 'Confirm enabling subscription' : 'Confirm disabling subscription');
    const titles = {
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
          answer.execution.queued ? t('خادم البث قبل الطلب. افحص حالة القناة للتأكد من النتيجة.', 'The stream backend accepted the request. Check channel status for the outcome.') :
          answer.execution.signal_pending ? t('تم حفظ التغيير، لكن تعذر إرسال إشعار التحديث. راجع الحالة.', 'Change saved, but the update notification failed. Check the current state.') :
          t('تم تنفيذ التغيير وتسجيل العملية بنجاح.', 'Change saved and audited successfully.'));
      } catch (error) { clearPending(); message(error.message); }
      finally { setBusy(false); }
    };
    el('pending').append(description, confirm, cancel); el('pending').hidden = false;
  }
  el('launch').onclick = async () => {
    el('panel').hidden = !el('panel').hidden; el('launch').setAttribute('aria-expanded', String(!el('panel').hidden));
    if (el('panel').hidden) return;
    el('input').focus();
    if (!csrf) {
      setBusy(true);
      try { const result = await request(); csrf = result.csrf; if (!result.enabled) throw new Error(errors.AI_UNAVAILABLE);
        message(t('أساعدك في الاشتراكات والبث والريسيلرز والأرصدة والتقارير حسب صلاحياتك. حدّد الهدف والقيم المطلوبة؛ سأعرض تفاصيل أي تغيير لتأكيدها قبل التنفيذ.', 'I can help with subscriptions, streaming, resellers, credits and reports within your permissions. Specify the target and values; review each change before confirming.'));
      } catch (error) { message(error.message); } finally { setBusy(false); }
    }
  };
  el('close').onclick = () => { el('panel').hidden = true; el('launch').setAttribute('aria-expanded', 'false'); el('launch').focus(); };
  root.addEventListener('keydown', event => { if (event.key === 'Escape') el('close').click(); });
  el('form').onsubmit = async event => {
    event.preventDefault(); const text = el('input').value.trim(); if (busy || !text || !csrf) return;
    clearPending(); message(text, true); el('input').value = ''; setBusy(true);
    try { const result = await request({operation: 'chat', message: text}); if (result.proposal) proposal(result); else message(result.message); }
    catch (error) { message(error.message); } finally { setBusy(false); el('input').focus(); }
  };
  el('reset').onclick = async () => {
    if (busy || !csrf) return; setBusy(true);
    try { await request({operation: 'reset'}); clearPending(); el('feed').replaceChildren(); }
    catch (error) { message(error.message); } finally { setBusy(false); }
  };
})();
