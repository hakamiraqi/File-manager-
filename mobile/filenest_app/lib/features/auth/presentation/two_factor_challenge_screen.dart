import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../state/auth_provider.dart';

class TwoFactorChallengeScreen extends StatefulWidget {
  const TwoFactorChallengeScreen({super.key, required this.twoFactorToken});

  final String twoFactorToken;

  @override
  State<TwoFactorChallengeScreen> createState() => _TwoFactorChallengeScreenState();
}

class _TwoFactorChallengeScreenState extends State<TwoFactorChallengeScreen> {
  final _controller = TextEditingController();
  bool _usingRecoveryCode = false;
  bool _isLoading = false;
  String? _error;

  Future<void> _submit() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      await context.read<AuthProvider>().completeTwoFactorLogin(
            twoFactorToken: widget.twoFactorToken,
            code: _usingRecoveryCode ? null : _controller.text.trim(),
            recoveryCode: _usingRecoveryCode ? _controller.text.trim() : null,
          );
    } catch (e) {
      setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.tr('twofactor.challenge_title'))),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  _usingRecoveryCode
                      ? context.tr('twofactor.enter_recovery_code')
                      : context.tr('twofactor.enter_verification_code'),
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 24),
                TextField(
                  controller: _controller,
                  autofocus: true,
                  keyboardType: _usingRecoveryCode ? TextInputType.text : TextInputType.number,
                  decoration: InputDecoration(
                    labelText: _usingRecoveryCode ? context.tr('twofactor.recovery_code') : context.tr('twofactor.code'),
                    border: const OutlineInputBorder(),
                  ),
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(_error!, style: const TextStyle(color: Colors.red)),
                ],
                const SizedBox(height: 20),
                FilledButton(
                  onPressed: _isLoading ? null : _submit,
                  child: _isLoading
                      ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                      : Text(context.tr('action.confirm')),
                ),
                const SizedBox(height: 12),
                TextButton(
                  onPressed: () => setState(() {
                    _usingRecoveryCode = !_usingRecoveryCode;
                    _controller.clear();
                    _error = null;
                  }),
                  child: Text(_usingRecoveryCode ? context.tr('twofactor.use_code_instead') : context.tr('twofactor.use_recovery_instead')),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
