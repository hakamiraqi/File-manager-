import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/empty_state.dart';
import '../data/share_repository.dart';
import '../models/share_item.dart';

/// Lists every active/expired share link for the current user.
///
/// [embedded] controls the chrome: `false` (default) renders a full
/// standalone screen with its own AppBar for when this is pushed as a
/// normal route; `true` renders just the list body for when HomeShell
/// hosts it as the "المشاركة" tab (HomeShell supplies the AppBar there).
class SharesScreen extends StatefulWidget {
  const SharesScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  State<SharesScreen> createState() => _SharesScreenState();
}

class _SharesScreenState extends State<SharesScreen> {
  final _shareRepository = ShareRepository();
  List<ShareItem> _shares = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _isLoading = true);
    try {
      final shares = await _shareRepository.shares();
      setState(() => _shares = shares);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _revoke(ShareItem share) async {
    await _shareRepository.revoke(share.id);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final body = _buildBody();

    if (widget.embedded) {
      return RefreshIndicator(onRefresh: _load, child: body);
    }

    return Scaffold(
      appBar: AppBar(title: Text(context.tr('shares.title'))),
      body: RefreshIndicator(onRefresh: _load, child: body),
    );
  }

  Widget _buildBody() {
    if (_isLoading) return const Center(child: CircularProgressIndicator());

    if (_shares.isEmpty) {
      return ListView(
        // Wrapped in a scrollable so pull-to-refresh still works on an
        // empty list, not just once items exist.
        children: [
          const SizedBox(height: 80),
          EmptyState(icon: Icons.link_off_rounded, message: context.tr('empty.no_shares')),
        ],
      );
    }

    return ListView.builder(
      itemCount: _shares.length,
      itemBuilder: (context, index) {
        final share = _shares[index];
        return ListTile(
          leading: Icon(share.isExpired || share.isRevoked ? Icons.link_off_rounded : Icons.link_rounded),
          title: Text(share.shareableName ?? context.tr('share.unnamed')),
          subtitle: Text(share.url, maxLines: 1, overflow: TextOverflow.ellipsis),
          trailing: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              IconButton(
                icon: const Icon(Icons.ios_share_rounded),
                onPressed: () => Share.share(share.url),
              ),
              IconButton(
                icon: const Icon(Icons.delete_outline_rounded),
                onPressed: () => _revoke(share),
              ),
            ],
          ),
        );
      },
    );
  }
}
