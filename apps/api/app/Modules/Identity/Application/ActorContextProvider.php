<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\ActorContext;
use CodeIgniter\Shield\Entities\User;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;

final class ActorContextProvider
{
    public function current(): ?ActorContext
    {
        $user = auth()->user();

        if (! $user instanceof User || ! is_numeric($user->id)) {
            return null;
        }

        $requestId = trim(service('request')->getHeaderLine('X-Request-ID'));
        if ($requestId === '') {
            $requestId = bin2hex(random_bytes(16));
        }

        $groups = array_values(array_unique(array_map(
            static fn (string $group): string => strtolower($group),
            $user->getGroups() ?? [],
        )));
        sort($groups, SORT_STRING);

        return new ActorContext(
            userId: (int) $user->id,
            groups: $groups,
            actorKind: 'user',
            requestId: $requestId,
            mode: (string) (Runtime::config()['mode'] ?? 'simulated'),
        );
    }

    public function requireAuthenticated(): ActorContext
    {
        return $this->current() ?? throw new PortalException(
            'authentication_required',
            'Authentication is required.',
            401,
        );
    }

    public function requireGroup(string $group): ActorContext
    {
        $actor = $this->requireAuthenticated();

        if (! $actor->isInGroup($group)) {
            throw new PortalException('authorization_denied', 'Access is not permitted.', 403);
        }

        return $actor;
    }
}
