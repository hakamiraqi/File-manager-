import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../config/api_config.dart';
import 'api_client.dart';

/// How the app picks which backend to talk to.
enum ConnectionMode {
  /// Try the local network address first (fast, works with no internet at
  /// all as long as the phone and the server share a LAN); fall back to
  /// the hosted address automatically when the local one isn't reachable.
  auto,

  /// Always use the local network address — for a fully offline LAN setup
  /// where the hosted address may not even be configured.
  local,

  /// Always use the hosted/internet address, ignoring any saved local one.
  hosted,
}

/// A snapshot of what's currently configured and what actually got used
/// the last time the app resolved a backend to talk to.
class ResolvedServer {
  const ResolvedServer({required this.origin, required this.usedLocal});

  final String origin;
  final bool usedLocal;
}

/// Persists the user's local-network and hosted server addresses plus their
/// preferred [ConnectionMode], and resolves which one ApiClient should
/// actually use right now — letting the same app work fully offline on a
/// home/office LAN and switch to the internet-hosted server elsewhere,
/// without rebuilding or reconfiguring anything by hand each time.
class ServerConfigService {
  ServerConfigService._();
  static final ServerConfigService instance = ServerConfigService._();

  final _storage = const FlutterSecureStorage();
  static const _localOriginKey = 'filenest_local_origin';
  static const _hostedOriginKey = 'filenest_hosted_origin';
  static const _modeKey = 'filenest_connection_mode';

  ResolvedServer? lastResolved;

  Future<String?> get localOrigin => _storage.read(key: _localOriginKey);

  Future<String> get hostedOrigin async {
    final saved = await _storage.read(key: _hostedOriginKey);
    return (saved != null && saved.isNotEmpty) ? saved : ApiConfig.defaultHostedOrigin;
  }

  Future<ConnectionMode> get mode async {
    final saved = await _storage.read(key: _modeKey);
    return ConnectionMode.values.firstWhere(
      (m) => m.name == saved,
      orElse: () => ConnectionMode.auto,
    );
  }

  Future<void> save({String? localOrigin, String? hostedOrigin, ConnectionMode? mode}) async {
    if (localOrigin != null) {
      await _storage.write(key: _localOriginKey, value: localOrigin.trim().isEmpty ? null : localOrigin.trim());
    }
    if (hostedOrigin != null) {
      await _storage.write(key: _hostedOriginKey, value: hostedOrigin.trim());
    }
    if (mode != null) {
      await _storage.write(key: _modeKey, value: mode.name);
    }
  }

  /// A quick, short-timeout probe against Laravel's built-in `/up`
  /// health-check route. Any HTTP response at all (even a non-2xx one)
  /// counts as "reachable" — we only care that something answered, not
  /// that the app itself is fully healthy; only a connection error or
  /// timeout means "not reachable".
  Future<bool> isReachable(String origin, {Duration timeout = const Duration(seconds: 2)}) async {
    final probe = Dio(BaseOptions(
      connectTimeout: timeout,
      receiveTimeout: timeout,
      validateStatus: (_) => true,
    ));

    try {
      await probe.get('${ApiConfig.normalizeOrigin(origin)}/up');
      return true;
    } catch (_) {
      // Any failure — connection refused/timeout, DNS failure, or even a
      // malformed address the user is still typing — just means "not
      // reachable right now", never a crash.
      return false;
    } finally {
      probe.close();
    }
  }

  /// Decides which origin to use given the saved mode (probing the local
  /// address first for [ConnectionMode.auto]), applies it to [ApiClient],
  /// and remembers the outcome in [lastResolved].
  Future<ResolvedServer> resolveAndApply() async {
    final local = await localOrigin;
    final hosted = await hostedOrigin;
    final currentMode = await mode;

    String origin;
    bool usedLocal;

    switch (currentMode) {
      case ConnectionMode.local:
        origin = (local != null && local.isNotEmpty) ? local : hosted;
        usedLocal = local != null && local.isNotEmpty;
        break;
      case ConnectionMode.hosted:
        origin = hosted;
        usedLocal = false;
        break;
      case ConnectionMode.auto:
        if (local != null && local.isNotEmpty && await isReachable(local)) {
          origin = local;
          usedLocal = true;
        } else {
          origin = hosted;
          usedLocal = false;
        }
        break;
    }

    ApiClient.instance.updateBaseUrl(ApiConfig.apiUrlFor(origin));
    lastResolved = ResolvedServer(origin: origin, usedLocal: usedLocal);

    return lastResolved!;
  }
}
