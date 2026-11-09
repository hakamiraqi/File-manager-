// The survey website: a list of the health centres' forms, and each form laid out like the Excel sheet.
// Anyone can fill or change any form. Changes save by themselves; if two people edit the same form,
// each one's changed cells are kept (the other cells come from whoever saved last).
'use strict';

const $ = (sel, el = document) => el.querySelector(sel);
const view = $('#view');
let schema = null;
let open = null;          // the form being edited: { id, version, values, updated_at }
let dirty = new Map();    // "r7|C" -> value changed here and not saved yet
let saving = false, again = false, timer = null, poll = null;

function h(tag, attrs = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') el.className = v;
        else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
        else el.setAttribute(k, v === true ? '' : v);
    }
    for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== false) el.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
    return el;
}
function toast(text) {
    const t = $('#toast');
    t.textContent = text;
    t.classList.add('show');
    clearTimeout(toast.t);
    toast.t = setTimeout(() => t.classList.remove('show'), 3200);
}
async function api(method, url, body) {
    const r = await fetch(url, { method, headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : undefined });
    let data = null;
    try { data = await r.json(); } catch { /* not JSON */ }
    return { status: r.status, ok: r.ok, data };
}
const when = (iso) => (iso ? new Date(iso).toLocaleString('ar-IQ', { dateStyle: 'medium', timeStyle: 'short' }) : '');
const totalItems = () => schema.sections.reduce((n, s) => n + s.items.length, 0);

// ---------------------------------------------------------------- list
async function showList(bin = false) {
    stopForm();
    document.title = schema.title;
    view.replaceChildren(h('p', { class: 'muted pad' }, 'يحمّل…'));
    const r = await api('GET', '/api/forms' + (bin ? '?bin=1' : ''));
    if (!r.ok) { view.replaceChildren(h('p', { class: 'pad status err' }, 'تعذّر التحميل. أعد المحاولة.')); return; }
    const forms = r.data.forms;
    const search = h('input', { class: 'search', type: 'search', placeholder: 'بحث بالمحافظة أو القطاع أو اسم المركز…', 'aria-label': 'بحث' });
    const body = h('tbody');
    const draw = () => {
        const q = search.value.trim();
        const rows = forms.filter((f) => !q || Object.values(f.header).join(' ').includes(q));
        body.replaceChildren(...rows.map((f) => {
            const pct = Math.round((100 * f.filled) / r.data.items);
            const link = '#/f/' + f.id;
            return h('tr', {},
                h('td', { class: 'c' }, f.id),
                h('td', {}, h('a', { href: link }, f.header.D || 'مركز بلا اسم')),
                h('td', {}, f.header.B || '—'), h('td', {}, f.header.C || '—'), h('td', {}, f.header.E || '—'), h('td', {}, f.header.F || '—'),
                h('td', { title: f.filled + ' من ' + r.data.items }, h('span', { class: 'meter' }, h('i', { style: 'width:' + pct + '%' })), ' ', pct + '%'),
                h('td', {}, when(f.updated_at)),
                h('td', {}, bin
                    ? h('button', { class: 'btn', onclick: async () => { await api('POST', `/api/forms/${f.id}/restore`); toast('استُرجعت الاستمارة'); showList(true); } }, 'استرجاع')
                    : h('a', { class: 'btn', href: `/api/forms/${f.id}.xlsx` }, 'Excel')));
        }));
        if (!rows.length) body.replaceChildren(h('tr', {}, h('td', { colspan: 9, class: 'c muted' }, 'لا نتائج.')));
    };
    search.addEventListener('input', draw);
    const head = ['#', 'المركز الصحي', 'المحافظة', 'القطاع', 'الهيكل', 'التاريخ', 'الإنجاز', 'آخر تعديل', ''];
    view.replaceChildren(h('div', { class: 'wrap' },
        h('h1', { style: 'font-size:20px;margin:4px 0 12px' }, bin ? 'الاستمارات المحذوفة' : schema.title),
        h('div', { class: 'tools' },
            search,
            h('span', { class: 'grow' }),
            bin ? h('a', { class: 'btn', href: '#/' }, 'رجوع إلى الاستمارات')
                : [h('a', { class: 'btn navy', href: '/api/export.xlsx' }, 'تنزيل كل الاستمارات Excel'), h('a', { class: 'btn', href: '#/bin' }, 'المحذوفات')]),
        forms.length
            ? h('div', { class: 'list-scroll' }, h('table', { class: 'list' }, h('thead', {}, h('tr', {}, head.map((x) => h('th', {}, x)))), body))
            : h('div', { class: 'empty' }, bin ? 'لا توجد استمارات محذوفة.' : h('div', {}, h('p', {}, 'لا توجد استمارات بعد.'), h('button', { class: 'btn light', onclick: newForm }, '+ املأ أول استمارة')))));
    if (forms.length) draw();
}

async function newForm() {
    const r = await api('POST', '/api/forms', { values: {} });
    if (!r.ok) return toast(r.data?.error || 'تعذّر إنشاء الاستمارة');
    location.hash = '#/f/' + r.data.id;
}

// ---------------------------------------------------------------- one form, like the sheet
const key = (row, col) => row + '|' + col;
const valueOf = (row, col) => open.values['r' + row]?.[col] ?? '';
function setValue(values, row, col, v) {
    const rk = 'r' + row;
    if (v === '' || v === null) { if (values[rk]) { delete values[rk][col]; if (!Object.keys(values[rk]).length) delete values[rk]; } }
    else (values[rk] ??= {})[col] = v;
}

const tint = (el) => { el.classList.toggle('v-yes', el.value === 'نعم'); el.classList.toggle('v-no', el.value === 'كلا'); };
function cellInput(row, col, kind, label) {
    const v = valueOf(row, col);
    const common = { class: 'cell', 'data-k': key(row, col), 'aria-label': label };
    let el;
    if (kind === 'yesno') {
        el = h('select', common, h('option', { value: '' }, '—'), h('option', { value: 'نعم' }, 'نعم'), h('option', { value: 'كلا' }, 'كلا'));
        el.value = v;
        tint(el);
        el.addEventListener('change', () => tint(el));
    } else if (kind === 'text') {
        el = h('textarea', { ...common, rows: 2, maxlength: 2000 });
        el.value = v;
    } else if (kind === 'date') {
        el = h('input', { ...common, type: 'date' });
        el.value = v;
    } else {
        el = h('input', { ...common, type: 'text', maxlength: 120, inputmode: kind === 'number' ? 'decimal' : null, dir: 'auto' });
        el.value = v;
    }
    el.addEventListener(kind === 'yesno' || kind === 'date' ? 'change' : 'input', () => edited(row, col, el.value.trim()));
    return el;
}

function sheetTable() {
    const cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    const colgroup = h('colgroup', {}, h('col', { style: 'width:44px' }), h('col', { class: 'c-item', style: 'width:26%' }), cols.slice(2).map(() => h('col', {})));
    const rows = [h('tr', {}, h('th', { class: 'h title', colspan: 8 }, schema.title))];
    // The centre's details
    const hdr = Object.fromEntries(schema.header.map((f) => [f.col, f]));
    rows.push(h('tr', {}, cols.map((c, i) => (hdr[c] ? h('th', { class: 'h' + (i === 1 ? ' item-h' : '') }, hdr[c].label) : h('th', { class: 'h' + (i === 0 ? ' no-h' : '') })))));
    rows.push(h('tr', {}, cols.map((c, i) => (hdr[c]
        ? h('td', { class: 'in orange' + (i === 1 ? ' item' : '') }, cellInput(schema.header_row, c, hdr[c].kind, hdr[c].label))
        : h('td', { class: 'gap' + (i === 0 ? ' no' : '') })))));
    for (const s of schema.sections) {
        rows.push(h('tr', {}, h('th', { class: 'h sec', colspan: 8 }, s.title)));
        let used = 2;
        const head = [h('th', { class: 'h no-h' }, s.labels[0] || 'الرقم'), h('th', { class: 'h item-h' }, s.labels[1] || 'العنصر')];
        for (const c of s.cols) { head.push(h('th', { class: 'h', colspan: c.span > 1 ? c.span : null }, c.label)); used += c.span; }
        if (used < 8) head.push(h('th', { class: 'h', colspan: 8 - used }));
        rows.push(h('tr', {}, head));
        for (const it of s.items) {
            const tds = [h('td', { class: 'no' }, it.no), h('td', { class: 'item' }, it.label)];
            for (const c of s.cols) tds.push(h('td', { class: 'in', colspan: c.span > 1 ? c.span : null }, cellInput(it.row, c.col, c.kind, it.label + ' — ' + c.label)));
            if (used < 8) tds.push(h('td', { colspan: 8 - used, style: 'border:0' }));
            rows.push(h('tr', {}, tds));
        }
    }
    return h('div', { class: 'sheet-scroll' }, h('table', { class: 'sheet' }, colgroup, h('tbody', {}, rows)));
}

function setStatus(text, cls = '') {
    const s = $('#save-status');
    if (s) { s.textContent = text; s.className = 'status ' + cls; }
}

async function showForm(id) {
    stopForm();
    view.replaceChildren(h('p', { class: 'muted pad' }, 'يحمّل…'));
    const r = await api('GET', '/api/forms/' + id);
    if (!r.ok) { view.replaceChildren(h('div', { class: 'wrap' }, h('p', { class: 'status err' }, r.data?.error || 'تعذّر التحميل.'), h('a', { class: 'btn', href: '#/' }, 'رجوع'))); return; }
    open = r.data;
    dirty = new Map();
    document.title = (open.header.D || 'استمارة') + ' — ' + schema.title;
    const deleted = !!open.deleted_at;
    view.replaceChildren(h('div', { class: 'wrap' },
        h('div', { class: 'tools' },
            h('a', { class: 'btn', href: '#/' }, '→ الاستمارات'),
            h('span', { id: 'save-status', class: 'status' }, deleted ? '' : 'كل تغيير يُحفظ تلقائياً'),
            h('span', { class: 'grow' }),
            h('a', { class: 'btn navy', href: `/api/forms/${open.id}.xlsx` }, 'تنزيل Excel'),
            h('button', { class: 'btn', onclick: () => window.print() }, 'طباعة'),
            h('button', { class: 'btn', onclick: showHistory }, 'سجل التعديلات'),
            deleted
                ? h('button', { class: 'btn light', onclick: async () => { await api('POST', `/api/forms/${open.id}/restore`); toast('استُرجعت'); showForm(open.id); } }, 'استرجاع الاستمارة')
                : h('button', { class: 'btn danger', onclick: removeForm }, 'حذف')),
        deleted ? h('p', { class: 'note' }, 'هذه الاستمارة في المحذوفات. استرجعها لتعديلها.') : null,
        sheetTable()));
    if (deleted) view.querySelectorAll('.cell').forEach((el) => (el.disabled = true));
    else poll = setInterval(refresh, 15000);
}

function stopForm() {
    clearInterval(poll); poll = null;
    if (open && dirty.size) save();
}

function edited(row, col, v) {
    setValue(open.values, row, col, v);
    dirty.set(key(row, col), v);
    setStatus('يحفظ…');
    clearTimeout(timer);
    timer = setTimeout(save, 700);
}

async function save() {
    if (!open) return;
    if (saving) { again = true; return; }
    saving = true;
    const form = open;
    const sent = new Map(dirty);
    const r = await api('PUT', '/api/forms/' + form.id, { version: form.version, values: form.values }).catch(() => ({ status: 0 }));
    saving = false;
    if (r.ok) {
        form.version = r.data.version;
        for (const [k, v] of sent) if (dirty.get(k) === v) dirty.delete(k);
        if (form === open) setStatus('حُفظ ' + new Date().toLocaleTimeString('ar-IQ', { timeStyle: 'short' }), 'ok');
    } else if (r.status === 409) {
        // Someone saved meanwhile: take theirs, put our changed cells back on top, and save again.
        const theirs = r.data;
        for (const [k, v] of dirty) { const [row, col] = k.split('|'); setValue(theirs.values, row, col, v); }
        form.values = theirs.values;
        form.version = theirs.version;
        if (form === open) { redraw(); toast('عدّل شخص آخر هذه الاستمارة؛ دُمجت التعديلات'); }
        again = true;
    } else {
        if (form === open) setStatus(r.status === 0 ? 'لا اتصال — سيُعاد الحفظ' : (r.data?.error || 'لم يُحفظ'), 'err');
        clearTimeout(timer);
        timer = setTimeout(save, 5000);
        return;
    }
    if (again || dirty.size) { again = false; if (dirty.size || r.status === 409) save(); }
}

// Changes from others, shown without touching the cell being typed in.
function redraw() {
    view.querySelectorAll('.cell').forEach((el) => {
        if (el === document.activeElement) return;
        const [row, col] = el.dataset.k.split('|');
        const v = valueOf(row, col);
        if (el.value !== v) {
            el.value = v;
            tint(el);
            const td = el.closest('td');
            td.classList.remove('changed'); void td.offsetWidth; td.classList.add('changed');
        }
    });
}

async function refresh() {
    if (!open || saving || dirty.size || document.hidden) return;
    const r = await api('GET', '/api/forms/' + open.id).catch(() => null);
    if (!r?.ok || !open || r.data.id !== open.id || dirty.size || saving) return;
    if (r.data.version !== open.version) {
        open.values = r.data.values;
        open.version = r.data.version;
        redraw();
        setStatus('حُدّثت بتعديلات شخص آخر', 'ok');
    }
}

async function removeForm() {
    if (!confirm('حذف هذه الاستمارة؟ تذهب إلى «المحذوفات» ويمكن استرجاعها.')) return;
    clearTimeout(timer);
    if (dirty.size) await save();
    await api('POST', `/api/forms/${open.id}/delete`);
    open = null;
    toast('حُذفت الاستمارة (موجودة في المحذوفات)');
    location.hash = '#/';
}

async function showHistory() {
    const r = await api('GET', `/api/forms/${open.id}/history`);
    const list = h('ul', { class: 'hist' }, (r.data?.revisions || []).map((x, i) => h('li', {},
        h('span', { class: 'grow' }, when(x.saved_at), ' · ', x.filled + ' عنصراً', x.note ? ' · ' + x.note : '', h('span', { class: 'muted' }, ' · جهاز ' + x.who)),
        i === 0 ? h('span', { class: 'muted' }, 'الحالية') : h('button', { class: 'btn', onclick: async () => {
            if (!confirm('إرجاع الاستمارة إلى هذه النسخة؟ (تبقى النسخة الحالية في السجل)')) return;
            const res = await api('POST', `/api/forms/${open.id}/history/${x.id}/restore`);
            if (res.ok) { $('#dlg').close(); toast('استُرجعت النسخة'); showForm(open.id); }
        } }, 'استرجاع'))));
    $('#dlg-body').replaceChildren(h('h2', { style: 'font-size:17px;margin:0 0 8px' }, 'سجل تعديلات الاستمارة'),
        h('p', { class: 'muted', style: 'font-size:13px;margin:0 0 8px' }, 'كل حفظ يُسجَّل هنا؛ يمكن إرجاع الاستمارة إلى أي نسخة سابقة.'), list);
    $('#dlg').showModal();
}

// ---------------------------------------------------------------- start
function route() {
    const m = location.hash.match(/^#\/f\/(\d+)/);
    if (m) return showForm(+m[1]);
    if (location.hash === '#/bin') return showList(true);
    return showList(false);
}
$('#new').addEventListener('click', newForm);
window.addEventListener('hashchange', route);
window.addEventListener('beforeunload', (e) => { if (dirty.size) { save(); e.preventDefault(); e.returnValue = ''; } });
document.addEventListener('visibilitychange', () => { if (document.hidden && dirty.size) save(); });
(async () => {
    const r = await api('GET', '/api/schema');
    if (!r.ok) { view.replaceChildren(h('p', { class: 'pad status err' }, 'تعذّر تحميل الاستمارة.')); return; }
    schema = r.data;
    route();
})();
