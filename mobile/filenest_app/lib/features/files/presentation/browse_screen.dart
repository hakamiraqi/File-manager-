import 'dart:io';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../../app/home/more_destinations_sheet.dart';
import '../../../core/models/file_item.dart';
import '../../../core/models/folder_item.dart';
import '../../../core/network/api_client.dart';
import '../../../core/widgets/empty_state.dart';
import '../../shares/data/share_repository.dart';
import '../../media_player/presentation/media_player_screen.dart';
import '../data/file_repository.dart';
import 'widgets/folder_picker_dialog.dart';
import 'widgets/item_tiles.dart';
import 'widgets/upload_fab.dart';

/// The file browser: search, breadcrumbs, folder/file CRUD, upload, and a
/// quick ephemeral preview-download on tap.
///
/// [embedded] controls the chrome, same convention as SharesScreen:
/// - `false` (default): a full standalone screen with its own AppBar, back
///   button and folder name — used when a subfolder is pushed as a new
///   route (`Navigator.push`).
/// - `true`: chromeless — just the body — used once, for the root folder,
///   as HomeShell's "ملفاتي" tab. HomeShell supplies the AppBar there.
///
/// Root is always embedded and every pushed instance is therefore always a
/// real subfolder, which is why the AppBar-only concerns below (back
/// button, folder name, the "more" overflow) only ever appear in the
/// non-embedded branch.
class BrowseScreen extends StatefulWidget {
  const BrowseScreen({super.key, this.folder, this.embedded = false});

  final FolderItem? folder;
  final bool embedded;

  @override
  State<BrowseScreen> createState() => _BrowseScreenState();
}

class _BrowseScreenState extends State<BrowseScreen> {
  final _fileRepository = FileRepository();
  final _shareRepository = ShareRepository();
  final _searchController = TextEditingController();

  List<FolderItem> _folders = [];
  List<FileItem> _files = [];
  List<FolderItem> _breadcrumbs = [];
  bool _isLoading = true;
  String? _error;
  double? _uploadProgress;

  final Set<int> _selectedFileIds = {};
  final Set<int> _selectedFolderIds = {};
  bool get _isSelecting => _selectedFileIds.isNotEmpty || _selectedFolderIds.isNotEmpty;

  // ---- Folder info: type + researcher/supervisor/thesis/publisher/etc. ----
  bool _showFolderInfo = false;
  bool _savingFolderInfo = false;
  late String _folderType = widget.folder?.folderType ?? 'files';
  late final _researcherNameController = TextEditingController(text: widget.folder?.researcherName);
  late final _supervisorNameController = TextEditingController(text: widget.folder?.supervisorName);
  late final _thesisTitleController = TextEditingController(text: widget.folder?.thesisTitle);
  late final _publisherController = TextEditingController(text: widget.folder?.publisher);
  late final _digitalLibraryController = TextEditingController(text: widget.folder?.digitalLibrary);
  late final _publicationYearController = TextEditingController(text: widget.folder?.publicationYear);

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    _researcherNameController.dispose();
    _supervisorNameController.dispose();
    _thesisTitleController.dispose();
    _publisherController.dispose();
    _digitalLibraryController.dispose();
    _publicationYearController.dispose();
    super.dispose();
  }

  Future<void> _saveFolderInfo() async {
    if (widget.folder == null) return;
    setState(() => _savingFolderInfo = true);
    try {
      await _fileRepository.updateFolderInfo(widget.folder!.id, {
        'folder_type': _folderType,
        'researcher_name': _researcherNameController.text,
        'supervisor_name': _supervisorNameController.text,
        'thesis_title': _thesisTitleController.text,
        'publisher': _publisherController.text,
        'digital_library': _digitalLibraryController.text,
        'publication_year': _publicationYearController.text,
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم حفظ معلومات المجلد.')));
      }
    } catch (e) {
      _showError(e);
    } finally {
      if (mounted) setState(() => _savingFolderInfo = false);
    }
  }

  Future<void> _load({String query = ''}) async {
    setState(() {
      _isLoading = true;
      _error = null;
    });

    try {
      final result = await _fileRepository.browse(folderId: widget.folder?.id, query: query);
      setState(() {
        _folders = result.folders;
        _files = result.files;
        _breadcrumbs = result.breadcrumbs;
      });
    } catch (e) {
      setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _openFolder(FolderItem folder) {
    Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => BrowseScreen(folder: folder)),
    );
  }

  Future<void> _createFolder() async {
    final controller = TextEditingController();
    final name = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('مجلد جديد'),
        content: TextField(controller: controller, decoration: const InputDecoration(labelText: 'اسم المجلد')),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.of(context).pop(controller.text), child: const Text('إنشاء')),
        ],
      ),
    );

    if (name == null || name.trim().isEmpty) return;

    try {
      await _fileRepository.createFolder(name: name.trim(), parentId: widget.folder?.id);
      _load();
    } catch (e) {
      _showError(e);
    }
  }

  Future<void> _uploadFile() async {
    final result = await FilePicker.platform.pickFiles();
    if (result == null || result.files.single.path == null) return;

    final picked = result.files.single;
    setState(() => _uploadProgress = 0);

    try {
      await _fileRepository.uploadFile(
        file: File(picked.path!),
        fileName: picked.name,
        folderId: widget.folder?.id,
        onProgress: (progress) => setState(() => _uploadProgress = progress),
      );
      _load();
    } catch (e) {
      _showError(e);
    } finally {
      if (mounted) setState(() => _uploadProgress = null);
    }
  }

  /// Picks a whole directory and uploads every file inside it, recreating
  /// its subfolder structure via repeated createFolder() calls (caching
  /// each relative path's folder id as it goes, so a directory shared by
  /// several files is only created once) before uploading each file into
  /// its resolved folder — the mobile counterpart of the web dashboard's
  /// folder-upload button.
  ///
  /// Known limitation: on Android, `getDirectoryPath()` can return a SAF
  /// `content://` tree URI instead of a real filesystem path, which
  /// `Directory(...).list()` can't read — that surfaces here as the
  /// generic error message below rather than a silent failure. Folder
  /// upload is reliably supported on iOS and desktop; on Android it
  /// depends on the device/OS version, so a user hitting this should fall
  /// back to picking files individually with "رفع ملف".
  Future<void> _uploadFolder() async {
    final directoryPath = await FilePicker.platform.getDirectoryPath();
    if (directoryPath == null) return;

    final normalizedRoot = directoryPath.endsWith(Platform.pathSeparator)
        ? directoryPath.substring(0, directoryPath.length - 1)
        : directoryPath;
    final rootName = normalizedRoot
        .split(Platform.pathSeparator)
        .where((segment) => segment.isNotEmpty)
        .last;

    List<File> files;
    try {
      final entities = await Directory(normalizedRoot).list(recursive: true, followLinks: false).toList();
      files = entities.whereType<File>().toList();
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('تعذّر قراءة محتويات هذا المجلد. على بعض أجهزة أندرويد لا يمكن رفع مجلد كامل مباشرة — جرّب اختيار الملفات فرادى باستخدام "رفع ملف".'),
      ));
      return;
    }

    if (files.isEmpty) return;

    setState(() => _uploadProgress = 0);

    // Relative directory path under the picked folder, with the picked
    // folder's own name as the first segment (so it is recreated as a
    // subfolder here, matching the web dashboard's behaviour) -> folder
    // id; "" is the destination folder itself.
    final folderIds = <String, int?>{'': widget.folder?.id};

    Future<int?> resolveFolder(String path) async {
      if (folderIds.containsKey(path)) return folderIds[path];

      final lastSlash = path.lastIndexOf('/');
      final parentPath = lastSlash == -1 ? '' : path.substring(0, lastSlash);
      final name = lastSlash == -1 ? path : path.substring(lastSlash + 1);

      final parentId = await resolveFolder(parentPath);
      final folder = await _fileRepository.createFolder(name: name, parentId: parentId);
      folderIds[path] = folder.id;
      return folder.id;
    }

    try {
      var completed = 0;
      for (final file in files) {
        final relative = file.path.substring(normalizedRoot.length + 1).replaceAll(Platform.pathSeparator, '/');
        final fullPath = '$rootName/$relative';
        final lastSlash = fullPath.lastIndexOf('/');
        final dirPath = fullPath.substring(0, lastSlash);
        final fileName = fullPath.substring(lastSlash + 1);

        final folderId = await resolveFolder(dirPath);
        final total = files.length;
        final base = completed;

        await _fileRepository.uploadFile(
          file: file,
          fileName: fileName,
          folderId: folderId,
          onProgress: (progress) => setState(() => _uploadProgress = (base + progress) / total),
        );
        completed++;
      }
      _load();
    } catch (e) {
      _showError(e);
    } finally {
      if (mounted) setState(() => _uploadProgress = null);
    }
  }

  /// Server-side download from an external URL — only works when an admin
  /// has turned it on in Settings; a 404 here (feature disabled) surfaces
  /// through the normal error path like any other rejected request.
  Future<void> _uploadFromUrl() async {
    final controller = TextEditingController();
    final url = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('رفع من رابط'),
        content: TextField(
          controller: controller,
          keyboardType: TextInputType.url,
          autofocus: true,
          decoration: const InputDecoration(hintText: 'https://example.com/file.pdf'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.of(context).pop(controller.text), child: const Text('رفع')),
        ],
      ),
    );

    if (url == null || url.trim().isEmpty) return;

    try {
      await _fileRepository.uploadFromUrl(url: url.trim(), folderId: widget.folder?.id);
      _load();
    } catch (e) {
      _showError(e);
    }
  }

  /// A quick, ephemeral preview: downloads to the OS temp directory (which
  /// the system can reclaim any time) and opens it immediately. This is
  /// distinct from — and doesn't interact with — the persistent "download
  /// for offline use" feature on each file's trailing icon (see
  /// DownloadStatusIcon), which keeps a permanent local copy instead.
  Future<void> _previewFile(FileItem file) async {
    try {
      final directory = await getTemporaryDirectory();
      final destination = '${directory.path}/${file.name}';
      await _fileRepository.downloadFile(file, destination);
      await OpenFilex.open(destination);
    } catch (e) {
      _showError(e);
    }
  }

  /// Shows the full text DocumentTextExtractor pulled from this document —
  /// an on-demand fetch (mirrors the web dashboard's preview panel) since
  /// the text can be large and isn't part of the normal file listing.
  Future<void> _viewExtractedText(FileItem file) async {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(file.name),
        content: SizedBox(
          width: double.maxFinite,
          child: FutureBuilder<String?>(
            future: _fileRepository.fileContent(file.id),
            builder: (context, snapshot) {
              if (snapshot.connectionState != ConnectionState.done) {
                return const SizedBox(height: 80, child: Center(child: CircularProgressIndicator()));
              }
              if (snapshot.hasError) {
                return Text(ApiClient.messageFor(snapshot.error!));
              }
              final text = snapshot.data;
              if (text == null || text.isEmpty) {
                return const Text('لا يوجد نص مستخرج لهذا الملف.');
              }
              return SingleChildScrollView(child: SelectableText(text));
            },
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('إغلاق')),
        ],
      ),
    );
  }

  /// Opens the in-app player for a video/audio file, queuing every other
  /// file of the same kind in this folder as its playlist — matching the
  /// web player's "sibling files become a queue" behaviour.
  void _playMedia(FileItem file) {
    final playlist = _files.where((f) => (file.isVideo && f.isVideo) || (file.isAudio && f.isAudio)).toList();
    final index = playlist.indexWhere((f) => f.id == file.id);

    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => MediaPlayerScreen(playlist: playlist, initialIndex: index < 0 ? 0 : index),
      ),
    );
  }

  Future<void> _shareFile(FileItem file) async {
    try {
      final share = await _shareRepository.createShare(shareableType: 'file', shareableId: file.id);
      await Share.share(share.url);
    } catch (e) {
      _showError(e);
    }
  }

  Future<void> _renameFile(FileItem file) async {
    final name = await _promptText('إعادة تسمية', initial: file.name);
    if (name == null || name.trim().isEmpty || name == file.name) return;
    try {
      await _fileRepository.renameFile(file.id, name.trim());
      _load();
    } catch (e) {
      _showError(e);
    }
  }

  Future<void> _deleteFile(FileItem file) async {
    try {
      await _fileRepository.deleteFile(file.id);
      _load();
    } catch (e) {
      _showError(e);
    }
  }

  Future<void> _moveFile(FileItem file) async {
    final (targetFolderId, cancelled) = await FolderPickerDialog.show(context);
    if (cancelled) return;
    try {
      await _fileRepository.moveFile(file.id, targetFolderId);
      _load();
    } catch (e) {
      _showError(e);
    }
  }

  Future<void> _renameFolder(FolderItem folder) async {
    final name = await _promptText('إعادة تسمية', initial: folder.name);
    if (name == null || name.trim().isEmpty || name == folder.name) return;
    try {
      await _fileRepository.renameFolder(folder.id, name.trim());
      _load();
    } catch (e) {
      _showError(e);
    }
  }

  Future<void> _deleteFolder(FolderItem folder) async {
    try {
      await _fileRepository.deleteFolder(folder.id);
      _load();
    } catch (e) {
      _showError(e);
    }
  }

  Future<void> _moveFolder(FolderItem folder) async {
    final (targetFolderId, cancelled) = await FolderPickerDialog.show(context, excludeFolderId: folder.id);
    if (cancelled) return;
    try {
      await _fileRepository.moveFolder(folder.id, targetFolderId);
      _load();
    } catch (e) {
      _showError(e);
    }
  }

  void _toggleFileSelection(FileItem file) {
    setState(() {
      if (!_selectedFileIds.remove(file.id)) _selectedFileIds.add(file.id);
    });
  }

  void _toggleFolderSelection(FolderItem folder) {
    setState(() {
      if (!_selectedFolderIds.remove(folder.id)) _selectedFolderIds.add(folder.id);
    });
  }

  void _clearSelection() {
    setState(() {
      _selectedFileIds.clear();
      _selectedFolderIds.clear();
    });
  }

  Future<void> _deleteSelected() async {
    final count = _selectedFileIds.length + _selectedFolderIds.length;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('نقل إلى سلة المحذوفات'),
        content: Text('هل تريد نقل $count عنصر إلى سلة المحذوفات؟'),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(false), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.of(context).pop(true), child: const Text('نقل')),
        ],
      ),
    );
    if (confirmed != true) return;

    try {
      for (final id in _selectedFileIds) {
        await _fileRepository.deleteFile(id);
      }
      for (final id in _selectedFolderIds) {
        await _fileRepository.deleteFolder(id);
      }
      _clearSelection();
      _load();
    } catch (e) {
      _showError(e);
    }
  }

  Future<void> _downloadSelectedZip() async {
    try {
      final directory = await getTemporaryDirectory();
      final destination = '${directory.path}/filenest-archive-${DateTime.now().millisecondsSinceEpoch}.zip';
      await _fileRepository.downloadZip(
        fileIds: _selectedFileIds.toList(),
        folderIds: _selectedFolderIds.toList(),
        destinationPath: destination,
      );
      _clearSelection();
      await OpenFilex.open(destination);
    } catch (e) {
      _showError(e);
    }
  }

  Future<String?> _promptText(String title, {String initial = ''}) {
    final controller = TextEditingController(text: initial);
    return showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: TextField(controller: controller, autofocus: true),
        actions: [
          TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('إلغاء')),
          FilledButton(onPressed: () => Navigator.of(context).pop(controller.text), child: const Text('حفظ')),
        ],
      ),
    );
  }

  void _showError(Object e) {
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
  }

  void _showFileMenu(FileItem file) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (context) => SafeArea(
        child: Wrap(children: [
          ListTile(
            leading: Icon(file.isPlayableMedia ? Icons.play_circle_outline_rounded : Icons.visibility_outlined),
            title: Text(file.isPlayableMedia ? 'تشغيل' : 'معاينة/فتح'),
            onTap: () {
              Navigator.of(context).pop();
              file.isPlayableMedia ? _playMedia(file) : _previewFile(file);
            },
          ),
          ListTile(leading: const Icon(Icons.ios_share_rounded), title: const Text('مشاركة'), onTap: () {
            Navigator.of(context).pop();
            _shareFile(file);
          }),
          ListTile(leading: const Icon(Icons.drive_file_rename_outline_rounded), title: const Text('إعادة تسمية'), onTap: () {
            Navigator.of(context).pop();
            _renameFile(file);
          }),
          if (file.isIndexed)
            ListTile(leading: const Icon(Icons.article_outlined), title: const Text('عرض النص المستخرج'), onTap: () {
              Navigator.of(context).pop();
              _viewExtractedText(file);
            }),
          ListTile(leading: const Icon(Icons.drive_file_move_outline), title: const Text('نقل إلى مجلد'), onTap: () {
            Navigator.of(context).pop();
            _moveFile(file);
          }),
          ListTile(
            leading: const Icon(Icons.delete_outline_rounded, color: Colors.red),
            title: const Text('نقل إلى سلة المحذوفات', style: TextStyle(color: Colors.red)),
            onTap: () {
              Navigator.of(context).pop();
              _deleteFile(file);
            },
          ),
        ]),
      ),
    );
  }

  void _showFolderMenu(FolderItem folder) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (context) => SafeArea(
        child: Wrap(children: [
          ListTile(leading: const Icon(Icons.drive_file_rename_outline_rounded), title: const Text('إعادة تسمية'), onTap: () {
            Navigator.of(context).pop();
            _renameFolder(folder);
          }),
          ListTile(leading: const Icon(Icons.drive_file_move_outline), title: const Text('نقل إلى مجلد'), onTap: () {
            Navigator.of(context).pop();
            _moveFolder(folder);
          }),
          ListTile(
            leading: const Icon(Icons.delete_outline_rounded, color: Colors.red),
            title: const Text('نقل إلى سلة المحذوفات', style: TextStyle(color: Colors.red)),
            onTap: () {
              Navigator.of(context).pop();
              _deleteFolder(folder);
            },
          ),
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final content = _buildContent();
    final fab = UploadFab(
      onNewFolder: _createFolder,
      onUpload: _uploadFile,
      onUploadFolder: _uploadFolder,
      onUploadFromUrl: _uploadFromUrl,
    );

    return WillPopScope(
      // The system/gesture back action exits selection mode first instead
      // of leaving the screen — the same behaviour as Gmail/Drive-style
      // selection toolbars.
      onWillPop: () async {
        if (_isSelecting) {
          _clearSelection();
          return false;
        }
        return true;
      },
      child: widget.embedded
          // A chromeless inner Scaffold purely to get correct FAB
          // positioning/animation "for free" — HomeShell supplies the real
          // AppBar and Drawer around this.
          ? Scaffold(backgroundColor: Colors.transparent, body: content, floatingActionButton: fab)
          : Scaffold(
              appBar: AppBar(
                title: Text(widget.folder?.name ?? 'FileNest'),
                actions: [
                  IconButton(
                    icon: const Icon(Icons.more_vert_rounded),
                    tooltip: 'المزيد',
                    onPressed: () => MoreDestinationsSheet.show(context),
                  ),
                ],
              ),
              body: content,
              floatingActionButton: fab,
            ),
    );
  }

  Widget _buildContent() {
    return Column(
      children: [
        if (_isSelecting)
          _SelectionToolbar(
            count: _selectedFileIds.length + _selectedFolderIds.length,
            onClear: _clearSelection,
            onDownloadZip: _downloadSelectedZip,
            onDelete: _deleteSelected,
          )
        else
          Padding(
            padding: const EdgeInsets.all(12),
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                hintText: 'ابحث بالاسم أو محتوى الملف...',
                prefixIcon: const Icon(Icons.search_rounded),
              ),
              onSubmitted: (value) => _load(query: value.trim()),
            ),
          ),
        if (_uploadProgress != null) LinearProgressIndicator(value: _uploadProgress),
        if (_breadcrumbs.isNotEmpty)
          SizedBox(
            height: 36,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              children: _breadcrumbs
                  .map((crumb) => Padding(
                        padding: const EdgeInsets.only(left: 8),
                        child: Chip(label: Text(crumb.name)),
                      ))
                  .toList(),
            ),
          ),
        if (widget.folder != null) _buildFolderInfoPanel(),
        Expanded(child: _buildBody()),
      ],
    );
  }

  /// Collapsible free-form bibliographic info for this folder (researcher,
  /// supervisor, thesis title, publisher, digital library, publication
  /// year) — the mobile counterpart of the web dashboard's folder info
  /// panel, shown only when browsing into a real folder (not the root).
  Widget _buildFolderInfoPanel() {
    return Card(
      margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
      child: Column(
        children: [
          ListTile(
            leading: const Icon(Icons.info_outline_rounded),
            title: const Text('معلومات المجلد'),
            trailing: Icon(_showFolderInfo ? Icons.expand_less_rounded : Icons.expand_more_rounded),
            onTap: () => setState(() => _showFolderInfo = !_showFolderInfo),
          ),
          if (_showFolderInfo)
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
              child: Column(
                children: [
                  Row(
                    children: [
                      const Text('نوع المجلد: '),
                      const SizedBox(width: 8),
                      ChoiceChip(
                        label: const Text('ملفات'),
                        selected: _folderType == 'files',
                        onSelected: (_) => setState(() => _folderType = 'files'),
                      ),
                      const SizedBox(width: 8),
                      ChoiceChip(
                        label: const Text('أطروحة'),
                        selected: _folderType == 'thesis',
                        onSelected: (_) => setState(() => _folderType = 'thesis'),
                      ),
                    ],
                  ),
                  if (_folderType == 'thesis') ...[
                    const SizedBox(height: 12),
                    TextField(controller: _researcherNameController, decoration: const InputDecoration(labelText: 'اسم الباحث')),
                    const SizedBox(height: 8),
                    TextField(controller: _supervisorNameController, decoration: const InputDecoration(labelText: 'المشرف')),
                    const SizedBox(height: 8),
                    TextField(controller: _thesisTitleController, decoration: const InputDecoration(labelText: 'عنوان الرسالة')),
                    const SizedBox(height: 8),
                    TextField(controller: _publisherController, decoration: const InputDecoration(labelText: 'دار النشر')),
                    const SizedBox(height: 8),
                    TextField(controller: _digitalLibraryController, decoration: const InputDecoration(labelText: 'المكتبة الرقمية')),
                    const SizedBox(height: 8),
                    TextField(controller: _publicationYearController, decoration: const InputDecoration(labelText: 'سنة النشر')),
                  ],
                  const SizedBox(height: 12),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: FilledButton(
                      onPressed: _savingFolderInfo ? null : _saveFolderInfo,
                      child: _savingFolderInfo
                          ? const SizedBox(height: 16, width: 16, child: CircularProgressIndicator(strokeWidth: 2))
                          : const Text('حفظ'),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildBody() {
    if (_isLoading) return const Center(child: CircularProgressIndicator());
    if (_error != null) return Center(child: Text(_error!));
    if (_folders.isEmpty && _files.isEmpty) {
      return const EmptyState(icon: Icons.folder_open_outlined, message: 'لا توجد ملفات أو مجلدات هنا بعد.');
    }

    return RefreshIndicator(
      onRefresh: () => _load(query: _searchController.text.trim()),
      child: ListView(
        children: [
          ..._folders.map((folder) => FolderTile(
                folder: folder,
                selectionMode: _isSelecting,
                selected: _selectedFolderIds.contains(folder.id),
                onTap: _isSelecting ? () => _toggleFolderSelection(folder) : () => _openFolder(folder),
                onMenu: () => _showFolderMenu(folder),
                onLongPress: () => _toggleFolderSelection(folder),
              )),
          ..._files.map((file) => FileTile(
                file: file,
                selectionMode: _isSelecting,
                selected: _selectedFileIds.contains(file.id),
                onTap: _isSelecting
                    ? () => _toggleFileSelection(file)
                    : (file.isPlayableMedia ? () => _playMedia(file) : () => _previewFile(file)),
                onMenu: () => _showFileMenu(file),
                onLongPress: () => _toggleFileSelection(file),
              )),
        ],
      ),
    );
  }
}

/// Replaces the search field while one or more items are selected —
/// count, and the two bulk actions the web dashboard's own selection
/// toolbar offers (download as one ZIP, move to trash).
class _SelectionToolbar extends StatelessWidget {
  const _SelectionToolbar({
    required this.count,
    required this.onClear,
    required this.onDownloadZip,
    required this.onDelete,
  });

  final int count;
  final VoidCallback onClear;
  final VoidCallback onDownloadZip;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Theme.of(context).colorScheme.surfaceContainerHighest,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child: Row(
          children: [
            IconButton(icon: const Icon(Icons.close_rounded), tooltip: 'إلغاء التحديد', onPressed: onClear),
            Expanded(
              child: Text('$count محدد', style: const TextStyle(fontWeight: FontWeight.w600)),
            ),
            IconButton(icon: const Icon(Icons.download_rounded), tooltip: 'تنزيل كملف مضغوط', onPressed: onDownloadZip),
            IconButton(
              icon: const Icon(Icons.delete_outline_rounded, color: Colors.red),
              tooltip: 'نقل إلى سلة المحذوفات',
              onPressed: onDelete,
            ),
          ],
        ),
      ),
    );
  }
}
