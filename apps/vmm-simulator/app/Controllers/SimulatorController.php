<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\SimulatorServices;
use Portal\Shared\PortalException;
use Portal\Shared\Runtime;
use Throwable;

final class SimulatorController extends BaseController
{
    public function create()
    {
        if (($denial = $this->authorizeLoopbackService()) !== null) {
            return $denial;
        }

        $raw = $this->request->getBody();
        if ($raw === '' || strlen($raw) > 65536) {
            return $this->error('INVALID_COMMAND', 'A bounded JSON command is required.', 400);
        }
        try {
            $command = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->error('INVALID_COMMAND', 'A JSON command is required.', 400);
        }
        if (! is_array($command) || array_is_list($command)) {
            return $this->error('INVALID_COMMAND', 'A JSON command object is required.', 400);
        }

        try {
            return $this->response->setStatusCode(202)->setJSON(SimulatorServices::simulator()->submit($command));
        } catch (PortalException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        } catch (Throwable) {
            SimulatorServices::logger()->write('sim.http.command_failed', ['error_code' => 'INTERNAL_SIMULATOR_ERROR']);
            return $this->error('SIMULATOR_UNAVAILABLE', 'The simulator could not accept the command.', 503);
        }
    }

    public function show(string $externalJobId)
    {
        if (($denial = $this->authorizeLoopbackService()) !== null) {
            return $denial;
        }

        try {
            return $this->response->setJSON(SimulatorServices::simulator()->getJob($externalJobId));
        } catch (PortalException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->httpStatus);
        } catch (Throwable) {
            SimulatorServices::logger()->write('sim.http.status_failed', ['error_code' => 'INTERNAL_SIMULATOR_ERROR']);
            return $this->error('SIMULATOR_UNAVAILABLE', 'The simulator status is unavailable.', 503);
        }
    }

    private function authorizeLoopbackService(): ?\CodeIgniter\HTTP\ResponseInterface
    {
        $config = Runtime::config();
        $remoteAddress = (string) $this->request->getServer('REMOTE_ADDR');
        $secret = $config['simulator_secret'] ?? null;
        $presented = $this->request->getHeaderLine('X-Portal-Simulator');

        if (
            ! defined('ENVIRONMENT')
            || ENVIRONMENT !== 'development'
            || ($config['mode'] ?? null) !== 'simulated'
            || ! in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)
            || ! is_string($secret)
            || $secret === ''
            || $presented === ''
            || ! hash_equals($secret, $presented)
        ) {
            return $this->error('SIMULATOR_AUTH_REQUIRED', 'A local simulator service identity is required.', 403);
        }

        return null;
    }

    private function error(string $code, string $message, int $status): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => ['code' => $code, 'message' => $message]]);
    }
}
