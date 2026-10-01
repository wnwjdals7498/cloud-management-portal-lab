<?php
declare(strict_types=1);

namespace App\Modules\Integrations\Http;

use App\Modules\Integrations\VmStatusReader;
use App\Modules\Jobs\Domain\ExpectedStatus;
use App\Modules\Jobs\Domain\StatusObservation;
use App\Modules\Jobs\Domain\StatusObservationValidator;
use Portal\Shared\PortalException;
use Throwable;

final class SimulatorHttpStatusReader extends AbstractSimulatorHttpClient implements VmStatusReader
{
    public function readJob(ExpectedStatus $target): StatusObservation
    {
        if ($target->mode !== 'simulated') {
            throw new PortalException('status_mode_unavailable', 'The requested status mode is unavailable.', 503);
        }

        $path = '/vmm/v1/jobs/' . rawurlencode($target->externalJobId);
        $response = $this->request('GET', $path);
        if ($response['transport_error'] !== null) {
            throw new PortalException('status_unavailable', 'The external job status is unavailable.', 503);
        }

        if ($response['status'] === 404) {
            throw new PortalException('external_job_not_found', 'The external job was not found.', 404);
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new PortalException('status_unavailable', 'The external job status is unavailable.', 503);
        }

        $decoded = $this->decodeObject($response['body']);
        if ($decoded === null) {
            throw new PortalException('status_response_invalid', 'The external job status response is invalid.', 503);
        }

        try {
            return (new StatusObservationValidator())->validate($decoded, $target);
        } catch (Throwable) {
            throw new PortalException('status_response_invalid', 'The external job status response is invalid.', 503);
        }
    }
}
