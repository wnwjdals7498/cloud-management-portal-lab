<?php
declare(strict_types=1);

namespace App\Modules\Jobs\Domain;

final readonly class JobAcceptance
{
    public function __construct(
        public string $jobId,
        public string $vmId,
        public string $status,
        public string $mode,
        public string $requestId,
    ) {
    }

    /** @return array{job_id:string,vm_id:string,status:string,mode:string,request_id:string} */
    public function toWire(): array
    {
        return [
            'job_id' => $this->jobId,
            'vm_id' => $this->vmId,
            'status' => $this->status,
            'mode' => $this->mode,
            'request_id' => $this->requestId,
        ];
    }
}
