<?php
// C:\mcq-extractor\public\preview.php
$session = isset($_GET['session']) ? preg_replace('/[^A-Za-z0-9\-]/','', $_GET['session']) : '';
$base = $session ? ('sessions/' . $session . '/') : '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Preview – MCQ</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<style>
  :root{
    /* clean white UI */
    --bg:#ffffff; --text:#111827; --muted:#6b7280;
    --card:#ffffff; --line:#e5e7eb;
    --ok:#16a34a; --bad:#ef4444; --accent:#2563eb;

    /* zone caps (JS may render slightly below these) */
    --eq-size:1.6em;
    --q-img-max:200px;  /* was 220 */
    --opt-img-max:150px;/* was 170 */
    --sol-img-max:170px;/* was 190 */

    --img-bg:#ffffff; --img-pad:4px; --img-border:#e5e7eb;
  }
  *{ box-sizing:border-box; }
  html, body { margin:0; padding:0; background:var(--bg); color:var(--text);
    font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto,Arial; height:100%; }
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

  /* Images inside HTML blocks */
  .blocks img {
    max-width:100%;
    height:auto;
    vertical-align:middle;
    margin:2px 4px;
    background: var(--img-bg);
    padding: var(--img-pad);
    border-radius:6px;
    border:1px solid var(--img-border);
    cursor: zoom-in;
  }
  /* Click-to-zoom */
  .blocks img.zoomed {
    max-height:none !important;
    height:auto !important;
    cursor: zoom-out;
    box-shadow:0 6px 24px rgba(0,0,0,.25);
  }

  /* Per-section hard caps (JS will usually render below these) */
  .qtext img { max-height:var(--q-img-max); }
  .options .blocks img { max-height:var(--opt-img-max); }
  .sol img { max-height:var(--sol-img-max); }

  .blocks math { font-size:var(--eq-size); }
  .qtext, .sol { font-size:20px; }
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
  .search input { flex:1; background:#fff; border:1px solid var(--line); color:var(--text);
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

<!-- MathJax (MathML) -->
<script>
  window.MathJax = { options:{ skipHtmlTags:['script','noscript','style','textarea','pre','code'] } };
</script>
<script async src="https://cdn.jsdelivr.net/npm/mathjax@3/es5/mml-chtml.js"></script>

<script>
(() => {
  const base = <?php echo json_encode($base); ?>;
  const $ = s => document.querySelector(s);

  const listEl = $('#list'), metaEl=$('#meta'), qidEl=$('#qid');
  const qEl=$('#question'), optsEl=$('#options'), solWrap=$('#solutionWrap'), solEl=$('#solution'), resEl=$('#result');
  const fileEl=$('#file'), reloadBtn=$('#reload'), qsearch=$('#qsearch'), clearBtn=$('#clear'), imgScale=$('#imgScale');
  const prev=$('#prev'), next=$('#next'), prev2=$('#prev2'), next2=$('#next2'), checkBtn=$('#check'), toggleSol=$('#toggleSolution');

  let data=[], filteredIdx=[], cur=0, selected=null;

  /* ---------- sizing utilities ---------- */
  const cssPx = name => {
    const v=getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    const m=v.match(/([\d.]+)/); return m?parseFloat(m[1]):0;
  };
  const emPx = el => {
    const baseEl = el?.closest?.('.blocks') || document.body;
    const fs = parseFloat(getComputedStyle(baseEl).fontSize);
    return isNaN(fs) ? 16 : fs;
  };
  const zoneCapPx = zone => {
    if (zone==='opt') return cssPx('--opt-img-max') || 150;
    if (zone==='sol') return cssPx('--sol-img-max') || 170;
    return cssPx('--q-img-max') || 200;
  };
  const currentTargets = () => {
    const v = (document.getElementById('imgScale')?.value)||'m';
    if (v==='s') return { glyphEm:1.25, eqEm:1.55, diagramFrac:0.68 };
    if (v==='l') return { glyphEm:1.6,  eqEm:2.2,  diagramFrac:0.8  };
    return            { glyphEm:1.4,  eqEm:1.85, diagramFrac:0.75 };
  };

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

  // categorize by intrinsic pixels (from API data-w/h or natural)
  function classify(img){
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

    const cls = classify(img);
    const { glyphEm, eqEm, diagramFrac } = currentTargets();
    const cap = zoneCapPx(zone);
    const oneEm = emPx(img);

    const nh = +(img.dataset.h||0) || img.naturalHeight || 0;

    // default: height:auto; let CSS max-height protect
    img.classList.remove('zoomed');
    img.style.maxHeight = '';  // CSS rule applies
    img.style.height    = 'auto';

    if (cls === 'glyph'){
      // tiny – make readable
      const target = Math.round(glyphEm * oneEm);
      img.style.height = target + 'px';
      img.dataset.sized='glyph';
      return;
    }
    if (cls === 'equation'){
      const target = Math.round(eqEm * oneEm);
      img.style.height = target + 'px';
      img.dataset.sized='equation';
      return;
    }

    // diagrams: render *below* the cap so they don't look huge
    // rule: if very big -> 75–80% of cap; if mid -> 70% of cap; if small -> keep natural (or gentle bump)
    let target;
    if (!nh) {
      target = Math.round(cap * diagramFrac);
    } else if (nh >= cap) {
      target = Math.round(cap * diagramFrac);          // e.g., 0.75 * cap
    } else if (nh >= cap * 0.6) {
      target = Math.round(cap * Math.max(0.65, diagramFrac - 0.05)); // ~0.7 * cap
    } else if (nh <= 60) {
      target = Math.min(Math.round(nh * 1.5), Math.round(cap * 0.55)); // gentle up for very small diagrams
    } else {
      target = nh; // already moderate
    }
    img.style.height = target + 'px';
    img.dataset.sized='diagram';
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
    optsEl.innerHTML = '';
    const opts = (item.options || []).slice(0,4); while (opts.length<4) opts.push('');
    const letters=['a','b','c','d'];
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

  async function typesetMath() {
    try { if (window.MathJax && MathJax.typesetPromise) { await MathJax.typesetPromise([qEl, solEl, optsEl]); } }
    catch {}
  }

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
    const letters=['a','b','c','d'];
    const item = data[filteredIdx[cur]];
    if (!selected) { resEl.className='pill bad'; resEl.textContent='select an option'; return; }
    const correct = (item.answer||'').toLowerCase();
    const ok = correct && selected===correct;
    resEl.className = 'pill ' + (ok ? 'ok' : 'bad');
    resEl.textContent = ok ? `correct: ${correct.toUpperCase()}` : `wrong (correct: ${correct.toUpperCase()})`;
    [...optsEl.children].forEach((div,i)=>{
      const val = letters[i];
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
  if (mode === 's'){
    root.setProperty('--eq-size','1.4em');   // was 1.3em
    root.setProperty('--q-img-max','160px');
    root.setProperty('--opt-img-max','120px');
    root.setProperty('--sol-img-max','140px');
  } else if (mode === 'l'){
    root.setProperty('--eq-size','2.0em');   // was 1.9em
    root.setProperty('--q-img-max','240px');
    root.setProperty('--opt-img-max','200px');
    root.setProperty('--sol-img-max','220px');
  } else {
    root.setProperty('--eq-size','1.7em');   // was 1.6em
    root.setProperty('--q-img-max','200px');
    root.setProperty('--opt-img-max','150px');
    root.setProperty('--sol-img-max','170px');
  }
  reNormalizeAll();
}

  const goPrev = ()=>{ if(cur>0){cur--; selected=null; draw();} };
  const goNext = ()=>{ if(cur<filteredIdx.length-1){cur++; selected=null; draw();} };
  prev.onclick = prev2.onclick = goPrev;
  next.onclick = next2.onclick = goNext;
  checkBtn.onclick = checkAnswer;
  toggleSol.onclick = ()=>{ solWrap.style.display = (solWrap.style.display==='none'?'block':'none'); typesetMath(); };
  qsearch.oninput = applyFilter;
  clearBtn.onclick = ()=>{ qsearch.value=''; applyFilter(); };
  imgScale.onchange = (e)=> setScale(e.target.value);
  setScale('m');

  async function loadDefault() {
    if (!base) return;
    try {
      const res = await fetch(base + 'questions.json?nocache=' + Date.now(), {cache:'no-store'});
      if (!res.ok) throw new Error('fetch failed');
      data = await res.json();
      filteredIdx = data.map((_,i)=>i);
      renderList(); await draw();
      metaEl.textContent = `Showing ${filteredIdx.length} of ${data.length}`;
    } catch (e) {
      console.log('Auto-load failed. Use the file picker.', e);
    }
  }

  fileEl.onchange = e => {
    const f = e.target.files?.[0];
    if (!f) return;
    const reader = new FileReader();
    reader.onload = async () => {
      try { data = JSON.parse(reader.result); filteredIdx = data.map((_,i)=>i); renderList(); await draw(); }
      catch (err) { alert('Invalid JSON: ' + err); }
    };
    reader.readAsText(f);
  };

  reloadBtn.onclick = loadDefault;
  loadDefault();
})();
</script>
</body>
</html>
