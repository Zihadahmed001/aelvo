<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/Schema.php';
use Aelvo\Schema;
$source="-- semicolon inside comment; not command\nCREATE TABLE x (s VARCHAR(50) DEFAULT 'a;b');\n# another comment;\nINSERT INTO x VALUES ('x;\\'y');";
$commands=Schema::statements($source);
if(count($commands)!==2 || !str_contains($commands[0],"'a;b'") || !str_contains($commands[1],"'x;\\'y'"))throw new RuntimeException('SQL command parser failed.');
foreach(Schema::FILES as $name){
    $sql=file_get_contents(dirname(__DIR__).'/database/'.$name.'.sql');
    if($sql===false||!Schema::statements($sql))throw new RuntimeException('Missing or empty schema: '.$name);
}
echo "Bundled SQL command boundary checks completed; no database migration executed.\n";
