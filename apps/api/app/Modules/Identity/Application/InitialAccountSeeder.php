<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Exceptions\ValidationException;
use CodeIgniter\Shield\Models\GroupModel;
use CodeIgniter\Shield\Models\UserIdentityModel;
use CodeIgniter\Shield\Models\UserModel;
use Portal\Shared\AuditSink;
use Portal\Shared\EventLogger;
use Portal\Shared\Identifiers;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;
use Throwable;

final class InitialAccountSeeder
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly AuditSink $audit,
        private readonly EventLogger $logger,
    ) {
    }

    /**
     * @return array{accounts: list<array{account_ref: int, role: string, result: string}>, request_id: string}
     */
    public function ensureInitialAccounts(): array
    {
        $config = Runtime::config();
        $password = $config['seed_password'] ?? null;
        $mode = $config['mode'] ?? null;

        if (! is_string($password) || $password === '' || ! is_string($mode) || $mode === '') {
            throw new PortalException('account_seed_unavailable', 'Initial account preparation is unavailable.', 503);
        }

        $requestId = Identifiers::new('req');
        $accounts = [
            ['username' => 'portal-admin', 'email' => 'admin@example.test', 'role' => 'admin'],
            ['username' => 'portal-member-a', 'email' => 'membera@example.test', 'role' => 'member'],
            ['username' => 'portal-member-b', 'email' => 'memberb@example.test', 'role' => 'member'],
        ];

        if (! $this->db->transBegin()) {
            $this->logFailure($requestId, 'account_prepare_failed', 'begin_transaction', null);
            throw new PortalException('account_prepare_failed', 'Initial account preparation failed.', 503);
        }

        $transactionOpen = true;
        $stage = 'load_model';

        try {
            $results = [];
            $users = model(UserModel::class);
            $identities = model(UserIdentityModel::class);

            foreach ($accounts as $definition) {
                $stage = 'find_identity_' . $definition['role'];
                $identity = $identities->getIdentityBySecret(
                    Session::ID_TYPE_EMAIL_PASSWORD,
                    $definition['email'],
                );
                $stage = 'find_user_' . $definition['role'];
                $user = $identity === null ? null : $this->findUserById($identity->user_id);
                $result = 'present';

                if ($identity !== null && $user === null) {
                    throw new PortalException('account_seed_conflict', 'Initial account identity conflicts with the expected account.', 409);
                }

                if ($user === null) {
                    $stage = 'save_' . $definition['role'];
                    $user = new User([
                        'username' => $definition['username'],
                        'email'    => $definition['email'],
                        'password' => $password,
                        'active'   => true,
                    ]);

                    if (! $users->save($user) || ! is_numeric($users->getInsertID())) {
                        throw new PortalException('account_prepare_failed', 'Initial account preparation failed.', 503);
                    }

                    $stage = 'reload_' . $definition['role'];
                    $user = $users->findById($users->getInsertID());
                    if ($user === null) {
                        throw new PortalException('account_prepare_failed', 'Initial account preparation failed.', 503);
                    }
                    $stage = 'read_groups_' . $definition['role'];
                    $user->getGroups();
                    $stage = 'validate_group_' . $definition['role'];
                    if (! model(GroupModel::class)->isValidGroup($definition['role'])) {
                        throw new PortalException('account_prepare_failed', 'Initial account group is unavailable.', 503);
                    }
                    $stage = 'assign_group_' . $definition['role'];
                    $user->addGroup($definition['role']);
                    $result = 'created';
                } elseif (! $this->hasExpectedState($user, $definition['role'])) {
                    throw new PortalException('account_seed_conflict', 'Initial account state conflicts with the expected role.', 409);
                }

                $userId = (int) $user->id;
                $stage = 'audit_' . $definition['role'];
                $this->audit->append([
                    'actor_id'    => null,
                    'actor_kind'  => 'system',
                    'target_type' => 'identity_account',
                    'target_id'   => (string) $userId,
                    'action'      => 'identity.initial_account.ensure',
                    'decision'    => 'allowed',
                    'reason'      => 'assigned_' . $definition['role'] . '_' . $result,
                    'request_id'  => $requestId,
                    'job_id'      => null,
                    'attempt'     => null,
                    'mode'        => $mode,
                    'before'      => null,
                    'after'       => ['result' => $result, 'state' => 'active'],
                ]);

                $results[] = [
                    'account_ref' => $userId,
                    'role'        => $definition['role'],
                    'result'      => $result,
                ];
            }

            $stage = 'validate_commit';
            if (! $this->db->transStatus()) {
                throw new PortalException('account_prepare_failed', 'Initial account preparation failed.', 503);
            }

            $stage = 'commit';
            if (! $this->db->transCommit()) {
                throw new PortalException('account_prepare_failed', 'Initial account preparation failed.', 503);
            }
            $transactionOpen = false;

            $this->logger->write('identity_initial_accounts_prepared', [
                'mode'       => $mode,
                'request_id' => $requestId,
                'outcome'    => 'success',
            ]);

            return ['accounts' => $results, 'request_id' => $requestId];
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $this->db->transRollback();
            }

            $this->logFailure(
                $requestId,
                $exception instanceof PortalException ? $exception->errorCode : 'account_prepare_failed',
                $stage,
                $exception,
            );

            if ($exception instanceof PortalException) {
                throw $exception;
            }

            throw new PortalException('account_prepare_failed', 'Initial account preparation failed.', 503);
        }
    }

    private function hasExpectedState(User $user, string $expectedRole): bool
    {
        $groups = $user->getGroups() ?? [];

        return $user->active === true
            && ! $user->isBanned()
            && count($groups) === 1
            && in_array($expectedRole, $groups, true);
    }

    private function findUserById(mixed $userId): ?User
    {
        if (! is_int($userId) && (! is_string($userId) || preg_match('/\A[0-9]+\z/', $userId) !== 1)) {
            return null;
        }

        $table = config('Auth')->tables['users'] ?? null;
        if (! is_string($table) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $table) !== 1) {
            throw new PortalException('account_prepare_failed', 'Initial account storage is unavailable.', 503);
        }

        $row = $this->db->table($table)
            ->where('id', (int) $userId)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return is_array($row) ? new User($row) : null;
    }

    private function logFailure(string $requestId, string $errorCode, string $stage, ?Throwable $exception): void
    {
        $classParts = $exception === null ? [] : explode('\\', $exception::class);
        $exceptionClass = $classParts === [] ? 'none' : (string) end($classParts);
        $reason = 'stage_' . $stage;

        if ($exception instanceof ValidationException
            && preg_match('/\[([a-zA-Z][a-zA-Z0-9_]*)\]/', $exception->getMessage(), $matches) === 1) {
            $reason = 'validation_' . $matches[1];
        }

        $this->logger->write('identity_initial_accounts_failed', [
            'request_id' => $requestId,
            'error_code' => $errorCode,
            'operation'  => 'seed.' . $stage . '.' . $exceptionClass,
            'reason'     => $reason,
            'outcome'    => 'failed',
        ]);
    }
}
