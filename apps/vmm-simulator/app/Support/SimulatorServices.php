<?php
declare(strict_types=1);

namespace App\Support;

use App\Modules\Simulator\Application\SimulatorService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Portal\Shared\Clock;
use Portal\Shared\EventLogger;
use Portal\Shared\StructuredLogger;
use Portal\Shared\SystemClock;

final class SimulatorServices
{
    public static function db(): BaseConnection
    {
        return Database::connect();
    }

    public static function clock(): Clock
    {
        return new SystemClock();
    }

    public static function logger(): EventLogger
    {
        $directory = \Portal\Shared\Runtime::root() . '/.runtime/logs/vmm-simulator';
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return new StructuredLogger('vmm-simulator', $directory . '/events.jsonl', self::clock());
        }
        return new StructuredLogger('vmm-simulator', $directory . '/events.jsonl', self::clock());
    }

    public static function simulator(): SimulatorService
    {
        return new SimulatorService(self::db(), self::clock(), self::logger());
    }
}
