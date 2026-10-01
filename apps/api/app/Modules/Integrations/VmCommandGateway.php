<?php
declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Modules\Jobs\Domain\VmCommand;

interface VmCommandGateway
{
    /** Performs external I/O only; callers must release database locks before calling. */
    public function submit(VmCommand $command): GatewayResult;
}
