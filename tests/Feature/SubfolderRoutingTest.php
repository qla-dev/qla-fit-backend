<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\TestCase;

class SubfolderRoutingTest extends TestCase
{
    public function test_mounted_routes_and_cors(): void
    {
        foreach ([['GET', '/up', 200], ['GET', '/api/account', 401], ['OPTIONS', '/api/sync', 204]] as [$method, $path, $status]) {
            $server = (require base_path('bootstrap/mount.php'))([
                'REQUEST_URI' => '/endpoints'.$path,
                'SCRIPT_NAME' => '/endpoints/public/index.php',
                'SCRIPT_FILENAME' => '/var/www/endpoints/public/index.php',
                'PHP_SELF' => '/endpoints/public/index.php',
                'REQUEST_METHOD' => $method,
                'HTTP_HOST' => 'fit.qla.dev',
                'HTTPS' => 'on',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'https://fit.qla.dev',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ]);
            $request = new Request(server: $server);
            $this->assertSame('/endpoints', $request->getBaseUrl());
            $this->assertSame($path, $request->getPathInfo());
            $response = $this->app->make(Kernel::class)->handle($request);
            $this->assertSame($status, $response->getStatusCode(), $response->getContent());
            if ($method === 'OPTIONS') {
                $this->assertSame('https://fit.qla.dev', $response->headers->get('Access-Control-Allow-Origin'));
            }
        }
    }

    public function test_mount_preserves_query_strings_and_other_deployment_paths(): void
    {
        $normalize = require base_path('bootstrap/mount.php');
        foreach (['/endpoints', '/endpoints?check=1', '/endpoints/api/sync?since=12'] as $uri) {
            $server = $normalize(['REQUEST_URI' => $uri, 'SCRIPT_FILENAME' => '/var/www/endpoints/public/index.php']);
            $request = new Request(server: $server);
            $this->assertSame(parse_url($uri, PHP_URL_QUERY), parse_url($request->getRequestUri(), PHP_URL_QUERY));
            $this->assertSame('/endpoints', $request->getBaseUrl());
            $this->assertSame(str_contains($uri, '/api/') ? '/api/sync' : '/', $request->getPathInfo());
        }
        foreach (['/api/sync', '/endpoints-other/up', '/endpoints/public/up'] as $uri) {
            $server = ['REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php'];
            $this->assertSame($server, $normalize($server));
        }
    }
}
