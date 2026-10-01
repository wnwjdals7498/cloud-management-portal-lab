<?php

declare(strict_types=1);

namespace Tests\App\Unit\Identity;

use App\Modules\Identity\Application\LoginCredentials;
use CodeIgniter\Test\CIUnitTestCase;
use Portal\Shared\PortalException;

final class LoginCredentialsTest extends CIUnitTestCase
{
    public function testMapsOnlyEmailAndCredentialToShieldFields(): void
    {
        $credentials = LoginCredentials::fromPayload([
            'login_identifier' => '  PERSON@EXAMPLE.TEST ',
            'credential'       => 'Credential Value',
        ]);

        self::assertSame('person@example.test', $credentials->email);
        self::assertSame([
            'email'    => 'person@example.test',
            'password' => 'Credential Value',
        ], $credentials->toShieldCredentials());
    }

    public function testRejectsMissingAndUnexpectedProperties(): void
    {
        foreach ([
            ['login_identifier' => 'person@example.test'],
            ['login_identifier' => 'person@example.test', 'credential' => 'secret', 'remember' => true],
            ['person@example.test', 'secret'],
        ] as $payload) {
            try {
                LoginCredentials::fromPayload($payload);
                self::fail('Invalid login payload was accepted.');
            } catch (PortalException $exception) {
                self::assertSame('validation_error', $exception->errorCode);
                self::assertSame(400, $exception->httpStatus);
                self::assertSame('Login request is invalid.', $exception->getMessage());
            }
        }
    }

    public function testRejectsNonEmailIdentifierAndNonStringCredential(): void
    {
        foreach ([
            ['login_identifier' => 'member-name', 'credential' => 'secret'],
            ['login_identifier' => 'person@example.test', 'credential' => ['secret']],
        ] as $payload) {
            try {
                LoginCredentials::fromPayload($payload);
                self::fail('Invalid login fields were accepted.');
            } catch (PortalException $exception) {
                self::assertSame('validation_error', $exception->errorCode);
            }
        }
    }
}
