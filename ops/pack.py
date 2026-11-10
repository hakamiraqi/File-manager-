# Packs a FileNest backup for the offline server into one zip (stored, not compressed: PDFs and
# pictures don't shrink): FileNestBackups/<database zip> and FileNestBackups/uploads/<folders>,
# the layout the offline panel's «استعادة» reads. Usage: pack.py STORAGE_APP_DIR OUT_ZIP
import os, sys, zipfile, glob
app, out = sys.argv[1], sys.argv[2]
backups = os.path.join(app, 'private', 'backups')
db = max(glob.glob(os.path.join(backups, '*.zip')), key=os.path.getmtime)
readme = """FileNest — نسخة احتياطية للسيرفر بدون إنترنت

1. فك الضغط على القرص C: فيصير عندك المجلد C:\\FileNestBackups (فيه ملف النسخة ومجلد uploads).
2. افتح لوحة FileNest.exe ← النسخ الاحتياطي ← «استعادة».
3. اختر الملف %s واختر «استبدال كل البيانات».
4. انتظر حتى تنتهي ثم افتح https://files.lan وادخل بحساب الأدمن نفسه الذي على الاستضافة.
""" % os.path.basename(db)
n = 0
size = 0
with zipfile.ZipFile(out + '.part', 'w', zipfile.ZIP_STORED, allowZip64=True) as z:
    z.writestr('FileNestBackups/اقرأني.txt', readme.encode('utf-8'))
    z.write(db, 'FileNestBackups/' + os.path.basename(db))
    for folder in ('filemanager', 'public'):
        root = os.path.join(app, folder)
        for d, _, files in os.walk(root):
            for f in files:
                p = os.path.join(d, f)
                if f == '.gitignore' or os.path.abspath(p) == os.path.abspath(out) or p.endswith('.part') or '/backup-exports/' in p:
                    continue
                z.write(p, 'FileNestBackups/uploads/' + folder + '/' + os.path.relpath(p, root))
                n += 1
                size += os.path.getsize(p)
os.replace(out + '.part', out)
print(f'PACKED: {n} files, {size / 1e9:.2f} GB, database {os.path.basename(db)} ({os.path.getsize(db) / 1e6:.1f} MB); archive {os.path.getsize(out) / 1e9:.2f} GB')
