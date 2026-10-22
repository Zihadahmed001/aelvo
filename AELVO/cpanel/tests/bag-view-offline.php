<?php
declare(strict_types=1);
namespace Aelvo {
 final class PublicRoutes {
  public static function productPath(string $slug): string {return 'product/'.$slug;}
  public static function localUrl(string $path=''): string {return '/'.$path;}
 }
}
namespace {
 function esc(mixed $value): string {return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
 function tr(string $en,string $bn): string {return $en;}
 function photo(array $p,string $colour=''): string {return 'assets/placeholder.svg';}
 function photoAlt(array $p,string $colour=''): string {return $p['name'];}
 function nameOf(array $p): string {return $p['name'];}
 function renderBag(array $bag,array $variants): string {
  $_SESSION=['bag'=>$bag,'csrf'=>'dummy-offline-token'];
  $pdo='offline';$page='cart';
  ob_start();require dirname(__DIR__).'/public/bag-checkout-view.php';return ob_get_clean();
 }
 $product=['slug'=>'sage-bag','name'=>'Sage Bag','price_minor'=>125000];
 $variant=['colour'=>'Sage','size'=>'One Size','available'=>2,'price_minor'=>125000];
 $empty=renderBag([],[]);
 if(!str_contains($empty,'Your bag is waiting') || str_contains($empty,'Proceed to checkout'))throw new \RuntimeException('Empty bag rendering failed');
 $normal=renderBag([42=>2],[42=>[$product,$variant]]);
 foreach(['name="action" value="bag-quantity"','name="variant_id" value="42"','name="qty"','Proceed to checkout','৳2,500.00','/product/sage-bag'] as $needle)
  if(!str_contains($normal,$needle))throw new \RuntimeException('Expected available-bag markup missing: '.$needle);
 // A variant-specific price must supersede the product base price even without JavaScript.
 $premium=$variant;$premium['price_minor']=179900;
 $override=renderBag([42=>2],[42=>[$product,$premium]]);
 if(!str_contains($override,'৳3,598.00'))throw new \RuntimeException('Bag does not use the effective variant price');
 $limited=renderBag([42=>3],[42=>[$product,$variant]]);
 if(str_contains($limited,'Proceed to checkout') || !str_contains($limited,'Only') || !str_contains($limited,'role="alert"'))throw new \RuntimeException('Insufficient-stock action was exposed');
 $missing=renderBag([900=>1],[]);
 if(str_contains($missing,'Proceed to checkout') || !str_contains($missing,'Remove it to continue'))throw new \RuntimeException('Missing-variant action was exposed');
 echo "Bag view: empty, in-stock, shortage and missing-variant markup passed (offline).\n";
}
