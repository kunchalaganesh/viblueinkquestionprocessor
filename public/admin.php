<?php
// C:\mcq-extractor\public\admin.php
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>MCQ Admin – Upload • Convert • View</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
  :root{ --bg:#0b1220; --card:#121a2b; --muted:#9db2d0; --text:#e8f0ff; }
  *{box-sizing:border-box;}
  html,body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Arial;}
  .shell{max-width:1200px;margin:24px auto;padding:0 16px;}
  .card{background:var(--card);border:1px solid #23314e;border-radius:14px;padding:16px 18px;box-shadow:0 8px 30px rgba(0,0,0,.25);}
  h1{margin:0 0 10px;font-size:18px;color:#bfe1ff;}
  .row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
  .btn, input[type=file], select{border:1px solid #2b3b5b;background:#0f1627;color:var(--text);padding:8px 12px;border-radius:10px;cursor:pointer;}
  .btn:hover{border-color:#3a4f79}
  .muted{color:var(--muted)}
  .log{white-space:pre-wrap;font-family:ui-monospace,Consolas,monospace;font-size:12px;background:#0e1628;border-radius:10px;border:1px solid #23314e;padding:10px;margin-top:10px;max-height:180px;overflow:auto}
  iframe{width:100%;height:78vh;border:1px solid #23314e;border-radius:14px;background:#0b1220}
  a.link{color:#9dd0ff;text-decoration:none;border-bottom:1px dashed #3a4f79}
</style>
</head>
<body>
  <div class="shell">
    <div class="card">
      <h1>Upload → Convert → View</h1>
      <div class="row">
        <input id="doc" type="file" accept=".docx" />
        <label class="muted">OCR:</label>
        <select id="ocr">
          <option value="off" selected>Off (fast)</option>
          <option value="auto">Auto</option>
        </select>
        <button id="go" class="btn">Convert & View</button>
        <span id="status" class="muted"></span>
      </div>
      <div id="log" class="log" style="display:none"></div>
    </div>

    <div style="height:14px"></div>

    <div class="card">
      <div class="row" style="justify-content:space-between;align-items:center;margin-bottom:8px">
        <div class="muted" id="meta">No session loaded.</div>
        <div class="muted"><span id="links"></span></div>
      </div>
      <iframe id="viewer" src="about:blank" title="MCQ Viewer"></iframe>
    </div>
  </div>

<script>
(() => {
  const doc = document.getElementById('doc');
  const ocr = document.getElementById('ocr');
  const go = document.getElementById('go');
  const statusEl = document.getElementById('status');
  const logEl = document.getElementById('log');
  const viewer = document.getElementById('viewer');
  const meta = document.getElementById('meta');
  const links = document.getElementById('links');

  function setStatus(s){ statusEl.textContent = s || ''; }
  function addLog(s){
    logEl.style.display = 'block';
    logEl.textContent += s + "\n";
    logEl.scrollTop = logEl.scrollHeight;
  }

  go.onclick = async () => {
    if (!doc.files || !doc.files[0]) { alert('Choose a .docx first'); return; }
    setStatus('Uploading & converting…');
    logEl.textContent = ''; logEl.style.display = 'none';
    links.textContent = '';

    const form = new FormData();
    form.append('doc', doc.files[0]);
    form.append('ocr', ocr.value);

    try {
      const res = await fetch('process.php?t=' + Date.now(), { method:'POST', body: form });
      const raw = await res.text();

      let js;
      try { js = JSON.parse(raw); }
      catch (e) { setStatus('Error'); addLog('Server returned non-JSON. Raw response:'); addLog(raw); return; }

      if (!js.ok) {
        setStatus('Error');
        addLog('ERROR: ' + (js.error || 'unknown'));
        if (js.details) addLog(js.details);
        if (js.logs) js.logs.forEach(addLog);
        return;
      }

      setStatus(`OK • ${js.items} items • session ${js.session}`);
      meta.textContent = `Session: ${js.session} • Items: ${js.items} • WMF/EMF→PNG: ${js.wmf2png}/${js.wmfFound}`;
      if (js.logs) js.logs.forEach(addLog);

      links.innerHTML = `
        <a class="link" href="sessions/${encodeURIComponent(js.session)}/full.html" target="_blank">full.html</a> &nbsp;|&nbsp;
        <a class="link" href="sessions/${encodeURIComponent(js.session)}/questions.json" target="_blank">questions.json</a>
      `;
      viewer.src = 'viewer.php?session=' + encodeURIComponent(js.session) + '&nocache=' + Date.now();
    } catch (e) {
      setStatus('Error'); addLog('Fetch exception: ' + e);
    }
  };
})();
</script>
</body>
</html>
