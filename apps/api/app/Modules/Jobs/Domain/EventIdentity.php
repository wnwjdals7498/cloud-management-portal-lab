<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

enum EventIdentity: string
{
    case NEW = 'new';
    case DUPLICATE = 'duplicate';
    case CONTENT_CONFLICT = 'content_conflict';
}
