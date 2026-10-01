<?php
declare(strict_types=1);

use App\Modules\Jobs\Domain\CommandContext;
use App\Modules\Jobs\Domain\CommandValidator;
use App\Modules\Jobs\Domain\CompletionContext;
use App\Modules\Jobs\Domain\CompletionMapping;
use App\Modules\Jobs\Domain\CompletionMappingValidator;
use App\Modules\Jobs\Domain\CompletionValidator;
use App\Modules\Jobs\Domain\CreateRequestValidator;
use App\Modules\Jobs\Domain\EventIdentity;
use App\Modules\Jobs\Domain\EventIdentityValidator;
use App\Modules\Jobs\Domain\ExpectedStatus;
use App\Modules\Jobs\Domain\ExternalReceiptValidator;
use App\Modules\Jobs\Domain\JobAcceptanceValidator;
use App\Modules\Jobs\Domain\StatusObservationValidator;
use App\Modules\Integrations\GatewayDisposition;
use App\Modules\Integrations\GatewayResult;
use PHPUnit\Framework\TestCase;
use Portal\Shared\PortalException;

final class JobsContractValidationTest extends TestCase
{
    public function testCreateRequestAcceptsOnlyTheProfileAndNonEmptyIdempotencyHeader(): void
    {
        $request = (new CreateRequestValidator())->validate(['profile_id' => 'profile-fixture-01'], 'key-fixture-01');

        self::assertSame('profile-fixture-01', $request->profileId);
        self::assertSame(['profile_id' => 'profile-fixture-01'], $request->canonicalPayload());
        self::assertSame($request->bodyFingerprint(), (new CreateRequestValidator())->validate(['profile_id' => 'profile-fixture-01'], 'key-fixture-02')->bodyFingerprint());
    }

    public function testIndependentFixturesMatchTheRootV1ContractFieldSets(): void
    {
        $path = dirname(__DIR__, 4) . '/contracts/prototype-v1.json';
        $contract = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $commandFields = array_keys($this->fixture('create-command.json'));
        $acceptanceFields = array_keys($this->fixture('job-acceptance.json'));
        $receiptFields = array_keys($this->fixture('external-receipt.json'));
        $completionFields = array_keys($this->fixture('completion-success.json'));
        sort($commandFields);
        sort($acceptanceFields);
        sort($receiptFields);
        sort($completionFields);

        $expectedCommand = $contract['command']['required'];
        $expectedAcceptance = $contract['api']['receipt'];
        $expectedReceipt = $contract['externalReceipt']['required'];
        $expectedCompletion = $contract['completion']['required'];
        sort($expectedCommand);
        sort($expectedAcceptance);
        sort($expectedReceipt);
        sort($expectedCompletion);

        self::assertSame($expectedCommand, $commandFields);
        self::assertSame($expectedAcceptance, $acceptanceFields);
        self::assertSame($expectedReceipt, $receiptFields);
        self::assertSame($expectedCompletion, $completionFields);
    }

    public function testCreateRequestRejectsExtraFieldsAndInvalidHeader(): void
    {
        $this->expectException(PortalException::class);
        (new CreateRequestValidator())->validate(['profile_id' => 'profile-fixture-01', 'vm_id' => 'caller-controlled'], 'key-fixture-01');
    }

    public function testCommandMatchesThePersistedAttemptAndRejectsUnknownFields(): void
    {
        $wire = $this->fixture('create-command.json');
        $command = (new CommandValidator())->validate($wire, $this->commandContext());

        self::assertSame('job-fixture-01', $command->jobId);
        self::assertSame($wire, $command->toWire());

        $wire['unrecognized'] = true;
        try {
            (new CommandValidator())->validate($wire, $this->commandContext());
            self::fail('Unknown command fields must be rejected.');
        } catch (PortalException $exception) {
            self::assertSame('INVALID_CONTRACT', $exception->errorCode);
        }
    }

    public function testCommandRejectsAttemptOrModeDifferentFromPersistedContext(): void
    {
        $wire = $this->fixture('create-command.json');
        $wire['attempt'] = 2;

        try {
            (new CommandValidator())->validate($wire, $this->commandContext());
            self::fail('A stale command must not be submitted under another attempt.');
        } catch (PortalException $exception) {
            self::assertSame('CONTRACT_CONFLICT', $exception->errorCode);
        }
    }

    public function testReceiptNormalizesTimestampAndOnlyAcceptsNonTerminalStates(): void
    {
        $receipt = (new ExternalReceiptValidator())->validate($this->fixture('external-receipt.json'), 'simulated');
        self::assertSame('2026-10-01T03:00:00+00:00', $receipt->observedAt->format('Y-m-d\TH:i:sP'));

        $wire = $this->fixture('external-receipt.json');
        $wire['status'] = 'succeeded';
        $this->expectException(PortalException::class);
        (new ExternalReceiptValidator())->validate($wire, 'simulated');
    }

    public function testApiAcceptanceReceiptIsQueuedAndCannotClaimCompletion(): void
    {
        $receipt = (new JobAcceptanceValidator())->validate($this->fixture('job-acceptance.json'));
        self::assertSame('queued', $receipt->status);
        self::assertSame($this->fixture('job-acceptance.json'), $receipt->toWire());

        $wire = $this->fixture('job-acceptance.json');
        $wire['status'] = 'succeeded';
        $this->expectException(PortalException::class);
        (new JobAcceptanceValidator())->validate($wire);
    }

    public function testCompletionShapeDoesNotRequireExternalMappingToExistYet(): void
    {
        $message = (new CompletionValidator())->validate($this->fixture('completion-success.json'), 'simulated');
        $decision = (new CompletionMappingValidator())->classify($message, null);

        self::assertSame(CompletionMapping::DEFERRED, $decision);
        self::assertSame('succeeded', $message->status);
    }

    public function testCompletionEnforcesErrorCodeStatusRelationshipAndRejectsUnknownFields(): void
    {
        $wire = $this->fixture('completion-success.json');
        $wire['error_code'] = 'SIM_EXECUTION_FAILED';
        try {
            (new CompletionValidator())->validate($wire);
            self::fail('A successful completion cannot carry a failure code.');
        } catch (PortalException $exception) {
            self::assertSame('INVALID_CONTRACT', $exception->errorCode);
        }

        $wire = $this->fixture('completion-success.json');
        $wire['future_field'] = 'unexpected';
        $this->expectException(PortalException::class);
        (new CompletionValidator())->validate($wire);
    }

    public function testFailureCompletionRequiresStableCodeAndAcceptsSupportedCodeShape(): void
    {
        $message = (new CompletionValidator())->validate($this->fixture('completion-failure.json'), 'simulated');
        self::assertSame('failed', $message->status);
        self::assertSame('SIM_EXECUTION_FAILED', $message->errorCode);

        $wire = $this->fixture('completion-failure.json');
        $wire['error_code'] = null;
        $this->expectException(PortalException::class);
        (new CompletionValidator())->validate($wire);
    }

    public function testSameEventIdAndCanonicalPayloadIsDuplicateButChangedContentConflicts(): void
    {
        $validator = new CompletionValidator();
        $identity = new EventIdentityValidator();
        $stored = $validator->validate($this->fixture('completion-success.json'));

        self::assertSame(EventIdentity::DUPLICATE, $identity->classify($stored, $stored));

        $changedWire = $this->fixture('completion-success.json');
        $changedWire['observed_at'] = '2026-10-01T03:00:01Z';
        $changed = $validator->validate($changedWire);
        self::assertSame(EventIdentity::CONTENT_CONFLICT, $identity->classify($stored, $changed));
    }

    public function testMappingClassifiesStaleAttemptAndMismatchedTargetSeparately(): void
    {
        $message = (new CompletionValidator())->validate($this->fixture('completion-success.json'));
        $validator = new CompletionMappingValidator();
        $mapping = new CompletionContext('job-fixture-01', 'external-job-fixture-01', 'external-vm-fixture-01', 2, 'simulated');

        self::assertSame(CompletionMapping::STALE_ATTEMPT, $validator->classify($message, $mapping));

        $otherTarget = new CompletionContext('job-fixture-01', 'external-job-other', 'external-vm-fixture-01', 1, 'simulated');
        self::assertSame(CompletionMapping::CONFLICT, $validator->classify($message, $otherTarget));
    }

    public function testCommandProfileObjectMemberOrderDoesNotChangeItsMeaning(): void
    {
        $wire = $this->fixture('create-command.json');
        $wire['profile'] = [
            'snapshot' => ['network_ref' => 'simulated-only', 'disk_gb' => 20, 'memory_mb' => 1024, 'vcpus' => 1],
            'profile_version' => 'profile-version-fixture-01',
            'profile_id' => 'profile-fixture-01',
        ];

        $command = (new CommandValidator())->validate($wire, $this->commandContext());
        self::assertSame('profile-fixture-01', $command->profile['profile_id']);
    }

    public function testCommandProfileSnapshotHasTheServerOwnedExactResourceShape(): void
    {
        $wire = $this->fixture('create-command.json');
        $wire['profile']['snapshot']['unrequested_resource'] = true;

        $this->expectException(PortalException::class);
        (new CommandValidator())->validate($wire, $this->commandContext());
    }

    public function testCommandRejectsValuesThatCannotBeRepresentedInCanonicalJson(): void
    {
        $wire = $this->fixture('create-command.json');
        $wire['profile']['snapshot']['unserializable'] = new stdClass();

        $this->expectException(PortalException::class);
        (new CommandValidator())->validate($wire, $this->commandContext());
    }

    public function testStatusReaderResultIsAnObservationWithIndependentPowerAndGuestFields(): void
    {
        $observation = (new StatusObservationValidator())->validate(
            $this->fixture('status-observation.json'),
            new ExpectedStatus('external-job-fixture-01', 'external-vm-fixture-01', 'simulated'),
        );

        self::assertSame('succeeded', $observation->status);
        self::assertSame('running', $observation->powerState);
        self::assertSame('not_ready', $observation->guestReadiness);
    }

    public function testGatewayResultKeepsUnknownDistinctFromFailure(): void
    {
        $unknown = GatewayResult::unknown('TRANSPORT_TIMEOUT');
        $rejected = GatewayResult::rejected('PROFILE_UNAVAILABLE');

        self::assertSame(GatewayDisposition::UNKNOWN, $unknown->disposition);
        self::assertSame(GatewayDisposition::REJECTED, $rejected->disposition);
        self::assertNull($unknown->receipt);
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        $path = __DIR__ . '/../_support/Fixtures/Jobs/' . $name;
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function commandContext(): CommandContext
    {
        return new CommandContext(
            'request-fixture-01',
            'job-fixture-01',
            'vm-fixture-01',
            1,
            'external-key-fixture-01',
            ['profile_id' => 'profile-fixture-01', 'profile_version' => 'profile-version-fixture-01', 'snapshot' => ['vcpus' => 1, 'memory_mb' => 1024, 'disk_gb' => 20, 'network_ref' => 'simulated-only']],
            'simulated',
        );
    }
}
