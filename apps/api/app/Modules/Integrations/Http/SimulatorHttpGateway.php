<?php
declare(strict_types=1);

namespace App\Modules\Integrations\Http;

use App\Modules\Integrations\GatewayResult;
use App\Modules\Integrations\VmCommandGateway;
use App\Modules\Jobs\Domain\ExternalReceiptValidator;
use App\Modules\Jobs\Domain\VmCommand;
use Portal\Shared\CanonicalJson;
use Throwable;

final class SimulatorHttpGateway extends AbstractSimulatorHttpClient implements VmCommandGateway
{
    public function submit(VmCommand $command): GatewayResult
    {
        if ($command->mode !== 'simulated') {
            return GatewayResult::rejected('UNSUPPORTED_MODE');
        }

        try {
            $body = CanonicalJson::encode($command->toWire());
        } catch (Throwable) {
            return GatewayResult::rejected('INVALID_COMMAND');
        }

        $response = $this->request('POST', '/vmm/v1/jobs', $body);
        if ($response['transport_error'] !== null) {
            return GatewayResult::unknown(strtoupper($response['transport_error']));
        }

        $decoded = $this->decodeObject($response['body']);
        if ($response['status'] === 408 || $response['status'] === 429 || $response['status'] >= 500 || $response['status'] === 0) {
            return GatewayResult::unknown($this->safeRemoteError($decoded ?? [], 'VMM_RESPONSE_UNKNOWN'));
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return GatewayResult::rejected($this->safeRemoteError($decoded ?? [], 'VMM_REQUEST_REJECTED'));
        }

        if ($decoded === null) {
            // A successful HTTP status with an unreadable receipt may follow a committed external request.
            return GatewayResult::unknown('VMM_RECEIPT_UNREADABLE');
        }

        try {
            $receipt = (new ExternalReceiptValidator())->validate($decoded, 'simulated');
        } catch (Throwable) {
            return GatewayResult::unknown('VMM_RECEIPT_INVALID');
        }

        return GatewayResult::accepted($receipt);
    }
}
