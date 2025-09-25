<?php
// Background worker. Called by api.php: php convert_job.php <session> <speed>

@ini_set('max_execution_time','0');
@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('memory_limit','1024M');

function is_windows(){ return stripos(PHP_OS,'WIN')===0; }
function haveBinary(string $exe): bool {
  $tool = is_windows() ? 'where' : 'which';
  @exec("$tool $exe", $out, $ret);
  return $ret===0 && !empty($out);
}
function run($cmd){ @exec($cmd.' 2>&1', $out, $ret); return [$ret, implode("\n",$out)]; }
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
function write_status($path,$arr){
  $arr['updated_at']=date('c');
  @file_put_contents($path, json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
}

if ($argc<2) exit(1);
$session = preg_replace('/[^A-Za-z0-9\-]/','', $argv[1]);
$speed   = ($argc>=3 && in_array($argv[2],['fast','normal'],true)) ? $argv[2] : 'normal';
$wmfDpi  = ($speed==='fast') ? 288 : (int)(getenv('WMF_DPI') ?: 600);

$baseDir     = __DIR__;
$sessionsDir = $baseDir.DIRECTORY_SEPARATOR.'sessions';
$sessionDir  = $sessionsDir.DIRECTORY_SEPARATOR.$session;
$assetsDir   = $sessionDir.DIRECTORY_SEPARATOR.'assets';
$statusPath  = $sessionDir.DIRECTORY_SEPARATOR.'status.json';
$htmlPath    = $sessionDir.DIRECTORY_SEPARATOR.'full.html';
$tmpDoc      = $sessionDir.DIRECTORY_SEPARATOR.'upload.docx';

$state = ['ok'=>true,'session'=>$session,'state'=>'running','progress'=>5,'message'=>'Starting','speed'=>$speed];
write_status($statusPath,$state);

// 1) pandoc
$pandoc = getenv('PANDOC_BIN') ?: 'pandoc';
if (!haveBinary($pandoc)) { write_status($statusPath, ['ok'=>false,'session'=>$session,'state'=>'error','message'=>'pandoc not found']); exit(2); }

$state['message']='Converting DOCX → HTML'; $state['progress']=20; write_status($statusPath,$state);
@mkdir($assetsDir,0777,true);

$cmd = $pandoc.' --extract-media='.escapeshellarg($assetsDir).' -f docx -t html --mathml '
     .escapeshellarg($tmpDoc).' -o '.escapeshellarg($htmlPath);
[$code,$out] = run($cmd);
if ($code!==0 || !is_file($htmlPath)) { write_status($statusPath, ['ok'=>false,'session'=>$session,'state'=>'error','message'=>'pandoc failed','details'=>$out]); exit(3); }

// 2) HTML -> JSON (normalize/annotate images)
$state['message']='Extracting questions'; $state['progress']=55; write_status($statusPath,$state);
$res = build_json_from_html($session,$sessionDir,$htmlPath,$wmfDpi);
if (!$res['ok']) { write_status($statusPath, array_merge(['session'=>$session,'state'=>'error'],$res)); exit(4); }

// 3) Done
$state = array_merge($state, [
  'message'=>'Done',
  'state'=>'done',
  'progress'=>100,
  'items'=>$res['items'],
  'wmfFound'=>$res['wmfFound'],
  'wmf2png'=>$res['wmf2png'],
]);
write_status($statusPath,$state);
exit(0);

/* --------- build_json_from_html ---------- */
function build_json_from_html(string $session, string $sessionDir, string $htmlPath, int $wmfDpi): array {
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

    // skip http(s)/data:/absolute
    if (preg_match('#^(?:https?:)?//#i',$src) || stripos($src,'data:')===0 || strpos($src,'/')===0) continue;

    $abs = $src;
    if (!preg_match('#^[A-Za-z]:/#',$abs) && strpos($abs,'//')!==0) {
      $abs = realpath(dirname($htmlPath).DIRECTORY_SEPARATOR.$src) ?: $src;
    }
    if (!is_file($abs)) continue;

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $targetAbs = $abs;

    if (in_array($ext,['wmf','emf','emz'], true)) {
      $wmfFound++;
      $pngAbs = preg_replace('/\.(wmf|emf|emz)$/i','.png',$abs);
      if (!is_file($pngAbs) && (haveBinary('magick') || haveBinary('convert'))) {
        $bin = haveBinary('magick') ? 'magick' : 'convert';
        $cmd = $bin.' '.escapeshellarg($abs)
             .' -density '.$wmfDpi.' -colorspace sRGB -background white -alpha remove -alpha off '
             .escapeshellarg($pngAbs);
        @exec($cmd.' 2>&1', $o, $r); clearstatcache();
      }
      if (is_file($pngAbs)) { $targetAbs = $pngAbs; $converted++; }
    }

    // annotate intrinsic size (used by preview.js for smart sizing)
    $info = @getimagesize($targetAbs);
    if (is_array($info) && isset($info[0], $info[1])) {
      $img->setAttribute('data-w', (string)$info[0]);
      $img->setAttribute('data-h', (string)$info[1]);
    }

    // rewrite src to session-relative path + strip inline size
    $rel = normalizeAssetSrc(relPathFrom($targetAbs, $sessionDir));
    $img->setAttribute('src', $rel);
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
  $textDeep = function(DOMNode $n):string{ return fixMojibake(trim(preg_replace('/\s+/u',' ', $n->textContent??''))); };
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
