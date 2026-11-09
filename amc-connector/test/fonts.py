"""Builds the same Arabic sheet with different font set-ups, to compare the pictures."""
import json, os, urllib.request
URL, TOKEN = 'http://127.0.0.1:8080', os.environ['AMC_TOKEN']
students = 'id,name\nS1,علي حسن\n'
body = """*[id=q1,ordered]{b=1} ما صيغة الماء؟ البروتوكول HTTPS وFTP وDNA، و15% من 200، وπ ≈ 3.14
+ H2O
- CO2 وH2O
- NaCl
"""
head = 'PaperSize: A4\nLang: AR\nTitle: امتحان AMC تجريبي — 100 سؤال\nPreAssociation: students.csv\nPreAssociationKey: id\nPreAssociationName: \\name{}\nSeparateAnswerSheet: 1\n'
latex_body = r"""*[id=q1,ordered]{b=1} ما صيغة الماء؟ البروتوكول \LR{HTTPS} و\LR{FTP} و\LR{DNA}، و\LR{15\%} من \LR{200}، و\LR{$\pi \approx 3.14$}
+ \LR{H\textsubscript{2}O}
- \LR{CO\textsubscript{2}} و\LR{H\textsubscript{2}O}
- \LR{NaCl}
"""
variants = {
    'a': head + 'ArabicFont: Amiri\n\n' + body,
    'b': head + 'ArabicFont: Amiri\nFont: Amiri\n\n' + body,
    'c': head + 'ArabicFont: Amiri\nLaTeX: 1\n\n' + latex_body,
    'd': head + 'ArabicFont: Amiri\nFont: Linux Libertine O\nLaTeX: 1\n\n' + latex_body.replace('\\LR{', '\\LR{\\rmfamily '),
}
for v, src in variants.items():
    req = urllib.request.Request(f'{URL}/projects/font{v}', method='PUT', data=json.dumps({'source': src, 'students': students}).encode(),
                                 headers={'Authorization': 'Bearer ' + TOKEN, 'Content-Type': 'application/json'})
    try:
        r = urllib.request.urlopen(req, timeout=600)
        print(v, r.status, r.read()[:200])
    except urllib.error.HTTPError as e:
        print(v, e.code, e.read().decode(errors='replace')[-1500:])
