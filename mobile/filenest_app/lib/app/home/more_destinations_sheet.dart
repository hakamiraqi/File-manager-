import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import 'nav_destinations.dart';

/// The same [buildSecondaryDestinations] list as [AppDrawer], rendered as
/// a rounded action sheet instead — used on any screen that isn't the
/// root (a pushed subfolder, for instance) where there's no drawer to
/// open, but the user should still be able to reach settings/trash/logout
/// without navigating all the way back first.
class MoreDestinationsSheet extends StatelessWidget {
  const MoreDestinationsSheet({super.key});

  static void show(BuildContext context) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => const MoreDestinationsSheet(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final destinations = buildSecondaryDestinations(context);

    return SafeArea(
      child: Wrap(
        children: destinations
            .map((item) => ListTile(
                  leading: Icon(item.icon, color: item.isDestructive ? AppColors.danger : null),
                  title: Text(
                    item.label,
                    style: TextStyle(color: item.isDestructive ? AppColors.danger : null),
                  ),
                  onTap: () {
                    Navigator.of(context).pop();
                    item.onTap();
                  },
                ))
            .toList(),
      ),
    );
  }
}
