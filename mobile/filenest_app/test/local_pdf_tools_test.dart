import 'dart:io';

import 'package:filenest_app/features/pdf_tools/data/local_pdf_tools.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;
import 'package:pdfrx/pdfrx.dart';

/// The on-device PDF tools, run with the real PDFium on the build machine.
void main() {
  late Directory dir;

  setUpAll(() async {
    TestWidgetsFlutterBinding.ensureInitialized();
    await pdfrxInitialize();
    dir = await Directory.systemTemp.createTemp('pdf_tools');
    LocalPdfTools.debugOutputDir = dir;
  });

  tearDownAll(() => dir.delete(recursive: true));

  Future<String> picture(String name, int w, int h, {bool alpha = false}) async {
    final im = img.Image(width: w, height: h, numChannels: alpha ? 4 : 3);
    img.fill(im, color: alpha ? img.ColorRgba8(0, 128, 255, 120) : img.ColorRgb8(200, 30, 30));
    final f = File('${dir.path}/$name');
    await f.writeAsBytes(name.endsWith('.png') ? img.encodePng(im) : img.encodeJpg(im));
    return f.path;
  }

  test('images to PDF, merge, organise with rotation, split ranges', () async {
    final a = await LocalPdfTools.imagesToPdf([await picture('a.jpg', 600, 800), await picture('b.png', 800, 600, alpha: true)], 'images');
    expect(await LocalPdfTools.pageCount(a.path), 2);

    final b = await LocalPdfTools.imagesToPdf([await picture('c.jpg', 400, 400)], 'one');
    final merged = await LocalPdfTools.merge([a.path, b.path], 'merged');
    expect(await LocalPdfTools.pageCount(merged.path), 3);

    final organised = await LocalPdfTools.assemble([merged.path, b.path], [const PageRef(0, 3, 1), const PageRef(1, 1), const PageRef(0, 1, 2)], 'organised');
    final doc = await PdfDocument.openFile(organised.path);
    expect(doc.pages.length, 3);
    expect(doc.pages[0].rotation, PdfPageRotation.clockwise90);
    expect(doc.pages[2].rotation, PdfPageRotation.clockwise180);
    doc.dispose();

    expect(LocalPdfTools.parseRanges('1-2, 3', 3), [
      [1, 2],
      [3],
    ]);
    expect(LocalPdfTools.parseRanges('z-2', 3), [
      [3, 2],
    ]);
    expect(() => LocalPdfTools.parseRanges('4', 3), throwsA(isA<LocalPdfException>()));

    // Blank pages: a white picture between two coloured ones is found and removed.
    final white = img.Image(width: 300, height: 400);
    img.fill(white, color: img.ColorRgb8(255, 255, 255));
    final whitePath = '${dir.path}/white.jpg';
    await File(whitePath).writeAsBytes(img.encodeJpg(white));
    final withBlank = await LocalPdfTools.imagesToPdf([await picture('d.jpg', 300, 400), whitePath, await picture('e.jpg', 300, 400)], 'with-blank');
    expect(await LocalPdfTools.blankPages(withBlank.path), [2]);
    final cleaned = await LocalPdfTools.removeBlank(withBlank.path, 'cleaned');
    expect(cleaned.removed, [2]);
    expect(await LocalPdfTools.pageCount(cleaned.file.path), 2);

    // Saving never overwrites: a second «merged» is «merged (2)».
    final again = await LocalPdfTools.merge([a.path, b.path], 'merged');
    expect(again.path.endsWith('merged (2).pdf'), isTrue);
  });
}
