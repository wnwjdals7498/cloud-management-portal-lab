<?php

declare(strict_types=1);

namespace Tests\App\Unit\Identity;

use App\Modules\Identity\Domain\ActorContext;
use CodeIgniter\Test\CIUnitTestCase;

final class ActorContextTest extends CIUnitTestCase
{
    public function testGroupCheckIsCaseInsensitiveAndDoesNotInferOtherRoles(): void
    {
        $actor = new ActorContext(17, ['member'], 'user', 'req_test', 'simulated');

        self::assertTrue($actor->isInGroup('MEMBER'));
        self::assertFalse($actor->isInGroup('admin'));
    }
}
