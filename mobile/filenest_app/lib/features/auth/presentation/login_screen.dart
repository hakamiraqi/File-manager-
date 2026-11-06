import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../contact/presentation/contact_screen.dart';
import '../../library/presentation/library_screen.dart';
import '../../server_settings/presentation/server_settings_screen.dart';
import '../../site/site_api.dart';
import '../../theses/presentation/theses_screen.dart';
import '../state/auth_provider.dart';
import 'forgot_password_screen.dart';
import 'register_screen.dart';
import 'two_factor_challenge_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _isLoading = false;
  String? _error;
  Map<String, dynamic>? _site;

  @override
  void initState() {
    super.initState();
    _loadSite();
  }

  /// Which parts of the visitors' site are open, for the «تصفح كزائر» buttons.
  Future<void> _loadSite() async {
    try {
      final s = await SiteApi.instance.site();
      if (mounted) setState(() => _site = s);
    } catch (_) {}
  }

  Widget _guestSection() {
    final s = _site;
    if (s == null || s['enabled'] != true) return const SizedBox.shrink();
    void push(Widget w) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => w));
    final items = [
      if (s['library'] == true) (Icons.local_library_rounded, const Color(0xFF7E57C2), context.l('المكتبة', 'Library'), () => push(const LibraryScreen(guest: true))),
      if (s['theses'] == true)
        (Icons.school_rounded, const Color(0xFF00897B), context.l('الأطاريح', 'Theses'), () => push(ThesesScreen(contactEnabled: s['contact'] == true))),
      if (s['contact'] == true) (Icons.forum_rounded, const Color(0xFFEF6C00), context.l('تواصل معنا', 'Contact'), () => push(const ContactScreen())),
    ];
    if (items.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.only(top: 20),
      child: Column(children: [
        Row(children: [
          const Expanded(child: Divider()),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 10),
            child: Text(context.l('أو تصفّح بدون تسجيل دخول', 'Or browse without signing in'),
                style: TextStyle(color: Theme.of(context).colorScheme.onSurfaceVariant, fontSize: 13)),
          ),
          const Expanded(child: Divider()),
        ]),
        const SizedBox(height: 12),
        Row(
          children: items
              .map((i) => Expanded(
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 4),
                      child: Material(
                        color: i.$2.withOpacity(0.1),
                        borderRadius: BorderRadius.circular(14),
                        child: InkWell(
                          borderRadius: BorderRadius.circular(14),
                          onTap: i.$4,
                          child: Padding(
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            child: Column(children: [
                              Icon(i.$1, color: i.$2, size: 28),
                              const SizedBox(height: 6),
                              Text(i.$3, style: TextStyle(fontWeight: FontWeight.w600, color: i.$2)),
                            ]),
                          ),
                        ),
                      ),
                    ),
                  ))
              .toList(),
        ),
      ]),
    );
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      final result = await context.read<AuthProvider>().login(
            _emailController.text.trim(),
            _passwordController.text,
          );

      if (result.twoFactorRequired && mounted) {
        Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => TwoFactorChallengeScreen(twoFactorToken: result.twoFactorToken!)),
        );
      }
    } catch (e) {
      setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        elevation: 0,
        backgroundColor: Colors.transparent,
        actions: [
          IconButton(
            icon: const Icon(Icons.dns_rounded),
            tooltip: context.tr('nav.server_settings'),
            onPressed: () => Navigator.of(context).push(
              MaterialPageRoute(builder: (_) => const ServerSettingsScreen()),
            ).then((_) => _loadSite()),
          ),
        ],
      ),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Icon(Icons.folder_copy_rounded, size: 64, color: Color(0xFF2563EB)),
                  const SizedBox(height: 12),
                  Text('FileNest', textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineMedium),
                  const SizedBox(height: 32),
                  TextFormField(
                    controller: _emailController,
                    keyboardType: TextInputType.emailAddress,
                    decoration: InputDecoration(labelText: context.tr('auth.email'), border: const OutlineInputBorder()),
                    validator: (value) => (value == null || value.isEmpty) ? context.tr('auth.email_required') : null,
                  ),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: _passwordController,
                    obscureText: true,
                    decoration: InputDecoration(labelText: context.tr('auth.password'), border: const OutlineInputBorder()),
                    validator: (value) => (value == null || value.isEmpty) ? context.tr('auth.password_required') : null,
                  ),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton(
                      onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => const ForgotPasswordScreen()),
                      ),
                      child: Text(context.tr('auth.forgot_password')),
                    ),
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    Text(_error!, style: const TextStyle(color: Colors.red)),
                  ],
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: _isLoading ? null : _submit,
                    child: _isLoading
                        ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                        : Text(context.tr('auth.login')),
                  ),
                  const SizedBox(height: 12),
                  TextButton(
                    onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => const RegisterScreen()),
                    ),
                    child: Text(context.tr('auth.no_account_create')),
                  ),
                  _guestSection(),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
