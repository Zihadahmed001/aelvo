<?php
declare(strict_types=1);
function tr(string $en,string $bn):string{return $en;}
function esc(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function photo(array $p,string $colour=''):string{return 'assets/placeholder.svg';}
function nameOf(array $p):string{return (string)$p['name'];}
$p=['name'=>'Sage Bag','variants'=>[
 ['id'=>3,'colour'=>'Olive','colour_hex'=>'#466b5c','size'=>'S','available'=>3],
 ['id'=>4,'colour'=>'Olive','colour_hex'=>'#466b5c','size'=>'M','available'=>0],
 ['id'=>5,'colour'=>'<script>alert(1)</script>','colour_hex'=>'#bad_hex','size'=>'M','available'=>1],
]];
ob_start();require dirname(__DIR__).'/public/variant-picker-view.php';$html=ob_get_clean();
foreach(['data-variant-picker hidden','data-pick-colour="Olive"','data-pick-size="S"','value="3"','data-available="3"','value="4"','disabled','fill="#466b5c"','fill="#d3dcd5"'] as $expected)
 if(!str_contains($html,$expected))throw new RuntimeException('Missing variant picker markup: '.$expected);
if(str_contains($html,'<script>') || !str_contains($html,'&lt;script&gt;'))throw new RuntimeException('Variant colour was not escaped');
if(!str_contains($html,'name="variant_id"') || !str_contains($html,'required'))throw new RuntimeException('Server-side select fallback absent');
echo "Variant picker: stock, bilingual-ready markup and HTML-escaped colour assertions passed (offline).\n";
