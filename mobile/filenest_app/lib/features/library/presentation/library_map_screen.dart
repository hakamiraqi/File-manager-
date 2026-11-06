import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:webview_flutter/webview_flutter.dart';
import 'package:webview_flutter_android/webview_flutter_android.dart';
import 'package:webview_flutter_wkwebview/webview_flutter_wkwebview.dart';
import 'package:webview_windows/webview_windows.dart' as win;

import '../../../core/config/api_config.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/ui_kit.dart';

/// «خريطة المكتبة» in the app (2026-11-06, by request): the same 3D map as the website
/// (three.js) with everything it does: search, the walk to the bookcase, floors and halls,
/// the QR sticker scanner and walking with the phone's motion sensors.
///
/// The server hands the app a short-lived signed link that opens the map signed in as the
/// same user (visitors get the public map), shown full screen without the site's menus.
/// Android and iPhone show it in the system web view, Windows in WebView2.
class LibraryMapScreen extends StatefulWidget {
  const LibraryMapScreen({super.key, this.bookId});

  /// Open straight on this book's bookcase and the walk to it.
  final int? bookId;

  @override
  State<LibraryMapScreen> createState() => _LibraryMapScreenState();
}

class _LibraryMapScreenState extends State<LibraryMapScreen> {
  static final bool _windows = !kIsWeb && Platform.isWindows;

  String? _error;
  bool _ready = false;
  double _progress = 0;
  WebViewController? _web;
  win.WebviewController? _winWeb;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _winWeb?.dispose();
    super.dispose();
  }

  Future<String> _link() async {
    final r = await ApiClient.instance.dio.get(
      '/public/library/map-link',
      queryParameters: {if (widget.bookId != null) 'book': widget.bookId},
    );
    final path = (r.data as Map)['path'] as String;
    return '${ApiConfig.stripApiSuffix(ApiClient.instance.baseUrl)}$path';
  }

  Future<void> _load() async {
    setState(() {
      _error = null;
      _ready = false;
      _progress = 0;
    });
    try {
      final url = await _link();
      if (_windows) {
        final c = _winWeb ?? win.WebviewController();
        if (_winWeb == null) {
          await c.initialize();
          c.loadingState.listen((s) {
            if (mounted) setState(() => _progress = s == win.LoadingState.loading ? 0 : 1);
          });
          _winWeb = c;
        }
        await c.loadUrl(url);
      } else {
        _web ??= _createMobile();
        await _web!.loadRequest(Uri.parse(url));
      }
      if (mounted) setState(() => _ready = true);
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    }
  }

  WebViewController _createMobile() {
    final params = WebViewPlatform.instance is WebKitWebViewPlatform
        ? WebKitWebViewControllerCreationParams(
            allowsInlineMediaPlayback: true,
            mediaTypesRequiringUserAction: const <PlaybackMediaTypes>{},
          )
        : const PlatformWebViewControllerCreationParams();
    final c = WebViewController.fromPlatformCreationParams(
      params,
      // The map's QR scanner asks for the camera: Android also needs the app's own permission.
      onPermissionRequest: (request) async {
        if (Platform.isAndroid && request.types.contains(WebViewPermissionResourceType.camera)) {
          if (!(await Permission.camera.request()).isGranted) {
            await request.deny();
            return;
          }
        }
        await request.grant();
      },
    )
      ..setJavaScriptMode(JavaScriptMode.unrestricted)
      ..setNavigationDelegate(NavigationDelegate(
        onProgress: (p) {
          if (mounted) setState(() => _progress = p / 100);
        },
        onWebResourceError: (e) {
          if (e.isForMainFrame == true && mounted) {
            setState(() {
              _ready = false;
              _error = e.description;
            });
          }
        },
      ));
    if (c.platform is AndroidWebViewController) {
      (c.platform as AndroidWebViewController).setMediaPlaybackRequiresUserGesture(false);
    }
    return c;
  }

  Future<void> _back() async {
    final web = _web;
    if (web != null && _ready && await web.canGoBack()) {
      await web.goBack();
    } else if (_winWeb != null && _ready && await _canGoBackWindows()) {
      await _winWeb!.goBack();
    } else if (mounted) {
      Navigator.of(context).pop();
    }
  }

  Future<bool> _canGoBackWindows() async {
    try {
      final r = await _winWeb!.executeScript('window.history.length > 1 && !location.search.includes("app=1")');
      return r == true;
    } catch (_) {
      return false;
    }
  }

  @override
  Widget build(BuildContext context) {
    Widget body;
    if (!_ready) {
      body = LoadingOrError(error: _error, onRetry: _load);
    } else if (_windows) {
      body = win.Webview(
        _winWeb!,
        permissionRequested: (url, kind, isUserInitiated) async => win.WebviewPermissionDecision.allow,
      );
    } else {
      body = WebViewWidget(controller: _web!);
    }

    return PopScope(
      // Back steps back inside the map first (e.g. from a book page), then leaves it.
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _back();
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.l('خريطة المكتبة', 'Library map')),
          actions: [
            IconButton(
              tooltip: context.l('إعادة التحميل', 'Reload'),
              icon: const Icon(Icons.refresh_rounded),
              onPressed: _load,
            ),
          ],
        ),
        body: Stack(children: [
          Positioned.fill(child: body),
          if (_ready && _progress < 1) LinearProgressIndicator(value: _progress == 0 ? null : _progress),
        ]),
      ),
    );
  }
}
