<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

final readonly class ActorContext
{
    /**
     * @param list<string> $groups
     */
    public function __construct(
        public int $userId,
        public array $groups,
        public string $actorKind,
        public string $requestId,
        public string $mode,
    ) {
    }

    public function isInGroup(string $group): bool
    {
        return in_array(strtolower($group), $this->groups, true);
    }
}
