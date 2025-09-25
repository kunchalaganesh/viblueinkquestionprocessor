<?php
// C:\mcq-extractor\public\worker.php
// Run: php worker.php <session>

@ini_set('max_execution_time','0');
@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('memory_limit','1024M');

if (PHP_SAPI !== 'cli') { http_response_code(400); echo "CLI only"; exit; }

$session = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9\-]+$/', $session)) { echo "bad session\n"; exit(1); }

$baseDir    = __DIR__;
$sessionDir = $baseDir.DIRECTORY_SEPARATOR.'sessions'.DIRECTORY_SEPARATOR.$session;
$assetsDir  = $sessionDir.DIRECTORY_SEPARATOR.'assets';
$htmlPath   = $sessionDir.DIRECTORY_SEPARATOR.'full.html';
$qjsonPath  = $sessionDir.DIRECTORY_SEPARATOR.'questions.json';
$statusPath = $sessionDir.DIRECTORY_SEPARATOR.'status.json';
$logPath    = $sessionDir.DIRECTORY_SEPARATOR.'process.log';
$docxPath   = $sessionDir.DIRECTORY_SEPARATOR.'upload.docx';

function logw($s){ global $logPath; @file_put_contents($logPath, '['.date('H:i:s')."] $s\n", FILE_APPEND); }
function status_set($state,$msg,$pct=null){ global $statusPath;
  $arr=['state'=>$state,'message'=>$msg]; if($pct!==null){$arr['progress']=(int)$pct;}
  $arr['updated_at']=date('c'); @file_put_contents($statusPath, json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function haveBinary(string $exe): bool {
  $tool = stripos(PHP_OS,'WIN')===0 ? 'where' : 'which';
  @exec("$tool $exe", $out, $ret);
  return $ret===0 && !empty($out);
}
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

// job options
$job = @json_decode(@file_get_contents($sessionDir.DIRECTORY_SEPARATOR.'job.json'), true) ?: [];
$speed = ($job['speed'] ?? 'normal')==='fast' ? 'fast' : 'normal';
$wmfDpi = $speed==='fast' ? 288 : (int)(getenv('WMF_DPI') ?: 600);

try{
  status_set('processing','Checking tools…',5);
  logw("session=$session speed=$speed WMF_DPI=$wmfDpi");

  $pandoc = getenv('PANDOC_BIN') ?: 'pandoc';
  if (!haveBinary($pandoc)) throw new RuntimeException('pandoc not found in PATH');
  $magickPresent = haveBinary('magick') || haveBinary('convert');

  if (!is_file($docxPath)) throw new RuntimeException('upload.docx missing');

  // 1) DOCX → HTML (+extract media)
  status_set('processing','Converting DOCX → HTML…',25);
  $cmd = escapeshellarg($pandoc)
       .' --extract-media='.escapeshellarg($assetsDir)
       .' -f docx -t html --mathml '
       .escapeshellarg($docxPath).' -o '.escapeshellarg($htmlPath);
  logw('$ '.$cmd);
  @exec($cmd.' 2>&1', $out, $ret);
  if ($ret!==0 || !is_file($htmlPath)) {
    logw("pandoc failed:\n".implode("\n",$out??[]));
    throw new RuntimeException('pandoc failed');
  }

  // 2) HTML → JSON (normalize images, annotate w/h, convert WMF/EMF)
  status_set('processing','Extracting questions…',70);
  $build = build_json_from_html($session,$sessionDir,$htmlPath,$magickPresent,$wmfDpi);
  if (!$build['ok']) throw new RuntimeException($build['error'] ?? 'build failed');

  @file_put_contents($qjsonPath, json_encode($build['items'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));

  status_set('done','Ready',100);
  logw("done: items={$build['count']} wmfFound={$build['wmfFound']} wmf2png={$build['wmf2png']}");
  exit(0);

} catch(Throwable $e){
  status_set('error','Error: '.$e->getMessage(),100);
  logw('ERROR: '.$e->getMessage());
  exit(1);
}

/* ===== parser ===== */
function build_json_from_html(string $session, string $sessionDir, string $htmlPath, bool $magickPresent, int $wmfDpi): array {
  libxml_use_internal_errors(true);
  $doc = new DOMDocument();
  $raw = file_get_contents($htmlPath);
  $raw = str_replace("\r\n","\n",$raw);
  $ok  = $doc->loadHTML('<?xml encoding="UTF-8" ?>'.$raw, LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_COMPACT);
  if (!$ok) return ['ok'=>false,'error'=>'failed to parse HTML as UTF-8'];

  $xp = new DOMXPath($doc);
  $wmfFound=0; $converted=0;

  foreach ($xp->query('//img') as $img) {
    /** @var DOMElement $img */
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

    // annotate w/h for viewer
    $info = @getimagesize($targetAbs);
    if (is_array($info) && isset($info[0], $info[1])) {
      $img->setAttribute('data-w', (string)$info[0]);
      $img->setAttribute('data-h', (string)$info[1]);
    }

    // rewrite src to session-relative path
    $rel = normalizeAssetSrc(relPathFrom($targetAbs, $sessionDir));
    $img->setAttribute('src', $rel);

    // strip inline size so viewer decides final look
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

  return ['ok'=>true,'items'=>$items,'count'=>count($items),'wmf2png'=>$converted,'wmfFound'=>$wmfFound];
}
