<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final class EventIdentityValidator
{
    public function classify(?CompletionMessage $stored, CompletionMessage $incoming): EventIdentity
    {
        if ($stored === null) {
            return EventIdentity::NEW;
        }

        if ($stored->eventId !== $incoming->eventId) {
            throw ContractViolation::invalid('Only messages sharing an event identity can be compared.');
        }

        return hash_equals($stored->contentHash(), $incoming->contentHash())
            ? EventIdentity::DUPLICATE
            : EventIdentity::CONTENT_CONFLICT;
    }
}
