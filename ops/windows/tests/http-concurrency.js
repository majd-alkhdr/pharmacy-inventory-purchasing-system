// قياس بلا تسجيل دخول (آمن على القاعدة الحية: GET /login فقط، بكوكي واحد فلا تُنشأ جلسة لكل طلب).
// node http-concurrency.js <base> [heavyUrl]
//   1) /login تسلسلياً ×10 (وسيط/أدنى/أقصى)  2) 8 طلبات /login متوازية  3) /login أثناء طلب ثقيل (إن أُعطي).
const base = process.argv[2] || 'http://127.0.0.1:8081';
const heavyUrl = process.argv[3];
let cookie = '';

async function get(url) {
    const t0 = performance.now();
    const res = await fetch(url, { redirect: 'manual', headers: { cookie, 'accept-encoding': 'gzip' } });
    const body = await res.arrayBuffer();
    const set = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    if (set.length) cookie = set.map(c => c.split(';')[0]).join('; ');
    return { status: res.status, ms: Math.round(performance.now() - t0), bytes: body.byteLength };
}
const stats = a => { const s = [...a].sort((x, y) => x - y); return { median: s[Math.floor(s.length / 2)], min: s[0], max: s[s.length - 1] }; };

(async () => {
    const out = { base };
    await get(base + '/login'); // تسخين + كوكي الجلسة
    const serial = [];
    for (let i = 0; i < 10; i++) serial.push(await get(base + '/login'));
    out.serial = { statuses: [...new Set(serial.map(r => r.status))], ...stats(serial.map(r => r.ms)) };

    const t0 = performance.now();
    const par = await Promise.all(Array.from({ length: 8 }, () => get(base + '/login')));
    out.parallel8 = { wall: Math.round(performance.now() - t0), each: par.map(r => r.ms), statuses: [...new Set(par.map(r => r.status))] };

    if (heavyUrl) {
        const th = performance.now();
        const heavy = get(heavyUrl);
        await new Promise(r => setTimeout(r, 1000));
        const during = [];
        for (let i = 0; i < 5; i++) during.push(await get(base + '/login'));
        const lightDoneAt = Math.round(performance.now() - th);
        const h = await heavy;
        out.duringHeavy = { heavy_ms: h.ms, heavy_status: h.status, light: during.map(r => r.ms), light_statuses: [...new Set(during.map(r => r.status))], light_done_at: lightDoneAt };
    }
    console.log(JSON.stringify(out));
})().catch(e => { console.error(e.message); process.exit(1); });
