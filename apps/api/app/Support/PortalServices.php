<?php
declare(strict_types=1);
namespace App\Support;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Portal\Shared\AuditAppender;
use Portal\Shared\AuditSink;
use Portal\Shared\Clock;
use Portal\Shared\EventLogger;
use Portal\Shared\StructuredLogger;
use Portal\Shared\SystemClock;

final class PortalServices
{
    public static function db(): BaseConnection { return Database::connect(); }
    public static function clock(): Clock { return new SystemClock(); }
    public static function audit(): AuditSink { return new AuditAppender(self::db(), self::clock()); }
    public static function worker(): \App\Modules\Jobs\Application\WorkerService
    {
        return new \App\Modules\Jobs\Application\WorkerService(self::db(),self::audit(),self::logger(),self::clock(),new \App\Modules\Integrations\Http\SimulatorHttpGateway(),new \App\Modules\Integrations\Http\SimulatorHttpStatusReader());
    }
    public static function logger(): EventLogger
    {
        $dir = \Portal\Shared\Runtime::root() . '/.runtime/logs/api';
        if (! is_dir($dir)) { mkdir($dir, 0700, true); }
        return new StructuredLogger('api', $dir . '/events.jsonl', self::clock());
    }
}
