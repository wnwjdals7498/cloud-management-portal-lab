<?php
declare(strict_types=1);
namespace App\Support;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Portal\Shared\PortalException;
use Throwable;

final class JsonExceptionHandler implements ExceptionHandlerInterface
{
    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        $status = $exception instanceof PortalException ? $exception->httpStatus : ($statusCode === 404 ? 404 : 500);
        $code = $exception instanceof PortalException ? $exception->errorCode : ($status === 404 ? 'NOT_FOUND' : 'INTERNAL_ERROR');
        $message = $exception instanceof PortalException ? $exception->getMessage() : 'The request could not be completed.';
        $requestId = $request->getHeaderLine('X-Request-ID');
        try { PortalServices::logger()->write('request_error', ['request_id'=>$requestId,'mode'=>'simulated','error_code'=>$code,'level'=>'ERROR']); } catch (Throwable) { /* Do not expose logger failures. */ }
        $response->setStatusCode($status)->setHeader('Cache-Control','no-store')->setHeader('X-Request-ID',$requestId);
        try { if (str_starts_with(RequestPath::get($request),'api/')) { $response->setHeader('X-CSRF-TOKEN',service('security')->getHash()); service('session')->close(); } } catch (Throwable) { /* No secret/error details in fallback. */ }
        $response->setJSON(['error'=>['code'=>$code,'message'=>$message],'request_id'=>$requestId,'mode'=>'simulated'])->send();
    }
}
