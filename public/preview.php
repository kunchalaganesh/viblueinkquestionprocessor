<?php
$session = isset($_GET['session']) ? preg_replace('/[^A-Za-z0-9\-]/','', $_GET['session']) : '';
$base = $session ? ('sessions/' . $session . '/') : '';
$statusUrl = $session ? ('api.php?status=' . rawurlencode($session)) : '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Preview – MCQ</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
  :root{
    --bg:#ffffff; --text:#111827; --muted:#6b7280;
    --card:#ffffff; --line:#e5e7eb;
    --ok:#16a34a; --bad:#ef4444; --accent:#2563eb;

    --eq-size:1.6em;
    --q-img-max:220px; --opt-img-max:170px; --sol-img-max:190px;

    --img-bg:#ffffff; --img-pad:4px; --img-border:#e5e7eb;
  }
  *{ box-sizing:border-box; }
  html, body { margin:0; padding:0; background:var(--bg); color:var(--text);
    font:16px/1.65 system-ui,-apple-system,Segoe UI,Roboto,Arial; height:100%; }
  .wrap { display:grid; grid-template-columns: 280px 1fr; height:100vh; }
  .left { border-right:1px solid var(--line); overflow:auto; padding:12px; background:#fafafa; }
  .right { overflow:auto; padding:24px; }
  .title { font-weight:700; margin:2px 0 12px; color:var(--text); }
  .btn, button, select { cursor:pointer; border:1px solid var(--line); background:#fff; color:var(--text);
    padding:8px 12px; border-radius:10px; }
  .btn.small, select.small { padding:6px 8px; font-size:12px; }
  .qitem { display:block; padding:6px 10px; border-radius:8px; color:#111827; text-decoration:none; }
  .qitem.active { background:#e5efff; color:#111827; }
  .controls { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; align-items:center; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:18px;
    box-shadow:0 8px 30px rgba(0,0,0,.06); }
  .notice { margin:0 0 14px; padding:10px 12px; border:1px dashed var(--line);
    border-radius:10px; background:#fffbe6; color:#3b3a1f; display:none; }

  .blocks img {
    max-width:100%; height:auto; vertical-align:middle; margin:2px 4px;
    background: var(--img-bg); padding: var(--img-pad); border-radius:6px; border:1px solid var(--img-border);
    cursor: zoom-in;
  }
  .blocks img.zoomed { max-height:none !important; height:auto !important; cursor: zoom-out; box-shadow:0 6px 24px rgba(0,0,0,.25); }

  .qtext img { max-height:var(--q-img-max); }
  .options .blocks img { max-height:var(--opt-img-max); }
  .sol img { max-height:var(--sol-img-max); }

  .blocks math { font-size:var(--eq-size); }
  .qtext, .sol { font-size:16px; }
  .options { margin-top:14px; display:grid; gap:10px; }
  .opt { display:flex; gap:10px; align-items:flex-start; padding:10px; border:1px solid var(--line);
    border-radius:12px; background:#fff; }
  .opt.correct { outline:2px solid var(--ok); }
  .opt.incorrect { outline:2px solid var(--bad); }
  .opt label { display:flex; gap:10px; align-items:center; cursor:pointer; width:100%; }
  .opt .lbl { font-weight:700; color:#111827; width:20px; text-transform:uppercase; }
  .muted { color:var(--muted); }
  .answerRow { margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
  .pill { display:inline-block; padding:3px 8px; border-radius:999px; font-size:12px; border:1px solid var(--line); }
  .ok { color:var(--ok); border-color:var(--ok); }
  .bad { color:var(--bad); border-color:var(--bad); }
  .nav { display:flex; gap:10px; margin-top:16px; }
  .search { display:flex; gap:8px; margin:10px 0 16px; }
  .search input { flex:1; background:#fff; border:1px solid var(--line); color:#111827;
    border-radius:10px; padding:8px 10px; }
  .small { font-size:12px; }

  @media (max-width: 900px){
    .wrap { grid-template-columns: 1fr; }
    .left { position:fixed; z-index:50; top:0; bottom:0; left:0; width:82%; max-width:340px;
      transform:translateX(-100%); transition:transform .25s ease; }
    .left.open { transform:translateX(0); }
    .right { padding:16px; }
  }
</style>
</head>
<body>
<div class="wrap">
  <div class="left" id="sidebar">
    <div class="title">Questions</div>
    <div class="controls">
      <input id="file" type="file" accept=".json" class="btn" />
      <button id="reload" class="btn">Load questions.json</button>
    </div>
    <div class="search">
      <input id="qsearch" placeholder="Filter (text in question)"/>
      <button id="clear" class="btn small">Clear</button>
    </div>
    <div id="list"></div>
    <div class="small muted" style="margin-top:10px;">
      Session: <code><?php echo htmlspecialchars($session ?: 'local'); ?></code>
    </div>
  </div>

  <div class="right">
    <div id="notice" class="notice"></div>

    <div class="controls">
      <div id="meta" class="muted"></div>
      <div style="flex:1"></div>
      <label class="small muted">Image size:</label>
      <select id="imgScale" class="small">
        <option value="s">Small</option>
        <option value="m" selected>Medium</option>
        <option value="l">Large</option>
      </select>
      <button id="prev" class="btn">◀ Prev</button>
      <button id="next" class="btn">Next ▶</button>
    </div>

    <div id="panel" class="card">
      <div id="qid" class="muted small"></div>
      <div id="question" class="qtext blocks"></div>
      <div id="options" class="options"></div>

      <div class="answerRow">
        <button id="check" class="btn">Check Answer</button>
        <div id="result" class="pill muted">not answered</div>
        <button id="toggleSolution" class="btn">Show Solution</button>
      </div>

      <div id="solutionWrap" style="margin-top:14px; display:none;">
        <div class="muted small" style="margin-bottom:6px;">Solution</div>
        <div id="solution" class="sol blocks"></div>
      </div>

      <div class="nav">
        <button id="prev2" class="btn">◀ Prev</button>
        <button id="next2" class="btn">Next ▶</button>
      </div>
    </div>
  </div>
</div>

<script>
  window.MathJax = { options:{ skipHtmlTags:['script','noscript','style','textarea','pre','code'] } };
</script>
<script async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/mml-chtml.js"></script>

<script>
(() => {
  const base = <?php echo json_encode($base); ?>;
  const statusUrl = <?php echo json_encode($statusUrl); ?>;

  const $ = s => document.querySelector(s);

  // SINGLE declaration of DOM refs (fixes the crash)
  const listEl = $('#list'), metaEl = $('#meta'), qidEl = $('#qid'), notice = $('#notice');
  const qEl = $('#question'), optsEl = $('#options'), solWrap = $('#solutionWrap'), solEl = $('#solution'), resEl = $('#result');
  const prev = $('#prev'), next = $('#next'), prev2 = $('#prev2'), next2 = $('#next2'),
        checkBtn = $('#check'), toggleSol = $('#toggleSolution');
  const fileEl = $('#file'), reloadBtn = $('#reload'), qsearch = $('#qsearch'), clearBtn = $('#clear'), imgScale = $('#imgScale');

  let data = [], filteredIdx = [], cur = 0, selected = null;

  /* ---------- sizing helpers ---------- */
  function cssPx(name){
    const v=getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const m=v.match(/([\d.]+)/); return m?parseFloat(m[1]):0;
  }
  function emPx(el){
    const baseEl = el?.closest?.('.blocks') || document.body;
    const fs = parseFloat(getComputedStyle(baseEl).fontSize);
    return isNaN(fs) ? 16 : fs;
  }
  function zoneCapPx(zone){
    if (zone==='opt') return cssPx('--opt-img-max') || 170;
    if (zone==='sol') return cssPx('--sol-img-max') || 190;
    return cssPx('--q-img-max') || 220;
  }
  function stripSizeConstraints(img){
    try{
      img.removeAttribute('width'); img.removeAttribute('height');
      let st=img.getAttribute('style')||'';
      if(st){
        const newSt = st.replace(/\b(width|height)\s*:\s*[^;]+;?/ig,'').trim().replace(/^;|;$/g,'');
        if (newSt) img.setAttribute('style', newSt); else img.removeAttribute('style');
      }
      img.style.width='auto'; img.style.height='auto';
    }catch{}
  }
  function classifyFromMeta(img){
    const w = +(img.dataset.w||0) || img.naturalWidth || 0;
    const h = +(img.dataset.h||0) || img.naturalHeight|| 0;
    if (!w || !h) return 'diagram';
    const area = w*h;
    if (h <= 24 || area <= 1200) return 'glyph';
    if (h <= 100 && area <= 25000) return 'equation';
    return 'diagram';
  }
  function normalizeImage(img, zone){
    stripSizeConstraints(img);
    const cls = classifyFromMeta(img);
    const oneEm = emPx(img);
    const cap = zoneCapPx(zone);

    img.classList.remove('zoomed');
    img.style.maxHeight = ''; img.style.height = 'auto';

    if (cls === 'glyph'){ img.style.height = Math.round(1.4 * oneEm) + 'px'; return; }
    if (cls === 'equation'){ img.style.height = Math.round(1.85 * oneEm) + 'px'; return; }

    const nh = +(img.dataset.h||0) || img.naturalHeight || 0;
    if (!nh) return;
    if (nh > cap) {
      img.style.height = cap + 'px';
    } else if (nh > cap * 0.7) {
      img.style.height = Math.round(cap * 0.85) + 'px';
    } else {
      img.style.height = 'auto';
    }
  }
  function attachZoom(img){ img.addEventListener('click', ()=> img.classList.toggle('zoomed')); }
  function fixAssets(container, zone){
    container.querySelectorAll('img').forEach(img=>{
      let src=img.getAttribute('src')||'';
      if (src.startsWith('./')) src=src.slice(2);
      if (/^(?:https?:)?\/\//i.test(src) || src.startsWith('data:') || src.startsWith('/') ||
          src.startsWith(base) || src.startsWith('sessions/')) {
        img.src=src;
      } else {
        img.src=base+src;
      }
      img.loading='lazy'; img.decoding='async';
      const apply = ()=> normalizeImage(img, zone);
      if (img.complete && (img.naturalHeight || img.dataset.h)) apply();
      else img.addEventListener('load', apply, { once:true });
      attachZoom(img);
    });
  }
  function setHTML(el,html,zone){ el.innerHTML=html||''; fixAssets(el,zone); }

  /* ---------- UI ---------- */
  function renderList() {
    listEl.innerHTML = '';
    filteredIdx.forEach((idx, i) => {
      const a = document.createElement('a');
      a.href = 'javascript:void(0)';
      a.className = 'qitem' + (i===cur ? ' active' : '');
      a.textContent = `${i+1}.`;
      a.onclick = () => { cur=i; selected=null; draw(); };
      listEl.appendChild(a);
    });
    metaEl.textContent = `Showing ${filteredIdx.length} of ${data.length}`;
  }
  function renderOptions(item) {
    const letters=['a','b','c','d'];
    optsEl.innerHTML = '';
    const opts = (item.options || []).slice(0,4); while (opts.length<4) opts.push('');
    opts.forEach((optHTML, i) => {
      const wrap = document.createElement('div'); wrap.className='opt';
      const input = document.createElement('input'); input.type='radio'; input.name='opt'; input.value=letters[i];
      input.onchange = () => {
        selected = input.value;
        resEl.className='pill muted'; resEl.textContent='not checked';
        [...optsEl.children].forEach(c=>c.classList.remove('correct','incorrect'));
      };
      const lbl = document.createElement('div'); lbl.className='lbl'; lbl.textContent = letters[i].toUpperCase();
      const body = document.createElement('div'); body.className='blocks'; setHTML(body, optHTML, 'opt');
      const lab = document.createElement('label'); lab.appendChild(input); lab.appendChild(lbl); lab.appendChild(body);
      wrap.appendChild(lab); optsEl.appendChild(wrap);
    });
  }
  async function typesetMath() { try { if (window.MathJax && MathJax.typesetPromise) { await MathJax.typesetPromise([qEl, solEl, optsEl]); } } catch(e) { console.warn(e); } }
  async function draw() {
    if (!filteredIdx.length) return;
    const idx = filteredIdx[cur];
    const item = data[idx];
    document.querySelectorAll('.qitem').forEach((el,i)=> el.classList.toggle('active', i===cur));
    qidEl.textContent = item.qid ? `ID: ${item.qid}` : '';
    setHTML(qEl, item.question, 'q');
    renderOptions(item);
    solWrap.style.display = 'none';
    setHTML(solEl, item.solutions, 'sol');
    resEl.className = 'pill muted'; resEl.textContent='not answered';
    await typesetMath();
  }
  function checkAnswer() {
    if (!filteredIdx.length) return;
    const item = data[filteredIdx[cur]];
    if (!selected) { resEl.className='pill bad'; resEl.textContent='select an option'; return; }
    const correct = (item.answer||'').toLowerCase();
    const ok = correct && selected===correct;
    resEl.className = 'pill ' + (ok ? 'ok' : 'bad');
    resEl.textContent = ok ? `correct: ${correct.toUpperCase()}` : `wrong (correct: ${correct.toUpperCase()})`;
    [...optsEl.children].forEach((div,i)=>{
      const val = ['a','b','c','d'][i];
      div.classList.remove('correct','incorrect');
      if (val===correct) div.classList.add('correct');
      if (val===selected && val!==correct) div.classList.add('incorrect');
    });
  }
  function applyFilter() {
    const q = (qsearch.value||'').toLowerCase();
    filteredIdx = [];
    data.forEach((item,i)=>{
      const plain = (item.question||'').replace(/<[^>]+>/g,' ').toLowerCase();
      if (!q || plain.includes(q)) filteredIdx.push(i);
    });
    cur=0; selected=null; renderList(); if (filteredIdx.length) draw(); else {
      qidEl.textContent=''; qEl.textContent='No results.'; optsEl.innerHTML='';
      resEl.className='pill muted'; resEl.textContent='not answered'; solWrap.style.display='none'; solEl.innerHTML='';
    }
  }
  function reNormalizeAll(){
    document.querySelectorAll('.qtext img, .options .blocks img, .sol img').forEach(img=>{
      const zone = img.closest('.options') ? 'opt' : (img.closest('.sol') ? 'sol' : 'q');
      normalizeImage(img, zone);
    });
  }
  function setScale(mode){
    const root = document.documentElement.style;
    if (mode === 's'){ root.setProperty('--eq-size','1.3em'); root.setProperty('--q-img-max','170px'); root.setProperty('--opt-img-max','135px'); root.setProperty('--sol-img-max','150px'); }
    else if (mode === 'l'){ root.setProperty('--eq-size','1.9em'); root.setProperty('--q-img-max','260px'); root.setProperty('--opt-img-max','210px'); root.setProperty('--sol-img-max','230px'); }
    else { root.setProperty('--eq-size','1.6em'); root.setProperty('--q-img-max','220px'); root.setProperty('--opt-img-max','170px'); root.setProperty('--sol-img-max','190px'); }
    reNormalizeAll();
  }

  const goPrev = ()=>{ if(cur>0){cur--; selected=null; draw();} };
  const goNext = ()=>{ if(cur<filteredIdx.length-1){cur++; selected=null; draw();} };
  prev.onclick = prev2.onclick = goPrev; next.onclick = next2.onclick = goNext;
  checkBtn.onclick = checkAnswer;
  toggleSol.onclick = ()=>{ solWrap.style.display = (solWrap.style.display==='none'?'block':'none'); typesetMath(); };
  qsearch.oninput = applyFilter; clearBtn.onclick = ()=>{ qsearch.value=''; applyFilter(); };
  imgScale.onchange = (e)=> setScale(e.target.value); setScale('m');

  async function loadJSON(){
    const url = base + 'questions.json?nocache=' + Date.now();
    const res = await fetch(url, {cache:'no-store'});
    if (!res.ok) throw new Error('fetch failed: ' + res.status);
    return res.json();
  }

  async function pollUntilReady(){
    if (!statusUrl) return;
    try{
      const r = await fetch(statusUrl, {cache:'no-store'});
      const js = await r.json();
      if (js.state === 'done'){
        notice.style.display='none';
        data = await loadJSON();
        filteredIdx = data.map((_,i)=>i);
        renderList(); await draw();
        metaEl.textContent = `Showing ${filteredIdx.length} of ${data.length}`;
        return;
      }
      if (js.state === 'error'){
        notice.style.display='block';
        notice.textContent = 'Error: ' + (js.message || 'conversion failed');
        return;
      }
      notice.style.display='block';
      notice.textContent = (js.message || 'Processing…') + (js.progress!=null ? ` (${js.progress}%)` : '');
    }catch(e){
      notice.style.display='block';
      notice.textContent = 'Waiting for converter…';
      console.warn(e);
    }
    setTimeout(pollUntilReady, 1500);
  }

  async function loadDefault() {
    if (!base) return;
    try {
      data = await loadJSON();
      filteredIdx = data.map((_,i)=>i);
      renderList(); await draw();
      metaEl.textContent = `Showing ${filteredIdx.length} of ${data.length}`;
    } catch {
      await pollUntilReady();
    }
  }

  reloadBtn.onclick = loadDefault;
  fileEl.onchange = e => {
    const f = e.target.files?.[0]; if (!f) return;
    const reader = new FileReader();
    reader.onload = async () => {
      try { data = JSON.parse(reader.result); filteredIdx = data.map((_,i)=>i); renderList(); await draw(); }
      catch (err) { alert('Invalid JSON: ' + err); }
    };
    reader.readAsText(f);
  };

  loadDefault();
})();
</script>
</body>
</html>
