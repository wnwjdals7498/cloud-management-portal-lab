<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Support\PortalServices;
use Portal\Shared\PortalException;
final class CompletionController extends BaseController
{
    public function receive()
    {
        try { $payload=$this->request->getJSON(true); } catch (\Throwable) { throw new PortalException('invalid_completion','A JSON completion is required.',400); }
        if(!is_array($payload)){throw new PortalException('invalid_completion','A JSON completion is required.',400);}
        $ack=PortalServices::worker()->receiveCompletion($payload,$this->request->getHeaderLine('X-Request-ID'));
        return $this->response->setStatusCode($ack['disposition']==='content_conflict' ? 409 : 202)->setJSON($ack);
    }
}
