import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:filenest_app/features/auth/presentation/login_screen.dart';

void main() {
  testWidgets('LoginScreen shows the email and password fields', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: LoginScreen()));

    expect(find.text('البريد الإلكتروني'), findsOneWidget);
    expect(find.text('كلمة المرور'), findsOneWidget);
    expect(find.text('تسجيل الدخول'), findsOneWidget);
  });
}
