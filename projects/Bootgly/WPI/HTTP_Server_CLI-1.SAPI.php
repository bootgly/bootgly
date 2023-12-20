<?php

namespace projects\Bootgly\WPI;


use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return [
   'on.Request' => function (Request $Request, Response $Response)
   {
      // Check HTTP_Server_CLI-example.SAPI.php for more examples
      return $Response(body: 'Hello, world!');
   }
];
