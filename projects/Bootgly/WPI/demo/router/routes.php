<?php

use Bootgly\WPI\Nodes\HTTP_Server_\Request;
use Bootgly\WPI\Nodes\HTTP_Server_\Response;


//! 1 - Route Callback - OK
//? 1.1 - Closure in Callback - OK
$Router->route('/', function ($Request, Response $Response) {
   return $Response->send(<<<HTML
   Bootgly WPI demo:<br>
   Hello, world!
   HTML);
}, GET);

//? 1.2 - Function in Callback - OK
function hello (Request $Request, Response $Response)
{
   return $Response->send('Hello ' . ($Request->queries['who'] ?? 'World!') . '!');
}
$Router->route('/function', 'hello', GET);

//? 1.3 - Static Class in Callback - OK
class World
{
   public static function hello (Request $Request, Response $Response)
   {
      return $Response->send('Hello World!');
   }
}
$Router->route('/static', __NAMESPACE__ . 'World::hello', GET);

//! 2 - Route Conditions - OK
//? 2.1 - Custom Method - OK
$Router->route('/custom', function (Request $Request, Response $Response) {
   return $Response->send('[2.1] - Bootgly supports custom HTTP method?!');
}, 'TEST1');

//? 2.2 - Multiple Methods - OK
$Router->route('/multiple', function (Request $Request, Response $Response) {
   return $Response->send('[2.1] - Bootgly supports multiple HTTP methods!');
}, [GET, POST]);

//! 3.0 - Route Route
//? Example 3.0 - Non-default route
$Router->route('/specify', function (Request $Request, Response $Response) {
   return $Response->send('[3.0] Non-default Route!');
}, GET);

//! 3.1 - Route Route - Params - In progress
//@ Named params
//? Example 3.1 - Single named param with Regex - OK
$Route->Params->id = '[0-9]+';
$Router->route('/param1/:id', function (Request $Request, Response $Response) {
   return $Response->send('[3.1] Single named param with Regex: ' . $this->Params->id);
}, GET);

//? Example 3.2 - Different named params with Regex - OK
$Route->Params->id = '[0-9]+';
$Route->Params->abc = '[a-z]+';
$Router->route('/param2/:id/param3/:abc/param4', function (Request $Request, Response $Response) {
   return $Response->send(<<<HTML
   [3.2.1] Different named params with Regex: {$this->Params->id}, {$this->Params->abc}
   HTML);
}, GET);

//? Example 3.3 - Equals named params with Regex - OK
$Route->Params->id = '[0-9]+';
$Router->route('/param6/:id/param7/:id', function (Request $Request, Response $Response) {
   return $Response->send(<<<HTML
[3.3] Equals named params with Regex:<br>
Param 1: {$this->Params->id[0]}<br>
Param 2: {$this->Params->id[1]}
HTML);
}, GET);

//? Example 3.4 - Equals and different named params with Regex - OK
$Route->Params->id = '[0-9]+';
$Route->Params->abc = '[a-z]+';
$Router->route('/param8/:id/param9/:id/param10/:abc', function (Request $Request, Response $Response) {
   return $Response->send(<<<HTML
[3.4] Route with params!<br>
Param id 1: {$this->Params->id[0]}<br>
Param id 2: {$this->Params->id[1]}<br>
Param abc: {$this->Params->abc}
HTML);
}, GET);

// Named Params without Regex defined
//? Example 3.5.1 - Single named param without Regex -> '(.*)/?(.*)' - OK
$Router->route('/user/:uid', function (Request $Request, Response $Response) {
   return $Response->send('[3.5.1] Single named param without Regex - Param uid: ' . $this->Params->uid);
}, GET);
//? Example 3.5.2 -  Multiple different named param without Regex - OK
$Router->route('/posts/:pid/comments/:cid', function (Request $Request, Response $Response) {
   return $Response->send(<<<HTML
[3.5.2] Multiple different named param without Regex:<br>
Param pid: {$this->Params->pid}<br>
Param cid: {$this->Params->cid}<br>
HTML);
}, GET);
//? Example 3.5.3 -  Multiple equals named param without Regex - OK
$Router->route('/post/:uid/comment/:uid', function (Request $Request, Response $Response) {
   return $Response->send(<<<HTML
[3.5.3] Multiple equals param without Regex:<br>
Param uid 0: {$this->Params->uid[0]}<br>
Param uid 1: {$this->Params->uid[1]}<br>
HTML);
}, GET);

$Router->continue();
// Named Param with custom Regex in Route
//? Example 3.6 - Named param with custom Regex in param - OK
$Router->route('/order/:oid(\\d+)', function (Request $Request, Response $Response) {
   return $Response->send(<<<HTML
[3.6] Named Param with custom Regex in Route!<br>
Order id: {$this->Params->oid}
HTML);
}, GET);


//! 4 - Route Group / Nested - Ok
//? Example 4.1 - Nested route with unparameterized routes in callback - OK
$Router->route('/profile/:*', function (Request $Request, Response $Response) use ($Router) {
   $Router->route('maria', function (Request $Request, Response $Response) {
      return $Response(body: '[4.1] Hello Maria!')->send();
   });

   $Router->route('bob', function (Request $Request, Response $Response) {
      return $Response(body: '[4.1] Hello Bob!')->send();
   });

   $Router->route('me', function (Request $Request, Response $Response) {
      return $Response->send('Your Profile!');
   });

   return $Response->send('?');
}, GET);

$Route->Params->id = '[0-9]+';
//? Example 4.2 - Nested route with parameterized routes in callback - OK
$Router->route('/page/:*', function (Request $Request, Response $Response) use ($Router) {
   $Router->route(':id', function (Request $Request, Response $Response) {
      return $Response->send('Hello, ' . $this->Params->id . '!');
   });

   return $Response->send('??');
}, GET);

//! ? - Catch-All - In progress
//? Catch-All parameterized - TODO
$Router->route('/search/:query*', function (Request $Request, Response $Response) {
   return $Response->send(<<<HTML
[?.1] Catch-All parameterized:<br>
Search query: {$this->Params->query}<br>
HTML);
});

//? Catch-All - 404 Not found
$Router->route('/*', function (Request $Request, Response $Response) {
   return $Response(code: 404, body: 'pages/404')->send();
});
