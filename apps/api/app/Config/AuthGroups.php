<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\AuthGroups as ShieldAuthGroups;

final class AuthGroups extends ShieldAuthGroups
{
    public string $defaultGroup = 'member';

    public array $groups = [
        'member' => [
            'title'       => 'Member',
            'description' => 'Customer account for the prototype.',
        ],
        'admin' => [
            'title'       => 'Administrator',
            'description' => 'Administrator account for the prototype.',
        ],
    ];

    public array $permissions = [];

    public array $matrix = [
        'member' => [],
        'admin'  => [],
    ];
}
