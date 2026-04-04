<?php
/**
 * Testing SAPI — All route cases from Testing.routes.php adapted to Generator pattern.
 * Tests: closures, functions, static methods, custom/multiple methods, params with/without regex,
 *        duplicate params, inline regex, nested groups, catch-all parameterized, catch-all 404.
 */

namespace projects\HTTP_Server_CLI\router;


use Generator;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Route;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


// 1.2 - Function callback
function testing_hello (Request $Request, Response $Response): Response
{
   return $Response->send('Hello ' . ($Request->queries['who'] ?? 'World!') . '!');
}

// 1.3 - Static class callback
class TestWorld
{
   public static function hello (Request $Request, Response $Response): Response
   {
      return $Response->send('Hello World!');
   }
}


return static function
(Request $Request, Response $Response, Router $Router): Generator
{
   $Route = $Router->Route;

   //! 1 - Route Callback
   // 1.1 - Closure
   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response->send('Bootgly WPI demo: Hello, world!');
   }, GET);
   // 1.2 - Function
   yield $Router->route('/function', __NAMESPACE__ . '\\testing_hello', GET);
   // 1.3 - Static class
   yield $Router->route('/static', __NAMESPACE__ . '\\TestWorld::hello', GET);

   //! 2 - Route Conditions
   // 2.1 - Custom method
   yield $Router->route('/custom', function (Request $Request, Response $Response) {
      return $Response->send('[2.1] - Bootgly supports custom HTTP method?!');
   }, 'TEST1');
   // 2.2 - Multiple methods
   yield $Router->route('/multiple', function (Request $Request, Response $Response) {
      return $Response->send('[2.2] - Bootgly supports multiple HTTP methods!');
   }, [GET, POST]);

   //! 3.0 - Static route
   yield $Router->route('/specify', function (Request $Request, Response $Response) {
      return $Response->send('[3.0] Non-default Route!');
   }, GET);

   //! 3.1 - Params with Regex
   // 3.1 - Single named param with Regex
   $Route->Params->id = '[0-9]+';
   yield $Router->route('/param1/:id', function (Request $Request, Response $Response) {
      return $Response->send('[3.1] Single named param with Regex: ' . $this->Params->id);
   }, GET);
   // 3.2 - Different named params with Regex
   $Route->Params->id = '[0-9]+';
   $Route->Params->abc = '[a-z]+';
   yield $Router->route('/param2/:id/param3/:abc/param4', function (Request $Request, Response $Response) {
      return $Response->send('[3.2] Different named params with Regex: ' . $this->Params->id . ', ' . $this->Params->abc);
   }, GET);
   // 3.3 - Equal named params with Regex
   $Route->Params->id = '[0-9]+';
   yield $Router->route('/param6/:id/param7/:id', function (Request $Request, Response $Response) {
      return $Response->send('[3.3] Equal params: ' . $this->Params->id[0] . ', ' . $this->Params->id[1]);
   }, GET);
   // 3.4 - Equal + different named params with Regex
   $Route->Params->id = '[0-9]+';
   $Route->Params->abc = '[a-z]+';
   yield $Router->route('/param8/:id/param9/:id/param10/:abc', function (Request $Request, Response $Response) {
      return $Response->send('[3.4] id1=' . $this->Params->id[0] . ' id2=' . $this->Params->id[1] . ' abc=' . $this->Params->abc);
   }, GET);

   //! 3.5 - Params without Regex
   // 3.5.1 - Single param without Regex
   yield $Router->route('/user/:uid', function (Request $Request, Response $Response) {
      return $Response->send('[3.5.1] uid=' . $this->Params->uid);
   }, GET);
   // 3.5.2 - Multiple different params without Regex
   yield $Router->route('/posts/:pid/comments/:cid', function (Request $Request, Response $Response) {
      return $Response->send('[3.5.2] pid=' . $this->Params->pid . ' cid=' . $this->Params->cid);
   }, GET);
   // 3.5.3 - Multiple equal params without Regex
   yield $Router->route('/post/:uid/comment/:uid', function (Request $Request, Response $Response) {
      return $Response->send('[3.5.3] uid0=' . $this->Params->uid[0] . ' uid1=' . $this->Params->uid[1]);
   }, GET);

   //! 3.6 - Named param with inline Regex
   yield $Router->route('/order/:oid(\\d+)', function (Request $Request, Response $Response) {
      return $Response->send('[3.6] oid=' . $this->Params->oid);
   }, GET);

   //! 4 - Route Group / Nested
   // 4.1 - Nested with unparameterized child routes
   yield $Router->route('/profile/:*', function () use ($Router) {
      yield $Router->route('maria', function ($Request, $Response) {
         return $Response(body: '[4.1] Hello Maria!')->send();
      });
      yield $Router->route('bob', function ($Request, $Response) {
         return $Response(body: '[4.1] Hello Bob!')->send();
      });
      yield $Router->route('me', function ($Request, $Response) {
         return $Response->send('[4.1] Your Profile!');
      });
   }, GET);
   // 4.2 - Nested with parameterized child routes
   $Route->Params->id = '[0-9]+';
   yield $Router->route('/page/:*', function () use ($Router) {
      yield $Router->route(':id', function (Request $Request, Response $Response) {
         return $Response->send('[4.2] Page id=' . $this->Params->id);
      });
   }, GET);

   //! 5 - Catch-All parameterized (TODO in original)
   yield $Router->route('/search/:query*', function (Request $Request, Response $Response) {
      return $Response->send('[5] search=' . $this->Params->query);
   });

   //! 6 - Catch-All 404
   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: '404 Not Found')->send();
   });
};
