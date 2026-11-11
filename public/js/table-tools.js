/*
 * 🎯 أداة موحّدة للبحث والفرز والفلترة بجداول المواد — بالمتصفح بلا أي طلب شبكة، وبلا اعتماديات.
 *
 * عقد الـHTML (راجع resources/views/components/table-toolbar.blade.php):
 *   <table data-tt-table="key" data-tt-scope="{id}" [data-tt-keep-scroll]>
 *   <tr data-tt-row data-tt-id data-tt-search data-tt-s-{col} data-tt-f-{filter}="token token">
 *   <tr data-tt-companion-of="{id}">        ← يتبع أمّه بالإخفاء والفرز
 *   <th data-tt-sort="{col}" data-tt-type="num|text">
 *   <td data-tt-index>                       ← ترقيم الظاهر فقط
 *   <input data-tt-live="{col}">             ← الفرز يقرأ قيمته الحالية
 *   <input data-tt-edit-base="{n}" data-tt-edit-filter="{filter}">  ← "معدَّل" يُحدَّث عند التغيير
 *
 * الإخفاء بالخاصية hidden فقط — حقول الصفوف المخفية تبقى مُرسَلة مع النموذج.
 */
(function () {
    'use strict';

    const DIGITS = { '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9',
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9' };

    // تطبيع بحث متساهل عمداً (غير MaterialNameKey الصارم بالخادم — لا يُستبدل أحدهما بالآخر).
    function normalize(text) {
        return String(text || '')
            .replace(/[ً-ٰٟـ]/g, '')
            .replace(/[أإآٱ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
            .replace(/[٠-٩۰-۹]/g, d => DIGITS[d])
            .toLowerCase().replace(/\s+/g, ' ').trim();
    }

    const collator = new Intl.Collator('ar', { numeric: true, sensitivity: 'base' });

    const storage = {
        get(key) { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (e) { return null; } },
        set(key, value) { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* بلا تخزين: تعمل الأداة عادياً */ } },
        remove(key) { try { sessionStorage.removeItem(key); } catch (e) { /* ignore */ } },
    };

    function init(table) {
        const key = table.dataset.ttTable;
        const toolbar = document.querySelector(`[data-tt-toolbar="${key}"]`);
        const tbody = table.tBodies[0];
        if (!toolbar || !tbody) return;

        const storageKey = `tt:${key}:${table.dataset.ttScope || ''}`;
        const search = toolbar.querySelector('[data-tt-search-input]');
        const selects = Array.from(toolbar.querySelectorAll('[data-tt-filter]'));
        const counter = toolbar.querySelector('[data-tt-count]');
        const clearBtn = toolbar.querySelector('[data-tt-clear]');
        const headers = Array.from(table.querySelectorAll('th[data-tt-sort]'));

        // تجهيز مرة واحدة: الصفوف ونصوص بحثها المطبَّعة ومرافقوها وترتيبها الأصلي.
        const companions = {};
        tbody.querySelectorAll('tr[data-tt-companion-of]').forEach(tr => {
            (companions[tr.dataset.ttCompanionOf] = companions[tr.dataset.ttCompanionOf] || []).push(tr);
        });
        const rows = Array.from(tbody.querySelectorAll('tr[data-tt-row]')).map((tr, i) => ({
            tr, order: i, text: normalize(tr.dataset.ttSearch),
            companions: companions[tr.dataset.ttId] || [],
            index: tr.querySelector('[data-tt-index]'),
            visible: true,
        }));
        const types = {};
        headers.forEach(th => { types[th.dataset.ttSort] = th.dataset.ttType === 'num' ? 'num' : 'text'; });

        let state = { q: '', f: {}, sort: null };
        let emptyRow = null;

        function sortValue(row, col) {
            const live = row.tr.querySelector(`[data-tt-live="${col}"]`);
            const raw = live ? live.value : row.tr.getAttribute(`data-tt-s-${col}`);
            if (types[col] !== 'num') return raw || '';
            return raw === null || raw === '' || isNaN(Number(raw)) ? null : Number(raw);
        }

        function matches(row, tokens) {
            for (const t of tokens) if (!row.text.includes(t)) return false;
            for (const [filter, value] of Object.entries(state.f)) {
                if (!value) continue;
                const have = (row.tr.getAttribute(`data-tt-f-${filter}`) || '').split(/\s+/);
                if (!have.includes(value)) return false;
            }
            return true;
        }

        function applySort() {
            const sort = state.sort;
            const sorted = rows.slice();
            if (sort && types[sort.col]) {
                const dir = sort.dir === 'desc' ? -1 : 1;
                const values = new Map(rows.map(r => [r, sortValue(r, sort.col)]));
                sorted.sort((a, b) => {
                    const va = values.get(a), vb = values.get(b);
                    // الفارغ دائماً بالآخر، والترتيب الأصلي كاسر تعادل (فرز مستقر).
                    if (va === null || vb === null) return (va === null) - (vb === null) || a.order - b.order;
                    const c = types[sort.col] === 'num' ? va - vb : collator.compare(va, vb);
                    return c * dir || a.order - b.order;
                });
            } else {
                sorted.sort((a, b) => a.order - b.order);
            }

            const fragment = document.createDocumentFragment();
            sorted.forEach(r => { fragment.appendChild(r.tr); r.companions.forEach(c => fragment.appendChild(c)); });
            tbody.appendChild(fragment);
            rows.splice(0, rows.length, ...sorted); // ترتيب العرض ← الترقيم يتبعه

            headers.forEach(th => {
                if (sort && th.dataset.ttSort === sort.col) th.setAttribute('aria-sort', sort.dir === 'desc' ? 'descending' : 'ascending');
                else th.removeAttribute('aria-sort');
            });
        }

        function applyFilter() {
            const tokens = normalize(state.q).split(' ').filter(Boolean);
            let shown = 0;
            for (const r of rows) {
                const visible = matches(r, tokens);
                if (visible !== r.visible) {
                    r.visible = visible;
                    r.tr.hidden = !visible;
                    r.companions.forEach(c => { c.hidden = !visible; });
                }
                if (visible) {
                    shown++;
                    if (r.index) r.index.textContent = shown;
                }
            }

            counter.textContent = `يعرض ${shown} من ${rows.length}`;
            const active = !!(state.q || Object.values(state.f).some(Boolean) || state.sort);
            clearBtn.hidden = !active;
            toggleEmpty(shown === 0 && rows.length > 0);
        }

        function toggleEmpty(show) {
            if (show && !emptyRow) {
                const cols = table.tHead ? table.tHead.rows[table.tHead.rows.length - 1].cells.length : 1;
                emptyRow = document.createElement('tr');
                emptyRow.className = 'tt-empty-row';
                emptyRow.innerHTML = `<td colspan="${cols}" class="text-center text-muted py-3">لا أصناف تطابق البحث الحالي — <a href="#" data-tt-clear-link>مسح الكل</a></td>`;
                emptyRow.querySelector('[data-tt-clear-link]').addEventListener('click', e => { e.preventDefault(); clearAll(); });
            }
            if (show) tbody.appendChild(emptyRow);
            else if (emptyRow && emptyRow.parentNode) emptyRow.remove();
        }

        function save() { storage.set(storageKey, state); }

        function update(resort) {
            if (resort) applySort();
            applyFilter();
            save();
        }

        function clearAll() {
            state = { q: '', f: {}, sort: null };
            search.value = '';
            selects.forEach(s => { s.value = ''; });
            update(true);
        }

        // ── الأحداث ──
        let timer = null;
        search.addEventListener('input', () => {
            clearTimeout(timer);
            timer = setTimeout(() => { state.q = search.value; update(false); }, 150);
        });
        search.addEventListener('keydown', e => {
            if (e.key === 'Escape') { search.value = ''; state.q = ''; update(false); }
            if (e.key === 'Enter') e.preventDefault(); // لا يُرسل نموذج التعديل المحيط
        });
        selects.forEach(s => s.addEventListener('change', () => { state.f[s.dataset.ttFilter] = s.value; update(false); }));
        clearBtn.addEventListener('click', clearAll);

        headers.forEach(th => {
            th.tabIndex = 0;
            th.setAttribute('role', 'button');
            const cycle = () => {
                const col = th.dataset.ttSort;
                const cur = state.sort && state.sort.col === col ? state.sort.dir : null;
                state.sort = cur === null ? { col, dir: 'asc' } : cur === 'asc' ? { col, dir: 'desc' } : null;
                update(true);
            };
            th.addEventListener('click', cycle);
            th.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cycle(); } });
        });

        // "معدَّلة عن المقترح" تتبع القيمة الحالية للحقل.
        tbody.querySelectorAll('[data-tt-edit-base]').forEach(input => input.addEventListener('change', () => {
            const tr = input.closest('tr[data-tt-row]');
            const filter = input.dataset.ttEditFilter;
            const tokens = (tr.getAttribute(`data-tt-f-${filter}`) || '').split(/\s+/).filter(t => t && t !== 'edited');
            if (Number(input.value) !== Number(input.dataset.ttEditBase)) tokens.push('edited');
            tr.setAttribute(`data-tt-f-${filter}`, tokens.join(' '));
            update(false);
        }));

        // حفظ موضع التمرير قبل إرسال نموذج يعيد تحميل الصفحة (مثل اختيار سبب الفرق).
        // التقاط (capture) ليسبق onchange="this.form.submit()" على العنصر نفسه.
        // يُحفظ نسبةً لأعلى الجدول: رسالة النجاح بعد التحميل تُزيح الجدول للأسفل.
        const tableTop = () => table.getBoundingClientRect().top + window.scrollY;
        if (table.hasAttribute('data-tt-keep-scroll')) {
            table.addEventListener('change', e => {
                if (e.target.form && e.target.tagName === 'SELECT') storage.set(storageKey + ':scroll', window.scrollY - tableTop());
            }, true);
        }

        // ── الاستعادة ──
        const saved = storage.get(storageKey);
        if (saved && typeof saved === 'object') {
            state.q = typeof saved.q === 'string' ? saved.q : '';
            search.value = state.q;
            selects.forEach(s => {
                const v = saved.f && saved.f[s.dataset.ttFilter];
                if (v && Array.from(s.options).some(o => o.value === v)) { s.value = v; state.f[s.dataset.ttFilter] = v; }
            });
            if (saved.sort && types[saved.sort.col]) state.sort = { col: saved.sort.col, dir: saved.sort.dir === 'desc' ? 'desc' : 'asc' };
        }
        update(!!state.sort);

        const scroll = storage.get(storageKey + ':scroll');
        if (typeof scroll === 'number') {
            storage.remove(storageKey + ':scroll');
            // instant: الصفحة تستعمل scroll-behavior: smooth، ولا نريد تمريراً متحركاً من الأعلى.
            requestAnimationFrame(() => window.scrollTo({ top: tableTop() + scroll, behavior: 'instant' }));
        }
    }

    function boot() { document.querySelectorAll('table[data-tt-table]').forEach(init); }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
