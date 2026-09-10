import 'package:flutter/material.dart';

import '../../../../core/models/folder_item.dart';
import '../../../../core/network/api_client.dart';
import '../../data/file_repository.dart';

/// A destination picker for "move to folder": fetches the user's whole
/// folder tree (flat list of {id, parent_id, name} from the API) and
/// renders it indented by depth, with a "المجلد الرئيسي" root option at
/// the top. Returns the chosen folder id (`null` for root), or `null`
/// (indistinguishable from "chose root") only when the dialog is
/// dismissed — callers should check [FolderPickerDialog.show]'s
/// `cancelled` flag via the returned record instead of the bare value
/// when that ambiguity matters.
class FolderPickerDialog extends StatefulWidget {
  const FolderPickerDialog({super.key, this.excludeFolderId});

  /// The folder currently being moved, if any — excluded from the list
  /// since a folder can't be moved into itself. Moving into one of its
  /// own descendants is still possible to *pick* here, but the backend
  /// rejects it with a clear error message (FolderController::update).
  final int? excludeFolderId;

  /// Shows the picker and returns `(folderId, cancelled)`. `cancelled` is
  /// true if the user dismissed the dialog without choosing anything.
  static Future<(int?, bool)> show(BuildContext context, {int? excludeFolderId}) async {
    final result = await showDialog<_PickResult>(
      context: context,
      builder: (_) => FolderPickerDialog(excludeFolderId: excludeFolderId),
    );
    return result == null ? (null, true) : (result.folderId, false);
  }

  @override
  State<FolderPickerDialog> createState() => _FolderPickerDialogState();
}

class _PickResult {
  const _PickResult(this.folderId);
  final int? folderId;
}

class _FolderPickerDialogState extends State<FolderPickerDialog> {
  final _fileRepository = FileRepository();
  List<FolderItem> _folders = [];
  bool _isLoading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final folders = await _fileRepository.folderTree();
      setState(() => _folders = folders.where((f) => f.id != widget.excludeFolderId).toList());
    } catch (e) {
      setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  /// Depth-first walk starting from root (parentId == null) so children
  /// always render right after their parent, indented one level deeper.
  List<(FolderItem, int)> _orderedWithDepth() {
    final byParent = <int?, List<FolderItem>>{};
    for (final folder in _folders) {
      byParent.putIfAbsent(folder.parentId, () => []).add(folder);
    }

    final result = <(FolderItem, int)>[];
    void visit(int? parentId, int depth) {
      for (final folder in byParent[parentId] ?? const <FolderItem>[]) {
        result.add((folder, depth));
        visit(folder.id, depth + 1);
      }
    }

    visit(null, 0);
    return result;
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('نقل إلى مجلد'),
      content: SizedBox(
        width: double.maxFinite,
        height: 400,
        child: _isLoading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? Center(child: Text(_error!))
                : ListView(
                    shrinkWrap: true,
                    children: [
                      ListTile(
                        leading: const Icon(Icons.home_rounded),
                        title: const Text('المجلد الرئيسي'),
                        onTap: () => Navigator.of(context).pop(const _PickResult(null)),
                      ),
                      const Divider(height: 1),
                      for (final (folder, depth) in _orderedWithDepth())
                        Padding(
                          padding: EdgeInsets.only(right: depth * 20.0),
                          child: ListTile(
                            leading: const Icon(Icons.folder_rounded),
                            title: Text(folder.name),
                            onTap: () => Navigator.of(context).pop(_PickResult(folder.id)),
                          ),
                        ),
                    ],
                  ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('إلغاء')),
      ],
    );
  }
}
