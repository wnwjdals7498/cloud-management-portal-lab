<?php
declare(strict_types=1);

use App\Modules\Simulator\Domain\SimulationProfileValidator;
use PHPUnit\Framework\TestCase;
use Portal\Shared\PortalException;

final class SimulationProfileValidatorTest extends TestCase
{
    public function testValidProfileIsReturnedAsAValidatedImmutableValue(): void
    {
        $profile = (new SimulationProfileValidator())->validate($this->fixture(), 100);

        self::assertSame('profile-fixture-01', $profile->values['profile_version']);
        self::assertSame(100, array_sum($profile->values['weights']));
    }

    public function testProfileRejectsMissingAdditionalAndWronglyTypedFields(): void
    {
        $validator = new SimulationProfileValidator();

        $missing = $this->fixture();
        unset($missing['seed']);
        $this->assertRejected(fn () => $validator->validate($missing, 100));

        $additional = $this->fixture();
        $additional['unrecognized'] = true;
        $this->assertRejected(fn () => $validator->validate($additional, 100));

        $wrongType = $this->fixture();
        $wrongType['delay_min_seconds'] = '2';
        $this->assertRejected(fn () => $validator->validate($wrongType, 100));
    }

    public function testProfileRejectsWeightErrorsAndInvertedDelayBoundaries(): void
    {
        $validator = new SimulationProfileValidator();

        $badWeights = $this->fixture();
        $badWeights['weights']['success']--;
        $this->assertRejected(fn () => $validator->validate($badWeights, 100));

        $inverted = $this->fixture();
        $inverted['late_min_seconds'] = $inverted['late_max_seconds'] + 1;
        $this->assertRejected(fn () => $validator->validate($inverted, 100));
    }

    public function testProfileRejectsPendingLimitAboveRuntimePolicyAndInvalidErrorAllowlist(): void
    {
        $validator = new SimulationProfileValidator();

        $tooLarge = $this->fixture();
        $tooLarge['maximum_pending'] = 101;
        $this->assertRejected(fn () => $validator->validate($tooLarge, 100));

        $badErrors = $this->fixture();
        $badErrors['error_codes'][] = 'not-a-stable-code';
        $this->assertRejected(fn () => $validator->validate($badErrors, 100));
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $path = __DIR__ . '/../_support/Fixtures/simulation-profile-valid.json';
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertRejected(Closure $action): void
    {
        try {
            $action();
            self::fail('The invalid profile was accepted.');
        } catch (PortalException) {
            self::assertTrue(true);
        }
    }
}
