import 'package:flutter/material.dart';

import '../storage/token_storage.dart';

/// Renders a file's thumbnail image (a protected `/files/{id}/thumbnail`
/// URL that requires the Bearer token, unlike a plain public image) —
/// falls back to [fallbackIcon] while the token is being read, if there's
/// no stored token, or if the image itself fails to load (a 404 when no
/// thumbnail was ever generated server-side, or a transient network
/// error) — this must never block or break the file list it's used in.
class AuthenticatedThumbnail extends StatelessWidget {
  const AuthenticatedThumbnail({
    super.key,
    required this.url,
    required this.fallbackIcon,
    this.size = 40,
  });

  final String url;
  final IconData fallbackIcon;
  final double size;

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<String?>(
      future: TokenStorage.instance.read(),
      builder: (context, snapshot) {
        final token = snapshot.data;
        if (token == null) return _fallback();

        return ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: Image.network(
            url,
            headers: {'Authorization': 'Bearer $token'},
            width: size,
            height: size,
            fit: BoxFit.cover,
            errorBuilder: (context, error, stackTrace) => _fallback(),
          ),
        );
      },
    );
  }

  Widget _fallback() => Icon(fallbackIcon, color: Colors.grey.shade700);
}
