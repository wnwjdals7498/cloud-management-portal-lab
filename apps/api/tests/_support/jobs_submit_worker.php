<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$appRoot = $root . '/apps/api';
chdir($appRoot);
define('ENVIRONMENT', 'testing');
define('FCPATH', $appRoot . '/public/');
require 'app/Config/Paths.php';
$paths = new Config\Paths();
require 'vendor/codeigniter4/framework/system/Boot.php';
CodeIgniter\Boot::bootConsole($paths);

$actorId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$requestId = $argv[2] ?? '';
$idempotencyKey = $argv[3] ?? '';
$readyPath = $argv[4] ?? '';
$goPath = $argv[5] ?? '';
if (! is_int($actorId) || $actorId < 1 || $requestId === '' || $idempotencyKey === '' || $readyPath === '' || $goPath === '') {
    fwrite(STDOUT, json_encode(['result' => 'error', 'code' => 'invalid_worker_input'], JSON_THROW_ON_ERROR));
    exit(2);
}

$config = Portal\Shared\Runtime::config();
$db = Config\Database::connect([
    'DSN' => '',
    'hostname' => '127.0.0.1',
    'username' => (string) ($config['test_user'] ?? ''),
    'password' => (string) ($config['test_password'] ?? ''),
    'database' => (string) ($config['test_db'] ?? ''),
    'DBDriver' => 'MySQLi',
    'DBPrefix' => '',
    'pConnect' => false,
    'DBDebug' => false,
    'charset' => 'utf8mb4',
    'DBCollat' => 'utf8mb4_0900_ai_ci',
    'port' => (int) ($config['mysql_port'] ?? 3306),
], false);

file_put_contents($readyPath, 'ready');
$deadline = microtime(true) + 15.0;
while (! is_file($goPath) && microtime(true) < $deadline) {
    usleep(1000);
}
if (! is_file($goPath)) {
    fwrite(STDOUT, json_encode(['result' => 'error', 'code' => 'barrier_timeout'], JSON_THROW_ON_ERROR));
    exit(2);
}

$actor = new App\Modules\Identity\Domain\ActorContext($actorId, ['member'], 'user', $requestId, 'simulated');
$clock = new Portal\Shared\SystemClock();
$audit = new Portal\Shared\AuditAppender($db, $clock);
$logger = new class implements Portal\Shared\EventLogger {
    /** @var list<array<string,mixed>> */
    public array $contexts = [];

    public function write(string $event, array $context = []): bool
    {
        $this->contexts[] = $context;
        return true;
    }
};

try {
    $service = new App\Modules\Jobs\Application\JobsService($db, $audit, $logger, $clock);
    $result = $service->submit($actor, ['profile_id' => 'sim-basic-1'], $idempotencyKey);
    fwrite(STDOUT, json_encode(['result' => 'accepted', 'job_id' => $result['job_id'], 'vm_id' => $result['vm_id']], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Portal\Shared\PortalException $exception) {
    $context = $logger->contexts === [] ? [] : end($logger->contexts);
    fwrite(STDOUT, json_encode(['result' => 'rejected', 'code' => $exception->errorCode, 'reason' => $context['reason'] ?? null], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable) {
    fwrite(STDOUT, json_encode(['result' => 'error', 'code' => 'request_unavailable'], JSON_THROW_ON_ERROR));
    exit(2);
}
