import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../app/state/theme_provider.dart';
import '../../../core/i18n/app_strings.dart';
import '../../../core/i18n/locale_provider.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/ui_kit.dart';
import '../../guest/presentation/guest_shell.dart';
import '../../server_settings/presentation/server_settings_screen.dart';
import '../../site/site_api.dart';
import '../state/auth_provider.dart';
import 'forgot_password_screen.dart';
import 'register_screen.dart';
import 'two_factor_challenge_screen.dart';

const _brandTop = Color(0xFF6366F1);
const _brandBottom = Color(0xFF4338CA);

/// Sign in, redesigned (2026-11-06): the FileNest icon on a brand header, a
/// rounded card with the form, and the visitors' sections as tiles below.
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
  bool _showPassword = false;
  String? _error;
  Map<String, dynamic>? _site;

  @override
  void initState() {
    super.initState();
    _loadSite();
  }

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  /// The site's name and which parts of the visitors' site are open.
  Future<void> _loadSite() async {
    try {
      final s = await SiteApi.instance.site();
      if (mounted) setState(() => _site = s);
    } catch (_) {}
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();

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

  void _openGuest(String tab) {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => GuestShell(site: _site!, initial: tab)));
  }

  void _openServerSettings() {
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ServerSettingsScreen())).then((_) => _loadSite());
  }

  void _toggleLanguage() {
    final locale = context.read<LocaleProvider>();
    locale.setLocale(locale.locale.languageCode == 'ar' ? 'en' : 'ar');
  }

  InputDecoration _field(String label, IconData icon, {Widget? suffix}) {
    final dark = context.isDark;
    return InputDecoration(
      labelText: label,
      prefixIcon: Icon(icon),
      suffixIcon: suffix,
      filled: true,
      fillColor: dark ? Colors.white.withValues(alpha: 0.06) : const Color(0xFFF3F4F8),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: _brandTop, width: 1.6)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final siteName = (_site?['site_name'] as String?)?.trim();
    final width = MediaQuery.of(context).size.width;
    final cardWidth = width > 520 ? 440.0 : width - 32;

    return Scaffold(
      backgroundColor: context.isDark ? const Color(0xFF111827) : const Color(0xFFF5F6FB),
      body: SingleChildScrollView(
        child: Column(children: [
          // Brand header
          Container(
            width: double.infinity,
            padding: EdgeInsets.only(top: MediaQuery.of(context).padding.top + 8, bottom: 64),
            decoration: const BoxDecoration(
              gradient: LinearGradient(colors: [_brandTop, _brandBottom], begin: Alignment.topLeft, end: Alignment.bottomRight),
              borderRadius: BorderRadius.vertical(bottom: Radius.circular(32)),
            ),
            child: Column(children: [
              Row(children: [
                const SizedBox(width: 8),
                IconButton(
                  tooltip: context.l('English', 'العربية'),
                  onPressed: _toggleLanguage,
                  icon: const Icon(Icons.translate_rounded, color: Colors.white),
                ),
                IconButton(
                  tooltip: context.l('المظهر', 'Appearance'),
                  onPressed: () => context.read<ThemeProvider>().setMode(context.isDark ? ThemeMode.light : ThemeMode.dark),
                  icon: Icon(context.isDark ? Icons.light_mode_rounded : Icons.dark_mode_rounded, color: Colors.white),
                ),
                const Spacer(),
                IconButton(
                  tooltip: context.tr('nav.server_settings'),
                  onPressed: _openServerSettings,
                  icon: const Icon(Icons.dns_rounded, color: Colors.white),
                ),
                const SizedBox(width: 8),
              ]),
              const SizedBox(height: 8),
              Container(
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(26),
                  boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.25), blurRadius: 24, offset: const Offset(0, 10))],
                ),
                child: ClipRRect(borderRadius: BorderRadius.circular(26), child: Image.asset('assets/images/logo.png', width: 92, height: 92)),
              ),
              const SizedBox(height: 14),
              Text(siteName == null || siteName.isEmpty ? 'FileNest' : siteName,
                  textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
              const SizedBox(height: 4),
              Text(context.l('الملفات والمكتبة والأطاريح في مكان واحد', 'Files, library and theses in one place'),
                  textAlign: TextAlign.center, style: TextStyle(color: Colors.white.withValues(alpha: 0.85), fontSize: 14)),
            ]),
          ),

          // Sign-in card
          Transform.translate(
            offset: const Offset(0, -40),
            child: Container(
              width: cardWidth,
              padding: const EdgeInsets.fromLTRB(20, 22, 20, 14),
              decoration: BoxDecoration(
                color: scheme.surface,
                borderRadius: BorderRadius.circular(24),
                boxShadow: [BoxShadow(color: Colors.black.withValues(alpha: 0.08), blurRadius: 30, offset: const Offset(0, 12))],
              ),
              child: Form(
                key: _formKey,
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text(context.l('تسجيل الدخول', 'Sign in'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 2),
                  Text(context.l('أهلاً بك من جديد', 'Welcome back'), style: TextStyle(color: scheme.onSurfaceVariant)),
                  const SizedBox(height: 18),
                  TextFormField(
                    controller: _emailController,
                    keyboardType: TextInputType.emailAddress,
                    textInputAction: TextInputAction.next,
                    autofillHints: const [AutofillHints.email, AutofillHints.username],
                    decoration: _field(context.tr('auth.email'), Icons.alternate_email_rounded),
                    validator: (value) => (value == null || value.isEmpty) ? context.tr('auth.email_required') : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _passwordController,
                    obscureText: !_showPassword,
                    textInputAction: TextInputAction.done,
                    autofillHints: const [AutofillHints.password],
                    onFieldSubmitted: (_) => _submit(),
                    decoration: _field(
                      context.tr('auth.password'),
                      Icons.lock_outline_rounded,
                      suffix: IconButton(
                        icon: Icon(_showPassword ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                        onPressed: () => setState(() => _showPassword = !_showPassword),
                      ),
                    ),
                    validator: (value) => (value == null || value.isEmpty) ? context.tr('auth.password_required') : null,
                  ),
                  Align(
                    alignment: AlignmentDirectional.centerEnd,
                    child: TextButton(
                      onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ForgotPasswordScreen())),
                      child: Text(context.tr('auth.forgot_password')),
                    ),
                  ),
                  if (_error != null)
                    Container(
                      margin: const EdgeInsets.only(bottom: 10),
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(color: Colors.red.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(12)),
                      child: Row(children: [
                        const Icon(Icons.error_outline_rounded, color: Colors.red, size: 20),
                        const SizedBox(width: 8),
                        Expanded(child: Text(_error!, style: const TextStyle(color: Colors.red))),
                      ]),
                    ),
                  SizedBox(
                    height: 52,
                    child: FilledButton(
                      style: FilledButton.styleFrom(
                        backgroundColor: _brandTop,
                        foregroundColor: Colors.white,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                        textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                      ),
                      onPressed: _isLoading ? null : _submit,
                      child: _isLoading
                          ? const SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                          : Text(context.tr('auth.login')),
                    ),
                  ),
                  const SizedBox(height: 6),
                  TextButton(
                    onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const RegisterScreen())),
                    child: Text(context.tr('auth.no_account_create')),
                  ),
                ]),
              ),
            ),
          ),

          // Visitors
          Transform.translate(offset: const Offset(0, -24), child: SizedBox(width: cardWidth, child: _guestSection())),
        ]),
      ),
    );
  }

  Widget _guestSection() {
    final s = _site;
    if (s == null || s['enabled'] != true) return const SizedBox.shrink();
    final items = [
      if (s['library'] == true)
        ('library', Icons.local_library_rounded, const Color(0xFF7E57C2), context.l('المكتبة', 'Library'), context.l('ابحث عن كتاب وشوف إذا متوفر', 'Find a book, see if it is in')),
      if (s['theses'] == true)
        ('theses', Icons.school_rounded, const Color(0xFF00897B), context.l('الرسائل والأطاريح', 'Theses'), context.l('تصفح وابحث بالعنوان أو الباحث', 'Browse by title or researcher')),
      if (s['contact'] == true)
        ('contact', Icons.forum_rounded, const Color(0xFFEF6C00), context.l('تواصل معنا', 'Contact us'), context.l('استفسار أو طلب كتاب أو رسالة', 'Ask, or request a book or thesis')),
    ];
    if (items.isEmpty) return const SizedBox.shrink();
    final scheme = Theme.of(context).colorScheme;

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Padding(
        padding: const EdgeInsetsDirectional.only(start: 6, bottom: 8),
        child: Text(context.l('تصفّح بدون حساب', 'Browse without an account'),
            style: TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: scheme.onSurfaceVariant)),
      ),
      Material(
        color: scheme.surface,
        borderRadius: BorderRadius.circular(20),
        clipBehavior: Clip.antiAlias,
        child: Column(children: [
          for (var i = 0; i < items.length; i++) ...[
            if (i > 0) const Divider(height: 1, indent: 64),
            ListTile(
              contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
              leading: IconTile(icon: items[i].$2, color: items[i].$3, size: 38),
              title: Text(items[i].$4, style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text(items[i].$5, maxLines: 1, overflow: TextOverflow.ellipsis),
              trailing: Icon(Directionality.of(context) == TextDirection.rtl ? Icons.chevron_left_rounded : Icons.chevron_right_rounded),
              onTap: () => _openGuest(items[i].$1),
            ),
          ],
        ]),
      ),
      const SizedBox(height: 24),
    ]);
  }
}
