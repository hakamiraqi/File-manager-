import 'package:flutter/material.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/ui_kit.dart';
import '../data/admin_repository.dart';
import '../models/ip_rule.dart';

/// Mobile counterpart of Admin/Security.vue — named AdminSecurityScreen to
/// avoid clashing with the account-level 2FA SecurityScreen already in
/// lib/features/security.
class AdminSecurityScreen extends StatefulWidget {
  const AdminSecurityScreen({super.key});

  @override
  State<AdminSecurityScreen> createState() => _AdminSecurityScreenState();
}

class _AdminSecurityScreenState extends State<AdminSecurityScreen> {
  final _repository = AdminRepository();

  bool _loading = true;
  bool _saving = false;
  String? _error;
  String _appUrl = '';
  List<IpRule> _ipRules = [];

  bool _guestAccessEnabled = false;
  bool _remoteUploadEnabled = false;
  bool _publicFiles = true;
  bool _publicLibrary = true;
  bool _publicTheses = true;
  bool _contactEnabled = true;
  bool _showCaptchaRegister = false;
  bool _showCaptchaReset = false;
  bool _showCaptchaDownload = false;
  bool _recaptchaEnabled = false;
  bool _recaptchaInvisible = false;
  String _ipFilterMode = 'none';

  final _recaptchaSiteKeyController = TextEditingController();
  final _recaptchaSecretKeyController = TextEditingController();
  final _ipRedirectUrlController = TextEditingController();
  final _googleIdController = TextEditingController();
  final _googleSecretController = TextEditingController();
  final _githubIdController = TextEditingController();
  final _githubSecretController = TextEditingController();
  final _linkedinIdController = TextEditingController();
  final _linkedinSecretController = TextEditingController();

  final _ipTypeController = ValueNotifier<String>('blacklist');
  final _ipValueController = TextEditingController();
  final _ipNoteController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _recaptchaSiteKeyController.dispose();
    _recaptchaSecretKeyController.dispose();
    _ipRedirectUrlController.dispose();
    _googleIdController.dispose();
    _googleSecretController.dispose();
    _githubIdController.dispose();
    _githubSecretController.dispose();
    _linkedinIdController.dispose();
    _linkedinSecretController.dispose();
    _ipValueController.dispose();
    _ipNoteController.dispose();
    _ipTypeController.dispose();
    super.dispose();
  }

  bool _isTrue(dynamic value) => value == '1' || value == true;

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final payload = await _repository.securityPayload();
      final settings = payload['settings'] as Map<String, dynamic>;
      setState(() {
        _appUrl = payload['app_url'] as String? ?? '';
        _ipRules = (payload['ip_rules'] as List).map((e) => IpRule.fromJson(e as Map<String, dynamic>)).toList();

        _guestAccessEnabled = _isTrue(settings['guest_access_enabled']);
        _remoteUploadEnabled = _isTrue(settings['remote_upload_enabled']);
        _publicFiles = settings['public_files_enabled'] == null || _isTrue(settings['public_files_enabled']);
        _publicLibrary = settings['public_library_enabled'] == null || _isTrue(settings['public_library_enabled']);
        _publicTheses = settings['public_theses_enabled'] == null || _isTrue(settings['public_theses_enabled']);
        _contactEnabled = settings['contact_enabled'] == null || _isTrue(settings['contact_enabled']);
        _showCaptchaRegister = _isTrue(settings['show_captcha_register']);
        _showCaptchaReset = _isTrue(settings['show_captcha_reset']);
        _showCaptchaDownload = _isTrue(settings['show_captcha_download']);
        _recaptchaEnabled = _isTrue(settings['recaptcha_enabled']);
        _recaptchaInvisible = _isTrue(settings['recaptcha_invisible']);
        _ipFilterMode = settings['ip_filter_mode'] as String? ?? 'none';

        _recaptchaSiteKeyController.text = settings['recaptcha_site_key'] as String? ?? '';
        _recaptchaSecretKeyController.text = settings['recaptcha_secret_key'] as String? ?? '';
        _ipRedirectUrlController.text = settings['ip_redirect_url'] as String? ?? '';
        _googleIdController.text = settings['oauth_google_client_id'] as String? ?? '';
        _googleSecretController.text = settings['oauth_google_client_secret'] as String? ?? '';
        _githubIdController.text = settings['oauth_github_client_id'] as String? ?? '';
        _githubSecretController.text = settings['oauth_github_client_secret'] as String? ?? '';
        _linkedinIdController.text = settings['oauth_linkedin_client_id'] as String? ?? '';
        _linkedinSecretController.text = settings['oauth_linkedin_client_secret'] as String? ?? '';
      });
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _submit() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await _repository.updateSecurity({
        'guest_access_enabled': _guestAccessEnabled,
        'remote_upload_enabled': _remoteUploadEnabled,
        'public_files_enabled': _publicFiles,
        'public_library_enabled': _publicLibrary,
        'public_theses_enabled': _publicTheses,
        'contact_enabled': _contactEnabled,
        'show_captcha_register': _showCaptchaRegister,
        'show_captcha_reset': _showCaptchaReset,
        'show_captcha_download': _showCaptchaDownload,
        'recaptcha_enabled': _recaptchaEnabled,
        'recaptcha_invisible': _recaptchaInvisible,
        'recaptcha_site_key': _recaptchaSiteKeyController.text,
        'recaptcha_secret_key': _recaptchaSecretKeyController.text,
        'ip_filter_mode': _ipFilterMode,
        'ip_redirect_url': _ipRedirectUrlController.text,
        'oauth_google_client_id': _googleIdController.text,
        'oauth_google_client_secret': _googleSecretController.text,
        'oauth_github_client_id': _githubIdController.text,
        'oauth_github_client_secret': _githubSecretController.text,
        'oauth_linkedin_client_id': _linkedinIdController.text,
        'oauth_linkedin_client_secret': _linkedinSecretController.text,
      });
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.tr('security.saved'))));
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  Future<void> _addIpRule() async {
    if (_ipValueController.text.trim().isEmpty) return;
    try {
      final rule = await _repository.addIpRule(
        type: _ipTypeController.value,
        ipOrCidr: _ipValueController.text.trim(),
        note: _ipNoteController.text.trim(),
      );
      setState(() {
        _ipRules = [..._ipRules, rule];
        _ipValueController.clear();
        _ipNoteController.clear();
      });
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    }
  }

  Future<void> _removeIpRule(IpRule rule) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(context.tr('security.confirm_delete_rule')),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: Text(context.tr('action.cancel'))),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.tr('action.delete'))),
        ],
      ),
    );
    if (confirmed != true) return;

    try {
      await _repository.deleteIpRule(rule.id);
      setState(() => _ipRules = _ipRules.where((r) => r.id != rule.id).toList());
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('admin.nav.security'))),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _Section(title: context.tr('security.public_access'), children: [
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _guestAccessEnabled,
                      onChanged: (v) => setState(() => _guestAccessEnabled = v),
                      title: Text(context.tr('security.guest_access')),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _remoteUploadEnabled,
                      onChanged: (v) => setState(() => _remoteUploadEnabled = v),
                      title: Text(context.tr('security.remote_upload')),
                    ),
                  ]),
                  _Section(title: context.l('واجهة الزوار', "Visitors' site"), children: [
                    if (!_guestAccessEnabled)
                      Text(context.l('فعّل «وصول الزوار» أعلاه حتى تظهر الأقسام للزوار.', 'Turn on guest access above to show these to visitors.'),
                          style: const TextStyle(fontSize: 12, color: Colors.grey)),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _publicFiles,
                      onChanged: (v) => setState(() => _publicFiles = v),
                      title: Text(context.l('الملفات العامة', 'Public files')),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _publicLibrary,
                      onChanged: (v) => setState(() => _publicLibrary = v),
                      title: Text(context.l('المكتبة (تصفح الكتب وتوفرها)', 'Library (browse books and availability)')),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _publicTheses,
                      onChanged: (v) => setState(() => _publicTheses = v),
                      title: Text(context.l('الرسائل والأطاريح', 'Theses')),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _contactEnabled,
                      onChanged: (v) => setState(() => _contactEnabled = v),
                      title: Text(context.l('تواصل معنا', 'Contact us')),
                    ),
                  ]),
                  _Section(title: context.tr('security.captcha_title'), children: [
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _showCaptchaRegister,
                      onChanged: (v) => setState(() => _showCaptchaRegister = v),
                      title: Text(context.tr('security.captcha_register')),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _showCaptchaReset,
                      onChanged: (v) => setState(() => _showCaptchaReset = v),
                      title: Text(context.tr('security.captcha_reset')),
                    ),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _showCaptchaDownload,
                      onChanged: (v) => setState(() => _showCaptchaDownload = v),
                      title: Text(context.tr('security.captcha_download')),
                    ),
                    const Divider(),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      value: _recaptchaEnabled,
                      onChanged: (v) => setState(() => _recaptchaEnabled = v),
                      title: Text(context.tr('security.recaptcha_enabled')),
                    ),
                    if (_recaptchaEnabled) ...[
                      TextField(controller: _recaptchaSiteKeyController, decoration: const InputDecoration(labelText: 'Site Key'), textDirection: TextDirection.ltr),
                      const SizedBox(height: 8),
                      TextField(controller: _recaptchaSecretKeyController, obscureText: true, decoration: const InputDecoration(labelText: 'Secret Key'), textDirection: TextDirection.ltr),
                      SwitchListTile(
                        contentPadding: EdgeInsets.zero,
                        value: _recaptchaInvisible,
                        onChanged: (v) => setState(() => _recaptchaInvisible = v),
                        title: Text(context.tr('security.recaptcha_invisible')),
                      ),
                    ],
                  ]),
                  _Section(title: context.tr('security.oauth_title'), children: [
                    if (_appUrl.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: Text(
                          '${context.tr('security.oauth_redirect')} $_appUrl/auth/<google|github|linkedin>/callback',
                          style: const TextStyle(fontSize: 11, color: Colors.grey),
                        ),
                      ),
                    const Text('Google', style: TextStyle(fontWeight: FontWeight.w600)),
                    TextField(controller: _googleIdController, decoration: const InputDecoration(labelText: 'Client ID'), textDirection: TextDirection.ltr),
                    TextField(controller: _googleSecretController, obscureText: true, decoration: const InputDecoration(labelText: 'Client Secret'), textDirection: TextDirection.ltr),
                    const SizedBox(height: 12),
                    const Text('GitHub', style: TextStyle(fontWeight: FontWeight.w600)),
                    TextField(controller: _githubIdController, decoration: const InputDecoration(labelText: 'Client ID'), textDirection: TextDirection.ltr),
                    TextField(controller: _githubSecretController, obscureText: true, decoration: const InputDecoration(labelText: 'Client Secret'), textDirection: TextDirection.ltr),
                    const SizedBox(height: 12),
                    const Text('LinkedIn', style: TextStyle(fontWeight: FontWeight.w600)),
                    TextField(controller: _linkedinIdController, decoration: const InputDecoration(labelText: 'Client ID'), textDirection: TextDirection.ltr),
                    TextField(controller: _linkedinSecretController, obscureText: true, decoration: const InputDecoration(labelText: 'Client Secret'), textDirection: TextDirection.ltr),
                  ]),
                  if (_error != null) ...[
                    Text(_error!, style: const TextStyle(color: Colors.red)),
                    const SizedBox(height: 8),
                  ],
                  FilledButton(onPressed: _saving ? null : _submit, child: Text(context.tr('security.save'))),
                  const SizedBox(height: 16),
                  _Section(title: context.tr('security.ip_rules_title'), children: [
                    DropdownButtonFormField<String>(
                      value: _ipFilterMode,
                      decoration: InputDecoration(labelText: context.tr('security.filter_mode_label')),
                      items: [
                        DropdownMenuItem(value: 'none', child: Text(context.tr('security.ip_mode_off'))),
                        DropdownMenuItem(value: 'blacklist', child: Text(context.tr('security.ip_mode_blacklist'))),
                        DropdownMenuItem(value: 'whitelist', child: Text(context.tr('security.ip_mode_whitelist'))),
                      ],
                      onChanged: (v) => setState(() => _ipFilterMode = v ?? 'none'),
                    ),
                    const SizedBox(height: 8),
                    TextField(controller: _ipRedirectUrlController, decoration: InputDecoration(labelText: context.tr('security.ip_redirect_placeholder')), textDirection: TextDirection.ltr),
                    const Divider(height: 24),
                    ValueListenableBuilder<String>(
                      valueListenable: _ipTypeController,
                      builder: (context, type, _) => DropdownButtonFormField<String>(
                        value: type,
                        decoration: InputDecoration(labelText: context.tr('security.new_rule_type')),
                        items: [
                          DropdownMenuItem(value: 'blacklist', child: Text(context.tr('security.block'))),
                          DropdownMenuItem(value: 'whitelist', child: Text(context.tr('security.allow'))),
                        ],
                        onChanged: (v) => _ipTypeController.value = v ?? 'blacklist',
                      ),
                    ),
                    const SizedBox(height: 8),
                    TextField(controller: _ipValueController, decoration: InputDecoration(labelText: context.tr('security.ip_example_placeholder')), textDirection: TextDirection.ltr),
                    const SizedBox(height: 8),
                    TextField(controller: _ipNoteController, decoration: InputDecoration(labelText: context.tr('security.note_optional'))),
                    const SizedBox(height: 8),
                    OutlinedButton(onPressed: _addIpRule, child: Text(context.tr('security.add_rule'))),
                    const SizedBox(height: 12),
                    if (_ipRules.isEmpty)
                      Text(context.tr('security.no_rules'), style: const TextStyle(color: Colors.grey))
                    else
                      ..._ipRules.map((rule) => ListTile(
                            contentPadding: EdgeInsets.zero,
                            dense: true,
                            leading: Text(rule.type == 'blacklist' ? context.tr('security.block') : context.tr('security.allow'), style: const TextStyle(fontSize: 12)),
                            title: Text(rule.ipOrCidr, textDirection: TextDirection.ltr),
                            subtitle: rule.note != null ? Text(rule.note!) : null,
                            trailing: IconButton(icon: const Icon(Icons.delete_outline_rounded, color: Colors.red), onPressed: () => _removeIpRule(rule)),
                          )),
                  ]),
                ],
              ),
            ),
    );
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
