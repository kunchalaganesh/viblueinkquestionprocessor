<?php
// C:\mcq-extractor\public\uploader.php
// CLI: php uploader.php <session>
// Parallel, streaming uploads to R2 (curl_multi). Writes r2-map.json & final.json.
// Also auto-calls Spring ingest if sessions/<session>/ingest-request.json exists.
//
// Concurrency = UPLOAD_CONCURRENCY (env) or 8. Flush status/map every 25 files.

@ini_set('max_execution_time','0');
@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('memory_limit','1024M');


// ---- CORS (frontend may run on another host)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
header('Vary: Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD']==='OPTIONS'){ http_response_code(204); exit; }

header('Content-Type: application/json; charset=utf-8');

/* ---------- path + cfg helpers ---------- */
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
function put_status($s, $arr){
  $file = status_path($s);
  $cur = is_file($file) ? (json_decode(@file_get_contents($file), true) ?: []) : [];
  $new = array_merge($cur, $arr);
  $new['updated_at'] = date('c');
  @file_put_contents($file, json_encode($new, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function read_json_file($path){
  if (!is_file($path)) return null;
  $s = @file_get_contents($path);
  if ($s===false) return null;
  return json_decode($s, true);
}

/* ---------- content helpers ---------- */
function mime_for($path){
  $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
  return [
    'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif',
    'webp'=>'image/webp','svg'=>'image/svg+xml','bmp'=>'image/bmp','tif'=>'image/tiff','tiff'=>'image/tiff'
  ][$ext] ?? 'application/octet-stream';
}

/* ---------- R2 signer + handle builder (HTTP/2, SigV4) ---------- */
function build_r2_put_handle($account,$ak,$sk,$bucket,$key,$path,$contentType){
  $host = $account . '.r2.cloudflarestorage.com';
  $uri  = '/'.$bucket.'/'.str_replace('%2F','/', rawurlencode($key));
  $url  = "https://{$host}{$uri}";

  $amzdate = gmdate('Ymd\THis\Z'); $date = gmdate('Ymd'); $region='auto'; $service='s3';
  $size = filesize($path);
  $payloadHash = hash_file('sha256', $path);

  $canonical_headers =
    "host:{$host}\n".
    "x-amz-content-sha256:{$payloadHash}\n".
    "x-amz-date:{$amzdate}\n";
  $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
  $canonical_request = "PUT\n{$uri}\n\n{$canonical_headers}\n{$signed_headers}\n{$payloadHash}";
  $cred_scope = "{$date}/{$region}/{$service}/aws4_request";
  $string_to_sign = "AWS4-HMAC-SHA256\n{$amzdate}\n{$cred_scope}\n".hash('sha256',$canonical_request);
  $kDate = hash_hmac('sha256',$date,'AWS4'.$sk,true);
  $kReg  = hash_hmac('sha256',$region,$kDate,true);
  $kSvc  = hash_hmac('sha256',$service,$kReg,true);
  $kSig  = hash_hmac('sha256','aws4_request',$kSvc,true);
  $sig   = hash_hmac('sha256',$string_to_sign,$kSig);

  $auth = "AWS4-HMAC-SHA256 Credential={$ak}/{$cred_scope}, SignedHeaders={$signed_headers}, Signature={$sig}";

  $fp = fopen($path, 'rb');
  if (!$fp) return [null, null, 'fopen failed'];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST   => 'PUT',
    CURLOPT_UPLOAD          => true,
    CURLOPT_INFILE          => $fp,
    CURLOPT_INFILESIZE      => $size,
    CURLOPT_HTTPHEADER      => [
      'Authorization: '.$auth,
      'x-amz-date: '.$amzdate,
      'x-amz-content-sha256: '.$payloadHash,
      'Content-Type: '.$contentType
    ],
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_HEADER          => true,
    CURLOPT_CONNECTTIMEOUT  => 20,
    CURLOPT_TIMEOUT         => 180,
    CURLOPT_NOSIGNAL        => true,
    CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_2TLS, // HTTP/2 if available
  ]);

  return [$ch, $fp, null];
}

/* ---------- Spring helpers (minimal, inline) ---------- */
function spring_base(){
  $s = rtrim(cfg('SPRING_BASE',''),'/');
  return $s ?: null;
}
function spring_headers(){
  $h=['Content-Type: application/json'];
  $auth=trim(cfg('SPRING_AUTH',''));
  if ($auth!=='') $h[]='Authorization: Bearer '.$auth;
  return $h;
}
function http_post_json_min($url,$payload){
  $ch=curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>spring_headers(),
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    CURLOPT_CONNECTTIMEOUT=>10,
    CURLOPT_TIMEOUT=>60,
    CURLOPT_SSL_VERIFYPEER=>false
  ]);
  $resp=curl_exec($ch);
  $code=curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err=curl_error($ch);
  curl_close($ch);
  $js = $resp!==false ? json_decode($resp,true) : null;
  return [$js,$err,$code];
}
function spring_upsert_subject_chapter_min($subject,$chapter,$className,$chapterNo){
  [$sr,$se,] = http_post_json_min(spring_base().'/api/mcq/admin/subjects', ['name'=>$subject,'code'=>null]);
  if ($se || empty($sr['id'])) return [0,0,'subject upsert failed'];
  [$cr,$ce,] = http_post_json_min(spring_base().'/api/mcq/admin/chapters', [
    'subjectId'=>(int)$sr['id'],
    'chapterNo'=>$chapterNo?:null,
    'name'=>$chapter,
    'className'=>$className
  ]);
  if ($ce || empty($cr['id'])) return [(int)$sr['id'],0,'chapter upsert failed'];
  return [(int)$sr['id'], (int)$cr['id'], null];
}
function spring_ingest_now_min($session,$subjectId,$chapterId,$chapterNo,$uploadedBy,$srcName,$extraMeta){
  // Build payload from final.json
  $ff = final_path($session);
  $data = read_json_file($ff);
  if (!is_array($data)) return [null,'final.json unreadable',0];
  $items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : (is_array($data)?$data:[]);
  $payload = [
    'sessionSlug'=>$session,
    'sourceName'=>$srcName ?: ($session.'.docx'),
    'subjectId'=>$subjectId,
    'chapterId'=>$chapterId,
    'chapterNo'=>$chapterNo?:null,
    'uploadedBy'=>($uploadedBy?:cfg('UPLOADED_BY','')),
    'data'=>['items'=>$items],
    'meta'=>[
      'examTitle'=>$extraMeta['exam']??null,
      'className'=>$extraMeta['class']??null
    ]
  ];
  return http_post_json_min(spring_base().'/api/mcq/ingest', $payload);
}

/* ---------- main ---------- */
if (php_sapi_name()!=='cli') { http_response_code(400); echo "CLI only\n"; exit(1); }
$session = $argv[1] ?? '';
$session = preg_replace('/[^A-Za-z0-9\-]/','', $session);
if (!$session){ echo "missing session\n"; exit(2); }

$qfile = qjson_path($session);
$dir   = session_dir($session);
if (!is_file($qfile) || !is_dir($dir)){
  put_status($session,['state'=>'error','phase'=>'upload','message'=>'Session missing']);
  exit(3);
}

$acct = cfg('R2_ACCOUNT_ID'); $ak = cfg('R2_ACCESS_KEY_ID'); $sk = cfg('R2_SECRET_ACCESS_KEY');
$bucket = cfg('R2_BUCKET') ?: 'mcq-assets';
$public = rtrim(cfg('R2_PUBLIC_BASE',''),'/');
if (!$acct || !$ak || !$sk || !$public){
  put_status($session,['state'=>'error','phase'=>'upload','message'=>'R2 env not set']);
  exit(4);
}

$concurrency = (int)(cfg('UPLOAD_CONCURRENCY', 8));
if ($concurrency < 2)  $concurrency = 2;
if ($concurrency > 16) $concurrency = 16;
$flushEvery = 25; // flush map/status every N completions

// Gather files
$assetsRoot = $dir.DIRECTORY_SEPARATOR.'assets';
$files = [];
if (is_dir($assetsRoot)) {
  $it = new RecursiveDirectoryIterator($assetsRoot, FilesystemIterator::SKIP_DOTS);
  foreach (new RecursiveIteratorIterator($it) as $f) {
    if ($f->isFile()) {
      $rel = str_replace('\\','/', substr($f->getPathname(), strlen($dir)+1)); // e.g. assets/media/image1.png
      $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
      if (in_array($ext, ['png','jpg','jpeg','gif','webp','svg','bmp','tif','tiff'])) {
        $files[] = $rel;
      }
    }
  }
}
$total = count($files);
if ($total===0){
  // No images → final.json is just questions.json
  @copy($qfile, final_path($session));
  put_status($session,['state'=>'uploaded','phase'=>'upload','message'=>'No images to upload','progress'=>100,'uploaded'=>0,'total'=>0]);
  // Attempt auto-ingest if requested
  goto AUTO_INGEST;
}

// Load existing map (resume)
$mapFile = map_path($session);
$map = is_file($mapFile) ? (json_decode(@file_get_contents($mapFile), true) ?: []) : [];

// Filter out already-uploaded (mapped) files
$queue = [];
foreach ($files as $rel) { if (empty($map[$rel])) $queue[] = $rel; }
$already = $total - count($queue);

$mh = curl_multi_init();
$active = []; // curl handle => [rel, fp, key]
$done = $already;
$uploadedNow = 0;
$errors = [];

put_status($session, [
  'state'=>'uploading','phase'=>'upload',
  'message'=>"Uploading… ($done/$total)",
  'progress'=> (int)floor($done*100/$total),
  'total'=>$total, 'concurrency'=>$concurrency
]);

// Helper to add one handle
$add_handle = function($rel) use ($session,$dir,$acct,$ak,$sk,$bucket,$public,$mh,&$active,&$errors){
  $path = $dir.DIRECTORY_SEPARATOR.str_replace('/','\\',$rel);
  if (!is_file($path)) { $errors[]="missing $rel"; return false; }
  $key  = $session.'/'.$rel;
  $mime = mime_for($path);
  [$ch,$fp,$err] = build_r2_put_handle($acct,$ak,$sk,$bucket,$key,$path,$mime);
  if (!$ch){ $errors[] = "$rel => $err"; return false; }
  curl_multi_add_handle($mh, $ch);
  $active[(int)$ch] = ['rel'=>$rel,'fp'=>$fp,'key'=>$key];
  return true;
};

// Prime lanes
while (count($active) < $concurrency && !empty($queue)) {
  $rel = array_shift($queue);
  $add_handle($rel);
}

// Event loop
do {
  $mrc = curl_multi_exec($mh, $running);
  if ($mrc === CURLM_OK) {
    $nf = curl_multi_select($mh, 1.0);
    if ($nf === -1) usleep(100000); // avoid busy loop

    while ($info = curl_multi_info_read($mh)) {
      $ch = $info['handle'];
      $meta = $active[(int)$ch] ?? null;
      if (!$meta) continue;

      $rel = $meta['rel'];
      $fp  = $meta['fp'];
      $key = $meta['key'];

      $resp = curl_multi_getcontent($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      if (is_resource($fp)) fclose($fp);
      curl_multi_remove_handle($mh, $ch);
      curl_close($ch);
      unset($active[(int)$ch]);

      if ($code>=200 && $code<300) {
        $map[$rel] = $public.'/'.$key;
        $uploadedNow++;
      } else {
        $errors[] = "$rel => HTTP $code";
      }

      $done++;
      if (!empty($queue)) {
        $nextRel = array_shift($queue);
        $add_handle($nextRel);
      }

      // Periodic flush
      if ($done % $flushEvery === 0 || $done === $total) {
        @file_put_contents($mapFile, json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
        $pct = max(1, (int)floor($done*100/$total));
        put_status($session, [
          'state'=>'uploading','phase'=>'upload',
          'message'=>"Uploading… ($done/$total)",
          'progress'=>$pct,'uploaded'=>$uploadedNow,'errors'=>count($errors)
        ]);
      }
    }
  }
} while ($running || !empty($active));

curl_multi_close($mh);

// Final flush of map
@file_put_contents($mapFile, json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

// Finish: rewrite questions.json -> final.json with CDN URLs
$qJsonStr = @file_get_contents($qfile);
if ($qJsonStr===false){
  put_status($session, ['state'=>'error','phase'=>'upload','message'=>'Cannot read questions.json']);
  exit(5);
}
if (!empty($map)){
  foreach ($map as $rel => $url){
    $qJsonStr = str_replace('src="'.$rel.'"', 'src="'.$url.'"', $qJsonStr);
    $qJsonStr = str_replace("src='".$rel."'", "src='".$url."'", $qJsonStr);
    $qJsonStr = str_replace($rel, $url, $qJsonStr); // assets/… is distinctive; low collision risk
  }
}
@file_put_contents(final_path($session), $qJsonStr);

// Mark complete (we'll update this again after trying Spring)
$finalMsg = $errors ? ('Uploaded with warnings (errors: '.count($errors).')') : 'CDN URLs ready';
put_status($session, [
  'state'=>'uploaded','phase'=>'upload','message'=>$finalMsg,
  'progress'=>100,'uploaded'=>$uploadedNow,'total'=>$total,'error_list'=>$errors
]);

/* ---------- Auto-ingest to Spring if requested ---------- */
AUTO_INGEST:
try {
  $reqFile = session_dir($session).DIRECTORY_SEPARATOR.'ingest-request.json';
  if (is_file($reqFile) && spring_base()) {
    $req = read_json_file($reqFile) ?: [];
    $subject   = $req['subject']   ?? null;
    $chapter   = $req['chapter']   ?? null;
    $class     = $req['class']     ?? null;
    $chapterNo = $req['chapterNo'] ?? null;
    $uploadedBy= $req['uploadedBy']?? cfg('UPLOADED_BY','');
    $extraMeta = ['exam'=>$req['exam'] ?? null, 'class'=>$class];

    if ($subject && $chapter && $class) {
      [$subjectId,$chapterId,$err] = spring_upsert_subject_chapter_min($subject,$chapter,$class,$chapterNo);
      if (!$err && $subjectId && $chapterId) {
        $srcName = is_file(session_dir($session).DIRECTORY_SEPARATOR.'upload.docx') ? 'upload.docx' : ($session.'.docx');
        [$ingRes,$ingErr,$ingCode] = spring_ingest_now_min($session,$subjectId,$chapterId,$chapterNo,$uploadedBy,$srcName,$extraMeta);
        @file_put_contents(session_dir($session).DIRECTORY_SEPARATOR.'ingested.json',
          json_encode(['ok'=>$ingRes['ok']??false,'code'=>$ingCode,'err'=>$ingErr,'ts'=>date('c')], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        if (!$ingErr && !empty($ingRes['ok'])) {
          // reflect success in status.json
          put_status($session, ['db_ingest'=>'ok','db_result'=>$ingRes]);
        } else {
          put_status($session, ['db_ingest'=>'error','db_error'=>$ingErr ?: 'ingest failed','db_status'=>$ingCode,'db_result'=>$ingRes]);
        }
      } else {
        put_status($session, ['db_ingest'=>'error','db_error'=>$err ?: 'subject/chapter upsert failed']);
      }
    } else {
      // missing fields; skip
      put_status($session, ['db_ingest'=>'skipped','db_reason'=>'ingest-request.json missing subject/chapter/class']);
    }
  }
} catch (Throwable $e) {
  put_status($session, ['db_ingest'=>'error','db_error'=>$e->getMessage()]);
}

exit(0);
