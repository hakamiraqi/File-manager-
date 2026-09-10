/// Small formatting helpers shared across screens so every list (browse,
/// downloads, trash) renders sizes/dates the same way instead of each
/// screen inventing its own.
String formatBytes(int bytes) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  var value = bytes.toDouble();
  var unitIndex = 0;

  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex++;
  }

  return '${value.toStringAsFixed(value >= 10 || unitIndex == 0 ? 0 : 2)} ${units[unitIndex]}';
}

String formatDate(DateTime date) {
  final local = date.toLocal();
  final two = (int n) => n.toString().padLeft(2, '0');
  return '${local.year}-${two(local.month)}-${two(local.day)} ${two(local.hour)}:${two(local.minute)}';
}
