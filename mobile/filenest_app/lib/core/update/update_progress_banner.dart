import 'package:flutter/material.dart';

import '../widgets/ui_kit.dart';
import 'app_updater.dart';

/// A small card at the bottom of every screen while an app update downloads:
/// version, a progress bar, megabytes and percent. It can be hidden (the
/// download carries on) or the download stopped.
class UpdateProgressBanner extends StatefulWidget {
  const UpdateProgressBanner({super.key});

  @override
  State<UpdateProgressBanner> createState() => _UpdateProgressBannerState();
}

class _UpdateProgressBannerState extends State<UpdateProgressBanner> {
  String? _hiddenVersion;

  static String _mb(int bytes) => (bytes / (1024 * 1024)).toStringAsFixed(1);

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<UpdateDownload?>(
      valueListenable: AppUpdater.instance.download,
      builder: (context, d, _) {
        final visible = d != null && d.version != _hiddenVersion;
        final media = MediaQuery.of(context);
        final scheme = Theme.of(context).colorScheme;
        final fraction = d?.fraction;

        return AnimatedPositioned(
          duration: const Duration(milliseconds: 280),
          curve: Curves.easeOutCubic,
          left: 12,
          right: 12,
          bottom: visible ? media.padding.bottom + 86 : -160,
          child: d == null
              ? const SizedBox.shrink()
              : Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 460),
                    child: Material(
                      elevation: 8,
                      color: scheme.surfaceContainerHighest,
                      borderRadius: BorderRadius.circular(18),
                      child: Padding(
                        padding: const EdgeInsetsDirectional.fromSTEB(14, 12, 6, 12),
                        child: Row(children: [
                          Container(
                            width: 42,
                            height: 42,
                            decoration: BoxDecoration(color: scheme.primary.withValues(alpha: 0.15), shape: BoxShape.circle),
                            child: Icon(Icons.system_update_rounded, color: scheme.primary),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                              Text(
                                context.l('جارِ تنزيل التحديث ${d.version}', 'Downloading update ${d.version}'),
                                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14),
                              ),
                              const SizedBox(height: 8),
                              Directionality(
                                textDirection: TextDirection.ltr,
                                child: ClipRRect(
                                  borderRadius: BorderRadius.circular(4),
                                  child: LinearProgressIndicator(value: fraction, minHeight: 6),
                                ),
                              ),
                              const SizedBox(height: 6),
                              Directionality(
                                textDirection: TextDirection.ltr,
                                child: Text(
                                  d.total > 0
                                      ? '${_mb(d.received)} / ${_mb(d.total)} MB  ·  ${((fraction ?? 0) * 100).round()}%'
                                      : '${_mb(d.received)} MB',
                                  style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant),
                                ),
                              ),
                            ]),
                          ),
                          // Plain buttons: this card sits above the app's Navigator, so no menus or tooltips.
                          Column(mainAxisSize: MainAxisSize.min, children: [
                            IconButton(
                              visualDensity: VisualDensity.compact,
                              icon: const Icon(Icons.keyboard_arrow_down_rounded),
                              onPressed: () => setState(() => _hiddenVersion = d.version),
                            ),
                            TextButton(
                              style: TextButton.styleFrom(visualDensity: VisualDensity.compact, foregroundColor: scheme.error),
                              onPressed: AppUpdater.instance.cancelDownload,
                              child: Text(context.l('إيقاف', 'Stop'), style: const TextStyle(fontSize: 12)),
                            ),
                          ]),
                        ]),
                      ),
                    ),
                  ),
                ),
        );
      },
    );
  }
}
