"""The survey server end to end: forms, saving, two people editing at once, history, bin, Excel."""
import io
import json
import os
import sys
import tempfile
import threading
import unittest
import urllib.error
import urllib.request

os.environ['SURVEY_DATA'] = tempfile.mkdtemp()
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'app'))
import openpyxl  # noqa: E402
import server  # noqa: E402


def call(method, path, body=None):
    req = urllib.request.Request(BASE + path, method=method, data=json.dumps(body).encode() if body is not None else None,
                                 headers={'Content-Type': 'application/json'} if body is not None else {})
    try:
        with urllib.request.urlopen(req) as r:
            raw = r.read()
            return r.status, (json.loads(raw) if r.headers['Content-Type'].startswith('application/json') else raw), r.headers
    except urllib.error.HTTPError as e:
        raw = e.read()
        return e.code, json.loads(raw) if raw.startswith(b'{') else raw, e.headers


class SurveyTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        global BASE
        cls.srv = server.ThreadingHTTPServer(('127.0.0.1', 0), server.Handler)
        BASE = 'http://127.0.0.1:%d' % cls.srv.server_address[1]
        threading.Thread(target=cls.srv.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.srv.shutdown()

    def test_the_form_is_read_from_the_excel_file(self):
        s = server.SCHEMA
        self.assertEqual([len(x['items']) for x in s['sections']], [2, 5, 15, 13, 8, 1])
        self.assertEqual([h['col'] for h in s['header']], ['B', 'C', 'D', 'E', 'F'])
        self.assertEqual(s['header_row'], 4)
        self.assertEqual(s['sections'][5]['cols'][-1], {'col': 'G', 'label': 'ملاحظات / المعوقات', 'kind': 'text', 'span': 2})
        code, page, _ = call('GET', '/')
        self.assertEqual(code, 200)
        self.assertIn('app.js'.encode(), page)

    def test_fill_save_merge_history_bin_and_excel(self):
        code, f, _ = call('POST', '/api/forms', {'values': {}})
        self.assertEqual(code, 201)
        fid, v = f['id'], f['version']
        values = {'r4': {'B': 'بغداد', 'C': 'الرصافة', 'D': 'مركز الشعب', 'F': '2026-11-09', 'Z': 'x'},
                  'r7': {'C': 'نعم', 'D': '3', 'H': 'ملاحظة'}, 'r11': {'C': 'ربما'}, 'r99': {'C': 'نعم'}}
        code, r, _ = call('PUT', '/api/forms/%d' % fid, {'version': v, 'values': values})
        self.assertEqual(code, 200)
        code, f, _ = call('GET', '/api/forms/%d' % fid)
        self.assertEqual(f['values'], {'r4': {'B': 'بغداد', 'C': 'الرصافة', 'D': 'مركز الشعب', 'F': '2026-11-09'}, 'r7': {'C': 'نعم', 'D': '3', 'H': 'ملاحظة'}})
        self.assertEqual(f['header']['D'], 'مركز الشعب')
        self.assertEqual(f['filled'], 1)

        # Someone saving an older version is refused with the newer form (the page then merges and saves again).
        code, newer, _ = call('PUT', '/api/forms/%d' % fid, {'version': v, 'values': {}})
        self.assertEqual(code, 409)
        self.assertEqual(newer['values']['r7']['D'], '3')

        code, lst, _ = call('GET', '/api/forms')
        self.assertEqual([x['id'] for x in lst['forms']], [fid])
        self.assertNotIn('values', lst['forms'][0])

        code, hist, _ = call('GET', '/api/forms/%d/history' % fid)
        first = hist['revisions'][-1]
        self.assertEqual(first['note'], 'أُنشئت')
        code, back, _ = call('POST', '/api/forms/%d/history/%d/restore' % (fid, first['id']))
        self.assertEqual(code, 200)
        self.assertEqual(back['values'], {})
        code, back, _ = call('POST', '/api/forms/%d/history/%d/restore' % (fid, hist['revisions'][0]['id']))
        self.assertEqual(back['values']['r7']['C'], 'نعم')

        # The Excel download is the original form, filled in.
        code, raw, headers = call('GET', '/api/forms/%d.xlsx' % fid)
        self.assertEqual(code, 200)
        self.assertIn("filename*=UTF-8''", headers['Content-Disposition'])
        ws = openpyxl.load_workbook(io.BytesIO(raw)).worksheets[0]
        self.assertEqual(ws.title, 'مركز الشعب')
        self.assertTrue(ws.sheet_view.rightToLeft)
        self.assertEqual((ws['B4'].value, ws['D4'].value, ws['C7'].value, ws['D7'].value, ws['H7'].value), ('بغداد', 'مركز الشعب', 'نعم', 3, 'ملاحظة'))
        self.assertEqual(ws['F4'].value.strftime('%Y-%m-%d'), '2026-11-09')
        self.assertEqual(ws['B7'].value.strip()[:9], 'هل يتوفر ')

        # All the forms: a summary sheet, then one sheet per centre.
        call('POST', '/api/forms', {'values': {'r4': {'D': 'مركز الشعب'}, 'r60': {'C': 'كلا', 'G': 'لا توجد غرفة'}}})
        code, raw, _ = call('GET', '/api/export.xlsx')
        wb = openpyxl.load_workbook(io.BytesIO(raw))
        self.assertEqual(wb.sheetnames, ['الملخص', 'مركز الشعب', 'مركز الشعب 2'])
        self.assertEqual(wb['الملخص'].max_row, 3)
        self.assertEqual(wb['مركز الشعب 2']['G60'].value, 'لا توجد غرفة')
        self.assertIn('G59:H59', [str(x) for x in wb['مركز الشعب 2'].merged_cells.ranges])

        # The bin: deleted forms leave the list, cannot be saved, and come back.
        self.assertEqual(call('POST', '/api/forms/%d/delete' % fid)[0], 200)
        self.assertEqual([x['id'] for x in call('GET', '/api/forms')[1]['forms']].count(fid), 0)
        self.assertEqual([x['id'] for x in call('GET', '/api/forms?bin=1')[1]['forms']], [fid])
        f = call('GET', '/api/forms/%d' % fid)[1]
        self.assertEqual(call('PUT', '/api/forms/%d' % fid, {'version': f['version'], 'values': {}})[0], 410)
        call('POST', '/api/forms/%d/restore' % fid)
        self.assertIn(fid, [x['id'] for x in call('GET', '/api/forms')[1]['forms']])

    def test_bad_requests(self):
        self.assertEqual(call('GET', '/api/forms/999')[0], 404)
        self.assertEqual(call('GET', '/static/../server.py')[0], 404)
        self.assertEqual(call('PUT', '/api/forms/1', {'version': 'x'})[0], 400)
        code, _, headers = call('GET', '/')
        self.assertIn("frame-ancestors 'none'", headers['Content-Security-Policy'])


if __name__ == '__main__':
    unittest.main()
