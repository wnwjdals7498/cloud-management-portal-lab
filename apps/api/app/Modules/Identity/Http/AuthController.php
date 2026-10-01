<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Controllers\BaseController;
use App\Modules\Identity\Application\ActorContextProvider;
use App\Modules\Identity\Application\LoginCredentials;
use App\Modules\Identity\Domain\ActorContext;
use App\Support\PortalServices;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;
use Throwable;

final class AuthController extends BaseController
{
    public function csrf(): ResponseInterface
    {
        service('session');

        return $this->response
            ->setHeader(csrf_header(), csrf_hash())
            ->setJSON([
                'csrf_metadata' => $this->csrfMetadata(),
                'mode'          => $this->mode(),
                'request_id'    => $this->requestId(),
            ]);
    }

    public function login(): ResponseInterface
    {
        $requestId = $this->requestId();
        $credentials = LoginCredentials::fromPayload($this->request->getJSON(true));
        $result = auth()->attempt($credentials->toShieldCredentials());

        if (! $result->isOK()) {
            $this->appendAuthAudit(
                action: 'identity.login',
                actorId: null,
                actorKind: 'anonymous',
                decision: 'denied',
                reason: 'credentials_rejected',
                beforeState: 'anonymous',
                afterState: 'anonymous',
                requestId: $requestId,
            );

            PortalServices::logger()->write('identity_login', [
                'request_id' => $requestId,
                'mode'       => $this->mode(),
                'outcome'    => 'denied',
                'error_code' => 'authentication_failed',
            ]);

            throw new PortalException('authentication_failed', 'Login failed.', 401);
        }

        try {
            $actor = (new ActorContextProvider())->requireAuthenticated();
            $this->appendAuthAudit(
                action: 'identity.login',
                actorId: $actor->userId,
                actorKind: 'user',
                decision: 'allowed',
                reason: 'credentials_accepted',
                beforeState: 'anonymous',
                afterState: 'authenticated',
                requestId: $requestId,
            );
        } catch (Throwable $exception) {
            try {
                auth()->logout();
            } catch (Throwable) {
                // The response remains unavailable; do not expose cleanup details.
            }

            PortalServices::logger()->write('identity_login', [
                'request_id' => $requestId,
                'mode'       => $actor->mode,
                'outcome'    => 'failed',
                'error_code' => $exception instanceof PortalException
                    ? $exception->errorCode
                    : 'identity_unavailable',
            ]);

            if ($exception instanceof PortalException && $exception->httpStatus >= 500) {
                throw $exception;
            }

            throw new PortalException('identity_unavailable', 'Authentication could not be completed.', 503);
        }

        PortalServices::logger()->write('identity_login', [
            'request_id' => $actor->requestId,
            'mode'       => $actor->mode,
            'outcome'    => 'success',
        ]);

        return $this->response
            ->setHeader(csrf_header(), csrf_hash())
            ->setJSON([
                'authentication_result' => 'authenticated',
                'user_context'          => $this->publicUserContext($actor),
                'session_transition'    => 'created',
                'csrf_metadata'         => $this->csrfMetadata(),
                'mode'                  => $actor->mode,
                'request_id'            => $actor->requestId,
            ]);
    }

    public function logout(): ResponseInterface
    {
        $wasAuthenticated = auth()->loggedIn();
        $user = auth()->user();
        $actorId = $user instanceof User && is_numeric($user->id) ? (int) $user->id : null;
        $requestId = $this->requestId();
        auth()->logout();
        service('security')->generateHash();

        if ($wasAuthenticated && $actorId !== null) {
            $this->appendAuthAudit(
                action: 'identity.logout',
                actorId: $actorId,
                actorKind: 'user',
                decision: 'allowed',
                reason: 'session_terminated',
                beforeState: 'authenticated',
                afterState: 'anonymous',
                requestId: $requestId,
            );
        }

        PortalServices::logger()->write('identity_logout', [
            'request_id' => $requestId,
            'mode'       => $this->mode(),
            'outcome'    => $wasAuthenticated ? 'success' : 'unchanged',
        ]);

        return $this->response
            ->setHeader(csrf_header(), csrf_hash())
            ->setJSON([
                'authentication_result' => 'anonymous',
                'session_transition'    => $wasAuthenticated ? 'terminated' : 'unchanged',
                'csrf_metadata'         => $this->csrfMetadata(),
                'mode'                  => $this->mode(),
                'request_id'            => $this->requestId(),
            ]);
    }

    public function me(): ResponseInterface
    {
        $actor = (new ActorContextProvider())->requireAuthenticated();

        return $this->response->setJSON([
            'authentication_result' => 'authenticated',
            'user_context'          => $this->publicUserContext($actor),
            'mode'                  => $actor->mode,
            'request_id'            => $actor->requestId,
        ]);
    }

    /** @return array{actor_id: int, actor_kind: string, groups: list<string>} */
    private function publicUserContext(ActorContext $actor): array
    {
        return [
            'actor_id'   => $actor->userId,
            'actor_kind' => $actor->actorKind,
            'groups'     => $actor->groups,
        ];
    }

    /** @return array{header_name: string, token_name: string, token_value: string} */
    private function csrfMetadata(): array
    {
        return [
            'header_name' => csrf_header(),
            'token_name'  => csrf_token(),
            'token_value' => csrf_hash(),
        ];
    }

    private function requestId(): string
    {
        $requestId = trim($this->request->getHeaderLine('X-Request-ID'));

        return $requestId !== '' ? $requestId : bin2hex(random_bytes(16));
    }

    private function mode(): string
    {
        return (string) (Runtime::config()['mode'] ?? 'simulated');
    }

    private function appendAuthAudit(
        string $action,
        ?int $actorId,
        string $actorKind,
        string $decision,
        string $reason,
        string $beforeState,
        string $afterState,
        string $requestId,
    ): void {
        /** @var BaseConnection $db */
        $db = PortalServices::db();

        if (! $db->transBegin()) {
            throw new PortalException('audit_unavailable', 'Authentication event could not be recorded.', 503);
        }

        $transactionOpen = true;
        try {
            PortalServices::audit()->append([
                'actor_id'    => $actorId,
                'actor_kind'  => $actorKind,
                'target_type' => 'identity_session',
                'target_id'   => null,
                'action'      => $action,
                'decision'    => $decision,
                'reason'      => $reason,
                'request_id'  => $requestId,
                'job_id'      => null,
                'attempt'     => null,
                'mode'        => $this->mode(),
                'before'      => ['state' => $beforeState],
                'after'       => ['state' => $afterState],
            ]);

            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new PortalException('audit_unavailable', 'Authentication event could not be recorded.', 503);
            }
            $transactionOpen = false;
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $db->transRollback();
            }

            if ($exception instanceof PortalException) {
                throw $exception;
            }

            throw new PortalException('audit_unavailable', 'Authentication event could not be recorded.', 503);
        }
    }
}
