<?php
declare(strict_types=1);

namespace App\Modules\Integrations;

enum GatewayDisposition: string
{
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case UNKNOWN = 'unknown';
}
