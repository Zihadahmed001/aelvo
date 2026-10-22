<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: text/css; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
$theme=Aelvo\Settings::get($pdo,'theme')['value'];$accent=$theme['accent']??'#466b5c';$surface=$theme['surface']??'#f2f5ef';
if(!preg_match('/^#[a-f0-9]{6}$/iD',$accent)||!preg_match('/^#[a-f0-9]{6}$/iD',$surface))exit;
function contrast(string $hex):string{$n=array_map(fn($h)=>hexdec($h)/255,str_split(substr($hex,1),2));$n=array_map(fn($v)=>$v<=.04045?$v/12.92:(($v+.055)/1.055)**2.4,$n);$l=$n[0]*.2126+$n[1]*.7152+$n[2]*.0722;return ($l+.05)/.05>1.05/($l+.05)?'#000000':'#ffffff';}
function tint(string $hex,float $amount):string{$a=str_split(substr($hex,1),2);$b=['fa','f9','f5'];$result='#';foreach($a as $i=>$h)$result.=str_pad(dechex((int)round(hexdec($h)*(1-$amount)+hexdec($b[$i])*$amount)),2,'0',STR_PAD_LEFT);return $result;}
$light=tint($accent,.86);$border=tint($accent,.7);$canvas=tint($accent,.97);$on=contrast($accent);$onLight=contrast($light);
echo ":root{--site-accent:$accent;--site-on-accent:$on;--site-tint:$light;--site-on-tint:$onLight;--site-border:$border;--site-canvas:$canvas;--site-soft:$surface}";
