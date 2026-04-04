<?php

namespace projects\Bootgly\WPI;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return static function
(Request $Request, Response $Response, Router $Router)
{
   // ! Response examples
   // ? Response Meta (first line of HTTP Response Header)
   #return $Response(code: 302); // 302 Not Found

   // ? Response Header
   #$Response->Header->set('Content-Type', 'text/plain');

   // Cookies
   #$Response->Header->Cookies->append('Test', 'value1');
   #$Response->Header->Cookies->append('Test2', 'value2');

   // ? Response Body
   #return $Response(body: 'Hello World!');

   // @ send
   #return $Response->JSON->send(['Hello' => 'World!']); // JSON

   // @ upload
   // Small files
   #return $Response->upload('statics/image1.jpg');
   #return $Response->upload(file: 'statics/alphanumeric.txt', offset: 0, length: 2);
   // Medium files
   #return $Response->upload('statics/screenshot.gif');

   // @ authenticate
   #return $Response->authenticate(realm: 'Protected area');

   // @ redirect
   #return $Response->redirect(URI: 'https://docs.bootgly.com/', code: 302);
};
