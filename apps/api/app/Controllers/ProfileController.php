<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Modules\Identity\Application\ActorContextProvider;
use App\Support\PortalServices;
final class ProfileController extends BaseController
{
    public function index()
    {
        $actor=(new ActorContextProvider())->requireAuthenticated();
        $rows=PortalServices::db()->table('vm_profiles')->where('enabled',1)->get()->getResultArray();
        foreach($rows as &$row){$row['snapshot']=json_decode($row['snapshot'],true,64,JSON_THROW_ON_ERROR);unset($row['enabled']);}
        PortalServices::logger()->write('profiles_read',['request_id'=>$actor->requestId,'mode'=>$actor->mode,'outcome'=>'success']);
        return $this->response->setJSON(['profiles'=>$rows,'mode'=>$actor->mode,'request_id'=>$actor->requestId]);
    }
}
