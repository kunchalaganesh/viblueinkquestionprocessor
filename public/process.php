<?php
// C:\mcq-extractor\public\process.php
require __DIR__ . '/app/config.php';
require __DIR__ . '/app/lib.php';

error_reporting(E_ALL);
ini_set('display_errors','0');

global $LOGS; $LOGS = [];

set_error_handler(function($no,$str,$file,$line){ throw new ErrorException($str,0,$no,$file,$line); });
set_exception_handler(function($ex){ out_json(['ok'=>false,'error'=>'PHP exception','details'=>$ex->getMessage(),'logs'=>$GLOBALS['LOGS']??[]],500); });
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && !headers_sent()){
    out_json(['ok'=>false,'error'=>'PHP fatal','details'=>$e['message'],'logs'=>$GLOBALS['LOGS']??[]],500);
  }
});

if (isset($_GET['health'])) out_json(['ok'=>true,'php'=>PHP_VERSION,'time_limit'=>PHP_TIME_LIMIT]);

// ---------- validate upload ----------
if (empty($_FILES['doc']) || $_FILES['doc']['error']!==UPLOAD_ERR_OK) {
  out_json(['ok'=>false,'error'=>'No DOCX uploaded or upload error.'],400);
}
$origName = $_FILES['doc']['name'] ?? 'upload.docx';
if (!preg_match('/\.docx$/i', $origName)) out_json(['ok'=>false,'error'=>'Only .docx accepted.'],400);
$ocrMode = isset($_POST['ocr']) ? strtolower($_POST['ocr']) : 'off'; // for future OCR wiring

// ---------- session paths ----------
if (!is_dir(SESSIONS_DIR)) @mkdir(SESSIONS_DIR,0777,true);
$slug = clean_name(pathinfo($origName, PATHINFO_FILENAME)); if (!$slug) $slug='session';
$session = $slug.'-'.date('Ymd-His');
$sessionDir = realpath(SESSIONS_DIR) ?: SESSIONS_DIR; $sessionDir .= DIRECTORY_SEPARATOR.$session;
$assetsDir  = $sessionDir.DIRECTORY_SEPARATOR.'assets';
@mkdir($sessionDir,0777,true); @mkdir($assetsDir,0777,true);
$tmpDoc = $sessionDir.DIRECTORY_SEPARATOR.'upload.docx';
if (!move_uploaded_file($_FILES['doc']['tmp_name'], $tmpDoc)) out_json(['ok'=>false,'error'=>'Failed to store uploaded file.'],500);

// ---------- locate tools ----------
if (!haveBinary(PANDOC_BIN)) out_json(['ok'=>false,'error'=>'pandoc not found in PATH'],500);
$imBin = detect_imagemagick_bin();

// ---------- 1) docx -> html (MathML) ----------
$htmlPath = $sessionDir.DIRECTORY_SEPARATOR.'full.html';
$cmd = PANDOC_BIN
     .' --extract-media='.escapeshellarg($assetsDir)
     .' -f docx -t html --mathml '
     .escapeshellarg($tmpDoc).' -o '.escapeshellarg($htmlPath);
logmsg('$ '.$cmd);
[$code,$out] = run($cmd); if ($out) logmsg($out);
if ($code!==0 || !is_file($htmlPath)) out_json(['ok'=>false,'error'=>'pandoc failed','details'=>$out,'logs'=>$LOGS],500);

// ---------- 2) HTML -> JSON (and normalize ALL images) ----------
$build = build_json_from_html($session,$sessionDir,$htmlPath,$imBin);
if (!$build['ok']) out_json(array_merge(['ok'=>false],$build,['logs'=>$LOGS]),500);

// Optional: write a tiny meta.json alongside outputs
file_put_contents($sessionDir.DIRECTORY_SEPARATOR.'meta.json', json_encode([
  'session'=>$session,
  'created'=>date('c'),
  'ocr'=>$ocrMode,
  'wmfFound'=>$build['wmfFound'],
  'wmf2png'=>$build['wmf2png'],
  'php_time_limit'=>PHP_TIME_LIMIT
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

out_json([
  'ok'=>true,
  'session'=>$session,
  'items'=>$build['items'],
  'wmf2png'=>$build['wmf2png'],
  'wmfFound'=>$build['wmfFound'],
  'logs'=>$LOGS
]);
