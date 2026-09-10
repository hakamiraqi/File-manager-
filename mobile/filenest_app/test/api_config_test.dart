import 'package:flutter_test/flutter_test.dart';

import 'package:filenest_app/core/config/api_config.dart';

void main() {
  group('ApiConfig.stripApiSuffix', () {
    test('removes a trailing /api/v1', () {
      expect(ApiConfig.stripApiSuffix('http://192.168.1.10:8000/api/v1'), 'http://192.168.1.10:8000');
    });

    test('removes trailing slashes with no /api/v1 present', () {
      expect(ApiConfig.stripApiSuffix('https://your-domain.com/'), 'https://your-domain.com');
    });

    test('leaves a bare origin untouched', () {
      expect(ApiConfig.stripApiSuffix('http://192.168.1.10:8000'), 'http://192.168.1.10:8000');
    });
  });

  group('ApiConfig.normalizeOrigin', () {
    test('adds http:// when no scheme was typed', () {
      expect(ApiConfig.normalizeOrigin('192.168.1.10:8000'), 'http://192.168.1.10:8000');
    });

    test('keeps an explicit https:// scheme', () {
      expect(ApiConfig.normalizeOrigin('https://your-domain.com'), 'https://your-domain.com');
    });

    test('strips a pasted full API URL down to the origin', () {
      expect(ApiConfig.normalizeOrigin('https://your-domain.com/api/v1/'), 'https://your-domain.com');
    });
  });

  group('ApiConfig.apiUrlFor', () {
    test('builds the full /api/v1 URL from a plain origin', () {
      expect(ApiConfig.apiUrlFor('http://192.168.1.10:8000'), 'http://192.168.1.10:8000/api/v1');
    });

    test('works for an origin typed without a scheme', () {
      expect(ApiConfig.apiUrlFor('192.168.1.10:8000'), 'http://192.168.1.10:8000/api/v1');
    });
  });
}
