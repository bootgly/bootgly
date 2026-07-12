<?php

use Bootgly\ACI\Logs\Data\Display;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;

$root = getenv('BOOTGLY_LEASE_ROOT');
$path = getenv('BOOTGLY_LEASE_STORAGE');
$challenges = getenv('BOOTGLY_LEASE_CHALLENGES');
$result = getenv('BOOTGLY_LEASE_RESULT');
if ($root === false || $path === false || $challenges === false || $result === false) {
   exit(2);
}

$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';
Display::show(Display::NONE);

$Server = new HTTP_Server_CLI(Modes::Test);
$Server->configure(
   host: '127.0.0.1',
   port: 18200,
   workers: 1,
   secure: new AutoTLS(
      domains: ['localhost'],
      email: 'lease@bootgly.test',
      path: $path,
      challenges: $challenges,
      port: 8078,
      options: ['verify_peer' => false]
   )
);

$Prime = new ReflectionMethod($Server, 'prime');
$Prime->invoke($Server);
$Gate = new ReflectionProperty($Server, 'Gate');
$Validator = new ReflectionProperty($Server, 'validator');
file_put_contents($result, json_encode([
   'ready' => $Server->helperReady,
   'local' => is_resource($Gate->getValue($Server)),
   'validator' => $Validator->getValue($Server)
]));
$Server->Process->State->clean();
