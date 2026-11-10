import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../../../../app/theme/app_colors.dart';
import '../../../../core/widgets/ui_kit.dart';
import '../../data/library_manage_api.dart';
import '../library_desk_screen.dart';
import '../library_map_screen.dart';
import 'books_manage_screen.dart';
import 'library_reports_screen.dart';
import 'library_rules_screen.dart';
import 'members_screen.dart';
import 'sections_screen.dart';

/// «إدارة المكتبة» (2026-11-10): everything the website's library staff pages do, in the app.
/// Most pages are native; the map editor and the RFID station are the website's own pages
/// inside the app, and printing (labels, cards, map stickers) opens the device's browser.
class LibraryManageScreen extends StatelessWidget {
  const LibraryManageScreen({super.key});

  void _push(BuildContext context, Widget screen) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));

  void _web(BuildContext context, String page, String title) => _push(
        context,
        LibraryMapScreen(title: title, link: () async => (await LibraryManageApi.instance.webLink(page)).path),
      );

  Future<void> _print(BuildContext context, String page) async {
    try {
      await LibraryManageApi.instance.openInBrowser(page);
    } catch (e) {
      if (context.mounted) context.toastError(e);
    }
  }

  Future<void> _import(BuildContext context, {required bool members}) async {
    final picked = await FilePicker.platform.pickFiles(type: FileType.custom, allowedExtensions: ['xlsx', 'csv']);
    final path = picked?.files.single.path;
    if (path == null || !context.mounted) return;
    context.toast(context.l('جارٍ الاستيراد…', 'Importing…'));
    try {
      final api = LibraryManageApi.instance;
      final msg = members ? await api.importMembers(path) : await api.importBooks(path);
      if (context.mounted) context.toast(msg);
    } catch (e) {
      if (context.mounted) context.toastError(e);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.l('إدارة المكتبة', 'Library management'))),
      body: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
        GroupCard(children: [
          NavRow(
            icon: Icons.qr_code_scanner_rounded,
            color: AppColors.primary,
            label: context.l('مكتب الإعارة', 'Lending desk'),
            subtitle: context.l('الإعارة والإرجاع والحجوزات والغرامات', 'Lend, return, holds and fines'),
            onTap: () => _push(context, const LibraryDeskScreen()),
          ),
        ]),
        GroupCard(title: context.l('الفهرس', 'Catalogue'), children: [
          NavRow(
            icon: Icons.menu_book_rounded,
            color: const Color(0xFF7E57C2),
            label: context.l('الكتب والنسخ', 'Books and copies'),
            onTap: () => _push(context, const BooksManageScreen()),
          ),
          NavRow(
            icon: Icons.account_tree_outlined,
            color: const Color(0xFF00897B),
            label: context.l('الأقسام', 'Sections'),
            onTap: () => _push(context, const SectionsScreen()),
          ),
          NavRow(
            icon: Icons.upload_file_rounded,
            color: const Color(0xFF43A047),
            label: context.l('استيراد كتب من Excel', 'Import books from Excel'),
            subtitle: context.l('أعمدة: العنوان، المؤلف، ISBN، القسم، النسخ…', 'Columns: title, author, ISBN, section, copies…'),
            onTap: () => _import(context, members: false),
          ),
        ]),
        GroupCard(title: context.l('القرّاء', 'Readers'), children: [
          NavRow(
            icon: Icons.badge_outlined,
            color: const Color(0xFFEF6C00),
            label: context.l('القرّاء وبطاقاتهم', 'Readers and cards'),
            onTap: () => _push(context, const MembersScreen()),
          ),
          NavRow(
            icon: Icons.group_add_outlined,
            color: const Color(0xFF43A047),
            label: context.l('استيراد قرّاء من Excel', 'Import readers from Excel'),
            subtitle: context.l('أعمدة: الاسم، رقم البطاقة، النوع، المرحلة، الهاتف، البريد', 'Columns: name, card, type, stage, phone, email'),
            onTap: () => _import(context, members: true),
          ),
        ]),
        GroupCard(title: context.l('الخريطة والرفوف', 'Map and shelves'), children: [
          NavRow(
            icon: Icons.edit_location_alt_outlined,
            color: const Color(0xFF1E88E5),
            label: context.l('تعديل خريطة المكتبة', 'Edit the library map'),
            subtitle: context.l('الرفوف والمجاميع والقاعات', 'Shelves, groups and halls'),
            onTap: () => _web(context, 'map-editor', context.l('تعديل الخريطة', 'Map editor')),
          ),
          NavRow(
            icon: Icons.qr_code_2_rounded,
            color: const Color(0xFF546E7A),
            label: context.l('طباعة ملصقات الرفوف', 'Print shelf stickers'),
            subtitle: context.l('تُفتح في المتصفح للطباعة', 'Opens in the browser to print'),
            onTap: () => _print(context, 'map-labels'),
          ),
          NavRow(
            icon: Icons.contactless_outlined,
            color: const Color(0xFFD81B60),
            label: context.l('محطة RFID', 'RFID station'),
            subtitle: context.l('ربط الرموز والجرد والترفيف', 'Tagging, inventory and shelving'),
            onTap: () => _web(context, 'rfid', context.l('محطة RFID', 'RFID station')),
          ),
        ]),
        GroupCard(title: context.l('التقارير والإعدادات', 'Reports and settings'), children: [
          NavRow(
            icon: Icons.insights_rounded,
            color: const Color(0xFF3949AB),
            label: context.l('التقارير', 'Reports'),
            onTap: () => _push(context, const LibraryReportsScreen()),
          ),
          NavRow(
            icon: Icons.rule_rounded,
            color: const Color(0xFF6D4C41),
            label: context.l('قواعد الإعارة والعطل', 'Lending rules and holidays'),
            onTap: () => _push(context, const LibraryRulesScreen()),
          ),
        ]),
      ]),
    );
  }
}
