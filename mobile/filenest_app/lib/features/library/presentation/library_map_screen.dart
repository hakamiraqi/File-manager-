import 'dart:io' show Platform;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:permission_handler/permission_handler.dart';

import '../../../core/config/api_config.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/ui_kit.dart';

/// «خريطة المكتبة» in the app (2026-11-06, by request): the same 3D map as the website
/// (three.js) with everything it does: search, the walk to the bookcase, floors and halls,
/// the QR sticker scanner and walking with the phone's motion sensors.
///
/// The server hands the app a short-lived signed link that opens the map signed in as the
/// same user (visitors get the public map), shown full screen without the site's menus.
class LibraryMapScreen extends StatefulWidget {
  const LibraryMapScreen({super.key, this.bookId});

  /// Open straight on this book's bookcase and the walk to it.
  final int? bookId;

  @override
  State<LibraryMapScreen> createState() => _LibraryMapScreenState();
}

class _LibraryMapScreenState extends State<LibraryMapScreen> {
  String? _url;
  String? _error;
  double _progress = 0;
  InAppWebViewController? _web;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final r = await ApiClient.instance.dio.get(
        '/public/library/map-link',
        queryParameters: {if (widget.bookId != null) 'book': widget.bookId},
      );
      final path = (r.data as Map)['path'] as String;
      final origin = ApiConfig.stripApiSuffix(ApiClient.instance.baseUrl);
      if (mounted) setState(() => _url = '$origin$path');
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    }
  }

  /// The map's QR scanner asks for the camera: Android also needs the app's own permission.
  Future<PermissionResponse> _onPermission(InAppWebViewController controller, PermissionRequest request) async {
    if (!kIsWeb && Platform.isAndroid && request.resources.contains(PermissionResourceType.CAMERA)) {
      final status = await Permission.camera.request();
      if (!status.isGranted) {
        return PermissionResponse(resources: request.resources, action: PermissionResponseAction.DENY);
      }
    }
    return PermissionResponse(resources: request.resources, action: PermissionResponseAction.GRANT);
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      // Back steps back inside the map first (e.g. from the editor), then leaves it.
      canPop: false,
      onPopInvokedWithResult: (didPop, _) async {
        if (didPop) return;
        final web = _web;
        if (web != null && await web.canGoBack()) {
          await web.goBack();
        } else if (context.mounted) {
          Navigator.of(context).pop();
        }
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(context.l('خريطة المكتبة', 'Library map')),
          actions: [
            IconButton(
              tooltip: context.l('إعادة التحميل', 'Reload'),
              icon: const Icon(Icons.refresh_rounded),
              onPressed: _url == null ? _load : () => _web?.reload(),
            ),
          ],
        ),
        body: _url == null
            ? LoadingOrError(error: _error, onRetry: _load)
            : Stack(children: [
                InAppWebView(
                  initialUrlRequest: URLRequest(url: WebUri(_url!)),
                  initialSettings: InAppWebViewSettings(
                    javaScriptEnabled: true,
                    mediaPlaybackRequiresUserGesture: false,
                    allowsInlineMediaPlayback: true,
                    useHybridComposition: true,
                    supportZoom: false,
                    disableContextMenu: true,
                  ),
                  onWebViewCreated: (c) => _web = c,
                  onPermissionRequest: _onPermission,
                  onProgressChanged: (_, p) {
                    if (mounted) setState(() => _progress = p / 100);
                  },
                  onReceivedError: (_, request, error) {
                    if (request.isForMainFrame == true && mounted) {
                      setState(() {
                        _url = null;
                        _error = error.description;
                      });
                    }
                  },
                ),
                if (_progress < 1) LinearProgressIndicator(value: _progress == 0 ? null : _progress),
              ]),
      ),
    );
  }
}
