<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\SimulatorServices;
use Throwable;

final class Health extends BaseController
{
    public function ready(): \CodeIgniter\HTTP\ResponseInterface
    {
        $remoteAddress = (string) $this->request->getServer('REMOTE_ADDR');
        if (! in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
            return $this->response->setStatusCode(403)->setJSON(['status' => 'unavailable']);
        }

        try {
            $result = SimulatorServices::db()->query('SELECT 1 AS ready');
            if ($result !== false && (int) $result->getRowArray()['ready'] === 1) {
                return $this->response->setJSON(['status' => 'ready']);
            }
        } catch (Throwable) {
            // The readiness response intentionally exposes only database reachability.
        }

        return $this->response->setStatusCode(503)->setJSON(['status' => 'unavailable']);
    }
}
