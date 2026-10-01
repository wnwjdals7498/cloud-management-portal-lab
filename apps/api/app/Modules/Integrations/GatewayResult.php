<?php
declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Modules\Jobs\Domain\ExternalReceipt;

final readonly class GatewayResult
{
    private function __construct(
        public GatewayDisposition $disposition,
        public ?ExternalReceipt $receipt,
        public ?string $errorCode,
    ) {
    }

    public static function accepted(ExternalReceipt $receipt): self
    {
        return new self(GatewayDisposition::ACCEPTED, $receipt, null);
    }

    public static function rejected(string $errorCode): self
    {
        return new self(GatewayDisposition::REJECTED, null, $errorCode);
    }

    public static function unknown(string $errorCode): self
    {
        return new self(GatewayDisposition::UNKNOWN, null, $errorCode);
    }
}
