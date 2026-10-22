<?php
declare(strict_types=1);
ini_set('display_errors','0');
$root=dirname(__DIR__);$configPath=$root.'/config/config.php';
if(is_file($configPath)){http_response_code(403);exit('Setup is locked. Open index.php for Owner setup or sign in. Never rerun installation over existing records.');}
$keyPath=$root.'/config/setup.key';
if(!is_file($keyPath)){http_response_code(403);exit('Run bin/prepare-install.php first, or follow INSTALL.md to create a private setup.key.');}
$local=in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true);
$https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
if(!$local&&!$https){http_response_code(400);exit('Use HTTPS for installation.');}
require $root.'/app/Schema.php';
require $root.'/app/Security.php';Aelvo\Security::start(!$local);
header('X-Robots-Tag: noindex, nofollow');
$checks=['PHP 8.2 or newer'=>PHP_VERSION_ID>=80200,'PDO MySQL'=>extension_loaded('pdo_mysql'),'Sodium encryption'=>extension_loaded('sodium'),'File information'=>extension_loaded('fileinfo'),'cURL for providers'=>extension_loaded('curl'),'Writable private config'=>is_writable($root.'/config')];
$error='';$success=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
 Aelvo\Security::csrf();$lock=null;
 try{
  if(in_array(false,$checks,true))throw new RuntimeException('Resolve failed preflight checks first.');
  $expected=trim(file_get_contents($keyPath));$token=$_POST['token']??'';
  if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||!hash_equals($expected,$token))throw new RuntimeException('Invalid installation key.');
  foreach(['host','port','name','user','password'] as $field)if(!is_string($_POST[$field]??null))throw new RuntimeException('Invalid database settings.');
  $host=trim($_POST['host']);$name=trim($_POST['name']);$user=trim($_POST['user']);$password=$_POST['password'];$port=filter_var($_POST['port'],FILTER_VALIDATE_INT);
  if(!preg_match('/^[a-zA-Z0-9_.:-]{1,253}$/D',$host)||!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$name)||!$port||$port<1||$port>65535||$user===''||strlen($user)>128||strlen($password)>500)throw new RuntimeException('Check database host, port, name and credentials.');
  $lock=fopen($root.'/config/install.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot acquire installation lock.');
  if(is_file($configPath))throw new RuntimeException('Another installation already configured this store.');
  $db=['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'password'=>$password];
  $pdo=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
  if($pdo->query('SHOW TABLES')->fetch())throw new RuntimeException('Use an empty dedicated database. Existing databases are not overwritten.');
  // Import into a strictly empty database first. A failed MySQL DDL operation
  // may leave partial tables; do not write a usable site config in that case.
  Aelvo\Schema::migrate($pdo);
  $config=['db'=>$db,'install_token'=>$expected,'secure_cookies'=>$https,'encryption_key'=>bin2hex(random_bytes(32))];
  $bytes="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
  $file=fopen($configPath,'x');
  if(!$file)throw new RuntimeException('Configuration could not be created. Do not rerun over populated database.');
  try {
    if(fwrite($file,$bytes)!==strlen($bytes) || !fflush($file))throw new RuntimeException('Configuration write failed.');
  }catch(Throwable $writeError){fclose($file);unlink($configPath);throw $writeError;}
  fclose($file);chmod($configPath,0600);
  unlink($keyPath);$success=true;
 }catch(Throwable $e){$error=$e instanceof PDOException?'Database connection or schema setup failed. A partial SQL import can remain; inspect logs and use a NEW EMPTY database before retrying.':$e->getMessage();error_log('AELVO installer: '.get_class($e));}
 finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
}
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AELVO installation</title><link rel="stylesheet" href="backend.css"></head><body><main><a class="brand" href="store.php">AELVO</a><h1>Install your store</h1>
<?php if($success):?><p>Database and private configuration created. Keep your installation key for the one-time Owner account step.</p><a class="action" href="index.php">Create Owner account</a>
<?php else:?><ul><?php foreach($checks as $label=>$ok)echo '<li>'.($ok?'Ready: ':'Missing: ').e($label).'</li>';?></ul><?php if($error):?><p role="alert" class="error"><?=e($error)?></p><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><label>Private installation key<input name="token" type="password" autocomplete="off" required></label><label>Database host<input name="host" value="127.0.0.1" required></label><label>Port<input name="port" type="number" value="3306" required></label><label>Empty database name<input name="name" required></label><label>Database user<input name="user" autocomplete="off" required></label><label>Database password<input name="password" type="password" autocomplete="off"></label><p>Provider keys can be added after installation. Signup and guest checkout remain unavailable until SMS is configured.</p><button <?=in_array(false,$checks,true)?'disabled':''?>>Install database</button></form><?php endif;?></main></body></html>
