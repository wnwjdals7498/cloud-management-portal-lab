<?php
declare(strict_types=1);

$secret = 'fixture-simulator-secret';
$headers = function_exists('getallheaders') ? getallheaders() : [];
$provided = $headers['X-Portal-Simulator'] ?? $headers['x-portal-simulator'] ?? '';
if (! is_string($provided) || ! hash_equals($secret, $provided)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['code' => 'SIMULATOR_AUTH_REQUIRED', 'message' => 'A local simulator identity is required.']], JSON_THROW_ON_ERROR);
    return;
}

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $path === '/vmm/v1/jobs') {
    $command = json_decode((string) file_get_contents('php://input'), true);
    if (! is_array($command) || ! is_string($command['job_id'] ?? null) || ! is_string($command['vm_id'] ?? null)) {
        http_response_code(400);
        echo json_encode(['error' => ['code' => 'INVALID_COMMAND', 'message' => 'The command is invalid.']], JSON_THROW_ON_ERROR);
        return;
    }

    $externalJobId = 'external_job_' . substr(hash('sha256', $command['job_id']), 0, 16);
    $externalVmId = 'external_vm_' . substr(hash('sha256', $command['vm_id']), 0, 16);
    $statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal-sim-http-' . hash('sha256', $externalJobId) . '.json';
    file_put_contents($statePath, json_encode(['external_job_id' => $externalJobId, 'external_vm_id' => $externalVmId], JSON_THROW_ON_ERROR));
    http_response_code(202);
    echo json_encode([
        'external_job_id' => $externalJobId,
        'external_vm_id' => $externalVmId,
        'status' => 'accepted',
        'observed_at' => '2026-10-01T03:00:00Z',
        'mode' => 'simulated',
    ], JSON_THROW_ON_ERROR);
    return;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && preg_match('/\A\/vmm\/v1\/jobs\/([a-zA-Z0-9._:-]+)\z/', $path, $matches) === 1) {
    $externalJobId = rawurldecode($matches[1]);
    $statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal-sim-http-' . hash('sha256', $externalJobId) . '.json';
    if (! is_file($statePath)) {
        http_response_code(404);
        echo json_encode(['error' => ['code' => 'EXTERNAL_JOB_NOT_FOUND', 'message' => 'The external job was not found.']], JSON_THROW_ON_ERROR);
        return;
    }
    $state = json_decode((string) file_get_contents($statePath), true, 16, JSON_THROW_ON_ERROR);
    echo json_encode([
        'external_job_id' => $state['external_job_id'],
        'external_vm_id' => $state['external_vm_id'],
        'status' => 'succeeded',
        'observed_at' => '2026-10-01T03:00:01Z',
        'mode' => 'simulated',
        'power_state' => 'running',
        'guest_readiness' => 'not_ready',
    ], JSON_THROW_ON_ERROR);
    return;
}

http_response_code(404);
echo json_encode(['error' => ['code' => 'ROUTE_NOT_FOUND', 'message' => 'The route was not found.']], JSON_THROW_ON_ERROR);
