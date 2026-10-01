<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final class CompletionMappingValidator
{
    public function classify(CompletionMessage $message, ?CompletionContext $mapping): CompletionMapping
    {
        if ($mapping === null || $mapping->externalJobId === null || $mapping->externalVmId === null) {
            return CompletionMapping::DEFERRED;
        }

        if ($message->jobId !== $mapping->jobId || $message->mode !== $mapping->mode) {
            return CompletionMapping::CONFLICT;
        }

        if ($message->attempt !== $mapping->attempt) {
            return CompletionMapping::STALE_ATTEMPT;
        }

        if (
            $message->externalJobId !== $mapping->externalJobId
            || $message->externalVmId !== $mapping->externalVmId
        ) {
            return CompletionMapping::CONFLICT;
        }

        return CompletionMapping::MATCHED;
    }
}
