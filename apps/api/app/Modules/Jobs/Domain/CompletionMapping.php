<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

enum CompletionMapping: string
{
    case MATCHED = 'matched';
    case DEFERRED = 'deferred';
    case STALE_ATTEMPT = 'stale_attempt';
    case CONFLICT = 'conflict';
}
