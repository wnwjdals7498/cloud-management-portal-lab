<?php
declare(strict_types=1);
namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Portal\Shared\Runtime;

final class PortalIngress implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $id = 'req_' . bin2hex(random_bytes(16));
        $request->setHeader('X-Request-ID', $id);
        $path = \App\Support\RequestPath::get($request);
        if ($path === 'health') { return null; }
        $config = Runtime::config();
        $remote = (string) $request->getServer('REMOTE_ADDR');
        $local = in_array($remote, ['127.0.0.1', '::1'], true);
        if ($config['mode'] !== 'simulated' || ENVIRONMENT !== 'development' || ! $local) {
            return $this->reject($id, 'INGRESS_REJECTED');
        }
        if (str_starts_with($path, 'internal/')) {
            $allowed = hash_equals($config['simulator_secret'], $request->getHeaderLine('X-Portal-Simulator'));
        } else {
            $host = strtolower(preg_replace('/:[0-9]+$/', '', $request->getHeaderLine('Host')) ?? '');
            $allowedHost = in_array($host, ['customer.localhost', 'admin.localhost'], true);
            $allowed = $allowedHost && hash_equals($config['proxy_secret'], $request->getHeaderLine('X-Portal-Proxy'));
        }
        if (! $allowed) { return $this->reject($id, 'INGRESS_REJECTED'); }
        if (strlen((string) $request->getBody()) > 1048576) {
            return service('response')->setStatusCode(413)->setJSON(['error' => ['code' => 'REQUEST_TOO_LARGE', 'message' => 'Request is too large.'], 'request_id' => $id, 'mode' => 'simulated']);
        }
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('X-Request-ID', $request->getHeaderLine('X-Request-ID'));
        $response->setHeader('Cache-Control', 'no-store');
        $path = \App\Support\RequestPath::get($request);
        if (str_starts_with($path, 'api/')) {
            $security = service('security');
            $response->setHeader('X-CSRF-TOKEN', $security->getHash());
            service('session')->close();
        }
        return $response;
    }

    private function reject(string $id, string $code): ResponseInterface
    {
        return service('response')->setStatusCode(403)->setJSON(['error' => ['code' => $code, 'message' => 'Request origin is not allowed.'], 'request_id' => $id, 'mode' => 'simulated']);
    }
}
