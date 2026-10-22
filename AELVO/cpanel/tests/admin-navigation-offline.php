<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Security.php';
require dirname(__DIR__).'/app/AdminView.php';
use Aelvo\AdminView;
function assertNav(bool $ok, string $message): void { if(!$ok)throw new RuntimeException($message); }

$model=AdminView::mobileTabModel(['dashboard','orders','inventory','finance','team'],'inventory');
assertNav($model['count']===5&&$model['active']===2&&!$model['more_active'],'Approved five-tab order changed');
assertNav(array_keys($model['tabs'])===['dashboard','orders','inventory','finance'],'Fixed tab order incorrect');
$model=AdminView::mobileTabModel(['dashboard','support','inventory'],'support');
assertNav($model['count']===3&&$model['active']===2&&$model['more_active'],'Extra module should activate More');
assertNav(array_keys($model['tabs'])===['dashboard','inventory'],'Unauthorized pinned tabs exposed');
$model=AdminView::mobileTabModel([], 'settings');
assertNav($model['count']===1&&$model['active']===0&&$model['tabs']===[],'Empty staff permissions exposed a tab');

final class NavigationStubPDO extends PDO {
    public function __construct(public array $allow){}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        return new NavigationStubStatement($this,$query);
    }
}
final class NavigationStubStatement extends PDOStatement {
    private array $params=[];
    public function __construct(private NavigationStubPDO $db,private string $sql){}
    public function execute(?array $params=null):bool {$this->params=$params??[];return true;}
    public function fetchColumn(int $column=0):mixed {
        return in_array(($this->params[1]??'').':'.($this->params[2]??''),$this->db->allow,true)?1:false;
    }
}
$db=new NavigationStubPDO(['dashboard:view','inventory:view','support:view']);
$staff=['id'=>2,'name'=>'Operations staff','status'=>'Active','is_owner'=>false];
ob_start();AdminView::mobileNavigation($db,$staff,'support');$html=ob_get_clean();
foreach(['Mobile administration','?page=dashboard','?page=inventory','?page=support','admin-tabs-count-3','More Admin modules','data-active="true"'] as $piece)
    assertNav(str_contains($html,$piece),'Missing permitted mobile navigation '.$piece);
assertNav(!str_contains($html, ' style=')&&!str_contains($html, '<script'), 'Mobile navigation violates strict style/script CSP');
foreach(['?page=finance','?page=orders','?page=team','?page=owner-ai','?page=activity'] as $bad)
    assertNav(!str_contains($html,$bad),'Unauthorized module in mobile menu '.$bad);
ob_start();AdminView::moduleFinder($db,$staff);$search=ob_get_clean();
foreach(['?page=dashboard','?page=inventory','?page=support','data-admin-module-query'] as $piece)
    assertNav(str_contains($search,$piece),'Missing authorized module finder '.$piece);
foreach(['?page=orders','?page=finance','?page=team','?page=owner-ai'] as $bad)
    assertNav(!str_contains($search,$bad),'Unauthorized module in Finder '.$bad);
ob_start();AdminView::navigation($db,$staff,'support');$desktop=ob_get_clean();
assertNav(str_contains($desktop,'aria-current="page"')&&!str_contains($desktop,'?page=finance'),'Sidebar permission drift');
$db->allow=['dashboard:view'];$staff['status']='Disabled';
ob_start();AdminView::mobileNavigation($db,$staff,'dashboard');$disabled=ob_get_clean();
assertNav(!str_contains($disabled,'?page=dashboard'),'Disabled staff can see module links');

$source=file_get_contents(dirname(__DIR__).'/public/index.php');
$css=file_get_contents(dirname(__DIR__).'/public/backend.css');
$js=file_get_contents(dirname(__DIR__).'/public/admin-mobile.js');
assertNav(str_contains($source,'AdminView::mobileNavigation($pdo,$actor,$page)')&&str_contains($source,'AdminView::moduleFinder($pdo,$actor)'),'Navigation not wired to the real Admin entry');
assertNav(str_contains($css,'prefers-reduced-motion')&&str_contains($css,'safe-area-inset-bottom')&&str_contains($css,'@media print'),'Mobile motion/viewport/print safeguards missing');
assertNav(str_contains($js,'Escape')&&str_contains($js,'[data-admin-module-link]'),'Accessible dismissal/filter behavior missing');
echo "Admin mobile tabs, RBAC isolation, More panel, module finder and fallback wiring passed (offline).\n";
