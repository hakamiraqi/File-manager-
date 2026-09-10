# إضافة ميزة جديدة

خطوات عملية، مبنية على كيف بُنيت ميزة `downloads` فعلياً — استخدمها كمرجع مباشر (كل مسار مذكور هنا موجود فعلاً في الكود).

## 1. أنشئ مجلد الميزة

```
lib/features/<feature_name>/
  data/           # إن كانت الميزة تكلّم API
  models/         # إن كانت الميزة تحتاج نموذج بيانات خاصاً بها لا يستخدمه أحد غيرها
  state/          # إن كانت الميزة تحتاج حالة مشتركة بين أكثر من شاشة (ChangeNotifier)
  presentation/
    widgets/      # عناصر مرئية صغيرة خاصة بهذه الميزة فقط
```

لا تُنشئ مجلداً فرعياً لن تستخدمه — `trash` مثلاً ليس لها `state/` لأنها لا تحتاجه.

## 2. طبقة البيانات (`data/`)

كلاس واحد، بلا حالة داخلية معقّدة، يعتمد فقط على `ApiClient.instance.dio`:

```dart
import '../../../core/network/api_client.dart';

class MyFeatureRepository {
  final _dio = ApiClient.instance.dio;

  Future<SomeModel> doSomething() async {
    final response = await _dio.get('/my-endpoint');
    return SomeModel.fromJson(response.data);
  }
}
```

إن كان النموذج (`SomeModel`) يُستخدَم من أكثر من ميزة، ضعه في `core/models/` بدلاً من `features/<feature_name>/models/`.

## 3. الحالة المشتركة (`state/`) — عند الحاجة فقط

```dart
class MyFeatureProvider extends ChangeNotifier {
  final _repository = MyFeatureRepository();
  bool loading = true;

  Future<void> load() async {
    // ...
    notifyListeners();
  }
}
```

سجّله في `app/app.dart` داخل `MultiProvider` **فقط** إن احتاجت أكثر من شاشة الوصول لنفس الحالة في آن واحد (كما فعلت `DownloadsProvider` — تحتاجها كل من شاشة "ملفاتي" وشاشة "التنزيلات" نفسها). إن كانت شاشة واحدة فقط تستخدم هذا المنطق، استخدم `State` عادية بدل `Provider` جديد (كما تفعل `SharesScreen`/`TrashScreen`/`BrowseScreen`).

## 4. الشاشة (`presentation/`)

- إن كانت الشاشة ستُستخدَم فقط عبر `Navigator.push` (ليست تبويباً في `HomeShell`): `Scaffold` كامل بشريط علوي خاص بها، بلا معامل `embedded`.
- إن كانت ستُستخدَم كتبويب داخل `HomeShell` (نادر — حالياً فقط `files`, `downloads`, `shares`): اجعلها بلا `Scaffold`/`AppBar` خاص بها (أو أضف معامل `embedded` كما في `BrowseScreen`/`SharesScreen`)، لأن `HomeShell` يوفّر الشريط العلوي والقائمة الجانبية.

## 5. إن كانت الميزة تحتاج الظهور في القائمة الجانبية/السفلية

أضف عنصراً واحداً في `app/home/nav_destinations.dart` داخل `buildSecondaryDestinations()` — يظهر تلقائياً في كل من `AppDrawer` (الجذر) و`MoreDestinationsSheet` (أي شاشة فرعية) دون أي تكرار.

## 6. الاختبارات

- منطق Dart خالص (بلا `Widget`) يمكن اختباره بدون Flutter widgets — ضعه في `test/` بنفس أسلوب `test/api_config_test.dart`.
- أي شاشة جديدة تستحق اختبار widget بسيطاً يتأكد من ظهور نصوصها الأساسية، على غرار `test/widget_test.dart`.

## مثال حقيقي كامل: كيف بُنيت `downloads`

1. `models/offline_file.dart` — نموذج `OfflineFileRecord` (بيانات فقط، بلا منطق شبكة).
2. `data/offline_download_service.dart` — منطق التنزيل الفعلي (Dio + ملفات القرص + سجلّ JSON)، مستقل تماماً عن الواجهة.
3. `state/downloads_provider.dart` — `ChangeNotifier` رقيق يغلّف الخدمة ويضيف حالة "نسبة التقدّم الحالية" التي تحتاجها الواجهة.
4. `presentation/widgets/download_status_icon.dart` — عنصر صغير قابل لإعادة الاستخدام (يُستخدَم داخل ميزة `files`).
5. `presentation/downloads_screen.dart` — الشاشة الكاملة (تبويب في `HomeShell`، بلا `Scaffold` خاص بها).
6. تسجيل `DownloadsProvider` في `app/app.dart`، وإضافة تبويب "التنزيلات" في `app/home/home_shell.dart`.

اتّبع نفس الترتيب (نموذج → خدمة → حالة → عنصر واجهة → شاشة → تسجيل) عند إضافة أي ميزة جديدة.
