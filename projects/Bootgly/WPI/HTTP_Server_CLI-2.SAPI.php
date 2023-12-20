<?php

namespace projects\Bootgly\WPI;


use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return static function
(Request $Request, Response $Response, Router $Router)
{
   // ? Router
   yield $Router->route('/', function ($Request, $Response) {
      return $Response(body: 'Hello World!');
   }, GET);
   yield $Router->route('/new', function () {
      return new Response(body: 'Testing Bootgly HTTP Router!');
   }, GET);
   yield $Router->route('/user/:*', function () use ($Router) {
      yield $Router->route('', function ($Request, $Response) {
         return $Response(body: 'Your profile!');
      });

      yield $Router->route('maria', function ($Request, $Response) {
         return $Response(body: 'Maria\'s user profile!');
      });

      yield $Router->route('bob', function ($Request, $Response) {
         return $Response(body: 'Bob\'s user profile!');
      });
   }, GET);

   return yield $Response(code: 404, body: '404 Not Found!');
};
