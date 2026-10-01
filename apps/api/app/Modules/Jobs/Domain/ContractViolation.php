<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

use Portal\Shared\PortalException;

final class ContractViolation
{
    public static function invalid(string $message = 'The request does not match the supported contract.'): PortalException
    {
        return new PortalException('INVALID_CONTRACT', $message, 400);
    }

    public static function conflict(string $message = 'The request conflicts with an existing record.'): PortalException
    {
        return new PortalException('CONTRACT_CONFLICT', $message, 409);
    }
}
