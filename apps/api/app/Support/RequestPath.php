<?php
declare(strict_types=1);
namespace App\Support;
use CodeIgniter\HTTP\RequestInterface;
final class RequestPath
{
    public static function get(RequestInterface $request): string
    {
        $uri=(string)$request->getServer('REQUEST_URI');
        $path=$uri !== '' ? (string)parse_url($uri,PHP_URL_PATH) : $request->getUri()->getPath();
        return trim(preg_replace('#^/index\.php(?:/|$)#','/',$path) ?? $path,'/');
    }
}
