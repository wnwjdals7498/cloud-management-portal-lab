<?php
declare(strict_types=1);
namespace Portal\Shared;
final class PortalException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $safeMessage, public readonly int $httpStatus = 400)
    {
        parent::__construct($safeMessage);
    }
}
