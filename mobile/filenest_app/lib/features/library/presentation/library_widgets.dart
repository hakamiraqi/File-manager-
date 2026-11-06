import 'package:flutter/material.dart';

import '../../../app/theme/app_colors.dart';
import '../../../core/widgets/ui_kit.dart';

/// A book cover from the server, or a coloured placeholder with the title.
class BookCover extends StatelessWidget {
  const BookCover({super.key, required this.title, this.url, this.width, this.radius = 10});

  final String title;
  final String? url;
  final double? width;
  final double radius;

  static const _tones = [
    [Color(0xFF2AABEE), Color(0xFF1E88C7)],
    [Color(0xFF7BC862), Color(0xFF4CAF50)],
    [Color(0xFFA695E7), Color(0xFF7E57C2)],
    [Color(0xFFFAA774), Color(0xFFEF6C00)],
    [Color(0xFFEE7AAE), Color(0xFFD81B60)],
    [Color(0xFF6EC9CB), Color(0xFF00897B)],
  ];

  @override
  Widget build(BuildContext context) {
    final tone = _tones[title.hashCode.abs() % _tones.length];
    final placeholder = Container(
      decoration: BoxDecoration(gradient: LinearGradient(colors: tone, begin: Alignment.topLeft, end: Alignment.bottomRight)),
      padding: const EdgeInsets.all(10),
      alignment: Alignment.center,
      child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
        const Icon(Icons.menu_book_rounded, color: Colors.white70, size: 28),
        const SizedBox(height: 8),
        Text(title, maxLines: 4, overflow: TextOverflow.ellipsis, textAlign: TextAlign.center,
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 12, height: 1.3)),
      ]),
    );
    return SizedBox(
      width: width,
      child: AspectRatio(
        aspectRatio: 2 / 3,
        child: ClipRRect(
          borderRadius: BorderRadius.circular(radius),
          child: url == null || url!.isEmpty
              ? placeholder
              : Image.network(url!, fit: BoxFit.cover, errorBuilder: (_, __, ___) => placeholder),
        ),
      ),
    );
  }
}

/// «متوفر» / «كل النسخ مُعارة» / «غير موجود حالياً» for a book card.
class Availability {
  const Availability(this.text, this.color, this.icon);
  final String text;
  final Color color;
  final IconData icon;

  static Availability of(BuildContext context, Map<String, dynamic> book) {
    final copies = (book['copies_count'] as num?)?.toInt() ?? 0;
    final available = (book['available_count'] as num?)?.toInt() ?? 0;
    if (book['reference_only'] == true) {
      return Availability(context.l('للقراءة داخل المكتبة', 'In-library reading'), Colors.blueGrey, Icons.chair_alt_rounded);
    }
    if (copies == 0) return Availability(context.l('غير موجود حالياً', 'Not in the library'), Colors.grey, Icons.block_rounded);
    if (available > 0) {
      return Availability(context.l('متوفر $available من $copies', '$available of $copies available'), AppColors.success, Icons.check_circle_rounded);
    }
    return Availability(context.l('كل النسخ مُعارة', 'All copies on loan'), AppColors.warning, Icons.schedule_rounded);
  }
}

String copyStatusLabel(BuildContext context, String status) {
  switch (status) {
    case 'available':
      return context.l('متاحة على الرف', 'On the shelf');
    case 'loaned':
      return context.l('مُعارة', 'On loan');
    case 'on_hold':
      return context.l('محجوزة لقارئ', 'Held for a reader');
    case 'damaged':
      return context.l('تالفة', 'Damaged');
    case 'lost':
      return context.l('مفقودة', 'Lost');
  }
  return status;
}

Color copyStatusColor(String status) {
  switch (status) {
    case 'available':
      return AppColors.success;
    case 'loaned':
      return AppColors.warning;
    case 'on_hold':
      return AppColors.primary;
    default:
      return AppColors.danger;
  }
}

class BookCard extends StatelessWidget {
  const BookCard({super.key, required this.book, required this.onTap});

  final Map<String, dynamic> book;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final a = Availability.of(context, book);
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: onTap,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        BookCover(title: book['title'] ?? '', url: book['cover_url'] as String?),
        const SizedBox(height: 6),
        Text(book['title'] ?? '', maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, height: 1.25)),
        if ((book['authors'] ?? '').toString().isNotEmpty)
          Text(book['authors'], maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 11.5, color: Theme.of(context).colorScheme.onSurfaceVariant)),
        const SizedBox(height: 4),
        Pill(text: a.text, color: a.color, icon: a.icon),
      ]),
    );
  }
}
