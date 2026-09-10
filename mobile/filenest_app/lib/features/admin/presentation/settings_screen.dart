import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/network/api_client.dart';
import '../../auth/state/auth_provider.dart';
import '../data/admin_repository.dart';

const _skinColors = {
  'indigo': Color(0xFF4F46E5), 'slate': Color(0xFF475569), 'emerald': Color(0xFF059669), 'rose': Color(0xFFE11D48),
  'amber': Color(0xFFD97706), 'sky': Color(0xFF0284C7), 'violet': Color(0xFF7C3AED), 'teal': Color(0xFF0D9488),
  'orange': Color(0xFFEA580C), 'zinc': Color(0xFF52525B), 'cyan': Color(0xFF0891B2), 'fuchsia': Color(0xFFC026D3),
};

const _registrationRoleLabels = {'user': 'مستخدم', 'editor': 'محرّر', 'contributor': 'مساهم'};

/// Mobile counterpart of Admin/Settings.vue — grouped exactly the same way,
/// with each section's visibility gated by the same appearance/settings
/// capability split as the web page.
class AdminSettingsScreen extends StatefulWidget {
  const AdminSettingsScreen({super.key});

  @override
  State<AdminSettingsScreen> createState() => _AdminSettingsScreenState();
}

class _AdminSettingsScreenState extends State<AdminSettingsScreen> {
  final _repository = AdminRepository();

  bool _loading = true;
  bool _saving = false;
  String? _error;
  Map<String, dynamic> _settings = {};
  List<String> _skins = [];
  Map<String, dynamic> _locales = {};

  final _siteNameController = TextEditingController();
  final _customCssController = TextEditingController();
  final _defaultQuotaController = TextEditingController();
  final _maxUploadController = TextEditingController();
  final _maxZipController = TextEditingController();
  final _extensionListController = TextEditingController();
  final _thumbnailMaxPxController = TextEditingController();
  final _videoQualityTargetsController = TextEditingController();
  final _contentIndexingMaxMbController = TextEditingController();

  String _activeSkin = 'indigo';
  String _extensionFilterMode = 'reject';
  String _defaultLocale = 'ar';
  String _registrationRole = 'user';
  bool _registrationEnabled = true;
  bool _enablePrettyLinks = false;
  bool _globalSearch = true;
  bool _thumbnailsEnabled = true;
  bool _videoTranscodingEnabled = true;
  bool _interactiveVideoEnabled = false;
  bool _contentIndexingEnabled = true;

  File? _logo;
  File? _headerImage;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _siteNameController.dispose();
    _customCssController.dispose();
    _defaultQuotaController.dispose();
    _maxUploadController.dispose();
    _maxZipController.dispose();
    _extensionListController.dispose();
    _thumbnailMaxPxController.dispose();
    _videoQualityTargetsController.dispose();
    _contentIndexingMaxMbController.dispose();
    super.dispose();
  }

  bool _isTrue(dynamic value) => value == '1' || value == true;

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final payload = await _repository.settingsPayload();
      final settings = payload['settings'] as Map<String, dynamic>;
      setState(() {
        _settings = settings;
        _skins = (payload['skins'] as List).map((e) => e as String).toList();
        _locales = payload['locales'] as Map<String, dynamic>;

        _siteNameController.text = settings['site_name'] as String? ?? '';
        _customCssController.text = settings['custom_css'] as String? ?? '';
        _defaultQuotaController.text = '${settings['default_quota_mb'] ?? ''}';
        _maxUploadController.text = '${settings['max_upload_mb'] ?? ''}';
        _maxZipController.text = '${settings['max_zip_files'] ?? ''}';
        _extensionListController.text = settings['extension_list'] as String? ?? '';
        _thumbnailMaxPxController.text = '${settings['thumbnail_max_px'] ?? ''}';
        _videoQualityTargetsController.text = settings['video_quality_targets'] as String? ?? '';
        _contentIndexingMaxMbController.text = '${settings['content_indexing_max_mb'] ?? ''}';

        _activeSkin = settings['active_skin'] as String? ?? 'indigo';
        _extensionFilterMode = settings['extension_filter_mode'] as String? ?? 'reject';
        _defaultLocale = settings['default_locale'] as String? ?? 'ar';
        _registrationRole = settings['registration_role'] as String? ?? 'user';
        _registrationEnabled = _isTrue(settings['registration_enabled']);
        _enablePrettyLinks = _isTrue(settings['enable_pretty_links']);
        _globalSearch = _isTrue(settings['global_search']);
        _thumbnailsEnabled = _isTrue(settings['thumbnails_enabled']);
        _videoTranscodingEnabled = _isTrue(settings['video_transcoding_enabled']);
        _interactiveVideoEnabled = _isTrue(settings['interactive_video_enabled']);
        _contentIndexingEnabled = _isTrue(settings['content_indexing_enabled']);
      });
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _pickImage({required bool isHeader}) async {
    final result = await FilePicker.platform.pickFiles(type: FileType.image);
    if (result == null || result.files.single.path == null) return;
    setState(() {
      if (isHeader) {
        _headerImage = File(result.files.single.path!);
      } else {
        _logo = File(result.files.single.path!);
      }
    });
  }

  Future<void> _submit() async {
    setState(() {
      _saving = true;
      _error = null;
    });

    final data = <String, dynamic>{
      'site_name': _siteNameController.text.trim(),
      'registration_enabled': _registrationEnabled,
      'registration_role': _registrationRole,
      'default_locale': _defaultLocale,
      'active_skin': _activeSkin,
      'custom_css': _customCssController.text,
      'default_quota_mb': _defaultQuotaController.text.trim(),
      'max_upload_mb': _maxUploadController.text.trim(),
      'extension_filter_mode': _extensionFilterMode,
      'extension_list': _extensionListController.text,
      'max_zip_files': _maxZipController.text.trim(),
      'enable_pretty_links': _enablePrettyLinks,
      'global_search': _globalSearch,
      'content_indexing_enabled': _contentIndexingEnabled,
      'content_indexing_max_mb': _contentIndexingMaxMbController.text.trim(),
      'thumbnails_enabled': _thumbnailsEnabled,
      'thumbnail_max_px': _thumbnailMaxPxController.text.trim(),
      'video_transcoding_enabled': _videoTranscodingEnabled,
      'video_quality_targets': _videoQualityTargetsController.text.trim(),
      'interactive_video_enabled': _interactiveVideoEnabled,
    };

    try {
      final settings = (_logo != null || _headerImage != null)
          ? await _repository.updateSettingsWithFiles(data, logo: _logo, headerImage: _headerImage)
          : await _repository.updateSettings(data);
      if (mounted) {
        setState(() {
          _settings = settings;
          _logo = null;
          _headerImage = null;
        });
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم حفظ الإعدادات.')));
      }
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final admin = context.watch<AuthProvider>().user?.admin;
    final canAppearance = admin?.appearance ?? false;
    final canSettings = admin?.settings ?? false;

    return Scaffold(
      appBar: AppBar(title: const Text('الإعدادات العامة')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  if (canAppearance) ..._buildAppearanceSection(),
                  if (canSettings) ..._buildSettingsSections(),
                  if (_error != null) ...[
                    const SizedBox(height: 8),
                    Text(_error!, style: const TextStyle(color: Colors.red)),
                  ],
                  const SizedBox(height: 16),
                  FilledButton(onPressed: _saving ? null : _submit, child: const Text('حفظ الإعدادات')),
                ],
              ),
            ),
    );
  }

  List<Widget> _buildAppearanceSection() {
    return [
      _Section(title: 'الهوية والعلامة التجارية', children: [
        TextField(controller: _siteNameController, decoration: const InputDecoration(labelText: 'اسم الموقع')),
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: OutlinedButton(onPressed: () => _pickImage(isHeader: false), child: Text(_logo != null ? 'تم اختيار الشعار' : 'اختيار الشعار (Logo)'))),
          const SizedBox(width: 8),
          Expanded(child: OutlinedButton(onPressed: () => _pickImage(isHeader: true), child: Text(_headerImage != null ? 'تم اختيار صورة الترويسة' : 'صورة الترويسة (اختياري)'))),
        ]),
      ]),
      _Section(title: 'المظهر (السكن)', children: [
        Wrap(
          spacing: 10,
          children: _skins.map((skin) {
            final selected = _activeSkin == skin;
            return GestureDetector(
              onTap: () => setState(() => _activeSkin = skin),
              child: CircleAvatar(
                radius: 18,
                backgroundColor: _skinColors[skin] ?? Colors.grey,
                child: selected ? const Icon(Icons.check_rounded, color: Colors.white, size: 18) : null,
              ),
            );
          }).toList(),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _customCssController,
          maxLines: 4,
          style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
          decoration: const InputDecoration(labelText: 'CSS مخصص (اختياري)', alignLabelWithHint: true),
        ),
      ]),
    ];
  }

  List<Widget> _buildSettingsSections() {
    return [
      _Section(title: 'التخزين والرفع', children: [
        TextField(controller: _defaultQuotaController, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'الحصة الافتراضية للمستخدم الجديد (ميغابايت)')),
        const SizedBox(height: 8),
        TextField(controller: _maxUploadController, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'الحد الأقصى لحجم الملف الواحد (ميغابايت)')),
        const SizedBox(height: 8),
        TextField(controller: _maxZipController, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'الحد الأقصى لعدد الملفات في أرشيف ZIP')),
      ]),
      _Section(title: 'امتدادات الملفات المسموح بها', children: [
        DropdownButtonFormField<String>(
          value: _extensionFilterMode,
          decoration: const InputDecoration(labelText: 'نمط التصفية'),
          items: const [
            DropdownMenuItem(value: 'reject', child: Text('حظر الامتدادات التالية فقط')),
            DropdownMenuItem(value: 'allow', child: Text('السماح بالامتدادات التالية فقط')),
          ],
          onChanged: (v) => setState(() => _extensionFilterMode = v ?? 'reject'),
        ),
        const SizedBox(height: 8),
        TextField(controller: _extensionListController, decoration: const InputDecoration(labelText: 'قائمة الامتدادات (مفصولة بفواصل)', hintText: 'exe, bat, sh')),
      ]),
      _Section(title: 'الصور المصغّرة (Thumbnails)', children: [
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _thumbnailsEnabled,
          onChanged: (v) => setState(() => _thumbnailsEnabled = v),
          title: const Text('تفعيل توليد الصور المصغّرة تلقائياً عند الرفع'),
        ),
        TextField(controller: _thumbnailMaxPxController, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'أقصى أبعاد للصورة المصغّرة (بكسل)')),
      ]),
      _Section(title: 'جودات تشغيل الفيديو', children: [
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _videoTranscodingEnabled,
          onChanged: (v) => setState(() => _videoTranscodingEnabled = v),
          title: const Text('تفعيل إنشاء نسخ بجودات مختلفة تلقائياً عند رفع الفيديو'),
        ),
        TextField(controller: _videoQualityTargetsController, decoration: const InputDecoration(labelText: 'الدقات المستهدفة (بالبكسل)', hintText: '360,480,720')),
      ]),
      _Section(title: 'الفيديو التفاعلي', children: [
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _interactiveVideoEnabled,
          onChanged: (v) => setState(() => _interactiveVideoEnabled = v),
          title: const Text('تفعيل الأسئلة التفاعلية داخل الفيديو'),
        ),
      ]),
      _Section(title: 'فهرسة محتوى المستندات للبحث', children: [
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _contentIndexingEnabled,
          onChanged: (v) => setState(() => _contentIndexingEnabled = v),
          title: const Text('تفعيل استخراج النص وفهرسته تلقائياً عند الرفع'),
        ),
        TextField(controller: _contentIndexingMaxMbController, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'لا تُفهرس الملفات الأكبر من (ميغابايت)')),
      ]),
      _Section(title: 'اللغة والتسجيل', children: [
        DropdownButtonFormField<String>(
          value: _defaultLocale,
          decoration: const InputDecoration(labelText: 'اللغة الافتراضية'),
          items: _locales.entries
              .map((e) => DropdownMenuItem(value: e.key, child: Text((e.value as Map)['label'] as String? ?? e.key)))
              .toList(),
          onChanged: (v) => setState(() => _defaultLocale = v ?? 'ar'),
        ),
        const SizedBox(height: 8),
        DropdownButtonFormField<String>(
          value: _registrationRole,
          decoration: const InputDecoration(labelText: 'الدور الافتراضي للمستخدم الجديد'),
          items: _registrationRoleLabels.entries.map((e) => DropdownMenuItem(value: e.key, child: Text(e.value))).toList(),
          onChanged: (v) => setState(() => _registrationRole = v ?? 'user'),
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _registrationEnabled,
          onChanged: (v) => setState(() => _registrationEnabled = v),
          title: const Text('السماح بإنشاء حسابات جديدة ذاتياً'),
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _enablePrettyLinks,
          onChanged: (v) => setState(() => _enablePrettyLinks = v),
          title: const Text('استخدام روابط مشاركة مختصرة'),
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _globalSearch,
          onChanged: (v) => setState(() => _globalSearch = v),
          title: const Text('تفعيل البحث الشامل في كل المجلدات'),
        ),
      ]),
    ];
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
            const SizedBox(height: 12),
            ...children,
          ],
        ),
      ),
    );
  }
}
