<?php
// Retained microbenchmark harness — complex (regex) route resolve() cost
// (hot-path report §5.1/§11: complex-route param batching).
//
// Run from the repository root:
//   php -r 'unset($_SERVER["SCRIPT_FILENAME"]); require $argv[1];' \
//     docs/reports/performance/scripts/bench_router_complex.php
//
// The SCRIPT_FILENAME unset bypasses the CLI script-registry validation for
// standalone harnesses. Absolute values include constant reflection overhead
// per iteration (URI/_URL writes) — compare ratios between trees, not raw ns.
require __DIR__ . '/../../../../vendor/autoload.php';

use const Bootgly\WPI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;

const N = 500_000;

$WPI = WPI;
$Router = new Router;
$handler = function ($Request, $Response) { return $Response; };

$Router->route('/users/:id<int>/posts/:post<int>', $handler, 'GET');
$Router->route('/inline/:code(\d{3})/x/:tag(\w+)', $handler, 'GET');
$Router->route('/dup/:a/:a/:a', $handler, 'GET');

$RouterReflection = new ReflectionClass(Router::class);
$RouterReflection->getMethod('flatten')->invoke($Router);
$RouterReflection->getProperty('cached')->setValue($Router, true);

$Request = new Request;
$Request->method = 'GET';
$Request->protocol = 'HTTP/1.1';
$URIProperty = new ReflectionProperty(Request::class, 'URI');
$URLProperty = new ReflectionProperty(Request::class, '_URL');
$WPI->Request = $Request;
$WPI->Response = new Response;

$targets = [
   '/users/12/posts/999',
   '/inline/404/x/tag1',
   '/dup/x/y/z',
   '/users/7/posts/1',
];

foreach ($targets as $URI) {
   $URIProperty->setValue($Request, $URI);
   $URLProperty->setValue($Request, null);
   $Result = $Router->resolve();
   echo "resolve('$URI'): " . ($Result instanceof Response ? 'matched' : var_export($Result, true)) . "\n";
}

$t0 = hrtime(true);
for ($i = 0; $i < N; $i++) {
   $URI = $targets[$i & 3];
   $URIProperty->setValue($Request, $URI);
   $URLProperty->setValue($Request, null);
   $Router->resolve();
}
$t1 = hrtime(true);

printf("%d resolves, %.1f ns/resolve\n", N, ($t1 - $t0) / N);
