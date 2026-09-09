<?php
// XAMPP local backup and optional restore rehearsal. Never restores over live databases.
$root=dirname(__DIR__);
require $root.'/platform/vendor/autoload.php';
$app=require $root.'/platform/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$folder=$root.'/platform/storage/app/private/backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
if(!mkdir($folder,0700,true))throw new RuntimeException('Cannot create backup directory');
$xml=simplexml_load_file($root.'/.tools/traccar-local.xml',SimpleXMLElement::class,LIBXML_NONET);$tracker=[];foreach($xml->entry as $entry)$tracker[(string)$entry['key']]=(string)$entry;
$db=config('database.connections.'.config('database.default'));
if(!in_array($db['driver'],['mysql','mariadb']) || $db['host']!=='127.0.0.1')throw new RuntimeException('This helper requires local MariaDB');
$sources=['tevera'=>[$db['database'],$db['username'],$db['password']], 'traccar'=>['traccar',$tracker['database.user'],$tracker['database.password']]];
$mysql='C:/xampp/mysql/bin/mysql.exe';$dump='C:/xampp/mysql/bin/mysqldump.exe';$verify=in_array('--verify-restore',$argv,true);$manifest=[];
function process(array $args,array $io): void {$p=proc_open($args,$io,$pipes);if(!is_resource($p)||proc_close($p)!==0)throw new RuntimeException('Database tool failed; inspect private backup error log');}
foreach($sources as $label=>[$database,$user,$password]){
    $options=$folder.'/'.$label.'.cnf';
    file_put_contents($options,"[client]\nhost=127.0.0.1\nport=3306\nuser=\"".addcslashes($user,"\\\"")."\"\npassword=\"".addcslashes($password,"\\\"")."\"\n");
    $sql=$folder.'/'.$label.'.sql';
    try{process([$dump,'--defaults-extra-file='.$options,'--single-transaction','--quick','--skip-lock-tables','--hex-blob',$database],[0=>['file','NUL','r'],1=>['file',$sql,'w'],2=>['file',$folder.'/errors.log','a']]);}finally{unlink($options);}
    $manifest[$label]=['sha256'=>hash_file('sha256',$sql),'bytes'=>filesize($sql),'restore_verified'=>false];
    if($verify){
        $admin=new PDO('mysql:host=127.0.0.1;port=3306','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $temporary='tevera_restore_'.bin2hex(random_bytes(8));
        $admin->exec("CREATE DATABASE `$temporary` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try{
            process([$mysql,'--host=127.0.0.1','--port=3306','--user=root',$temporary],[0=>['file',$sql,'r'],1=>['file','NUL','w'],2=>['file',$folder.'/errors.log','a']]);
            $tables=$admin->query("SHOW TABLES FROM `$temporary`")->fetchAll(PDO::FETCH_COLUMN);if(!$tables)throw new RuntimeException('Restored database empty');
            foreach($tables as $table){if(!preg_match('/^[a-zA-Z0-9_]+$/',$table))throw new RuntimeException('Unexpected table');$manifest[$label]['restored_rows'][$table]=(int)$admin->query("SELECT COUNT(*) FROM `$temporary`.`$table`")->fetchColumn();}
            $manifest[$label]['restore_verified']=true;
        }finally{$admin->exec("DROP DATABASE `$temporary`");}
    }
    echo "$label backup created".($verify?' and restored into an isolated test database':'').".\n";
}
file_put_contents($folder.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT));
Illuminate\Support\Facades\Cache::forever('deployment:last-backup',time());
echo "Backup location: $folder\n";
