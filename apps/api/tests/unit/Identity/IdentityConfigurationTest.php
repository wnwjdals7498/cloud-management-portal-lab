<?php

declare(strict_types=1);

namespace Tests\App\Unit\Identity;

use CodeIgniter\Session\Handlers\DatabaseHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Auth;
use Config\AuthGroups;
use Config\Cookie;
use Config\Security;
use Config\Session;

final class IdentityConfigurationTest extends CIUnitTestCase
{
    public function testShieldUsesOnlyEmailSessionAuthenticationWithoutRegistrationOrRememberMe(): void
    {
        $auth = new Auth();
        $groups = new AuthGroups();

        self::assertSame('session', $auth->defaultAuthenticator);
        self::assertSame(['email'], $auth->validFields);
        self::assertFalse($auth->allowRegistration);
        self::assertFalse($auth->allowMagicLinkLogins);
        self::assertFalse($auth->sessionConfig['allowRemembering']);
        self::assertSame(['member', 'admin'], array_keys($groups->groups));
        self::assertSame('member', $groups->defaultGroup);
    }

    public function testSessionAndCsrfUseTheLockedPrototypeBoundary(): void
    {
        $session = new Session();
        $security = new Security();
        $cookie = new Cookie();

        self::assertSame(DatabaseHandler::class, $session->driver);
        self::assertSame('ci_sessions', $session->savePath);
        self::assertSame('portal_session', $session->cookieName);
        self::assertSame('session', $security->csrfProtection);
        self::assertTrue($security->regenerate);
        self::assertSame('', $cookie->domain);
        self::assertTrue($cookie->httponly);
        self::assertSame('Lax', $cookie->samesite);
        self::assertSame(ENVIRONMENT === 'production', $cookie->secure);
    }
}
