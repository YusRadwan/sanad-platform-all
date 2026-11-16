# Sanad Platform — project context

آخر تحديث: 16 نوفمبر 2026  
المستودع المرجعي: [YusRadwan/sanad-platform-all](https://github.com/YusRadwan/sanad-platform-all)  
الموقع التجريبي على Hostinger: [orchid-gerbil-115887.hostingersite.com](https://orchid-gerbil-115887.hostingersite.com)

هذا الملف هو ملخص تسليم حي للمساعدين الذين يعملون على Sanad. راجع GitHub ونتائج Actions قبل الاعتماد على أي حالة زمنية هنا، ثم حدّث قسم «الحالة الحالية» بعد كل تغيير مهم.

## المشروع

منصة تعليمية تتكون من:

- **Frontend:** React + TypeScript + Vite داخل `frontend/`.
- **Backend:** Laravel داخل `backend/`، يستخدم PHP 8.3 وMySQL.
- **API:** نفس الـOrigin تحت `/api/v1`، مع مصادقة جلسات Cookies وCSRF.
- **الاستضافة:** Hostinger، والواجهة والـBackend يُنشران من مسارين منفصلين في GitHub Actions.

لا توجد بيانات دخول أو قيم أسرار في هذا الملف. أسرار SSH وبيئة التشغيل محفوظة في GitHub Actions وملف `.env` على السيرفر فقط.

## الفروع ومصدر الكود المنشور

- **`main`:** الفرع الافتراضي، ويحتوي الواجهة الحالية وميزات إدارة الأدمن، ومنها صفحة التفعيل اليدوي.
- **`test/video-providers`:** قاعدة مصدر الـBackend الذي يعبئه Workflow نشر الـBackend إلى Hostinger.
- الفرعان متباعدان ماديًا؛ أظهر GitHub في 16 نوفمبر 2026 أن كلاً منهما متقدم عن الآخر بـ37 commit. لا تستبدل كود Backend على Hostinger بكامل `main` ولا تدمج الفرعين على نطاق واسع من دون طلب صريح ومراجعة توافق.
- تمت إضافة Endpoint التفعيل اليدوي تحديدًا إلى `test/video-providers` عبر PR #5 ليصل إلى Backend المنشور، مع إبقاء التعديلات محدودة ومتوافقة.

## طريقة العمل المعتمدة

1. يفهم المساعد المطلوب ويراجع الكود الفعلي في الفرع المناسب.
2. ينفذ التغيير في فرع مستقل ويقدمه في Pull Request؛ لا يكتب مباشرة إلى `main`.
3. يراجع الاختبارات ونتائج CI عند توفرها، ويتأكد من توافق الـFrontend والـBackend.
4. بعد موافقة المستخدم على النشر، يشغّل Workflow المناسب على الفرع المقصود.
5. يتأكد من نتيجة GitHub Actions ثم يختبر الموقع والـAPI والميزة على Hostinger.

تفضيلات المستخدم: الشرح بالعربية، خطوات عملية مباشرة، عدم طلب أوامر Terminal محلية كجزء من الدورة المعتادة، وعدم إعادة تنفيذ إعداد سبق إنجازه. مجلد العمل المحلي قد يكون مجلد تسليم بلا checkout كامل؛ لذلك GitHub هو مصدر الكود ما لم يثبت وجود نسخة محلية صالحة.

## Workflows النشر

### Frontend

الملف: `.github/workflows/deploy-production.yml`

- يبني ملفات Vite وينشر ملفات الـFrontend إلى Hostinger.
- **لا ينشر Backend ولا يشغّل Database Migrations.**
- اختيار فرع الواجهة في Workflow يحدد نسخة الواجهة فقط.

### Backend

الملف: `.github/workflows/deploy-backend-production.yml`

- هذا هو Workflow نشر الـBackend المستخدم حاليًا.
- عند تشغيله من GitHub Actions، اختر **Use workflow from: `main`**؛ هذا يختار نسخة تعريف الـWorkflow.
- الـWorkflow نفسه يعمل checkout لفرع **`test/video-providers`** ويعبئ الـBackend منه. لا تغيّر هذا الفرق بين فرع تعريف الـWorkflow وفرع مصدر التطبيق من غير مراجعة.
- ينشئ نسخة احتياطية على Hostinger، ويحافظ على `.env` و`storage` و`vendor` عند رفع المصدر، ويستعيد النسخة السابقة عند فشل التحقق.
- لا يشغّل Migrations تلقائيًا. افحص الحاجة إليها لكل ميزة على حدة.
- Hostinger يعطّل PHP `proc_open`. لذلك يشغّل Composer مع `--no-scripts` ثم ينفذ `php artisan package:discover --ansi` مباشرةً. لا تُزل هذا التجاوز إلا بعد التحقق من إعدادات PHP على Hostinger.
- بعد التحديث، يتحقق من ملفات Backend ومن تسجيل المسار `POST /api/v1/admin/manual-activations`.

كان هناك Workflow غير مناسب باسم `deploy-backend-hostinger.yml` يفترض أن checkout السيرفر نظيفًا؛ حُذف في PR #6. لا تعِد استخدام طريقة `git pull` هذه على مجلد Hostinger.

## ميزات مهمة

### التفعيل اليدوي

- الواجهة: `frontend/src/components/Admin/ManualActivation.tsx`.
- الـEndpoint: `POST /api/v1/admin/manual-activations`.
- الـBackend: `GrantManualActivation` و`ManualActivationController` و`ManualActivationRequest`.
- يدعم تفعيلًا مدى الحياة أو حتى نهاية اليوم المحدد.
- يجب ألا يسجل Revenue Transaction أو أرباحًا للمدرس.
- محمي بصلاحية الأدمن `admin_manual_activation.manage`.
- هذه الميزة لا تحتاج Migration جديدة.
- يُختبر إتمامها بتفعيل كورس، ثم التأكد من ظهور الكورس لحساب الطالب.

### ميزات إدارة أخرى

- إلغاء اشتراك كورس لطالب: PR #2 مدموج في `main`.
- تفعيل يدوي مجاني للكورس: PR #3 مدموج في `main`، ونسخة Backend الخاصة بالفرع المنشور أضيفت في PR #5.
- جلسات مشاهدة الفيديو: PR #1 مدموج في قاعدة `test/video-providers`، وله تغييرات Backend وMigration خاصة به؛ لا تفترض أن نشر الـBackend وحده ينفذ Migration.

## الحالة الحالية — 16 نوفمبر 2026

- تم دمج إصلاح Workflow التفعيل اليدوي إلى `test/video-providers` عبر PR #5.
- تم تحديث Workflow Backend في `main` وإزالة Workflow غير المناسب عبر PR #6.
- أُصلحت مشكلة Composer المرتبطة بتعطيل `proc_open` على Hostinger عبر PR #7.
- أحدث تشغيل لـ`Deploy Sanad Backend to Hostinger` الذي تم التحقق منه نجح، وكل خطواته خضراء، وسجل العلامة `MANUAL_ACTIVATION_ROUTE_VERIFIED`.
- **المتبقي:** المستخدم لم يؤكد بعد نجاح عملية تفعيل طالب فعلية أو وصول الطالب للكورس. الخطوة التالية هي تجربة التفعيل من صفحة الأدمن، ثم الدخول بحساب الطالب والتحقق من ظهور الكورس. إذا فشلت، افحص Status Code وResponse وPayload لطلب الـEndpoint بدل إعادة كتابة الميزة.

## ملاحظات تشخيصية

- استجابة `404 Not found` العامة كانت بسبب غياب الـEndpoint في Backend المنشور، بينما كانت الواجهة الجديدة على Hostinger. فحص الـBackend بعد النشر أصبح جزءًا من Workflow.
- فشل سابق في Workflow جديد كان بسبب ملفات محلية مختلفة في checkout Hostinger؛ لم يحدّث ذلك التشغيل الملفات ورجع بأمان.
- فشل Composer اللاحق كان بسبب `proc_open`، وتمت استعادة نسخة Backend تلقائيًا. معالجة PR #7 تجاوزت هذا العائق، ثم نجح تشغيل النشر التالي.
- لا تستنتج أن التفعيل نجح من نجاح Workflow وحده؛ Workflow يثبت وجود المسار فقط، والاختبار الوظيفي الفعلي ما زال مطلوبًا.
