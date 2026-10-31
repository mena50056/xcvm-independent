# XCVM Independent — experimental distribution

مستودع النسخة التجريبية المستقلة. التغيير الحالي في أدوات التنصيب والتجميع؛
لم نغيّر شكل الواجهة أو طريقة إدارة القنوات والمستخدمين.

المصدر والإصدارات: `mena50056/xcvm-independent` — المستودع عام الآن.
الإصدار الكامل الحالي `v0.1.0-lab.4`، ومُثبّت MAIN المثبت على الوسم
`installer-public-v7`. الحزم والمصدر من هذا المستودع العام، دون توكن GitHub
أو مفتاح تفعيل رسمي. ينشئ التنصيب مفاتيح مستقلة وبيانات دخول جديدة على السيرفر نفسه.

## تنصيب MAIN على IP بدون تدخل

شغّل أمرًا واحدًا بحساب root على Ubuntu 22.04 amd64 فارغ:

```bash
python3 -c 'import subprocess,sys,urllib.request; u="https://raw.githubusercontent.com/mena50056/xcvm-independent/installer-public-v7/deployment/bootstrap-main-ip.sh"; sys.exit(subprocess.run(["bash","-s","--"],input=urllib.request.urlopen(u,timeout=60).read()).returncode)'
```

يكتشف IPv4 العام المعيّن للسيرفر تلقائيًا، ويثبت MAIN ويطبع رابط الدخول
`https://IP/lab/login` واسم admin وكلمة مرور عشوائية. لا يطلب دومينًا أو توكنًا
أو مفتاح تفعيل. تُنشأ شهادة HTTPS ذاتية التوقيع للـIP.

يتوقف قبل تثبيت الحزم أو تغيير الخدمات إذا وجد مسار بانل معروفًا، أو قاعدة
بياناتها، أو حسابها أو ملف خدمتها، أو منفذ ويب مشغولًا. لا يحذف بانل قائمة
ولا يرقيها. تفاصيل التنفيذ تُحفظ في ملف خاص `/root/xcvm-install-*.log`.
العنوان السابق المحمي يحتاج `--allow-original-test-host` صراحة؛ هذا لا يتجاوز
فحص وجود بانل. إذا كان للسيرفر أكثر من IP دون مسار افتراضي واضح، استخدم
`--server-ip=IP` بدل الاكتشاف. الشبكات التي لا تعيّن IP عامًا محليًا ليست ضمن هذا المسار.

حزمة `.4` تضم إصلاحات حفظ القنوات وHLS والتشفير المحلي المستقل، ووكيل اللود
المختبر `0.16.0-independent.1`، ومسار زر تنصيب اللود من GitHub العام، وخدمة
signals لتحديث كاش المشاهدة. يشمل فحص النجاح HTTPS فعليًا مع التحقق من شهادة
IP وفحص SHA-256 للوكيل. إعداد HTTP على المنفذ 80 يحول إلى HTTPS.

اختُبرت حراس الرفض على Linux واختبارات الحزم والتشفير؛ يبقى التنصيب الكامل
على جهاز فارغ اختبارًا منفصلًا عن فحوص الحزمة. لا تستخدم هذا الأمر لترقية سيرفر قائم.

## حدود الإصدار

- Ubuntu 22.04 amd64 فقط، سيرفر جديد، ومسار `/opt/xcvm-independent-lab`.
- المُثبّت يرفض أي بانل موجود، ولا ينفذ ترقية أو فورمات.
- عنوان السيرفر الأصلي محظور صراحة في المُثبّت.
- حزم النظام تُحمّل من مستودعات Ubuntu؛ ملفات البانل واللود من Releases بهذا المستودع.
- `xcvm_core` وionCube الأصلية مستبعدتان؛ تُنشأ مفاتيح تشفير محلية مستقلة.
- هذه نسخة مختبر، وليست نسخة إنتاج مكتملة. راجع NOTICE.md.
- تحديث `independent-lb-button-v3` يربط زر تنصيب اللود بالمثبت المستقل؛ راجع القسم التالي لتحديث MAIN القائمة.
- تحديث النسخ القائمة يحتاج مسار ترقية منفصل؛ لا تشغّل المُثبّت على نسخة قائمة.

## تنصيب اللود من زر البانل

التحديث `independent-lb-button-v3` مستخدم الآن على MAIN التجربة. الزر الحالي
يستهلك بيانات SSH من ملف خاص مؤقت، ويتحقق من مفتاح SSH المحفوظ أو المحدد
من المسؤول، ويرفض تنصيب اللود على MAIN أو فوق بانل قائمة.

ينزّل اللود حزمته من Releases العامة لهذا المستودع مع فحص SHA-256، وينشئ
مفاتيحه محليًا، ثم MAIN تسجل نفس صف السيرفر الموجود في الواجهة وتصدر له
enrollment مشفرًا وموقعًا. لا تُرسل بيانات قاعدة MAIN أو مفاتيحها الخاصة.
تُنقل شهادة MAIN العامة عبر SSH، وتُستخدم في ملف ثقة خاص بخدمة agent؛
التحقق من HTTPS وIP يبقى مفعّلًا، ولا تتغير ثقة النظام العامة.

تعرض صفحة التنصيب التقدم، ولا تُسجل النتيجة ناجحة إلا بعد استلام MAIN
نبضة حديثة وبيانات مراقبة من العقدة. يلزم root SSH وUbuntu 22.04 على اللود.
البروكسي غير مشمول بهذا التحديث. تجديد شهادة MAIN مستقبلًا يحتاج تحديث ملف
ثقة العقد؛ لا يجري قبول شهادة بديلة مجهولة تلقائيًا.

لتحديث MAIN مستقلة أخرى قائمة، نزّل السورس بالوسم نفسه وشغّل بحساب root:

```bash
python3 deployment/apply-lb-button.py --source "$PWD"
```

الأداة تحفظ نسخة من الملفات السابقة، وتتحقق من بصمات التحديث وصحة PHP قبل
تفعيل الزر، ولا تعدّل قواعد البيانات أو القنوات أو المستخدمين.

الاختبارات الآلية شملت رفض بانل قائمة عبر SSH الحقيقي من MAIN، وفحص TLS
بشهادة صحيحة وأخرى غير موثوقة أو لعنوان مختلف، وتنزيل حزمة اللود العامة
وتوليد مفاتيحه، وتسجيل Go agent مؤقت واستلام نبضته الفعلية على MAIN.
تم أيضًا تنصيب لود فعلي من الصفر على Ubuntu 22.04 عبر نفس مسار الزر،
وتشغيل خدمتي agent وfanout واستلام MAIN نبضة موثقة؛ دون توكن GitHub أو مفتاح تفعيل أصلي.

## نشر حزم الإصدارات

ارفع المصدر المنظف إلى Git. ضع الملفين الناتجين و`SHA256SUMS` و`install.py`
و`fetch_release.py` في Release بالوسم `v0.1.0-lab.3`:

```
xcvm-independent-main-linux-amd64.tar.gz
xcvm-independent-node-linux-amd64.tar.gz
SHA256SUMS
install.py
fetch_release.py
```

لا ترفع مجلدات `private` أو `.runtime-state` أو قواعد البيانات أو شهادات TLS
أو ملف هوية عقدة مسجلة. الحفاظ على إشعارات الترخيص مطلوب.

## تنصيب MAIN

نزّل `install.py` و`fetch_release.py` من الإصدار الخاص عبر حسابك، ثم انقلهما
إلى مجلد واحد على السيرفر الجديد. يحتاج المُثبّت Python 3.10 أو أحدث
(متوفر افتراضيًا في Ubuntu 22.04).
أنشئ ملفًا محليًا `chmod 600` يحتوي تصريح GitHub محدودًا بالمستودع وبصلاحية
Contents: read. لا تضع قيمة التصريح في الأمر أو الرابط أو السجل.

```bash
sudo python3 install.py --role main --repository mena50056/xcvm-independent \
  --tag v0.1.0-lab.3 --token-file /root/github-read-token \
  --server-ip NEW_SERVER_IP --hostname panel.example.com --plan

sudo python3 install.py --role main --repository mena50056/xcvm-independent \
  --tag v0.1.0-lab.3 --token-file /root/github-read-token \
  --server-ip NEW_SERVER_IP --hostname panel.example.com --admin-user admin \
  --tls-cert /path/fullchain.pem --tls-key /path/privkey.pem
```

بيانات الدخول تُنشأ عشوائيًا في ملف خاص بالسيرفر:
`/opt/xcvm-independent-lab/.runtime-state/main/admin-login.json`.
واجهة البانل `/lab/login`. الشهادة الافتراضية ذاتية التوقيع؛ تواصل الكلاستر
بين أجهزة مختلفة يحتاج شهادة HTTPS موثوقة يثق بها الطرف الآخر. يمكن تمرير
`--tls-cert /path/fullchain.pem --tls-key /path/privkey.pem` أثناء التنصيب.
لا يفتح المُثبّت الجدار الناري تلقائيًا؛ احتفظ بوصول SSH وافتح منافذ الخدمة المطلوبة.

## MAIN على IP بدون دومين

في الإصدار `v0.1.0-lab.3` يمكن حذف `--hostname` و`--tls-cert` و`--tls-key`.
ينشئ المُثبّت شهادة ذاتية التوقيع تحتوي IP السيرفر، ويكون رابط الدخول
`https://SERVER_IP/lab/login`. لا يحتاج ذلك إلى certbot أو تسجيل دومين.
سيعرض المتصفح تنبيهًا لأن الشهادة غير موثوقة تلقائيًا. ربط لود على جهاز آخر
يحتاج شهادة موثوقة للـIP أو إعداد ثقة بشهادة MAIN؛ لا يتم تعطيل التحقق من TLS.
التنصيب الكامل من الصفر والربط بين جهازين عبر IP لم يُعتمدا بعد.

```bash
python3 deployment/install.py --role main --tag v0.1.0-lab.3 \
  --token-file /root/github-read-token --server-ip SERVER_IP --admin-user admin
```

## تسجيل وتنصيب عقدة لود مستقلة

1. نزّل حزمة `node` من نفس الإصدار بواسطة `fetch_release.py` إلى السيرفر الجديد.
2. استخرج executable `panel/src/bin/xc_agent/xc_agent` إلى مجلد تجهيز خاص.
3. شغّل `xc_agent keygen -uuid NODE_UUID -state /root/node-setup/agent.json`.
   لا تنقل المفتاح الخاص إلى MAIN؛ انقل ناتج المفاتيح العامة فقط.
4. على MAIN استخدم `deployment/enroll-node.php` لتسجيل المفاتيح العامة وإصدار
   enrollment JSON مشفر وموقّع لهذه العقدة، ثم انقله بجانب `agent.json`.
5. شغّل المُثبّت:

```bash
sudo python3 install.py --role node --repository mena50056/xcvm-independent \
  --tag v0.1.0-lab.3 --token-file /root/github-read-token \
  --server-ip NEW_NODE_IP --enrollment-file /root/node-setup/enrollment.json
```

عقدة اللود تعمل بـGo دون بيانات دخول قاعدة MAIN ودون PHP أو إضافة التفعيل.
لا تنسخ هوية عقدة مسجلة إلى جهاز آخر.

أمر التسجيل على MAIN (ضع المفاتيح العامة في `node-public.json`):

```bash
sudo env XCVM_LAB_STATE=/opt/xcvm-independent-lab/.runtime-state/main \
  /opt/xcvm-independent-lab/panel/src/bin/php/bin/php \
  -c /opt/xcvm-independent-lab/php.ini \
  /opt/xcvm-independent-lab/deployment/enroll-node.php \
  --public-keys=/root/node-public.json --server-ip=NEW_NODE_IP \
  --main-url=https://panel.example.com/cluster/v1/ --output=/root/enrollment.json
```

استبدل `panel.example.com` بدومينك الذي يشير إلى MAIN، وثبّت شهادة موثوقة له
قبل تسجيل العقد. إذا كان للود دومين وشهادة، أضف `--hostname=...` إلى أمر
التسجيل ونفس `--hostname` مع مساري الشهادة إلى أمر تنصيب اللود.

## الاختبارات والتجميع

`deployment/build.py` ينشئ checkout نظيفًا وحزمتي MAIN/node من مدخلات تشغيل
محلية منتقاة. يتعمد عدم تنزيل أي ملفات من مستودعات المطوّر الأصلي.
الحزم لا تتضمن قنوات التجربة أو حسابات المشاهدين أو مفاتيح التجربة.

```bash
python3 deployment/build.py --source-output /tmp/xcvm-clean \
  --release-output /tmp/xcvm-release \
  --php-runtime /secure/runtime-input.tar.gz \
  --go-runtime /secure/go-runtime-input.tar.gz \
  --native-agent /secure/xc_agent-linux-amd64
```

ملفات التشغيل الثنائية تُنشر في Releases، ولا تُلزم Git بتخزينها.
بصمة SHA-256 تكشف تلف الملفات؛ الثقة في الإصدار تعتمد أيضًا على حماية حساب GitHub.

## HLS validation on an independent load balancer

A temporary synthetic H.264/AAC channel was saved through the authenticated panel form handler and assigned only to a fresh load balancer. A temporary viewer requested its HLS URL through MAIN. MAIN redirected to the load balancer; three playlist refreshes advanced the media sequence, and three real MPEG-TS segments returned HTTP 200. FFmpeg decoded 450 video frames across approximately 18 seconds of segments, with AAC audio. TLS hostname and certificate checks remained enabled. The channel, viewer, bouquet, source process and generated node segments were removed afterward.

This validation exposed and fixed three issues in the source and the running test MAIN: temporal SQL defaults must be evaluated by MariaDB, the independent crypto adapter must classify signed commands, and confirmed codec metadata from fanout must clear the pending-analysis flag. The full v0.1.0-lab.4 MAIN and node payloads include these changes.

The original agent acknowledged controls without executing them when PHP was absent. Native agent release `native-controls-v1` fixes live-stream start, stop and restart inside Go. MAIN routes live starts only when the node advertises `native_stream_controls`; unsupported operations fail explicitly. Stops persist across replica refresh and agent restart. MAIN now runs its signals worker to refresh viewer caches from authenticated stream events.

The panel buttons were exercised on a temporary normal H.264/AAC HLS channel: Start produced real video on the load balancer; Stop removed the producer and made existing/fresh HLS requests return 404; the stopped channel stayed stopped after an agent restart; Start restored HLS; Restart changed the encoder PID and restored HLS. Test records and source processes were removed. This validates these live controls, not VOD/root administration or every source format.

`deployment/native-agent.json` pins the compressed and uncompressed agent SHA-256. The button installer overlays that verified public GitHub release asset on the existing node bundle, with no token. The matching full native source and tests are in `native/fanout`, with the upstream commit and build instructions. Fresh MAIN installations from installer-public-v7 use the complete v0.1.0-lab.4 bundle. Existing installations can use the separate independent-lb-button-v4 source updater; the fresh-host bootstrap always refuses existing panels.

## Independent GitHub update notifications

Fresh MAIN installations using `installer-public-v7` enable hourly release checks and the existing Settings update control automatically. Existing MAIN installations enable it once using [the update setup command](deployment/UPDATES.md). Updating application code requires pressing Update now; checks alone do not install anything. Only compatible, checksummed MAIN update releases are offered. See [update scope and publishing](deployment/UPDATES.md).
