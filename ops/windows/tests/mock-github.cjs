// خادم GitHub وهمي لاختبار نشر tunnel-supervisor.ps1 بلا توكن حقيقي ولا مستودع حقيقي.
// node mock-github.cjs <port> <expectedToken> [--conflict N] [--drop N] [--state file.json]
//   --conflict N : أول N طلبات PUT تُرد بـ409 (تعارض sha) كأن أحداً عدّل الملف بينهما.
//   --drop N     : أول N اتصالات تُقطع بلا رد (فشل شبكة مؤقت).
// GET /__state يعيد ما سُجّل (للاختبار): المحتوى الحالي، عدد الطلبات، وهل وصل التوكن صحيحاً.
const http = require('http');
const crypto = require('crypto');
const fs = require('fs');

const port = +process.argv[2];
const expected = process.argv[3];
const opt = name => { const i = process.argv.indexOf(name); return i > 0 ? process.argv[i + 1] : undefined; };
let conflicts = +(opt('--conflict') || 0);
let drops = +(opt('--drop') || 0);
const stateFile = opt('--state');

const state = { file: null, sha: null, gets: 0, puts: 0, conflictsSent: 0, dropped: 0, badAuth: 0, history: [] };
const save = () => stateFile && fs.writeFileSync(stateFile, JSON.stringify(state, null, 1));

http.createServer((req, res) => {
    if (req.url === '/__state') { res.setHeader('content-type', 'application/json'); return res.end(JSON.stringify(state)); }
    if (drops > 0) { drops--; state.dropped++; save(); return req.socket.destroy(); }
    const send = (code, obj) => { res.writeHead(code, { 'content-type': 'application/json' }); res.end(JSON.stringify(obj)); };
    if (req.headers.authorization !== `Bearer ${expected}`) { state.badAuth++; save(); return send(401, { message: 'Bad credentials' }); }
    if (!/^\/repos\/[^/]+\/[^/]+\/contents\/url\.json/.test(req.url)) return send(404, { message: 'Not Found' });

    let body = '';
    req.on('data', c => body += c);
    req.on('end', () => {
        if (req.method === 'GET') {
            state.gets++; save();
            if (!state.file) return send(404, { message: 'Not Found' });
            return send(200, { sha: state.sha, content: Buffer.from(state.file).toString('base64').replace(/(.{60})/g, '$1\n'), encoding: 'base64' });
        }
        if (req.method === 'PUT') {
            state.puts++;
            const b = JSON.parse(body);
            if (conflicts > 0) {
                conflicts--; state.conflictsSent++;
                // محاكاة تعديل من طرف آخر بين القراءة والكتابة: يتغير الملف وsha فيصبح sha الطلب قديماً.
                state.file = JSON.stringify({ url: 'https://someone-else-' + crypto.randomBytes(3).toString('hex') + '.trycloudflare.com', updated: new Date().toISOString() });
                state.sha = crypto.createHash('sha1').update(state.file).digest('hex');
                save();
                return send(409, { message: 'url.json does not match ' + (b.sha || '') });
            }
            if ((b.sha || null) !== state.sha) { save(); return send(409, { message: 'sha mismatch' }); }
            state.file = Buffer.from(b.content, 'base64').toString('utf8');
            state.sha = crypto.createHash('sha1').update(state.file).digest('hex');
            state.history.push({ at: new Date().toISOString(), file: state.file, message: b.message, branch: b.branch });
            save();
            return send(state.history.length === 1 ? 201 : 200, { content: { sha: state.sha } });
        }
        send(405, { message: 'method' });
    });
}).listen(port, '127.0.0.1', () => console.log(`mock github on ${port}`));
