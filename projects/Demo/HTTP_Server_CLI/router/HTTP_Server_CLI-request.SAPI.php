<?php

namespace projects\Bootgly\WPI;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return static function
(Request $Request, Response $Response, Router $Router)
{
   // ! Request examples
   // ? Request Meta (first line of HTTP Request Header)
   #$Request->method;    // GET
   #$Request->URI;       // /path/to?query1=value2...
   #$Request->protocol;  // HTTP/1.1
   // ? Request Header
   #$host = $Request->Header->get('Host');
   // ? Request Body
   // @ download
   // Form-data ($_POST, $_FILES)
   #$files = $Request->download('file1'); // $_FILES and $Request->files available too
   // @ receive
   // Raw - JSON, URL Encoded, Text, etc.
   #$Request->receive();

   #dump($_POST, $_FILES, $Request->input); // $Request->input ↔ file_get_contents('php://input')
};
