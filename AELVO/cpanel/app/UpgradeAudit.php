<?php
declare(strict_types=1);
namespace Aelvo;

/** Read-only release integrity and migration preflight; NEVER applies SQL or restores data. */
final class UpgradeAudit {
    /**
     * The release file manifest is an accidental-corruption/tampering signal, not
     * an independent cryptographic publisher signature. Keep it PRIVATE to Owner.
     */
    public static function verifyPackage(string $cpanelDirectory): array {
        $root=realpath($cpanelDirectory);
        if($root===false)throw new \RuntimeException('Project directory is missing.');
        $manifestPath=$root.'/RELEASE-MANIFEST.json';
        $raw=@file_get_contents($manifestPath);
        if(!is_string($raw))throw new \RuntimeException('Release file manifest is absent.');
        try{$manifest=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new \RuntimeException('Release file manifest is invalid.',0,$e);}
        if(!is_array($manifest)||!isset($manifest['files'])||!is_array($manifest['files'])||!$manifest['files'])throw new \RuntimeException('Release file manifest contains no files.');
        $seen=[];$total=0;
        foreach($manifest['files'] as $entry){
            if(!is_array($entry)||!is_string($entry['path']??null)||!is_string($entry['sha256']??null)||!is_int($entry['bytes']??null))throw new \RuntimeException('Invalid file manifest entry.');
            $path=$entry['path'];
            if(!self::safeRelativePath($path)||isset($seen[$path])||$path==='RELEASE-MANIFEST.json')throw new \RuntimeException('Unsafe or duplicate file manifest path.');
            $seen[$path]=true;
            if(!preg_match('/^[a-f0-9]{64}$/D',$entry['sha256'])||$entry['bytes']<0)throw new \RuntimeException('Invalid file manifest digest.');
            $absolute=realpath($root.'/'.$path);
            if($absolute===false||is_link($root.'/'.$path)||!str_starts_with($absolute,$root.DIRECTORY_SEPARATOR)||!is_file($absolute))throw new \RuntimeException('Release file missing or outside project: '.$path);
            if(filesize($absolute)!==$entry['bytes']||!hash_equals($entry['sha256'],hash_file('sha256',$absolute)))throw new \RuntimeException('Release file does not match manifest: '.$path);
            $total++;
        }
        // Reject additional executable/database source that the release manifest
        // did not list. Generated config.php, setup.key and private uploads are
        // explicitly excluded; those are not source-package artifacts.
        foreach(['app','public','database','bin','tests'] as $subdir){
            $directory=$root.'/'.$subdir;
            if(!is_dir($directory))throw new \RuntimeException('Missing release directory: '.$subdir);
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $file){
                if($file->isLink())throw new \RuntimeException('Symbolic link inside release source is not allowed.');
                if(!$file->isFile())continue;
                $ext=strtolower($file->getExtension());
                if(!in_array($ext,['php','sql','js','css'],true))continue;
                $rel=substr($file->getPathname(),strlen($root)+1);
                $rel=str_replace(DIRECTORY_SEPARATOR,'/',$rel);
                if(!isset($seen[$rel]))throw new \RuntimeException('Untracked executable/database source: '.$rel);
            }
        }
        return ['files'=>$total,'sha256'=>hash('sha256',$raw)];
    }

    public static function safeRelativePath(string $path): bool {
        if($path===''||strlen($path)>512||str_contains($path,'\\')||str_contains($path,'//')||!preg_match('~^[a-zA-Z0-9_./-]+$~D',$path))return false;
        foreach(explode('/',$path) as $piece)if($piece===''||$piece==='.'||$piece==='..')return false;
        return true;
    }

    /** Pure fingerprint that changes with ANY applied/missing migration or SQL byte change. */
    public static function fingerprint(array $package,array $schemas,array $plan): string {
        $sequence=[];
        foreach($schemas as $version=>$entry){
            $sequence[]=[(int)$version,$entry['name'],$entry['sha256'],in_array((int)$version,$plan['applied'],true)?'applied':'pending'];
        }
        return hash('sha256',json_encode([$package['sha256'],$plan['mode'],$sequence],JSON_THROW_ON_ERROR));
    }

    public static function environment(string $cpanelDirectory,array $config,array $server): array {
        $checks=[];$warnings=[];
        $checks['PHP 8.2+']=PHP_VERSION_ID>=80200;
        foreach(['pdo_mysql','sodium','fileinfo','curl'] as $extension)$checks['PHP extension '.$extension]=extension_loaded($extension);
        $checks['Private config exists']=is_file($cpanelDirectory.'/config/config.php');
        $checks['Random key configured']=is_string($config['encryption_key']??null)&&preg_match('/^[a-f0-9]{64}$/D',$config['encryption_key'])===1;
        $documentRoot=realpath((string)($server['DOCUMENT_ROOT']??''));
        $cpanelRoot=realpath($cpanelDirectory);
        if($documentRoot===false){$checks['Document root inspected']=false;$warnings[]='Server document root could not be verified from this request.';}
        elseif($cpanelRoot!==false){
            $privatePaths=['app','bin','config','database','storage','tests'];
            foreach($privatePaths as $folder){
                $privateRoot=realpath($cpanelRoot.'/'.$folder);
                if($privateRoot!==false && ($privateRoot===$documentRoot||str_starts_with($privateRoot,$documentRoot.DIRECTORY_SEPARATOR)))$checks['Private '.$folder.' outside web root']=false;
            }
            // This also prevents installing with cpanel/ as the document root.
            if($documentRoot===$cpanelRoot)$checks['Only public/ is web-served']=false;
        }
        $isLocal=in_array((string)($server['REMOTE_ADDR']??''),['127.0.0.1','::1'],true);
        $isHttps=!empty($server['HTTPS'])&&$server['HTTPS']!=='off';
        $checks['HTTPS outside localhost']=$isLocal||$isHttps;
        if(!($config['secure_cookies']??false)&&!$isLocal)$checks['Secure cookies configured for production']=false;
        $checks['Private storage directory']=is_dir($cpanelDirectory.'/storage');
        return ['checks'=>$checks,'warnings'=>$warnings,'ready'=>!in_array(false,$checks,true)];
    }

    /** Each operation is read only. Report deliberately excludes credentials/data. */
    public static function report(\PDO $db,string $cpanelDirectory,array $config,array $server): array {
        $package=self::verifyPackage($cpanelDirectory);
        $schemas=Schema::manifest();
        $plan=Schema::plan($db);
        $environment=self::environment($cpanelDirectory,$config,$server);
        $list=[];
        foreach($schemas as $version=>$schema){$list[]=['version'=>$version,'name'=>$schema['name'],'sha256'=>$schema['sha256'],'status'=>in_array($version,$plan['applied'],true)?'applied':'pending'];}
        return ['package'=>$package,'environment'=>$environment,'plan'=>$plan,'schemas'=>$list,'fingerprint'=>self::fingerprint($package,$schemas,$plan)];
    }
}
