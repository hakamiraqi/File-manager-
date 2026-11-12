# FileNest — السيرفر بدون إنترنت (ويندوز)

نسخة كاملة من النظام (الملفات، الرسائل الجامعية، المكتبة) تعمل على حاسوب ويندوز واحد داخل الشبكة
المحلية بدون إنترنت. تُدار من لوحة التشغيل `FileNest.exe`، وهي نفس لوحة سيرفر نظام العيادات.

## أول تشغيل
1. فك ضغط الملف في مجلد ثابت، مثل `C:\FileNestOffline` (ليس داخل مجلد التنزيلات).
2. شغّل `FileNest.exe`. أول تشغيل ينشئ قاعدة البيانات ويأخذ دقيقة تقريباً.
3. من صفحة «الشبكة والأجهزة» اضغط «السماح لأجهزة الشبكة» مرة واحدة (يطلب صلاحية المسؤول).
4. افتح النظام، وأدخل **رمز تفعيل FileNest** الخاص بجامعتك من منصة التراخيص. التفعيل يحتاج إنترنت مرة واحدة فقط.

أول دخول: `admin@filenest.test` وكلمة المرور `password`. غيّرها مباشرة من الحساب.

## لوحة التشغيل
- **الرئيسية:** تشغيل وإيقاف وإعادة تشغيل، وحالة كل خدمة (قاعدة البيانات، PHP، خادم الويب، الأسماء، المهام، المعالجة في الخلفية). أي خدمة تتوقف تُعاد تلقائياً.
- **الشبكة والأجهزة:** عنوان النظام `https://files.lan` وعنوان IP، وصفحة تجهيز الأجهزة `/setup` (فتح النظام، التطبيقات، شهادة الأمان).
- **النسخ الاحتياطي:** نسخة يومية، ونسخة قبل كل تحديث، وزر «نسخة الآن» واستعادة أو دمج من نسخة. المجلد الافتراضي `C:\FileNestBackups` خارج مجلد البرنامج.
- **التحديثات:** الحاسوب المفعّل ينزّل النسخة الجديدة بنفسه ويحدّث مع الحفاظ على البيانات، ويرجع للنسخة السابقة إذا فشل التشغيل.
- **الوصول عبر الإنترنت:** نفق Cloudflare مجاني للوصول إلى النظام واللوحة من خارج الشبكة.
- **الإعدادات:** اسم النظام على الشبكة، المنافذ، عدد الطلبات المتزامنة، مجلد النسخ، كلمة مرور اللوحة، التشغيل مع ويندوز.

## التطبيقات
صفحة `http://files.lan/apps/` فيها تطبيقات ويندوز وأندرويد وآيفون. داخل التطبيق: «إعدادات الخادم» ←
«عنوان الشبكة المحلية» = عنوان السيرفر.

## ملاحظات
- كل الخطوط والأيقونات والمكتبات داخل الحزمة، فلا تحتاج الصفحات أي اتصال خارجي.
- قاعدة البيانات على المنفذ 3308 لهذا الحاسوب فقط، فيمكن تشغيلها بجانب سيرفر العيادات (غيّر منفذ الويب لأحدهما).
- يحتاج ويندوز 10 أو أحدث (64 بت).

## Office editor add-on (Word, Excel, PowerPoint, PDF)

The office editor (ONLYOFFICE Docs Community) needs Windows services (Erlang, RabbitMQ,
PostgreSQL), so it is a separate download: `FileNestOffice-addon-windows.zip` from
`/downloads` on the main site (built by the "Offline server (Windows)" workflow).
Unzip it inside the FileNest folder (it becomes `office-addon\`), run
`install-office.cmd` as administrator, then restart FileNest from its panel. Nothing is
downloaded during the install. The editor listens on 127.0.0.1:8088 and is reached through
Caddy at `/office/`; the script writes the shared key and addresses into `app\.env`.
Without the add-on the site works as before and simply hides the editor.

qpdf (searchable copies of scanned PDFs) and ImageMagick (page cleanup before text
recognition) are part of the main package in `qpdf\` and `imagemagick\`.
