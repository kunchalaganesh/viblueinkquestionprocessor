<?php
// C:\mcq-extractor\public\viewer.php
$session = isset($_GET['session']) ? preg_replace('/[^A-Za-z0-9\-]/','', $_GET['session']) : '';
$base = $session ? ('sessions/' . $session . '/') : '';
$theme = (isset($_GET['theme']) && $_GET['theme']==='dark') ? 'dark' : 'light';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>MCQ Viewer</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
<link rel="stylesheet" href="assets/viewer.css" />
</head>
<body class="<?php echo $theme==='dark' ? 'theme-dark' : ''; ?>">
<div class="mobilebar">
  <button id="menuBtn" class="hamb">☰</button>
  <div class="muted">MCQ Viewer</div>
  <div style="flex:1"></div>
</div>

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
  window.__SESSION_BASE__ = <?php echo json_encode($base); ?>;
</script>
<script src="assets/viewer.js"></script>
</body>
</html>
