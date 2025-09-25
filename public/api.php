<?php
// C:\mcq-extractor\public\api.php
// Upload DOCX -> spawn converter worker (background).
// Confirm -> spawn uploader worker (background).
// GET ?status=<session> for progress polling.
// GET ?final=<session> returns final.json (CORS).
// POST ?action=push queues Spring ingest (and ingests immediately if possible).
// POST ?action=push_retry retries ingest without re-uploading images.
// POST ?action=delete deletes a session safely.

@ini_set('max_execution_time','0');
@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('memory_limit','1024M');

error_reporting(E_ALL);
ini_set('display_errors','0');

/* ---------------- CORS ---------------- */
$reqMethod  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$originHdr  = $_SERVER['HTTP_ORIGIN']    ?? '';
$reqHdrs    = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
$allowList  = array_filter(array_map('trim', explode(',', cfg('CORS_ALLOW_ORIGINS','*'))));
$allowCreds = strtolower(cfg('CORS_ALLOW_CREDENTIALS','true')) === 'true';

$allowedOrigin = null;
if ($allowList === ['*']) {
  if ($allowCreds) {
    if ($originHdr) $allowedOrigin = $originHdr;
  } else {
    $allowedOrigin = '*';
  }
} else {
  if ($originHdr && in_array($originHdr, $allowList, true)) {
    $allowedOrigin = $originHdr;
  }
}
header('Vary: Origin');
if ($allowedOrigin) {
  header('Access-Control-Allow-Origin: '.$allowedOrigin);
  if ($allowCreds) header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: ' . ($reqHdrs ?: 'Content-Type, Authorization, X-Requested-With, Accept, Origin'));
header('Access-Control-Max-Age: 86400');
if ($reqMethod === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

/* --------------- helpers --------------- */
function out_json($arr, $code=200){ http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function clean_name($s){ $s=preg_replace('/[^a-z0-9]+/i','-', $s); return trim($s,'-'); }
function session_dir($s){ return __DIR__.DIRECTORY_SEPARATOR.'sessions'.DIRECTORY_SEPARATOR.$s; }
function status_path($s){ return session_dir($s).DIRECTORY_SEPARATOR.'status.json'; }
function qjson_path($s){ return session_dir($s).DIRECTORY_SEPARATOR.'questions.json'; }
function final_path($s){ return session_dir($s).DIRECTORY_SEPARATOR.'final.json'; }
function map_path($s){ return session_dir($s).DIRECTORY_SEPARATOR.'r2-map.json'; }

function cfg($key, $def=null){
  $v = getenv($key);
  if ($v!==false && $v!=='') return $v;
  static $dot = null;
  if ($dot===null){
    $dotPath = __DIR__.'/.env';
    $dot = is_file($dotPath) ? @parse_ini_file($dotPath, false, INI_SCANNER_RAW) : [];
    if (!is_array($dot)) $dot = [];
  }
  if (isset($dot[$key]) && $dot[$key] !== '') return $dot[$key];
  return $def;
}
function read_json_file($path){ if(!is_file($path)) return null; $s=@file_get_contents($path); if($s===false) return null; return json_decode($s,true); }
function write_json_file($path,$data){ @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); }

set_error_handler(function($no,$str,$file,$line){ throw new ErrorException($str,0,$no,$file,$line); });
set_exception_handler(function($ex){ out_json(['ok'=>false,'error'=>'PHP exception','details'=>$ex->getMessage()],500); });
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && !headers_sent()){
    out_json(['ok'=>false,'error'=>'PHP fatal','details'=>$e['message']],500);
  }
});

/* ----------- GET /api.php?env=1 ----------- */
if (isset($_GET['env'])){
  $mask = function($s){ if(!$s) return ''; return substr($s,0,4).'…'.substr($s,-4); };
  out_json([
    'ok'=>true,
    'R2_ACCOUNT_ID'        => $mask(cfg('R2_ACCOUNT_ID')),
    'R2_ACCESS_KEY_ID'     => $mask(cfg('R2_ACCESS_KEY_ID')),
    'R2_SECRET_ACCESS_KEY' => $mask(cfg('R2_SECRET_ACCESS_KEY')),
    'R2_BUCKET'            => cfg('R2_BUCKET'),
    'R2_PUBLIC_BASE'       => cfg('R2_PUBLIC_BASE'),
    'SPRING_BASE'          => cfg('SPRING_BASE') ? '[set]' : '[not set]',
  ]);
}

/* ----------- GET /api.php?status=<session> ----------- */
if (isset($_GET['status'])) {
  $session = preg_replace('/[^A-Za-z0-9\-]/','', $_GET['status']);
  if (!$session) out_json(['ok'=>false,'error'=>'bad session'],400);

  $sfile = status_path($session);
  $qfile = qjson_path($session);
  $ffile = final_path($session);

  if (is_file($sfile)) {
    $js = json_decode(@file_get_contents($sfile), true) ?: null;
    if ($js) { $js['ok']=true; $js['has_questions']=is_file($qfile); $js['has_final']=is_file($ffile); out_json($js); }
  }

  if (is_file($ffile)) out_json(['ok'=>true,'state'=>'uploaded','phase'=>'upload','message'=>'CDN URLs ready','progress'=>100,'has_final'=>true]);
  if (is_file($qfile)) out_json(['ok'=>true,'state'=>'done','phase'=>'convert','message'=>'Extracted','progress'=>100,'has_questions'=>true]);

  if (is_dir(session_dir($session))) out_json(['ok'=>true,'state'=>'processing','phase'=>'convert','message'=>'Working…']);
  out_json(['ok'=>false,'error'=>'session not found'],404);
}

/* ----------- GET /api.php?final=<session> ----------- */
if (isset($_GET['final'])) {
  $session = preg_replace('/[^A-Za-z0-9\-]/','', $_GET['final']);
  if (!$session) out_json(['ok'=>false,'error'=>'bad session'],400);
  $ffile = final_path($session);
  if (!is_file($ffile)) out_json(['ok'=>false,'error'=>'final.json not ready'],404);
  header('Content-Type: application/json; charset=utf-8');
  readfile($ffile);
  exit;
}

/* ----------- POST /api.php?action=delete ----------- */
if (isset($_GET['action']) && $_GET['action']==='delete') {
  $session = preg_replace('/[^A-Za-z0-9\-]/','', $_POST['session'] ?? '');
  if (!$session) out_json(['ok'=>false,'error'=>'bad session'],400);
  $dir = session_dir($session);
  if (!is_dir($dir)) out_json(['ok'=>true,'deleted'=>0,'message'=>'already gone']);
  $it = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
  $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($ri as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
  @rmdir($dir);
  out_json(['ok'=>true,'deleted'=>1]);
}

/* ----------- POST /api.php?action=confirm (spawn uploader) ----------- */
if (isset($_GET['action']) && $_GET['action']==='confirm') {
  $session = preg_replace('/[^A-Za-z0-9\-]/','', $_POST['session'] ?? '');
  if (!$session) out_json(['ok'=>false,'error'=>'bad session'],400);

  $qfile = qjson_path($session);
  if (!is_file($qfile)) out_json(['ok'=>false,'error'=>'questions.json not found for session'],404);

  $acct = cfg('R2_ACCOUNT_ID'); $ak = cfg('R2_ACCESS_KEY_ID'); $sk = cfg('R2_SECRET_ACCESS_KEY'); $pub = rtrim(cfg('R2_PUBLIC_BASE',''),'/');
  if (!$acct || !$ak || !$sk || !$pub) out_json(['ok'=>false,'error'=>'R2 env vars missing (R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_PUBLIC_BASE)'],500);

  $sfile = status_path($session);
  $seed = ['state'=>'upload_queued','phase'=>'upload','message'=>'Upload queued','progress'=>0,'queued_at'=>date('c')];
  @file_put_contents($sfile, json_encode($seed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

  $php = getenv('PHP_BIN') ?: 'php';
  $worker = __DIR__.DIRECTORY_SEPARATOR.'uploader.php';
  $cmd = escapeshellarg($php).' '.escapeshellarg($worker).' '.escapeshellarg($session);

  if (stripos(PHP_OS,'WIN')===0) { @pclose(@popen('cmd /C start /B "" '.$cmd, 'r')); }
  else { @exec($cmd.' > /dev/null 2>&1 &'); }

  $status  = 'api.php?status='.rawurlencode($session);
  $final   = 'api.php?final='.rawurlencode($session);
  out_json(['ok'=>true,'session'=>$session,'status_url'=>$status,'final_url'=>$final,'message'=>'Upload started']);
}

/* ---------- Spring helpers ---------- */
function spring_base(){ $s = rtrim(cfg('SPRING_BASE',''),'/'); return $s ?: null; } // non-throwing
function spring_headers(){
  $h=['Content-Type: application/json'];
  $auth=trim(cfg('SPRING_AUTH','')); if($auth!=='') $h[]='Authorization: Bearer '.$auth;
  return $h;
}
function http_post_json($url,$payload){
  $ch=curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>spring_headers(),
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>60, CURLOPT_SSL_VERIFYPEER=>false
  ]);
  $resp=curl_exec($ch); $err=curl_error($ch); $code=curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  $js = null; if ($resp !== false) $js = json_decode($resp,true);
  return [$js,$err,$code,$resp]; // include raw response for diagnostics
}
function build_ingest_payload($session){
  $ff=final_path($session); $qf=qjson_path($session); $mf=map_path($session);
  $items=null;
  if(is_file($ff)){
    $data=read_json_file($ff);
    if(is_array($data) && isset($data['items']) && is_array($data['items'])) $items=$data['items'];
    elseif(is_array($data)) $items=$data;
  }
  if($items===null){
    $q = read_json_file($qf);
    $m = read_json_file($mf) ?: [];
    if(!is_array($q)) return [null,'questions.json not ready',0];
    $jsonStr = json_encode($q, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    foreach(($m?:[]) as $rel=>$url){
      $jsonStr = str_replace('"src":"'.$rel.'"','"src":"'.$url.'"',$jsonStr);
      $jsonStr = str_replace($rel,$url,$jsonStr);
    }
    $items = json_decode($jsonStr,true);
  }
  if(!is_array($items)) return [null,'Cannot build items',0];
  return [[ 'data'=>['items'=>$items] ], null, 200];
}
function spring_upsert_subject_chapter($subject,$chapter,$className,$chapterNo){
  $base = spring_base(); if(!$base) throw new RuntimeException('SPRING_BASE not set');
  [$sr,$se,$sc,$sraw] = http_post_json($base.'/api/mcq/admin/subjects', ['name'=>$subject,'code'=>null]);
  if($se || empty($sr['id'])){
    throw new RuntimeException('Subject upsert failed: status='.$sc.' body='.substr((string)$sraw,0,500));
  }
  [$cr,$ce,$cc,$craw] = http_post_json($base.'/api/mcq/admin/chapters', [
    'subjectId'=>(int)$sr['id'],'chapterNo'=>$chapterNo?:null,'name'=>$chapter,'className'=>$className
  ]);
  if($ce || empty($cr['id'])){
    throw new RuntimeException('Chapter upsert failed: status='.$cc.' body='.substr((string)$craw,0,500));
  }
  return [(int)$sr['id'], (int)$cr['id']];
}
function spring_ingest_now($session,$subjectId,$chapterId,$chapterNo,$uploadedBy,$sourceName,$extraMeta=[]){
  $base = spring_base(); if(!$base) return [null,'SPRING_BASE not set',0];
  [$payload,$err,] = build_ingest_payload($session);
  if($err) return [null,$err,0];
  $payload['sessionSlug']=$session;
  $payload['sourceName']=$sourceName ?: ($session.'.docx');
  $payload['subjectId']=$subjectId;
  $payload['chapterId']=$chapterId;
  $payload['chapterNo']=$chapterNo?:null;
  $payload['uploadedBy']=$uploadedBy ?: cfg('UPLOADED_BY','');
  $payload['meta'] = ['examTitle'=>$extraMeta['exam']??null, 'className'=>$extraMeta['class']??null];

  [$js,$e,$code,$raw] = http_post_json($base.'/api/mcq/ingest', $payload);
  if ($e) return [null, "cURL: $e", $code];
  if (!is_array($js)) return [null, "Invalid JSON (status=$code, body=".substr((string)$raw,0,500).")", $code];
  return [$js,null,$code];
}

/* ----------- POST /api.php?action=push (queue-first; never 502) ----------- */
if (isset($_GET['action']) && $_GET['action']==='push') {
  $session   = preg_replace('/[^A-Za-z0-9\-]/','', $_POST['session'] ?? '');
  $exam      = trim($_POST['exam'] ?? '');
  $className = trim($_POST['class'] ?? '');
  $subject   = trim($_POST['subject'] ?? '');
  $chapter   = trim($_POST['chapter'] ?? '');
  $chapterNo = isset($_POST['chapterNo']) ? (int)$_POST['chapterNo'] : null;
  $uploadedBy= trim($_POST['uploadedBy'] ?? (cfg('UPLOADED_BY','')));

  if(!$session || !$exam || !$className || !$subject || !$chapter){
    out_json(['ok'=>false,'error'=>'Required: session, exam, class, subject, chapter','hint'=>'chapterNo, uploadedBy optional'],400);
  }
  $dir = session_dir($session);
  if(!is_dir($dir)) out_json(['ok'=>false,'error'=>'session not found'],404);

  // Always save ingest-request so uploader or push_retry can finish later
  $reqFile = $dir.DIRECTORY_SEPARATOR.'ingest-request.json';
  write_json_file($reqFile, [
    'exam'=>$exam,'class'=>$className,'subject'=>$subject,'chapter'=>$chapter,
    'chapterNo'=>$chapterNo,'uploadedBy'=>$uploadedBy
  ]);

  $finalExists = is_file(final_path($session));
  $base = spring_base();

  // If Spring not configured, just queue and return
  if (!$base) {
    out_json(['ok'=>true,'queued'=>true,'message'=>'Queued for ingest; SPRING_BASE not set','status_url'=>'api.php?status='.rawurlencode($session)]);
  }

  // Try immediate upsert/ingest; on any error, DO NOT 502 — return queued with diagnostics
  try{
    [$subjectId,$chapterId] = spring_upsert_subject_chapter($subject,$chapter,$className,$chapterNo);

    if($finalExists){
      $srcName = is_file($dir.DIRECTORY_SEPARATOR.'upload.docx') ? 'upload.docx' : ($session.'.docx');
      [$res,$err,$code] = spring_ingest_now($session,$subjectId,$chapterId,$chapterNo,$uploadedBy,$srcName,['exam'=>$exam,'class'=>$className]);
      if($err){
        out_json([
          'ok'=>true,'queued'=>true,
          'message'=>'Queued; immediate ingest failed, will retry after upload or via push_retry',
          'diag'=>['status'=>$code,'error'=>$err]
        ]);
      }
      out_json(['ok'=>true,'queued'=>false,'message'=>'Ingested immediately','result'=>$res]);
    } else {
      out_json(['ok'=>true,'queued'=>true,'message'=>'Queued for ingest after upload','status_url'=>'api.php?status='.rawurlencode($session)]);
    }
  } catch(Throwable $e){
    out_json([
      'ok'=>true,'queued'=>true,
      'message'=>'Queued; subject/chapter upsert failed now but can retry later',
      'diag'=>['error'=>$e->getMessage()]
    ]);
  }
}

/* ----------- POST /api.php?action=push_retry (retry ingest only) ----------- */
if (isset($_GET['action']) && $_GET['action']==='push_retry') {
  $session   = preg_replace('/[^A-Za-z0-9\-]/','', $_POST['session'] ?? '');
  if(!$session) out_json(['ok'=>false,'error'=>'session required'],400);
  $dir = session_dir($session); if(!is_dir($dir)) out_json(['ok'=>false,'error'=>'session not found'],404);

  $req = read_json_file($dir.DIRECTORY_SEPARATOR.'ingest-request.json') ?: [];
  $subject   = trim($_POST['subject'] ?? ($req['subject'] ?? ''));
  $chapter   = trim($_POST['chapter'] ?? ($req['chapter'] ?? ''));
  $className = trim($_POST['class']   ?? ($req['class'] ?? ''));
  $chapterNo = isset($_POST['chapterNo']) ? (int)$_POST['chapterNo'] : ($req['chapterNo'] ?? null);
  $uploadedBy= trim($_POST['uploadedBy'] ?? ($req['uploadedBy'] ?? (cfg('UPLOADED_BY',''))));
  $exam      = trim($_POST['exam'] ?? ($req['exam'] ?? ''));

  if(!$subject || !$chapter || !$className){
    out_json(['ok'=>false,'error'=>'Need subject, chapter, class (from body or saved ingest-request.json)'],400);
  }

  $base = spring_base();
  if (!$base) out_json(['ok'=>false,'error'=>'SPRING_BASE not set'],400);

  try{
    [$subjectId,$chapterId] = spring_upsert_subject_chapter($subject,$chapter,$className,$chapterNo);
    $srcName = is_file($dir.DIRECTORY_SEPARATOR.'upload.docx') ? 'upload.docx' : ($session.'.docx');
    [$res,$err,$code] = spring_ingest_now($session,$subjectId,$chapterId,$chapterNo,$uploadedBy,$srcName,['exam'=>$exam,'class'=>$className]);
    if($err) out_json(['ok'=>false,'error'=>$err,'status'=>$code],502);
    out_json(['ok'=>true,'message'=>'Re-ingested without re-uploading images','result'=>$res]);
  } catch(Throwable $e){
    out_json(['ok'=>false,'error'=>$e->getMessage()],502);
  }
}

/* ----------- POST (DOCX upload starts converter worker) ----------- */
if ($_SERVER['REQUEST_METHOD']!=='POST') out_json(['ok'=>false,'error'=>'POST a .docx (field name: doc)'],405);

if (empty($_FILES['doc']) || $_FILES['doc']['error']!==UPLOAD_ERR_OK) out_json(['ok'=>false,'error'=>'No DOCX uploaded or upload error.'],400);
$origName = $_FILES['doc']['name'] ?? 'upload.docx';
if (!preg_match('/\.docx$/i', $origName)) out_json(['ok'=>false,'error'=>'Only .docx accepted.'],400);

$speed = isset($_POST['speed']) && $_POST['speed']==='fast' ? 'fast' : 'normal';

$sessionsDir = __DIR__.DIRECTORY_SEPARATOR.'sessions';
if (!is_dir($sessionsDir)) mkdir($sessionsDir,0777,true);
$slug = clean_name(pathinfo($origName, PATHINFO_FILENAME)) ?: 'session';
$session = $slug.'-'.date('Ymd-His');
$sessionDir = session_dir($session);
$assetsDir  = $sessionDir.DIRECTORY_SEPARATOR.'assets';
@mkdir($sessionDir,0777,true); @mkdir($assetsDir,0777,true);

$tmpDoc = $sessionDir.DIRECTORY_SEPARATOR.'upload.docx';
if (!move_uploaded_file($_FILES['doc']['tmp_name'], $tmpDoc)) out_json(['ok'=>false,'error'=>'Failed to store uploaded file.'],500);

// seed converter status + job options
$seed = ['state'=>'queued','phase'=>'convert','message'=>'Queued','progress'=>0,'speed'=>$speed,'started_at'=>date('c')];
@file_put_contents(status_path($session), json_encode($seed, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
@file_put_contents($sessionDir.DIRECTORY_SEPARATOR.'job.json', json_encode(['speed'=>$speed], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

// spawn converter worker
$php = getenv('PHP_BIN') ?: 'php';
$worker = __DIR__.DIRECTORY_SEPARATOR.'worker.php';
$cmd = escapeshellarg($php).' '.escapeshellarg($worker).' '.escapeshellarg($session);
if (stripos(PHP_OS,'WIN')===0) { @pclose(@popen('cmd /C start /B "" '.$cmd, 'r')); }
else { @exec($cmd.' > /dev/null 2>&1 &'); }

$preview = 'preview.php?session='.rawurlencode($session);
$status  = 'api.php?status='.rawurlencode($session);
out_json(['ok'=>true,'session'=>$session,'preview_url'=>$preview,'status_url'=>$status,'message'=>'Processing started']);
