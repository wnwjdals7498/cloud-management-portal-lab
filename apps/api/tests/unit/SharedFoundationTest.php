<?php
declare(strict_types=1);

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Portal\Shared\AuditAppender;
use Portal\Shared\CanonicalJson;
use Portal\Shared\Clock;
use Portal\Shared\Identifiers;
use Portal\Shared\SystemClock;
use Portal\Shared\StructuredLogger;

/** @internal */
final class SharedFoundationTest extends CIUnitTestCase
{
    public function testCanonicalJsonSortsObjectKeysRecursivelyAndHashesStableContent(): void
    {
        $left = ['z' => 1, 'nested' => ['b' => 2, 'a' => 1]];
        $right = ['nested' => ['a' => 1, 'b' => 2], 'z' => 1];

        $this->assertSame(CanonicalJson::encode($left), CanonicalJson::encode($right));
        $this->assertSame(CanonicalJson::hash($left), CanonicalJson::hash($right));
        $this->assertNotSame(CanonicalJson::encode([1, 2]), CanonicalJson::encode([2, 1]));
    }

    public function testCanonicalJsonRejectsObjectsAndNonFiniteNumbers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CanonicalJson::encode(['unsafe' => new stdClass()]);
    }

    public function testIdentifierHasValidatedPrefixAndIndependentRandomSuffix(): void
    {
        $first = Identifiers::new('job');
        $second = Identifiers::new('job');

        $this->assertMatchesRegularExpression('/\Ajob_[a-f0-9]{32}\z/', $first);
        $this->assertNotSame($first, $second);
    }

    public function testIdentifierRejectsUnsafePrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Identifiers::new('job-secret');
    }

    public function testSystemClockUsesUtcAndMonotonicTime(): void
    {
        $clock = new SystemClock();
        $first = $clock->monotonic();
        $second = $clock->monotonic();

        $this->assertSame('UTC', $clock->utcNow()->getTimezone()->getName());
        $this->assertGreaterThanOrEqual($first, $second);
    }

    public function testStructuredLoggerWritesOnlyAllowlistedSafeContext(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'portal-log-');
        $this->assertNotFalse($path);
        try {
            $logger = new StructuredLogger('api', $path, new FixedClock());
            $this->assertTrue($logger->write('job.dispatched', [
                'request_id' => 'req_123',
                'job_id' => 'job_123',
                'attempt' => 2,
                'mode' => 'simulated',
                'duration_ms' => 12.5,
                'level' => 'WARN',
                'password' => 'secret-value',
                'authorization' => 'Bearer secret-value',
                'body' => ['token' => 'secret-value'],
                'csrf' => 'secret-value',
                'private_key' => 'secret-value',
                'internal_address' => 'secret-value',
                'proxy_credential' => 'secret-value',
                'secret' => 'secret-value',
                'reason' => "invalid\nsecret-value",
            ]));

            $contents = (string) file_get_contents($path);
            $record = json_decode(trim($contents), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame('req_123', $record['request_id']);
            $this->assertSame('job_123', $record['job_id']);
            $this->assertSame(2, $record['attempt']);
            $this->assertSame('WARN', $record['level']);
            $this->assertSame('[redacted]', $record['reason']);
            $this->assertArrayNotHasKey('password', $record);
            $this->assertArrayNotHasKey('authorization', $record);
            $this->assertArrayNotHasKey('body', $record);
            $this->assertArrayNotHasKey('csrf', $record);
            $this->assertArrayNotHasKey('private_key', $record);
            $this->assertArrayNotHasKey('internal_address', $record);
            $this->assertArrayNotHasKey('proxy_credential', $record);
            $this->assertArrayNotHasKey('secret', $record);
            $this->assertStringNotContainsString('secret-value', $contents);
            $this->assertFalse($logger->isDegraded());
        } finally {
            @unlink($path);
        }
    }

    public function testStructuredLoggerAcceptsEmptyContextWithSafeDefaultLevel(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'portal-log-empty-');
        $this->assertNotFalse($path);
        try {
            $logger = new StructuredLogger('', $path, new FixedClock());
            $this->assertTrue($logger->write('', []));

            $record = json_decode(trim((string) file_get_contents($path)), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame('unknown', $record['service']);
            $this->assertSame('invalid_event', $record['event']);
            $this->assertSame('INFO', $record['level']);
        } finally {
            @unlink($path);
        }
    }

    public function testStructuredLoggerSignalsSinkFailureWithoutThrowing(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-portal-dir-' . bin2hex(random_bytes(5)) . DIRECTORY_SEPARATOR . 'log.jsonl';
        $logger = new StructuredLogger('api', $path, new FixedClock());

        $this->assertFalse($logger->write('sink.failure', ['job_id' => 'job_1']));
        $this->assertTrue($logger->isDegraded());
    }

    public function testAuditAppenderUsesCallerConnectionAndFiltersSummaryFields(): void
    {
        $builder = $this->createMock(BaseBuilder::class);
        $inserted = null;
        $builder->expects($this->once())
            ->method('insert')
            ->willReturnCallback(static function (array $row) use (&$inserted): bool {
                $inserted = $row;
                return true;
            });

        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->once())->method('table')->with('audit_events')->willReturn($builder);
        $db->expects($this->never())->method('transCommit');

        $appender = new AuditAppender($db, new FixedClock());
        $ref = $appender->append([
            'actor_id' => 7,
            'actor_kind' => 'user',
            'target_type' => 'job',
            'target_id' => 'job_123',
            'action' => 'create',
            'decision' => 'allowed',
            'reason' => 'policy_passed',
            'request_id' => 'req_123',
            'job_id' => 'job_123',
            'attempt' => 1,
            'mode' => 'simulated',
            'before' => ['state' => 'none', 'password' => 'secret-value'],
            'after' => ['state' => 'queued', 'profile_id' => 'basic'],
        ]);

        $before = json_decode($inserted['before_summary'], true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($ref, $inserted['event_id']);
        $this->assertSame(['state' => 'none'], $before);
        $this->assertStringNotContainsString('secret-value', $inserted['before_summary']);
        $this->assertSame('2026-10-01 12:34:56.123456', $inserted['occurred_at']);
    }

    public function testAuditAppenderRejectsUnsupportedFieldsBeforeDatabaseWrite(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('table');
        $appender = new AuditAppender($db, new FixedClock());

        $this->expectException(InvalidArgumentException::class);
        $appender->append(['message' => 'must not become an audit field']);
    }

    public function testAuditAppenderSurfacesPersistenceFailureWithoutCommitting(): void
    {
        $builder = $this->createMock(BaseBuilder::class);
        $builder->expects($this->once())->method('insert')->willReturn(false);
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->once())->method('table')->with('audit_events')->willReturn($builder);
        $db->expects($this->never())->method('transCommit');
        $appender = new AuditAppender($db, new FixedClock());

        $this->expectException(RuntimeException::class);
        $appender->append([
            'actor_id' => null,
            'actor_kind' => 'service',
            'target_type' => 'system',
            'target_id' => 'api',
            'action' => 'startup',
            'decision' => 'observed',
            'reason' => 'audit_sink_unavailable',
            'request_id' => null,
            'job_id' => null,
            'attempt' => null,
            'mode' => 'development',
            'before' => null,
            'after' => null,
        ]);
    }
}

final class FixedClock implements Clock
{
    public function utcNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-01T12:34:56.123456+00:00');
    }

    public function monotonic(): float
    {
        return 123.5;
    }
}
