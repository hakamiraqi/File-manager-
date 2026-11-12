import 'package:flutter/material.dart';
import 'package:pdfrx/pdfrx.dart';

import 'app/app.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  // PDFium for the on-device PDF tools (merge, organise, split, rotate, images to PDF).
  pdfrxFlutterInitialize();
  runApp(const FileNestApp());
}
