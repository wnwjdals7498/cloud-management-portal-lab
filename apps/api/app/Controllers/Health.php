<?php
declare(strict_types=1);
namespace App\Controllers;
final class Health extends BaseController
{
    public function index()
    {
        try { $ready = \App\Support\PortalServices::db()->query('SELECT 1') !== false; }
        catch (\Throwable) { $ready = false; }
        return $this->response->setStatusCode($ready ? 200 : 503)->setJSON(['service'=>'api','mode'=>'simulated','ready'=>$ready]);
    }
}
