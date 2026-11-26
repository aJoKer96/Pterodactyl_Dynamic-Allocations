<?php
// Standalone regression check of the registered route patterns, not a Laravel integration test.
namespace Illuminate\Support\Facades {
    class Route {
        public static array $routes = [];
        public static function __callStatic($method, $args) {
            self::$routes[$method.' '.$args[0]] = $args[1][1];
            return new self();
        }
        public function middleware($name) { return $this; }
    }
}
namespace {
    use Illuminate\Support\Facades\Route;
    require __DIR__.'/../routes/admin.php';
    $expected = [
        'get /options'=>'options',
        'post /create'=>'create',
        'put /eggs/{egg:id}/port-preset'=>'savePreset',
        'delete /eggs/{egg:id}/port-preset'=>'deletePreset',
        'put /nodes/{node:id}/bind-ip'=>'saveNodeAddress',
        'delete /nodes/{node:id}/bind-ip'=>'resetNodeAddress',
        'get /network/options'=>'networkOptions',
        'get /servers/{server:admin_identifier}/network'=>'serverNetwork',
        'patch /servers/{server:admin_identifier}/network'=>'update',
    ];
    if (Route::$routes !== $expected) throw new \RuntimeException('Unexpected route patterns or handlers.');
    echo "Route registration regression test passed\n";
}
