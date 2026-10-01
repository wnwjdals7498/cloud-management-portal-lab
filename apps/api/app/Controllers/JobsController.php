<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Modules\Identity\Application\ActorContextProvider;
use App\Modules\Jobs\Application\JobsService;
use App\Support\PortalServices;
use Portal\Shared\PortalException;

final class JobsController extends BaseController
{
    private function service(): JobsService { return new JobsService(PortalServices::db(),PortalServices::audit(),PortalServices::logger(),PortalServices::clock()); }
    private function actor()
    {
        $actor=(new ActorContextProvider())->requireAuthenticated();
        service('security')->getHash();
        return $actor;
    }
    public function create()
    {
        $actor=$this->actor();
        try { $body=$this->request->getJSON(true); } catch (\Throwable) { throw new PortalException('invalid_input','A JSON object is required.',400); }
        if (! is_array($body)) { throw new PortalException('invalid_input','A JSON object is required.',400); }
        $receipt=$this->service()->submit($actor,$body,$this->request->getHeaderLine('Idempotency-Key'));
        return $this->response->setStatusCode(202)->setJSON($receipt);
    }
    public function vms() { $actor=$this->actor(); return $this->response->setJSON(['vms'=>$this->service()->listVms($actor),'request_id'=>$actor->requestId,'mode'=>$actor->mode]); }
    public function vm(string $id) { $actor=$this->actor(); return $this->response->setJSON(['vm'=>$this->service()->getVm($actor,$id),'request_id'=>$actor->requestId,'mode'=>$actor->mode]); }
    public function jobs() { $actor=$this->actor(); return $this->response->setJSON(['jobs'=>$this->service()->listJobs($actor),'request_id'=>$actor->requestId,'mode'=>$actor->mode]); }
    public function job(string $id)
    {
        $actor=$this->actor();$job=$this->service()->getJob($actor,$id);
        service('session')->close();
        $observed=PortalServices::worker()->observeJob($actor,$id);
        $observation=$observed['observation'];
        return $this->response->setJSON(['job'=>$job,'observation'=>$observation,'freshness'=>$observed['freshness'],'discrepancy'=>$observed['discrepancy'],'request_id'=>$actor->requestId,'mode'=>$actor->mode]);
    }
    public function summary()
    {
        $actor=$this->actor(); $service=$this->service(); $vms=$service->listVms($actor); $jobs=$service->listJobs($actor);
        return $this->response->setJSON(['vm_count'=>count($vms),'pending_jobs'=>count(array_filter($jobs,static fn($job)=>!in_array($job['state'],['succeeded','failed'],true))),'failed_jobs'=>count(array_filter($jobs,static fn($job)=>$job['state']==='failed')),'recent_jobs'=>array_slice($jobs,0,10),'observed_at'=>gmdate('c'),'mode'=>'simulated','request_id'=>$actor->requestId]);
    }
}
