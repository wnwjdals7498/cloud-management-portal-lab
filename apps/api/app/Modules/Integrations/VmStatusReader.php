<?php
declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Modules\Jobs\Domain\ExpectedStatus;
use App\Modules\Jobs\Domain\StatusObservation;

interface VmStatusReader
{
    /** Returns an observation only; the caller owns persistence and state transitions. */
    public function readJob(ExpectedStatus $target): StatusObservation;
}
