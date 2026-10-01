<?php
declare(strict_types=1);
$root=dirname(__DIR__);
putenv('PORTAL_CONFIG='.$root.'/.runtime/integration.json');
define('ENVIRONMENT','development');define('FCPATH',$root.'/apps/api/public/');
chdir($root.'/apps/api');require 'app/Config/Paths.php';$paths=new Config\Paths();require 'vendor/codeigniter4/framework/system/Boot.php';CodeIgniter\Boot::bootConsole($paths);
$config=Portal\Shared\Runtime::config();$db=App\Support\PortalServices::db();
if($db->getDatabase()!==$config['test_db'] || !str_ends_with($db->getDatabase(),'_test')){throw new RuntimeException('Test database isolation guard failed.');}
$command=$argv[1]??'';
if($command==='capacity'){
    $db->query('UPDATE quota_accounts SET limit_count=reserved_count+consumed_count+6');
    echo "Test capacity prepared\n";
}elseif($command==='command'){
    $jobId=$argv[2]??'';
    if(!preg_match('/^job_[a-f0-9]{32}$/',$jobId)){throw new RuntimeException('Invalid test job reference.');}
    $row=$db->table('outbox')->select('command_payload')->where('job_id',$jobId)->get()->getRowArray();
    if(!$row){throw new RuntimeException('Test command missing.');}
    echo $row['command_payload'];
}else{throw new RuntimeException('Unknown test action.');}
