<?php

namespace projects\Bootgly\WPI;


use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Request;
use Bootgly\WPI\Nodes\HTTP\Server\CLI\Response;


return static function
(Request $Request, Response $Response, Router $Router)
{
   // Check HTTP_Server_CLI-example.SAPI.php for more examples
   return $Response(body: 'Hello World!');
};
