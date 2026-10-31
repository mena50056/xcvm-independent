# XCVM Independent — experimental distribution

مستودع النسخة التجريبية المستقلة. التغيير الحالي في أدوات التنصيب والتجميع؛
لم نغيّر شكل الواجهة أو طريقة إدارة القنوات والمستخدمين.

المصدر والإصدارات: `mena50056/xcvm-independent` — المستودع عام الآن.
أداة التنصيب من الوسم `installer-public-v1` تعمل بدون توكن GitHub، وتحمّل
حزم الإصدار `v0.1.0-lab.3`. خيار `--token-file` اختياري للمستودعات الخاصة فقط.
لا تُحفظ مفاتيح GitHub أو بيانات السيرفرات في Git.

## تنصيب MAIN على IP بدون دومين أو توكن

أمر واحد دون أسئلة، مع السماح الصريح باستخدام السيرفر السابق للتجربة:

```bash
python3 -c 'import subprocess,urllib.request; u="https://raw.githubusercontent.com/mena50056/xcvm-independent/installer-public-v2/deployment/bootstrap-main-ip.sh"; subprocess.run(["bash","-s","--","--server-ip=45.32.223.254","--allow-original-test-host"],input=urllib.request.urlopen(u,timeout=60).read(),check=True)'
```

يتوقف قبل تثبيت الحزم إذا وجد مسار بانل أو قاعدة بيانات معروفة أو حساب خدمة
قديمًا أو منافذ ويب مشغولة. السماح بالعنوان السابق لا يتجاوز فحوص وجود البانل.
لا ينفذ حذفًا أو ترقية لبانل قائمة. يسجل التنفيذ في ملف خاص داخل `/root`،
ويطبع بيانات الدخول عند النجاح. يتطلب Ubuntu 22.04 amd64 وroot وPython 3.
الاختبارات تؤكد عمل حارس الرفض؛ التنصيب الكامل من الصفر ما زال تجريبيًا.

على سيرفر Ubuntu 22.04 amd64 فارغ، بحساب root، وافتح المنفذ 443:

```bash
apt-get update
apt-get install -y git python3 ca-certificates
git clone --depth 1 --branch installer-public-v1 https://github.com/mena50056/xcvm-independent.git /root/xcvm-public-install
cd /root/xcvm-public-install
read -rp "Server IP: " SERVER_IP
python3 deployment/install.py --role main --tag v0.1.0-lab.3 --server-ip "$SERVER_IP" --plan
python3 deployment/install.py --role main --tag v0.1.0-lab.3 --server-ip "$SERVER_IP" --admin-user admin
cat /opt/xcvm-independent-lab/.runtime-state/main/admin-login.json
```

الرابط `https://SERVER_IP/lab/login` بشهادة اختبار ذاتية التوقيع.
هذه أداة تنزيل مُحدّثة تستخدم الحزم المرفوعة سابقًا؛ لا تستخدم أداة
التنصيب القديمة الموجودة داخل وسم `v0.1.0-lab.3` عند التنصيب بلا توكن.
التنصيب الكامل من الصفر ما زال غير معتمد؛ إذا ظهر خطأ توقف وافحصه قبل إعادة المحاولة.

## حدود الإصدار

- Ubuntu 22.04 amd64 فقط، سيرفر جديد، ومسار `/opt/xcvm-independent-lab`.
- المُثبّت يرفض أي بانل موجود، ولا ينفذ ترقية أو فورمات.
- عنوان السيرفر الأصلي محظور صراحة في المُثبّت.
- حزم النظام تُحمّل من مستودعات Ubuntu؛ ملفات البانل واللود من Releases بهذا المستودع.
- `xcvm_core` وionCube الأصلية مستبعدتان؛ تُنشأ مفاتيح تشفير محلية مستقلة.
- هذه نسخة مختبر، وليست نسخة إنتاج مكتملة. راجع NOTICE.md.
- تحديث `independent-lb-button-v1` يربط زر تنصيب اللود بالمثبت المستقل؛ راجع القسم التالي لتحديث MAIN القائمة.
- تحديث النسخ القائمة يحتاج مسار ترقية منفصل؛ لا تشغّل المُثبّت على نسخة قائمة.

## تنصيب اللود من زر البانل

التحديث `independent-lb-button-v1` مستخدم الآن على MAIN التجربة. الزر الحالي
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
لم يُنفذ تنصيب كامل من الصفر على سيرفر لود فارغ في هذه الاختبارات.

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
  --go-runtime /secure/go-runtime-input.tar.gz
```

ملفات التشغيل الثنائية تُنشر في Releases، ولا تُلزم Git بتخزينها.
بصمة SHA-256 تكشف تلف الملفات؛ الثقة في الإصدار تعتمد أيضًا على حماية حساب GitHub.
