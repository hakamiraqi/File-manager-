/// Central place to point the app at a FileNest backend.
///
/// Override the built-in default at build/run time with:
///   flutter run --dart-define=API_BASE_URL=https://your-domain.com/api/v1
///
/// This is only the *fallback* used before the user ever configures
/// anything in "إعدادات الخادم" (see ServerConfigService) — once a local
/// network and/or hosted address is saved, the app resolves and applies
/// the actual base URL to ApiClient at startup instead.
class ApiConfig {
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  /// The default hosted origin (scheme + host + port, no `/api/v1` suffix)
  /// pre-filled into the server settings form the first time it's opened.
  static String get defaultHostedOrigin => stripApiSuffix(baseUrl);

  /// Strips a trailing `/api/v1` (or `/api/v1/`) suffix so the user only
  /// ever has to think about and type a plain server origin.
  static String stripApiSuffix(String url) {
    var trimmed = url.trim();
    while (trimmed.endsWith('/')) {
      trimmed = trimmed.substring(0, trimmed.length - 1);
    }
    if (trimmed.endsWith('/api/v1')) {
      trimmed = trimmed.substring(0, trimmed.length - '/api/v1'.length);
    }
    return trimmed;
  }

  /// Normalizes whatever the user typed into a plain origin Dio can use:
  /// trims it, drops a `/api/v1` suffix if they pasted a full API URL, and
  /// assumes `http://` when no scheme was given at all — a local-network
  /// FileNest server is virtually always plain HTTP (see the mobile
  /// README's cleartext-traffic note), so typing just `192.168.1.10:8000`
  /// should just work.
  static String normalizeOrigin(String origin) {
    final stripped = stripApiSuffix(origin);
    if (stripped.startsWith('http://') || stripped.startsWith('https://')) {
      return stripped;
    }
    return stripped.isEmpty ? stripped : 'http://$stripped';
  }

  /// Builds the full `.../api/v1` base URL Dio should use from a plain
  /// origin the user typed (e.g. `http://192.168.1.10:8000`).
  static String apiUrlFor(String origin) => '${normalizeOrigin(origin)}/api/v1';
}
