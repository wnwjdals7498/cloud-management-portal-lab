<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$app = $argv[1] ?? 'api';
$command = $argv[2] ?? 'help';
if (! in_array($app, ['api','vmm-simulator'], true)) { throw new RuntimeException('Unknown app role.'); }
$config = json_decode(file_get_contents(getenv('PORTAL_CONFIG') ?: $root . '/.runtime/local.json'),true,64,JSON_THROW_ON_ERROR);
$testing = in_array('--test',$argv,true);
if ($testing && ! in_array($command, ['schema', 'seed'], true)) {
    throw new RuntimeException('--test is supported for schema/seed only. Test workers require a dedicated PORTAL_CONFIG.');
}
define('ENVIRONMENT','development');
define('FCPATH',$root . '/apps/' . $app . '/public/');
chdir($root . '/apps/' . $app);
require 'app/Config/Paths.php';
$paths = new Config\Paths();
require 'vendor/codeigniter4/framework/system/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
$isApi = $app === 'api';
$database = $config[$testing ? ($isApi ? 'test_db' : 'sim_test_db') : ($isApi ? 'api_db' : 'sim_db')];
$appUser = $config[$testing ? ($isApi ? 'test_user' : 'sim_test_user') : ($isApi ? 'api_user' : 'sim_user')];
$appPassword = $config[$testing ? ($isApi ? 'test_password' : 'sim_test_password') : ($isApi ? 'api_password' : 'sim_password')];
if ($command === 'schema') {
    $admin = Config\Database::connect(['hostname'=>'127.0.0.1','username'=>'root','password'=>$config['root_password'],'database'=>$database,'DBDriver'=>'MySQLi','port'=>$config['mysql_port'],'DBDebug'=>false,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci','pConnect'=>false],false);
    if ($isApi) {
        // Vendor migration is the source of Shield's native schema.
        $migration = new CodeIgniter\Database\MigrationRunner(config('Migrations'),$admin);
        $migration->setNamespace('CodeIgniter\\Shield')->latest();
        $migration->setNamespace('CodeIgniter\\Settings')->latest();
        $admin->query('CREATE TABLE IF NOT EXISTS ci_sessions (id VARCHAR(128) NOT NULL PRIMARY KEY, ip_address VARCHAR(45) NOT NULL, timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP NOT NULL, data BLOB NOT NULL, KEY ci_sessions_timestamp(timestamp)) ENGINE=InnoDB');
        $primary=$admin->query("SHOW INDEX FROM ci_sessions WHERE Key_name='PRIMARY'")->getNumRows();
        if($primary===0){$admin->query('ALTER TABLE ci_sessions ADD PRIMARY KEY(id)');}
    }
    foreach (App\Database\FoundationSchema::statements() as $statement) {
        if ($admin->query($statement) === false) { throw new RuntimeException('Initial schema application failed.'); }
    }
    foreach ($admin->listTables() as $table) {
        if (! preg_match('/^[a-zA-Z0-9_]+$/',$table)) { throw new RuntimeException('Invalid schema object'); }
        $privileges = $table === 'audit_events' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE';
        $admin->query("GRANT $privileges ON `$database`.`$table` TO '$appUser'@'127.0.0.1'");
    }
    if ($isApi) {
        $snapshot = json_encode(['vcpus'=>1,'memory_mb'=>1024,'disk_gb'=>20,'network_ref'=>'simulated-only'],JSON_THROW_ON_ERROR);
        $admin->query('INSERT IGNORE INTO vm_profiles(profile_id,profile_version,snapshot,enabled,created_at) VALUES(?,?,?,1,UTC_TIMESTAMP(6))',['sim-basic-1','v1',$snapshot]);
        // Preserve P2 prototype requests created before the P3 delivery name was frozen.
        $admin->query("UPDATE outbox o JOIN jobs j ON j.id=o.job_id SET o.state='queued' WHERE o.state='pending' AND j.state='queued' AND j.mode='simulated'");
    }
    echo "Schema and scoped application permissions ready: $app" . ($testing ? ' (test)' : '') . "\n";
} elseif ($command === 'seed' && $isApi) {
    $dbConfig = config('Database');
    $dbConfig->default['hostname']='127.0.0.1'; $dbConfig->default['port']=$config['mysql_port'];
    $dbConfig->default['database']=$database; $dbConfig->default['username']=$appUser; $dbConfig->default['password']=$appPassword;
    $dbConfig->default['DBDriver']='MySQLi'; $dbConfig->default['pConnect']=false; $dbConfig->default['DBDebug']=false;
    $db=App\Support\PortalServices::db();
    $seeder = new App\Modules\Identity\Application\InitialAccountSeeder($db,App\Support\PortalServices::audit(),App\Support\PortalServices::logger());
    $result = $seeder->ensureInitialAccounts();
    foreach ($result['accounts'] as $account) {
        $db->query('INSERT IGNORE INTO quota_accounts(actor_id,limit_count,reserved_count,consumed_count,policy_revision,updated_at) VALUES(?,?,0,0,?,UTC_TIMESTAMP(6))',[$account['account_ref'],$config['active_vm_limit'],'prototype-v1']);
    }
    echo json_encode($result,JSON_THROW_ON_ERROR) . "\n";
} elseif ($command === 'tick') {
    if($isApi){$worker=App\Support\PortalServices::worker();$result=['delivery'=>$worker->tick('local-cli'),'inbox'=>$worker->processInbox()];}
    else{$result=App\Support\SimulatorServices::simulator()->tick();}
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
} elseif ($command === 'loop') {
    $service=$isApi ? App\Support\PortalServices::worker() : App\Support\SimulatorServices::simulator();
    echo "Local simulated worker started.\n";
    while(true){
        try { if($isApi){$service->tick('local-worker');$service->processInbox();}else{$service->tick();} }
        catch(Throwable){ error_log('portal_worker_tick_failed'); }
        usleep(250000);
    }
} else { echo "Usage: portal-cli.php api|vmm-simulator schema|seed [--test]; tick|loop use PORTAL_CONFIG\n"; }
