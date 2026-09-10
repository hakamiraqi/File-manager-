import 'package:flutter/material.dart';

import '../../../core/i18n/app_strings.dart';
import '../../../core/network/api_client.dart';
import '../data/admin_repository.dart';
import '../models/translation_row.dart';

/// Mobile counterpart of Admin/Translations.vue. A local search filter is
/// added on top of the web page's own behavior since the full string list
/// can run into the hundreds — purely a mobile usability addition, not a
/// functional difference.
class TranslationsScreen extends StatefulWidget {
  const TranslationsScreen({super.key});

  @override
  State<TranslationsScreen> createState() => _TranslationsScreenState();
}

class _TranslationsScreenState extends State<TranslationsScreen> {
  final _repository = AdminRepository();
  final _searchController = TextEditingController();

  bool _loading = true;
  String? _error;
  String _locale = 'ar';
  Map<String, dynamic> _locales = {};
  List<TranslationRow> _rows = [];
  final Set<String> _savingKeys = {};

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final payload = await _repository.translations(_locale);
      setState(() {
        _locale = payload['locale'] as String;
        _locales = payload['locales'] as Map<String, dynamic>;
        _rows = (payload['rows'] as List).map((e) => TranslationRow.fromJson(e as Map<String, dynamic>)).toList();
      });
    } catch (e) {
      if (mounted) setState(() => _error = ApiClient.messageFor(e));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _save(TranslationRow row) async {
    setState(() => _savingKeys.add(row.key));
    try {
      await _repository.saveTranslation(locale: _locale, key: row.key, value: row.value);
      if (mounted) setState(() => row.overridden = true);
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    } finally {
      if (mounted) setState(() => _savingKeys.remove(row.key));
    }
  }

  Future<void> _reset(TranslationRow row) async {
    setState(() => _savingKeys.add(row.key));
    try {
      await _repository.resetTranslation(locale: _locale, key: row.key);
      if (mounted) {
        setState(() {
          row.value = row.base;
          row.overridden = false;
        });
      }
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiClient.messageFor(e))));
    } finally {
      if (mounted) setState(() => _savingKeys.remove(row.key));
    }
  }

  @override
  Widget build(BuildContext context) {
    final search = _searchController.text.trim().toLowerCase();
    final visibleRows = search.isEmpty
        ? _rows
        : _rows.where((r) => r.key.toLowerCase().contains(search) || r.value.toLowerCase().contains(search)).toList();

    return Scaffold(
      appBar: AppBar(title: Text(context.tr('admin.nav.translations'))),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!))
              : Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        children: [
                          DropdownButton<String>(
                            value: _locale,
                            items: _locales.entries
                                .map((e) => DropdownMenuItem(
                                      value: e.key as String,
                                      child: Text((e.value as Map)['label'] as String? ?? e.key),
                                    ))
                                .toList(),
                            onChanged: (v) {
                              if (v == null) return;
                              setState(() => _locale = v);
                              _load();
                            },
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: TextField(
                              controller: _searchController,
                              decoration: InputDecoration(hintText: context.tr('translations.search_placeholder'), isDense: true, border: const OutlineInputBorder()),
                              onChanged: (_) => setState(() {}),
                            ),
                          ),
                        ],
                      ),
                    ),
                    Expanded(
                      child: ListView.separated(
                        itemCount: visibleRows.length,
                        separatorBuilder: (_, __) => const Divider(height: 1),
                        itemBuilder: (context, index) {
                          final row = visibleRows[index];
                          final saving = _savingKeys.contains(row.key);
                          return Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(row.key, style: const TextStyle(fontFamily: 'monospace', fontSize: 11, color: Colors.grey)),
                                const SizedBox(height: 4),
                                Row(
                                  children: [
                                    Expanded(
                                      child: TextFormField(
                                        key: ValueKey(row.key),
                                        initialValue: row.value,
                                        decoration: const InputDecoration(isDense: true, border: OutlineInputBorder()),
                                        onChanged: (v) => row.value = v,
                                        onFieldSubmitted: (_) => _save(row),
                                      ),
                                    ),
                                    IconButton(
                                      icon: saving
                                          ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                                          : const Icon(Icons.save_outlined, size: 20),
                                      onPressed: saving ? null : () => _save(row),
                                    ),
                                    if (row.overridden)
                                      IconButton(
                                        icon: const Icon(Icons.undo_rounded, size: 20),
                                        tooltip: context.tr('translations.original'),
                                        onPressed: saving ? null : () => _reset(row),
                                      ),
                                  ],
                                ),
                              ],
                            ),
                          );
                        },
                      ),
                    ),
                  ],
                ),
    );
  }
}
