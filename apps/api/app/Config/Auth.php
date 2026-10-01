<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Shield\Config\Auth as ShieldAuth;

final class Auth extends ShieldAuth
{
    public bool $allowRegistration = false;

    public bool $allowMagicLinkLogins = false;

    public bool $recordActiveDate = false;

    public array $validFields = ['email'];

    public array $sessionConfig = [
        'field'              => 'user',
        'allowRemembering'   => false,
        'rememberCookieName' => 'remember',
        'rememberLength'     => 30 * DAY,
    ];
}
