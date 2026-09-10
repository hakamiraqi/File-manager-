import 'package:flutter/material.dart';

/// The one place that maps a file extension to a Material icon — reused by
/// the file browser, the offline downloads list, and anywhere else a file
/// needs an icon, so every screen agrees on what a ".pdf" looks like.
IconData fileIconForExtension(String? extension) {
  switch ((extension ?? '').toLowerCase()) {
    case 'pdf':
      return Icons.picture_as_pdf_rounded;
    case 'doc':
    case 'docx':
      return Icons.description_rounded;
    case 'xls':
    case 'xlsx':
    case 'csv':
      return Icons.table_chart_rounded;
    case 'ppt':
    case 'pptx':
      return Icons.slideshow_rounded;
    case 'jpg':
    case 'jpeg':
    case 'png':
    case 'gif':
    case 'webp':
      return Icons.image_rounded;
    case 'mp4':
    case 'mov':
    case 'avi':
    case 'mkv':
      return Icons.movie_rounded;
    case 'mp3':
    case 'wav':
      return Icons.audiotrack_rounded;
    case 'zip':
    case 'rar':
      return Icons.folder_zip_rounded;
    default:
      return Icons.insert_drive_file_rounded;
  }
}
