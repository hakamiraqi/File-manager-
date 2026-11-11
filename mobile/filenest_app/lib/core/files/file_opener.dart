import 'package:flutter/material.dart';
import 'package:open_filex/open_filex.dart';

import '../widgets/ui_kit.dart';
import 'pdf_reader_screen.dart';

/// Opens a file already on the device (2026-11-11): a PDF in the app's own reader on phones,
/// anything else in the app the system picks, and a clear message when there is none.
Future<void> openLocalFile(BuildContext context, String path, String name) async {
  final ext = name.contains('.') ? name.split('.').last.toLowerCase() : '';
  if (ext == 'pdf' && PdfReaderScreen.supported) {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => PdfReaderScreen(path: path, title: name)));
    return;
  }
  final r = await OpenFilex.open(path);
  if (r.type != ResultType.done && context.mounted) {
    context.toast(
      r.type == ResultType.noAppToOpen
          ? context.l('لا يوجد برنامج لفتح هذا النوع من الملفات على الجهاز.', 'No app on this device opens this kind of file.')
          : context.l('تعذّر فتح الملف: ${r.message}', 'Could not open the file: ${r.message}'),
      error: true,
    );
  }
}
