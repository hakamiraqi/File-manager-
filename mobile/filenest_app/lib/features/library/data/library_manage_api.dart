import 'package:dio/dio.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../../core/config/api_config.dart';
import '../../../core/network/api_client.dart';

typedef Json = Map<String, dynamic>;

/// The website's library management for staff (2026-11-10): books and copies, sections,
/// readers, Excel imports, reports, lending rules and holidays. The server answers with
/// the same data the web pages get, and `{message}` after a change.
class LibraryManageApi {
  LibraryManageApi._();
  static final LibraryManageApi instance = LibraryManageApi._();

  Dio get _dio => ApiClient.instance.dio;
  static const _base = '/library/manage';

  Future<Json> _get(String path, [Map<String, dynamic>? query]) async {
    final r = await _dio.get('$_base$path', queryParameters: query?..removeWhere((_, v) => v == null || v == ''));
    return Map<String, dynamic>.from(r.data as Map);
  }

  /// Returns the server's message ("تمت إضافة الكتاب." …).
  Future<String> _send(String method, String path, [Object? data]) async {
    final r = await _dio.request('$_base$path', data: data, options: Options(method: method));
    final body = r.data;
    return body is Map && body['message'] is String ? body['message'] as String : '';
  }

  // Books
  Future<Json> books({String? q, int? section, int page = 1}) => _get('/books', {'q': q, 'section': section, 'page': page});

  /// [cover] is a picked image path; sent as a form so the picture travels with it.
  Future<String> saveBook(Json data, {int? id, String? cover}) async {
    // Empty means "none" (the server turns '' into null), so a cleared box clears the field.
    final fields = <String, dynamic>{...data}..updateAll((_, v) => v == null ? '' : v is bool ? (v ? '1' : '0') : v);
    if (id != null) fields['_method'] = 'PUT';
    final form = FormData.fromMap({...fields, if (cover != null) 'cover': await MultipartFile.fromFile(cover)});
    return _send('POST', id == null ? '/books' : '/books/$id', form);
  }

  Future<String> deleteBook(int id) => _send('DELETE', '/books/$id');
  Future<Json> isbn(String isbn) => _get('/isbn/${Uri.encodeComponent(isbn)}');
  /// The text printed on a photographed cover: {lines: [{text, size}], title, isbn}.
  Future<Json> coverText(String imagePath) async {
    final r = await _dio.post('$_base/cover-text', data: FormData.fromMap({'image': await MultipartFile.fromFile(imagePath)}));
    return Map<String, dynamic>.from(r.data as Map);
  }

  Future<String> addCopies(int bookId, int count, {String? shelf}) => _send('POST', '/books/$bookId/copies', {'count': count, 'shelf': shelf});
  Future<String> updateCopy(int id, Json data) => _send('PUT', '/copies/$id', data);
  Future<String> deleteCopy(int id) => _send('DELETE', '/copies/$id');
  Future<String> importBooks(String path) async => _send('POST', '/import', FormData.fromMap({'file': await MultipartFile.fromFile(path)}));

  /// Copy cataloguing: the world catalogues (SRU / Z39.50) set up on the website.
  /// {results: [{server, book: {title, authors, …}, marc_xml}], errors: [server names]}
  Future<Json> catalogSearch({String? isbn, String? title, String? author}) async {
    final r = await _dio.get('$_base/catalog-search',
        queryParameters: {'isbn': isbn, 'title': title, 'author': author}..removeWhere((_, v) => v == null || v == ''),
        options: Options(receiveTimeout: const Duration(seconds: 45)));
    return Map<String, dynamic>.from(r.data as Map);
  }

  /// A MARC file from Koha or any library system (.mrc or MARCXML); [update] refreshes books already here.
  Future<String> importMarc(String path, {bool update = false}) async => _send(
      'POST', '/import/marc', FormData.fromMap({'file': await MultipartFile.fromFile(path), 'existing': update ? 'update' : 'skip'}));

  /// The whole catalogue as MARC21 for Koha, saved to [target].
  Future<void> exportMarc(String target, {String format = 'mrc'}) async {
    await _dio.download('$_base/export/marc', target,
        queryParameters: {'format': format}, options: Options(receiveTimeout: const Duration(minutes: 5)));
  }

  // Sections
  Future<List<Json>> sections() async => ((await _get('/sections'))['sections'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
  Future<String> saveSection(Json data, {int? id, String? cover, bool removeCover = false}) async {
    final fields = <String, dynamic>{...data}..updateAll((_, v) => v ?? '');
    if (id != null) fields['_method'] = 'PUT';
    if (removeCover) fields['remove_cover'] = '1';
    final form = FormData.fromMap({...fields, if (cover != null) 'cover': await MultipartFile.fromFile(cover)});
    return _send('POST', id == null ? '/sections' : '/sections/$id', form);
  }

  Future<String> deleteSection(int id) => _send('DELETE', '/sections/$id');

  // Readers
  Future<Json> members({String? q, int page = 1}) => _get('/members', {'q': q, 'page': page});
  Future<String> saveMember(Json data, {int? id}) => id == null ? _send('POST', '/members', data) : _send('PUT', '/members/$id', data);
  Future<String> deleteMember(int id) => _send('DELETE', '/members/$id');
  Future<String> importMembers(String path) async => _send('POST', '/members/import', FormData.fromMap({'file': await MultipartFile.fromFile(path)}));

  // Rules, holidays, reports
  Future<Json> settings() => _get('/settings');
  Future<String> saveRules(Json rules) => _send('POST', '/settings', rules);
  Future<String> addHoliday(String from, {String? to, String? name}) => _send('POST', '/holidays', {'from': from, 'to': to, 'name': name});
  Future<String> removeHoliday(int id) => _send('DELETE', '/holidays/$id');
  Future<Json> reports({String? from, String? to}) => _get('/reports', {'from': from, 'to': to});

  /// A website page opened by a short-lived signed link (map editor, RFID, labels, cards).
  Future<({String path, bool external})> webLink(String page, {List<int> books = const [], List<int> ids = const []}) async {
    final r = await _dio.get('/web-link', queryParameters: {
      'page': page,
      if (books.isNotEmpty) 'books[]': books,
      if (ids.isNotEmpty) 'ids[]': ids,
    });
    final data = r.data as Map;
    return (path: data['path'] as String, external: data['external'] == true);
  }

  /// Printing pages open in the device's browser (a web view cannot print).
  Future<void> openInBrowser(String page, {List<int> books = const [], List<int> ids = const []}) async {
    final link = await webLink(page, books: books, ids: ids);
    final url = Uri.parse('${ApiConfig.stripApiSuffix(ApiClient.instance.baseUrl)}${link.path}');
    if (!await launchUrl(url, mode: LaunchMode.externalApplication)) {
      throw Exception('تعذّر فتح المتصفح.');
    }
  }
}
