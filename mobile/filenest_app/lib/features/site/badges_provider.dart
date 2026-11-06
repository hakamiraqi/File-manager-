import 'dart:async';

import 'package:flutter/widgets.dart';

import 'site_api.dart';

/// Unread counts for the tab bar: notifications and new «تواصل معنا» messages.
/// Refreshed every minute while the app is open, and whenever a screen asks.
class BadgesProvider extends ChangeNotifier with WidgetsBindingObserver {
  int notifications = 0;
  int contactNew = 0;
  Timer? _timer;

  void start() {
    stop();
    WidgetsBinding.instance.addObserver(this);
    refresh();
    _timer = Timer.periodic(const Duration(seconds: 60), (_) => refresh());
  }

  void stop() {
    _timer?.cancel();
    _timer = null;
    WidgetsBinding.instance.removeObserver(this);
  }

  Future<void> refresh() async {
    try {
      final b = await SiteApi.instance.badges();
      final n = (b['notifications'] as num?)?.toInt() ?? 0;
      final c = (b['contact_new'] as num?)?.toInt() ?? 0;
      if (n != notifications || c != contactNew) {
        notifications = n;
        contactNew = c;
        notifyListeners();
      }
    } catch (_) {
      // Offline or an older server: keep the last counts.
    }
  }

  void setNotifications(int value) {
    notifications = value;
    notifyListeners();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) refresh();
  }

  @override
  void dispose() {
    stop();
    super.dispose();
  }
}
