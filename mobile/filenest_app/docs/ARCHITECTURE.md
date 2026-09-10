# البنية المعمارية

## لماذا "حسب الميزة" (feature-first) لا "حسب النوع"

البنية القديمة كانت تجمع كل الملفات حسب نوعها: كل الشاشات في `screens/`، كل الخدمات في `services/`، كل النماذج في `models/`. هذا يعمل جيداً في مشروع صغير، لكنه يصبح مؤلماً بمجرد أن يكبر المشروع: لإضافة ميزة واحدة تفتح ملفات في 4-5 مجلدات مختلفة، ولا توجد طريقة سريعة لمعرفة "ما الذي يخصّ ميزة التنزيلات تحديداً" بمجرد النظر لهيكل المجلدات.

البنية الحالية تقلب الترتيب: كل ميزة (`files`, `downloads`, `shares`, `trash`, `security`, `account`, `server_settings`, `auth`) لها مجلدها الخاص تحت `lib/features/`، وداخله نفس الطبقات الثلاث دائماً:

```
features/<name>/
  data/           # طبقة الشبكة: تكلّم API فقط (Dio)، لا تعرف شيئاً عن الواجهة
  models/         # نماذج بيانات خاصة بهذه الميزة فقط (إن وُجدت)
  state/          # ChangeNotifier إن احتاجت الميزة حالة مشتركة بين أكثر من شاشة
  presentation/   # الشاشات والعناصر المرئية (widgets/)
```

ليس كل مجلد فرعي مطلوباً لكل ميزة — `trash` مثلاً لا تحتاج `state/` لأن شاشتها الوحيدة تكفي بحالة داخلية محلية (`StatefulWidget`)، بينما `downloads` تحتاج `state/` لأن حالة التنزيلات (نِسَب التقدّم، القائمة المحمَّلة) يجب أن تكون مرئية من شاشتين مختلفتين في آن واحد (شاشة "ملفاتي" وشاشة "التنزيلات").

## `app/` مقابل `core/` مقابل `features/`

| المجلد | ماذا يحتوي | القاعدة |
|---|---|---|
| `app/` | شكل التطبيق ككل: الثيم، `MaterialApp`، الشاشة الرئيسية بعد الدخول (`HomeShell`) وملحقاتها (القائمة الجانبية) | لا يوجد إلا نسخة واحدة من كل شيء هنا — ليست "ميزة" يمكن تكرار نمطها |
| `core/` | بنية تحتية يستخدمها **أكثر من ميزة واحدة**: `ApiClient`، نماذج مشتركة (`FileItem`, `FolderItem`, `FileNestUser`)، أدوات تنسيق، عناصر واجهة عامة (`EmptyState`) | لا يستورد أي ملف هنا من `features/` أبداً — الاتجاه في اتجاه واحد فقط: `features` تعتمد على `core`، والعكس ممنوع |
| `features/` | كل شيء خاص بميزة واحدة بعينها | يمكن لميزة أن تعتمد على ميزة أخرى (مثال أدناه) لكن ليس العكس دائرياً |

### مثال على اعتماد ميزة على أخرى: `files` ← `downloads`

`features/files/presentation/widgets/item_tiles.dart` يستورد `DownloadStatusIcon` من `features/downloads/presentation/widgets/` لأن كل صفّ ملف في شاشة التصفّح يحتاج إظهار حالة التنزيل بدون إنترنت الخاصة به. هذا اعتماد باتّجاه واحد فقط (`files` تعرف عن `downloads`، لكن `downloads` لا تستورد أي شيء من `files` سوى `FileItem` من `core/models`) — وهو مقصود وموثَّق هنا حتى لا يُعتبر خطأً عند المراجعة.

## تدفّق البيانات

```
شاشة (presentation/)
   │  يستدعي دالة async
   ▼
Repository أو Service (data/)
   │  يستخدم ApiClient.instance.dio
   ▼
core/network/api_client.dart  ──► يُرفق رمز Bearer تلقائياً (من core/storage/token_storage.dart)
   │
   ▼
خادم FileNest (/api/v1/...، عنوانه يُقرَّر عبر core/network/server_config_service.dart)
```

- **بدون حالة مشتركة** (`FileRepository`, `ShareRepository`, `TrashRepository`, `ProfileService`, `TwoFactorService`): تُنشأ نسخة جديدة داخل كل شاشة تحتاجها (`final _fileRepository = FileRepository();`) — كلها تشترك في نفس `ApiClient.instance` (Singleton) خلف الكواليس، فلا تكلفة حقيقية لإنشاء عدة نسخ.
- **بحالة مشتركة عبر أكثر من شاشة** (`AuthProvider`, `DownloadsProvider`): تُسجَّل مرة واحدة في جذر التطبيق (`app/app.dart` عبر `MultiProvider`) وتُقرأ من أي شاشة عبر `context.watch<T>()`/`context.read<T>()`.

## نقطة الدخول والتنقّل

```
main.dart → app/app.dart (FileNestApp)
              │
              ├─ MultiProvider(AuthProvider, DownloadsProvider)
              │
              └─ AuthGate يقرأ AuthProvider.status:
                   unknown        → SplashScreen
                   unauthenticated → LoginScreen (يحتوي رابطاً لـ ServerSettingsScreen)
                   authenticated   → HomeShell
                                        │
                                        ├─ NavigationBar (تبويبات): ملفاتي / التنزيلات / المشاركة
                                        ├─ Drawer (قائمة جانبية): الأمان / الحساب / السلة / إعدادات الخادم / خروج
                                        └─ فتح مجلد فرعي → Navigator.push(BrowseScreen(folder: ...))
                                             (شاشة كاملة بشريط علوي خاص بها + قائمة "المزيد" السفلية
                                              التي تُعيد استخدام نفس عناصر القائمة الجانبية)
```

`BrowseScreen` و`SharesScreen` تقبلان معامل `embedded` (`true`/`false`): `true` تعني "بلا شريط علوي خاص — أنا داخل تبويب في HomeShell"، و`false` (الافتراضي) تعني "شاشة كاملة مستقلة بشريط علوي خاص بها — تم فتحي عبر `Navigator.push`". هذا يتفادى ازدواج الأشرطة العلوية دون تكرار منطق التحميل/البحث/القوائم بين نسختين من نفس الشاشة.
