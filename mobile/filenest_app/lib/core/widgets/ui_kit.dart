import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../app/theme/app_colors.dart';
import '../i18n/locale_provider.dart';
import '../network/api_client.dart';

/// Shared building blocks for the Telegram-style screens: grouped setting
/// cards, coloured icon tiles, status pills, avatars and a paged loader.

extension BilingualX on BuildContext {
  /// `context.l('عربي', 'English')`: inline wording for the newer screens.
  String l(String ar, String en) {
    final code = Provider.of<LocaleProvider>(this).locale.languageCode;
    return code == 'ar' ? ar : en;
  }

  bool get isDark => Theme.of(this).brightness == Brightness.dark;

  void toast(String message, {bool error = false}) {
    ScaffoldMessenger.of(this)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(
        content: Text(message),
        backgroundColor: error ? AppColors.danger : null,
      ));
  }

  void toastError(Object e) => toast(ApiClient.messageFor(e), error: true);
}

/// A rounded group of rows, like Telegram's settings sections.
class GroupCard extends StatelessWidget {
  const GroupCard({super.key, required this.children, this.title, this.footer, this.padding = const EdgeInsets.fromLTRB(16, 8, 16, 8)});

  final List<Widget> children;
  final String? title;
  final String? footer;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final rows = <Widget>[];
    for (var i = 0; i < children.length; i++) {
      rows.add(children[i]);
      if (i < children.length - 1) {
        rows.add(Divider(height: 1, indent: 60, color: Theme.of(context).dividerColor.withOpacity(0.6)));
      }
    }
    return Padding(
      padding: padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (title != null)
            Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(14, 4, 14, 8),
              child: Text(title!, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: scheme.primary)),
            ),
          Material(
            color: scheme.surface,
            borderRadius: BorderRadius.circular(16),
            clipBehavior: Clip.antiAlias,
            child: Column(children: rows),
          ),
          if (footer != null)
            Padding(
              padding: const EdgeInsetsDirectional.fromSTEB(14, 6, 14, 0),
              child: Text(footer!, style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
            ),
        ],
      ),
    );
  }
}

/// A square, coloured icon like the ones beside Telegram's settings rows.
class IconTile extends StatelessWidget {
  const IconTile({super.key, required this.icon, required this.color, this.size = 32});

  final IconData icon;
  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(size * 0.28)),
      child: Icon(icon, color: Colors.white, size: size * 0.6),
    );
  }
}

class NavRow extends StatelessWidget {
  const NavRow({
    super.key,
    required this.icon,
    required this.color,
    required this.label,
    this.subtitle,
    this.onTap,
    this.badge = 0,
    this.trailing,
    this.destructive = false,
  });

  final IconData icon;
  final Color color;
  final String label;
  final String? subtitle;
  final VoidCallback? onTap;
  final int badge;
  final Widget? trailing;
  final bool destructive;

  @override
  Widget build(BuildContext context) {
    return ListTile(
      contentPadding: const EdgeInsetsDirectional.only(start: 14, end: 10),
      leading: IconTile(icon: icon, color: color),
      title: Text(label, style: TextStyle(fontWeight: FontWeight.w500, color: destructive ? AppColors.danger : null)),
      subtitle: subtitle == null ? null : Text(subtitle!, maxLines: 1, overflow: TextOverflow.ellipsis),
      shape: const RoundedRectangleBorder(),
      trailing: trailing ??
          Row(mainAxisSize: MainAxisSize.min, children: [
            if (badge > 0) CountBadge(count: badge),
            if (onTap != null && !destructive)
              Icon(Directionality.of(context) == TextDirection.rtl ? Icons.chevron_left_rounded : Icons.chevron_right_rounded,
                  color: Theme.of(context).colorScheme.onSurfaceVariant.withOpacity(0.6)),
          ]),
      onTap: onTap,
    );
  }
}

class CountBadge extends StatelessWidget {
  const CountBadge({super.key, required this.count, this.color = AppColors.primary});

  final int count;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: const BoxConstraints(minWidth: 22),
      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
      decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(12)),
      child: Text(count > 99 ? '99+' : '$count', textAlign: TextAlign.center,
          style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700)),
    );
  }
}

class Pill extends StatelessWidget {
  const Pill({super.key, required this.text, required this.color, this.icon});

  final String text;
  final Color color;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: color.withOpacity(0.14), borderRadius: BorderRadius.circular(20)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        if (icon != null) ...[Icon(icon, size: 13, color: color), const SizedBox(width: 4)],
        Flexible(child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis,
            style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600, color: color))),
      ]),
    );
  }
}

/// A round picture with the person's initial when there is no photo.
class Avatar extends StatelessWidget {
  const Avatar({super.key, required this.name, this.url, this.radius = 22});

  final String name;
  final String? url;
  final double radius;

  static const _palette = [Color(0xFFE17076), Color(0xFF7BC862), Color(0xFF65AADD), Color(0xFFA695E7), Color(0xFFEE7AAE), Color(0xFF6EC9CB), Color(0xFFFAA774)];

  @override
  Widget build(BuildContext context) {
    final initial = name.trim().isEmpty ? '؟' : name.trim().characters.first.toUpperCase();
    final bg = _palette[name.hashCode.abs() % _palette.length];
    return CircleAvatar(
      radius: radius,
      backgroundColor: bg,
      foregroundImage: url != null && url!.isNotEmpty ? NetworkImage(url!) : null,
      child: Text(initial, style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: radius * 0.8)),
    );
  }
}

/// Rounded search field used at the top of list screens.
class SearchField extends StatelessWidget {
  const SearchField({super.key, required this.controller, required this.hint, required this.onSubmitted, this.onClear});

  final TextEditingController controller;
  final String hint;
  final ValueChanged<String> onSubmitted;
  final VoidCallback? onClear;

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: controller,
      textInputAction: TextInputAction.search,
      onSubmitted: onSubmitted,
      decoration: InputDecoration(
        hintText: hint,
        prefixIcon: const Icon(Icons.search_rounded),
        isDense: true,
        contentPadding: const EdgeInsets.symmetric(vertical: 10),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(24), borderSide: BorderSide.none),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(24), borderSide: BorderSide.none),
        fillColor: context.isDark ? AppColors.darkSurfaceVariant : AppColors.lightSurfaceVariant,
        suffixIcon: ValueListenableBuilder<TextEditingValue>(
          valueListenable: controller,
          builder: (_, v, __) => v.text.isEmpty
              ? const SizedBox.shrink()
              : IconButton(icon: const Icon(Icons.close_rounded), onPressed: () {
                  controller.clear();
                  (onClear ?? () => onSubmitted(''))();
                }),
        ),
      ),
    );
  }
}

/// Segmented filter chips in a horizontal strip.
class FilterStrip extends StatelessWidget {
  const FilterStrip({super.key, required this.items, required this.selected, required this.onSelected});

  /// key → label (label may contain a count).
  final Map<String, String> items;
  final String selected;
  final ValueChanged<String> onSelected;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      height: 40,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        children: items.entries.map((e) {
          final on = e.key == selected;
          return Padding(
            padding: const EdgeInsets.symmetric(horizontal: 3),
            child: ChoiceChip(
              label: Text(e.value),
              selected: on,
              showCheckmark: false,
              selectedColor: AppColors.primary,
              labelStyle: TextStyle(color: on ? Colors.white : null, fontWeight: on ? FontWeight.w600 : FontWeight.w400),
              onSelected: (_) => onSelected(e.key),
            ),
          );
        }).toList(),
      ),
    );
  }
}

/// "3 minutes ago" style dates.
String timeAgo(BuildContext context, String? iso) {
  if (iso == null) return '';
  final t = DateTime.tryParse(iso)?.toLocal();
  if (t == null) return '';
  final d = DateTime.now().difference(t);
  final ar = Provider.of<LocaleProvider>(context, listen: false).locale.languageCode == 'ar';
  if (d.inMinutes < 1) return ar ? 'الآن' : 'now';
  if (d.inMinutes < 60) return ar ? 'قبل ${d.inMinutes} د' : '${d.inMinutes}m ago';
  if (d.inHours < 24) return ar ? 'قبل ${d.inHours} س' : '${d.inHours}h ago';
  if (d.inDays < 7) return ar ? 'قبل ${d.inDays} يوم' : '${d.inDays}d ago';
  return shortDate(iso);
}

String shortDate(String? iso) {
  final t = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
  if (t == null) return '';
  String two(int n) => n.toString().padLeft(2, '0');
  return '${t.year}/${two(t.month)}/${two(t.day)}';
}

/// Page-by-page loading for the list screens (pull to refresh, load more at the end).
class Paged<T> {
  final List<T> items = [];
  int page = 0;
  int lastPage = 1;
  bool loading = false;
  String? error;

  bool get hasMore => page < lastPage;

  void reset() {
    items.clear();
    page = 0;
    lastPage = 1;
    error = null;
  }
}

class LoadingOrError extends StatelessWidget {
  const LoadingOrError({super.key, required this.error, required this.onRetry});

  final String? error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    if (error == null) return const Center(child: CircularProgressIndicator());
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.cloud_off_rounded, size: 48, color: AppColors.warning),
          const SizedBox(height: 12),
          Text(error!, textAlign: TextAlign.center),
          const SizedBox(height: 12),
          FilledButton.tonal(onPressed: onRetry, child: Text(context.l('إعادة المحاولة', 'Retry'))),
        ]),
      ),
    );
  }
}
