<?php
// C:\mcq-extractor\public\app\lib.php

function logmsg($s){ global $LOGS; $LOGS[] = $s; }
function out_json($arr, $code=200){ http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

function haveBinary(string $exe): bool {
  $tool = stripos(PHP_OS,'WIN')===0 ? 'where' : 'which';
  @exec("$tool $exe", $out, $ret);
  return $ret===0 && !empty($out);
}
function detect_imagemagick_bin(): ?string {
  if (haveBinary('magick'))  return 'magick';
  if (haveBinary('convert')) return 'convert';
  return null;
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
  return strtr($s,[
    "â€™"=>"’","â€˜"=>"‘","â€œ"=>"“","â€"=>"”","â€“"=>"–","â€”"=>"—","â€¦"=>"…",
    "Â©"=>"©","Â®"=>"®","Â±"=>"±","Â§"=>"§","Â·"=>"·","Â"=>"" ]);
}

/**
 * Convert WMF/EMF/EMZ to PNG (white matte) at high DPI for clarity.
 */
function convert_vector_to_png(string $abs, string $imBin, int $dpi): ?string {
  $png = preg_replace('/\.(wmf|emf|emz)$/i', '.png', $abs);
  if (is_file($png)) return $png;
  if (!$imBin) return null;
  $cmd = $imBin.' '.escapeshellarg($abs).' -density '.$dpi.' -colorspace sRGB -background white -alpha remove -alpha off '.escapeshellarg($png);
  @exec($cmd.' 2>&1', $o, $r); clearstatcache();
  return is_file($png) ? $png : null;
}

/**
 * HTML → JSON extractor (tables with [Question, Option, Answer, Solution])
 * Also normalizes <img src> to session-relative paths and converts WMF/EMF.
 */
function build_json_from_html(string $session, string $sessionDir, string $htmlPath, ?string $imBin): array {
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

    // skip absolute web/data paths
    if (preg_match('#^(?:https?:)?//#i',$src) || stripos($src,'data:')===0 || strpos($src,'/')===0) {
      continue;
    }

    // Resolve to absolute file path relative to the HTML file
    $abs = realpath(dirname($htmlPath).DIRECTORY_SEPARATOR.$src) ?: $src;
    if (!is_file($abs)) continue;

    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $target = $abs;

    if (in_array($ext,['wmf','emf','emz'], true)) {
      $wmfFound++;
      $png = convert_vector_to_png($abs, $imBin, WMF_DPI);
      if ($png) { $target = $png; $converted++; }
    }

    // rewrite to session-relative (web forward slashes)
    $rel = normalizeAssetSrc(relPathFrom($target, $sessionDir));
    $img->setAttribute('src', $rel);
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
