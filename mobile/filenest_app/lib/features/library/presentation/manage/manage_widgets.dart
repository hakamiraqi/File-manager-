import 'dart:io';

import 'package:flutter/material.dart';

import '../../../../core/network/api_client.dart';
import '../../../../core/widgets/ui_kit.dart';

/// Small pieces shared by the library management screens.

abstract class ApiMessage {
  static String of(Object e) => ApiClient.messageFor(e);
}

Future<bool> confirmAction(BuildContext context, String question) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (_) => AlertDialog(
      content: Text(question),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: Text(context.l('إلغاء', 'Cancel'))),
        FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(context.l('نعم', 'Yes'))),
      ],
    ),
  );
  return ok == true;
}

/// A section chooser showing each section's full path ("التشريح / الرأس والرقبة").
class SectionPicker extends StatelessWidget {
  const SectionPicker({super.key, required this.sections, required this.value, required this.onChanged, required this.allLabel, this.label, this.exclude = const {}});

  final List<Map<String, dynamic>> sections;
  final int? value;
  final ValueChanged<int?> onChanged;
  final String allLabel;
  final String? label;
  final Set<int> exclude;

  @override
  Widget build(BuildContext context) {
    final ids = sections.map((s) => (s['id'] as num).toInt()).toSet();
    return DropdownButtonFormField<int?>(
      value: value != null && ids.contains(value) ? value : null,
      isExpanded: true,
      decoration: InputDecoration(labelText: label, isDense: true),
      items: [
        DropdownMenuItem<int?>(value: null, child: Text(allLabel)),
        for (final s in sections)
          if (!exclude.contains((s['id'] as num).toInt()))
            DropdownMenuItem<int?>(
              value: (s['id'] as num).toInt(),
              child: Text('${s['path'] ?? s['name']}', overflow: TextOverflow.ellipsis),
            ),
      ],
      onChanged: onChanged,
    );
  }
}

/// «الرف» with the bookcase codes from the library map as suggestions.
class ShelfField extends StatelessWidget {
  const ShelfField({super.key, required this.controller, required this.codes});

  final TextEditingController controller;
  final List<String> codes;

  @override
  Widget build(BuildContext context) {
    return Autocomplete<String>(
      initialValue: TextEditingValue(text: controller.text),
      optionsBuilder: (v) => codes.where((c) => c.toLowerCase().contains(v.text.toLowerCase())).take(20),
      onSelected: (v) => controller.text = v,
      fieldViewBuilder: (context, field, focus, onSubmit) {
        field.addListener(() => controller.text = field.text);
        return TextField(
          controller: field,
          focusNode: focus,
          decoration: InputDecoration(labelText: context.l('الرف', 'Shelf'), hintText: codes.isEmpty ? null : codes.take(3).join('، ')),
        );
      },
    );
  }
}

/// A picture picked on the device (before it is uploaded).
class LocalImage extends StatelessWidget {
  const LocalImage({super.key, required this.path});
  final String path;

  @override
  Widget build(BuildContext context) => Image.file(File(path), fit: BoxFit.cover);
}

/// A number tile for summaries (reports, OCR).
class StatTile extends StatelessWidget {
  const StatTile({super.key, required this.label, required this.value, required this.color, required this.icon});

  final String label;
  final Object? value;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(color: Theme.of(context).colorScheme.surface, borderRadius: BorderRadius.circular(14)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        Icon(icon, color: color, size: 20),
        FittedBox(fit: BoxFit.scaleDown, child: Text('${value ?? 0}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
        Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 11.5, color: Theme.of(context).colorScheme.onSurfaceVariant)),
      ]),
    );
  }
}

class StatGrid extends StatelessWidget {
  const StatGrid({super.key, required this.tiles});
  final List<StatTile> tiles;

  @override
  Widget build(BuildContext context) {
    final w = MediaQuery.of(context).size.width;
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
      child: GridView.count(
        crossAxisCount: w > 700 ? 5 : 3,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 8,
        crossAxisSpacing: 8,
        childAspectRatio: 1.35,
        children: tiles,
      ),
    );
  }
}
