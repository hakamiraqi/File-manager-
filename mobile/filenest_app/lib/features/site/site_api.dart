import 'package:dio/dio.dart';

import '../../core/network/api_client.dart';
import '../../core/storage/offline_cache.dart';

typedef Json = Map<String, dynamic>;

/// Calls for the website features added to the app (2026-11-06): the library,
/// theses, notifications, saved items, «تواصل معنا», its inbox and the profile picture.
class SiteApi {
  SiteApi._();
  static final SiteApi instance = SiteApi._();

  Dio get _dio => ApiClient.instance.dio;

  /// Every page read here is kept on the device (2026-11-11, by request) and shown again when
  /// there is no internet: the library, a book opened before, my loans, the theses.
  Future<Json> _get(String path, [Map<String, dynamic>? query]) async {
    query?.removeWhere((_, v) => v == null || v == '');
    final key = 'site_${path}_${(query?.entries.toList() ?? [])..sort((a, b) => a.key.compareTo(b.key))}';
    try {
      final r = await _dio.get(path, queryParameters: query);
      final data = Map<String, dynamic>.from(r.data as Map);
      OfflineCache.instance.put(key, data);
      return data;
    } catch (e) {
      final saved = OfflineCache.isOffline(e) ? await OfflineCache.instance.get(key) : null;
      if (saved is Map) return Map<String, dynamic>.from(saved)..['_offline'] = true;
      rethrow;
    }
  }

  Future<Json> _send(String method, String path, [Object? data]) async {
    final r = await _dio.request(path, data: data, options: Options(method: method));
    return r.data is Map ? Map<String, dynamic>.from(r.data as Map) : <String, dynamic>{};
  }

  // Visitors' site
  Future<Json> site() => _get('/public/site');

  // Library
  Future<Json> books({String? q, int? section, bool available = false, String? sort, int page = 1}) =>
      _get('/public/library', {'q': q, 'section': section, 'available': available ? 1 : null, 'sort': sort, 'page': page});
  Future<Json> book(int id) => _get('/public/library/books/$id');
  Future<Json> hold(int bookId) => _send('POST', '/library/books/$bookId/hold');
  Future<Json> cancelHold(int holdId) => _send('DELETE', '/library/holds/$holdId');
  Future<Json> myLoans() => _get('/library/mine');
  Future<Json> renew(int loanId) => _send('POST', '/library/loans/$loanId/renew');

  // Library staff
  Future<Json> libraryStats() => _get('/library/manage/stats');
  Future<Json> loans({String filter = 'active', String? q, int page = 1}) => _get('/library/manage/loans', {'filter': filter, 'q': q, 'page': page});
  Future<List<dynamic>> holdsQueue() async => (await _dio.get('/library/manage/holds')).data as List<dynamic>;
  Future<Json> payFine(int loanId) => _send('POST', '/library/manage/loans/$loanId/pay');
  Future<Json> waiveFine(int loanId) => _send('POST', '/library/manage/loans/$loanId/waive');
  Future<Json> scan(String code, {int? memberId, String mode = 'auto'}) =>
      _send('POST', '/library/manage/counter/scan', {'code': code, 'member_id': memberId, 'mode': mode});
  Future<List<dynamic>> findMembers(String q) async => (await _dio.get('/library/manage/counter/members', queryParameters: {'q': q})).data as List<dynamic>;
  Future<Json> pickMember({int? memberId, int? userId}) => _send('POST', '/library/manage/counter/member', {'member_id': memberId, 'user_id': userId});

  // Theses
  Future<Json> theses({Map<String, dynamic> filters = const {}, int page = 1}) => _get('/public/theses', {...filters, 'page': page});

  // Notifications
  Future<Json> notifications({String filter = 'all', int page = 1}) => _get('/notifications', {'filter': filter, 'page': page});
  Future<Json> badges() => _get('/me/badges');
  Future<void> readNotification(int id) => _send('POST', '/notifications/$id/read');
  Future<void> readAllNotifications() => _send('POST', '/notifications/read-all');
  Future<void> deleteNotification(int id) => _send('DELETE', '/notifications/$id');

  // Saved
  Future<Json> saved() => _get('/saved');
  Future<bool> toggleSaved(String type, int id) async => (await _send('POST', '/saved/toggle', {'type': type, 'id': id}))['saved'] == true;
  Future<void> forgetDownload([int? fileId]) => _send('POST', '/saved/forget-download', {'id': fileId});

  // Contact
  Future<Json> contactInfo() => _get('/public/contact');
  Future<Json> sendContact(Json data) => _send('POST', '/public/contact', data);
  Future<Json> inbox({String status = 'open', String? kind, String? q, int page = 1}) =>
      _get('/inbox', {'status': status, 'kind': kind, 'q': q, 'page': page});
  Future<Json> updateInbox(int id, {required String status, String? reply}) => _send('PATCH', '/inbox/$id', {'status': status, 'reply': reply});
  Future<Json> deleteInbox(int id) => _send('DELETE', '/inbox/$id');

  // Profile picture
  Future<Json> uploadAvatar(String path) async {
    final form = FormData.fromMap({'avatar': await MultipartFile.fromFile(path)});
    return _send('POST', '/profile/avatar', form);
  }

  Future<Json> deleteAvatar() => _send('DELETE', '/profile/avatar');
}
