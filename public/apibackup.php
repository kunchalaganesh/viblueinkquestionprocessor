<?php
// C:\mcq-extractor\public\api.php

header('Access-Control-Allow-Origin: *');
header('Vary: Origin');
header('Access-Control-Allow-Methods: POST, OPTIONS, GET');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Optional quick health check (GET /api.php?health=1)
if (isset($_GET['health'])) {
  $pandocOk = function_exists('exec') ? (exec((stripos(PHP_OS,'WIN')===0?'where':'which')." pandoc", $o, $r) === 0) : false;
  $magickOk = function_exists('exec') ? (exec((stripos(PHP_OS,'WIN')===0?'where':'which')." magick", $o2, $r2) === 0
                                        || exec((stripos(PHP_OS,'WIN')===0?'where':'which')." convert", $o3, $r3) === 0) : false;
  out_json(['ok'=>true,'php'=>PHP_VERSION,'pandoc'=>$pandocOk,'imagemagick'=>$magickOk]);
}

// ---- keep long jobs alive on the admin PC ----
@ini_set('max_execution_time', '0');   // no time limit
@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('memory_limit', '1024M');

error_reporting(E_ALL);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');

$logs = [];
function logmsg($s){ global $logs; $logs[]=$s; }
function out_json($arr, $code=200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

set_error_handler(function($no,$str,$file,$line){ throw new ErrorException($str,0,$no,$file,$line); });
set_exception_handler(function($ex){ out_json(['ok'=>false,'error'=>'PHP exception','details'=>$ex->getMessage()],500); });
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && !headers_sent()){
    out_json(['ok'=>false,'error'=>'PHP fatal','details'=>$e['message']],500);
  }
});

// speed mode: fast (288 DPI) vs normal (600 DPI)
$speed = (isset($_GET['speed']) ? $_GET['speed'] : (isset($_POST['speed']) ? $_POST['speed'] : 'normal'));
$speed = in_array($speed,['fast','normal'],true) ? $speed : 'normal';
$wmfDpi = ($speed==='fast') ? 288 : (int)(getenv('WMF_DPI') ?: 600);
logmsg("speed={$speed}, WMF_DPI={$wmfDpi}");

if ($_SERVER['REQUEST_METHOD']!=='POST') out_json(['ok'=>false,'error'=>'POST a .docx (field name: doc)'],405);

function haveBinary(string $exe): bool {
  $tool = stripos(PHP_OS,'WIN')===0 ? 'where' : 'which';
  @exec("$tool $exe", $out, $ret);
  return $ret===0 && !empty($out);
}
function run($cmd){
  @exec($cmd.' 2>&1', $out, $ret);
  return [$ret, implode("\n",$out)];
}
function clean_name($s){ $s=preg_replace('/[^a-z0-9]+/i','-', $s); return trim($s,'-'); }
function normalizeAssetSrc(string $src){
  $src = trim($src);
  $src = preg_replace('#^file:/+#i','', $src);
  if (strpos($src,'./')===0) $src=substr($src,2);
  return str_replace('\\','/',$src);
}
function relPathFrom(string $abs, string $fromDir){
  $from=str_replace('\\','/', realpath($fromDir)?:$fromDir);
  $to=str_replace('\\','/',$abs);
  $from=rtrim($from,'/').'/';
  $i=0;$m=min(strlen($from),strlen($to));
  while($i<$m && $from[$i]===$to[$i]) $i++;
  $rel=substr($to,$i);
  if ($rel===''||$rel[0]==='/') $rel=basename($to);
  return $rel;
}
function cleanHTML(string $h){ $h=preg_replace('/\s+/u',' ',$h); return trim($h); }
function fixMojibake(string $s){
  if (strpos($s,'Ã')===false && strpos($s,'â')===false && strpos($s,'Â')===false) return $s;
  return strtr($s,[ "â€™"=>"’","â€˜"=>"‘","â€œ"=>"“","â€"=>"”","â€“"=>"–","â€”"=>"—","â€¦"=>"…","Â©"=>"©","Â®"=>"®","Â±"=>"±","Â§"=>"§","Â·"=>"·","Â"=>"" ]);
}

/* ---------- validate upload ---------- */
if (empty($_FILES['doc']) || $_FILES['doc']['error']!==UPLOAD_ERR_OK) out_json(['ok'=>false,'error'=>'No DOCX uploaded or upload error.'],400);
$origName = $_FILES['doc']['name'] ?? 'upload.docx';
if (!preg_match('/\.docx$/i', $origName)) out_json(['ok'=>false,'error'=>'Only .docx accepted.'],400);

/* ---------- session paths ---------- */
$baseDir = __DIR__;
$sessionsDir = $baseDir.DIRECTORY_SEPARATOR.'sessions';
if (!is_dir($sessionsDir)) mkdir($sessionsDir,0777,true);
$slug = clean_name(pathinfo($origName, PATHINFO_FILENAME)); if (!$slug) $slug='session';
$session = $slug.'-'.date('Ymd-His');
$sessionDir = $sessionsDir.DIRECTORY_SEPARATOR.$session;
$assetsDir  = $sessionDir.DIRECTORY_SEPARATOR.'assets';
@mkdir($sessionDir,0777,true); @mkdir($assetsDir,0777,true);
$tmpDoc = $sessionDir.DIRECTORY_SEPARATOR.'upload.docx';
if (!move_uploaded_file($_FILES['doc']['tmp_name'], $tmpDoc)) out_json(['ok'=>false,'error'=>'Failed to store uploaded file.'],500);

/* ---------- locate tools ---------- */
$pandoc = getenv('PANDOC_BIN') ?: 'pandoc';
if (!haveBinary($pandoc)) out_json(['ok'=>false,'error'=>'pandoc not found in PATH'],500);
$magickPresent = haveBinary('magick') || haveBinary('convert');

/* ---------- 1) docx -> html (MathML) ---------- */
$htmlPath = $sessionDir.DIRECTORY_SEPARATOR.'full.html';
$cmd = $pandoc.' --extract-media='.escapeshellarg($assetsDir).' -f docx -t html --mathml '
     .escapeshellarg($tmpDoc).' -o '.escapeshellarg($htmlPath);
logmsg('$ '.$cmd);
[$code,$out] = run($cmd); if ($out) logmsg($out);
if ($code!==0 || !is_file($htmlPath)) out_json(['ok'=>false,'error'=>'pandoc failed','details'=>$out,'logs'=>$logs],500);

/* ---------- 2) HTML -> JSON (normalize/annotate images) ---------- */
$build = build_json_from_html($session,$sessionDir,$htmlPath,$magickPresent,$wmfDpi);
if (!$build['ok']) out_json(array_merge(['ok'=>false],$build,['logs'=>$logs]),500);

/* ---------- reply ---------- */

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] . (!empty($_SERVER['SERVER_PORT']) ? (':'.$_SERVER['SERVER_PORT']) : ''));
$baseUrl = $scheme . '://' . $host;

$preview = $baseUrl . '/preview.php?session=' . rawurlencode($session);

// $preview = 'preview.php?session='.rawurlencode($session);
out_json([
  'ok'=>true,
  'session'=>$session,
  'items'=>$build['items'],
  'wmf2png'=>$build['wmf2png'],
  'wmfFound'=>$build['wmfFound'],
  'preview_url'=>$preview,
  'logs'=>$logs
]);

/* ===== HTML->JSON ===== */
function build_json_from_html(string $session, string $sessionDir, string $htmlPath, bool $magickPresent, int $wmfDpi): array {
  libxml_use_internal_errors(true);
  $doc = new DOMDocument();

  $raw = file_get_contents($htmlPath);
  $raw = str_replace("\r\n","\n",$raw);
  $ok  = $doc->loadHTML('<?xml encoding="UTF-8" ?>'.$raw, LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_COMPACT);
  if (!$ok) return ['ok'=>false,'error'=>'failed to parse HTML as UTF-8'];

  $xp = new DOMXPath($doc);

  $wmfFound=0; $converted=0; $i=0;

  foreach ($xp->query('//img') as $img) {
    /** @var DOMElement $img */
    // keep the PHP watchdog alive every few iterations
    // if (($i++ % 10) === 0) { @set_time_limit(10); }

    $src = normalizeAssetSrc($img->getAttribute('src'));
    if ($src==='') continue;

    if (preg_match('#^(?:https?:)?//#i',$src) || stripos($src,'data:')===0 || strpos($src,'/')===0) {
      continue;
    }

    $abs = $src;
    if (!preg_match('#^[A-Za-z]:/#', $abs) && strpos($abs,'//')!==0) {
      $abs = realpath(dirname($htmlPath).DIRECTORY_SEPARATOR.$src) ?: $src;
    }
    if (!is_file($abs)) continue;

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $targetAbs = $abs;

    // WMF/EMF -> PNG (density based on $wmfDpi). 288 (fast) or 600 (pretty)
    if (in_array($ext,['wmf','emf','emz'], true)) {
      $wmfFound++;
      $pngAbs = preg_replace('/\.(wmf|emf|emz)$/i','.png',$abs);
      if (!is_file($pngAbs) && $magickPresent) {
        $bin = haveBinary('magick') ? 'magick' : 'convert';
        $cmd = $bin.' '.escapeshellarg($abs)
             .' -density '.$wmfDpi.' -colorspace sRGB -background white -alpha remove -alpha off '
             .escapeshellarg($pngAbs);
        @exec($cmd.' 2>&1', $o, $r); clearstatcache();
      }
      if (is_file($pngAbs)) { $targetAbs = $pngAbs; $converted++; }
    }

    // annotate intrinsic size for the viewer (helps smart sizing)
    $info = @getimagesize($targetAbs);
    if (is_array($info) && isset($info[0], $info[1])) {
      $img->setAttribute('data-w', (string)$info[0]);
      $img->setAttribute('data-h', (string)$info[1]);
    }

    // rewrite src to session-relative path
    $rel = normalizeAssetSrc(relPathFrom($targetAbs, $sessionDir));
    $img->setAttribute('src', $rel);

    // strip inline size so the preview decides final look
    $img->removeAttribute('width'); $img->removeAttribute('height');
    if ($img->hasAttribute('style')) {
      $st = $img->getAttribute('style');
      $st = preg_replace('/\b(?:width|height)\s*:\s*[^;]+;?/i','',$st);
      $st = trim($st," ;\t\r\n");
      if ($st==='') $img->removeAttribute('style'); else $img->setAttribute('style',$st);
    }
  }

  // helpers
  $norm = function(string $s):string{
    $s = mb_strtolower(trim(preg_replace('/\s+/u',' ',$s)),'UTF-8');
    if (preg_match('/^question\b/u',$s)) return 'question';
    if (preg_match('/^options?\b/u',$s)) return 'option';
    if (preg_match('/^answer\b/u',$s))   return 'answer';
    if (preg_match('/^solution\b/u',$s)) return 'solution';
    return '';
  };
  $textDeep = function(DOMNode $n):string{
    return fixMojibake(trim(preg_replace('/\s+/u',' ', $n->textContent??'')));
  };
  $inner = function(DOMNode $n) use($doc):string{
    $h=''; foreach($n->childNodes as $c) $h.=$doc->saveHTML($c);
    return fixMojibake(cleanHTML($h));
  };

  // Extract MCQs from 2-col tables
  $items=[]; $q=0;
  foreach ($xp->query('//table') as $t) {
    $rows=$xp->query('.//tr',$t); if (!$rows || !$rows->length) continue;
    $item=['question'=>'','options'=>['','','',''],'answer'=>'','solutions'=>'','qid'=>null];
    $opt=0; $seen=false;
    foreach ($rows as $row) {
      $cells=$xp->query('./th|./td',$row); if (!$cells || $cells->length<2) continue;
      $label = $norm($textDeep($cells->item(0))); if ($label==='') continue;
      $seen=true;
      if     ($label==='question')  $item['question']  = $inner($cells->item(1));
      elseif ($label==='option')    { $h=$inner($cells->item(1)); if ($opt<4) $item['options'][$opt]=$h; $opt++; }
      elseif ($label==='answer')    { $txt=$textDeep($cells->item(1)); if (preg_match('/([a-dA-D])/', $txt,$m)) $item['answer']=strtolower($m[1]); }
      elseif ($label==='solution')  $item['solutions'] = $inner($cells->item(1));
    }
    if ($seen && $item['question']!==''){
      $item['options']=array_slice($item['options'],0,4);
      $item['qid']=$session.'-Q'.str_pad((string)(++$q),4,'0',STR_PAD_LEFT);
      $items[]=$item;
    }
  }

  $jsonPath=$sessionDir.DIRECTORY_SEPARATOR.'questions.json';
  file_put_contents($jsonPath, json_encode($items, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

  return ['ok'=>true,'items'=>count($items),'wmf2png'=>$converted,'wmfFound'=>$wmfFound];
}
