import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/foundation.dart';
import 'package:image/image.dart' as img;
import 'package:path_provider/path_provider.dart';
import 'package:pdfrx/pdfrx.dart';

/// One page of the result: [source] indexes the list of source PDFs, [page] is 1-based and
/// [quarterTurns] turns it clockwise by 90° steps.
class PageRef {
  const PageRef(this.source, this.page, [this.quarterTurns = 0]);

  final int source;
  final int page;
  final int quarterTurns;

  PageRef turned() => PageRef(source, page, (quarterTurns + 1) % 4);
}

/// «أدوات PDF على الجهاز» (2026-11-12, by request: they must work without internet): merge,
/// organise, split / extract, rotate and images to PDF, done on the phone (or the Windows
/// computer) itself with PDFium through pdfrx. Nothing leaves the device; results are kept in
/// the app's own «pdf_tools» folder until deleted.
class LocalPdfTools {
  LocalPdfTools._();

  /// Tests write here instead of the app's documents folder.
  @visibleForTesting
  static Directory? debugOutputDir;

  static Future<Directory> outputDir() async {
    if (debugOutputDir != null) return debugOutputDir!;
    final docs = await getApplicationDocumentsDirectory();
    final dir = Directory('${docs.path}/pdf_tools');
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }

  static Future<List<File>> results() async {
    final dir = await outputDir();
    final files = dir.listSync().whereType<File>().where((f) => f.path.toLowerCase().endsWith('.pdf')).toList();
    files.sort((a, b) => b.lastModifiedSync().compareTo(a.lastModifiedSync()));
    return files;
  }

  static Future<int> pageCount(String path) async {
    final doc = await PdfDocument.openFile(path);
    try {
      return doc.pages.length;
    } finally {
      doc.dispose();
    }
  }

  /// Every page of every file, one after the other.
  static Future<File> merge(List<String> paths, String name) async {
    final refs = <PageRef>[];
    for (var i = 0; i < paths.length; i++) {
      final n = await pageCount(paths[i]);
      for (var p = 1; p <= n; p++) {
        refs.add(PageRef(i, p));
      }
    }
    return assemble(paths, refs, name);
  }

  /// The chosen pages of the sources, in this order and turned as asked, as one new PDF.
  static Future<File> assemble(List<String> sources, List<PageRef> pages, String name) async {
    if (pages.isEmpty) throw const LocalPdfException('لا توجد صفحات في النتيجة.');
    final docs = <PdfDocument>[];
    PdfDocument? out;
    try {
      for (final s in sources) {
        docs.add(await PdfDocument.openFile(s));
      }
      out = await PdfDocument.createNew(sourceName: name);
      out.pages = [
        for (final r in pages)
          if (r.quarterTurns % 4 == 0)
            docs[r.source].pages[r.page - 1]
          else
            docs[r.source].pages[r.page - 1].rotatedBy(PdfPageRotation.values[r.quarterTurns % 4]),
      ];
      final bytes = await out.encodePdf();
      return _save(bytes, name);
    } on LocalPdfException {
      rethrow;
    } catch (e) {
      throw LocalPdfException(_explain(e));
    } finally {
      out?.dispose();
      for (final d in docs) {
        d.dispose();
      }
    }
  }

  /// Pictures (phone photos, scans) as pages, each fitted to an A4-wide page, in order.
  static Future<File> imagesToPdf(List<String> imagePaths, String name) async {
    final docs = <PdfDocument>[];
    PdfDocument? out;
    try {
      for (var i = 0; i < imagePaths.length; i++) {
        final bytes = await File(imagePaths[i]).readAsBytes();
        final jpeg = await compute(_toJpeg, bytes);
        if (jpeg == null) throw LocalPdfException('تعذّر قراءة الصورة ${i + 1}.');
        const width = 595.0;
        final height = width * jpeg.height / jpeg.width;
        docs.add(await PdfDocument.createFromJpegData(jpeg.data, width: width, height: height, sourceName: 'image$i.pdf'));
      }
      out = await PdfDocument.createNew(sourceName: name);
      out.pages = [for (final d in docs) ...d.pages];
      return _save(await out.encodePdf(), name);
    } on LocalPdfException {
      rethrow;
    } catch (e) {
      throw LocalPdfException(_explain(e));
    } finally {
      out?.dispose();
      for (final d in docs) {
        d.dispose();
      }
    }
  }

  /// "1-3, 5, 8-z" => [[1,2,3],[5],[8..last]] (z = the last page).
  static List<List<int>> parseRanges(String text, int count) {
    final out = <List<int>>[];
    for (final raw in text.split(RegExp(r'[,،]'))) {
      final part = raw.replaceAll(' ', '').toLowerCase();
      if (part.isEmpty) continue;
      final m = RegExp(r'^(\d+|z)(?:-(\d+|z))?$').firstMatch(part);
      if (m == null) throw LocalPdfException('المدى «$part» غير مفهوم. مثال: 1-3, 5, 8-z');
      int v(String s) => s == 'z' ? count : int.parse(s);
      final a = v(m.group(1)!);
      final b = m.group(2) == null ? a : v(m.group(2)!);
      if (a < 1 || b < 1 || a > count || b > count) throw LocalPdfException('المدى «$part» خارج الملف (عدد صفحاته $count).');
      out.add([for (var p = a; a <= b ? p <= b : p >= b; a <= b ? p++ : p--) p]);
    }
    if (out.isEmpty) throw const LocalPdfException('اكتب الصفحات، مثل: 1-3, 5, 8-z');
    return out;
  }

  static Future<File> _save(Uint8List bytes, String name) async {
    final dir = await outputDir();
    final safe = name.replaceAll(RegExp(r'[\\/:*?"<>|]'), '_');
    final base = safe.toLowerCase().endsWith('.pdf') ? safe.substring(0, safe.length - 4) : safe;
    var file = File('${dir.path}/$base.pdf');
    for (var i = 2; await file.exists(); i++) {
      file = File('${dir.path}/$base ($i).pdf');
    }
    await file.writeAsBytes(bytes, flush: true);
    return file;
  }

  static String _explain(Object e) {
    final s = e.toString().toLowerCase();
    if (s.contains('password')) return 'الملف محمي بكلمة مرور؛ لا يمكن تعديله على الجهاز.';
    return 'تعذّر تنفيذ العملية على الملف.';
  }
}

class LocalPdfException implements Exception {
  const LocalPdfException(this.message);
  final String message;
  @override
  String toString() => message;
}

class _Jpeg {
  const _Jpeg(this.data, this.width, this.height);
  final Uint8List data;
  final int width;
  final int height;
}

/// Any picture (also HEIC-less PNG/WebP/GIF/BMP) upright and re-encoded as JPEG, on a worker isolate.
_Jpeg? _toJpeg(Uint8List bytes) {
  final decoded = img.decodeImage(bytes);
  if (decoded == null) return null;
  var image = img.bakeOrientation(decoded);
  // Very large camera photos are scaled down to ~300 dpi on A4 (keeps the PDF light).
  if (image.width > 2480) image = img.copyResize(image, width: 2480);
  // Transparent parts (PNG) on white, not black.
  if (image.hasAlpha) {
    final bg = img.Image(width: image.width, height: image.height);
    img.fill(bg, color: img.ColorRgb8(255, 255, 255));
    img.compositeImage(bg, image);
    image = bg;
  }
  return _Jpeg(Uint8List.fromList(img.encodeJpg(image, quality: 88)), image.width, image.height);
}
