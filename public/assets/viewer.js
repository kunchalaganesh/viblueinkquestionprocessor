// C:\mcq-extractor\public\assets\viewer.js
(() => {
    const base = window.__SESSION_BASE__ || '';
  
    const $ = s => document.querySelector(s);
    const sidebar = $('#sidebar');
    const menuBtn = $('#menuBtn');
  
    const listEl = $('#list');
    const metaEl = $('#meta');
    const qidEl = $('#qid');
    const qEl = $('#question');
    const optsEl = $('#options');
    const solWrap = $('#solutionWrap');
    const solEl = $('#solution');
    const resEl = $('#result');
  
    const fileEl = $('#file');
    const reloadBtn = $('#reload');
    const prev = $('#prev'), next = $('#next'), prev2 = $('#prev2'), next2 = $('#next2');
    const checkBtn = $('#check'), toggleSol = $('#toggleSolution');
    const qsearch = $('#qsearch'), clearBtn = $('#clear');
    const imgScale = $('#imgScale');
  
    let data = [];
    let filteredIdx = [];
    let cur = 0;
    let selected = null;
    const letters = ['a','b','c','d'];
  
    /* ---------- sizing helpers ---------- */
    function cssPx(varName){
      const v = getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
      const m = v.match(/([\d.]+)/); return m ? parseFloat(m[1]) : 0;
    }
    function stripSizeConstraints(img){
      try {
        img.removeAttribute('width');
        img.removeAttribute('height');
        let st = img.getAttribute('style') || '';
        if (st) {
          const newSt = st.replace(/\bwidth\s*:\s*[^;]+;?/ig,'').replace(/\bheight\s*:\s*[^;]+;?/ig,'').trim().replace(/^;|;$/g,'');
          if (newSt) img.setAttribute('style', newSt); else img.removeAttribute('style');
        }
        img.style.width = 'auto';
        img.style.height = 'auto';
      } catch {}
    }
    function currentMinPx(){
      const v = imgScale.value || 'm';
      if (v==='s') return 22;
      if (v==='l') return 36;
      return 28; // medium
    }
    // Aggressive downscale for big images, upscale tiny glyphs
    function computeTargetHeight(nh, zone, cap, minH){
      if (!nh) return minH;
      if (nh <= 18)  return minH;                // glyphs (“s”, subscripts)
      if (nh <= 48)  return Math.round(minH*1.6); // small inline
  
      let f;
      if (nh <= 120)      f = 0.55;
      else if (nh <= 240) f = 0.45;
      else if (nh <= 480) f = 0.35;
      else                f = 0.28;              // very large → quite small
  
      if (zone === 'opt') f -= 0.07;              // options a bit smaller
  
      const h = Math.round(cap * f);
      return Math.max(minH, Math.min(h, cap));
    }
  
    function normalizeAfterLoad(img, zone){
      const apply = () => {
        const nw = img.naturalWidth  || 0;
        const nh = img.naturalHeight || 0;
  
        const minH = currentMinPx();
        const capQ   = cssPx('--q-img-max');
        const capOpt = cssPx('--opt-img-max');
        const capSol = cssPx('--sol-img-max');
        const cap = zone==='opt' ? capOpt : (zone==='sol' ? capSol : capQ);
  
        const target = computeTargetHeight(nh, zone, cap, minH);
  
        // Clear & apply final
        stripSizeConstraints(img);
        img.style.maxHeight = ''; // CSS uses the cap; we set a concrete height
        img.style.height = target + 'px';
        img.style.width  = 'auto';
  
        // click-to-zoom works beyond this fixed height via class toggle
      };
  
      if (img.complete && img.naturalHeight) apply();
      else img.addEventListener('load', apply, { once:true });
    }
  
    function attachZoom(img){
      img.addEventListener('click', () => {
        if (img.classList.toggle('zoomed')) {
          img.style.height = 'auto';
        } else {
          // restore normalized height
          normalizeAfterLoad(img, img.dataset.zone || 'q');
        }
      });
    }
  
    function fixAssets(container, zone){
      container.querySelectorAll('img').forEach(img=>{
        let src = img.getAttribute('src') || '';
        if (src.startsWith('./')) src = src.slice(2);
        if (/^(?:https?:)?\/\//i.test(src) || src.startsWith('data:') || src.startsWith('/') ||
            src.startsWith(base) || src.startsWith('sessions/')) {
          img.src = src;
        } else {
          img.src = base + src;
        }
        img.loading = 'lazy';
        img.decoding = 'async';
        img.dataset.zone = zone;
  
        stripSizeConstraints(img);
        normalizeAfterLoad(img, zone);
        attachZoom(img);
      });
    }
  
    function setHTML(container, html, zone){
      container.innerHTML = html || '';
      fixAssets(container, zone);
    }
  
    /* ---------- UI rendering ---------- */
    function renderList() {
      listEl.innerHTML = '';
      filteredIdx.forEach((idx, i) => {
        const a = document.createElement('a');
        a.href = 'javascript:void(0)';
        a.className = 'qitem' + (i===cur ? ' active' : '');
        a.textContent = `${i+1}.`;
        a.onclick = () => { cur=i; selected=null; draw(); if (window.innerWidth<=900) sidebar.classList.remove('open'); };
        listEl.appendChild(a);
      });
      metaEl.textContent = `Showing ${filteredIdx.length} of ${data.length}`;
    }
  
    function renderOptions(item) {
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
        img.classList.remove('zoomed');
        normalizeAfterLoad(img, zone);
      });
    }
  
    function setScale(mode){
      const root = document.documentElement.style;
      if (mode === 's'){
        root.setProperty('--eq-size','1.3em');
        root.setProperty('--q-img-max','150px');
        root.setProperty('--opt-img-max','120px');
        root.setProperty('--sol-img-max','130px');
        root.setProperty('--img-min','22px');
      } else if (mode === 'l'){
        root.setProperty('--eq-size','1.9em');
        root.setProperty('--q-img-max','220px');
        root.setProperty('--opt-img-max','180px');
        root.setProperty('--sol-img-max','200px');
        root.setProperty('--img-min','36px');
      } else {
        root.setProperty('--eq-size','1.6em');
        root.setProperty('--q-img-max','180px');
        root.setProperty('--opt-img-max','140px');
        root.setProperty('--sol-img-max','160px');
        root.setProperty('--img-min','28px');
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
  
    if (menuBtn) menuBtn.onclick = ()=> sidebar.classList.toggle('open');
  
    async function loadDefault() {
      if (!base) return; // local mode
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
  