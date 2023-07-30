<?php
function hello($who)
{
   echo 'Hello ' . $who . '!';
}
class World
{
   public static function hello()
   {
      echo 'Hello World!';
   }
}

//! 1 - Route Callback - OK
//? 1.1 - Closure in Callback - OK
$Router->route('/', function () {
   echo '[1.1] - Closure in Callback!';
   // render(['title' => 'Testing']);
}, GET);
//? 1.2 - Function in Callback - OK
$Router->route('/hello', ['hello', 'world'], GET);
//? 1.3 - Static Class in Callback - OK
$Router->route('/staticclass', [__NAMESPACE__ . 'World::hello'], GET);

//! 2 - Route Conditions - OK
//? 2.1 - Custom Method - OK
$Router->route('/', function () {
   echo '[2.1] - Bootgly supports custom HTTP method!';
}, 'TEST1');

//? 2.2 - Multiple Methods - OK
$Router->route('/multiple', function () {
   echo '[2.1] - Bootgly supports multiple HTTP methods!';
}, [GET, POST]);

//! 3.0 - Route Route
//? Example 3.0 - Non-default route
$Router->route('/specify', function () {
   echo '[3.0] Non-default Route!';
}, GET);

//! 3.1 - Route Route - Params - In progress
//@ Named params
//? Example 3.1 - Single named param with Regex - OK
$Route->Params->id = '[0-9]+';
$Router->route('/param1/:id', function () use ($Route) {
   echo '[3.1] Single named param with Regex: ' . $Route->Params->id;
}, GET);

//? Example 3.2 - Different named params with Regex - OK
$Route->Params->id = '[0-9]+';
$Route->Params->abc = '[a-z]+';
$Router->route('/param2/:id/param3/:abc/param4', function () use ($Route) {
   echo '[3.2.1] Different named params with Regex: ' . $Route->Params->id . ', ' . $Route->Params->abc;
}, GET);

//? Example 3.3 - Equals named params with Regex - OK
$Route->Params->id = '[0-9]+';
$Router->route('/param6/:id/param7/:id', function () use ($Route) {
   echo <<<HTML
[3.3] Equals named params with Regex:<br>
Param 1: {$Route->Params->id[0]}<br>
Param 2: {$Route->Params->id[1]}
HTML;
}, GET);

//? Example 3.4 - Equals and different named params with Regex - OK
$Route->Params->id = '[0-9]+';
$Route->Params->abc = '[a-z]+';
$Router->route('/param8/:id/param9/:id/param10/:abc', function () use ($Route) {
   echo <<<HTML
[3.4] Route with params!<br>
Param id 1: {$Route->Params->id[0]}<br>
Param id 2: {$Route->Params->id[1]}<br>
Param abc: {$Route->Params->abc}
HTML;
}, GET);

// Named Params without Regex defined
//? Example 3.5.1 - Single named param without Regex -> '(.*)/?(.*)' - OK
$Router->route('/user/:uid', function () use ($Route) {
   echo '[3.5.1] Single named param without Regex - Param uid: ' . $Route->Params->uid;
}, GET);
//? Example 3.5.2 -  Multiple different named param without Regex - OK
$Router->route('/posts/:pid/comments/:cid', function () use ($Route) {
   echo <<<HTML
[3.5.2] Multiple different named param without Regex:<br>
Param pid: {$Route->Params->pid}<br>
Param cid: {$Route->Params->cid}<br>
HTML;
}, GET);
//? Example 3.5.3 -  Multiple equals named param without Regex - OK
$Router->route('/post/:uid/comment/:uid', function () use ($Route) {
   echo <<<HTML
[3.5.3] Multiple equals param without Regex:<br>
Param uid 0: {$Route->Params->uid[0]}<br>
Param uid 1: {$Route->Params->uid[1]}<br>
HTML;
}, GET);

$Router->continue();
// Named Param with custom Regex in Route
//? Example 3.6 - Named param with custom Regex in param - OK
$Router->route('/order/:oid(\\d+)', function () use ($Route) {
   echo <<<HTML
[3.6] Named Param with custom Regex in Route!<br>
Order id: {$Route->Params->oid}
HTML;
}, GET);


//! 4 - Route Group / Nested - Ok
//? Example 4.1 - Nested route with unparameterized routes in callback - OK
$Router->route('/profile/:*', function () use ($Router) {
   echo '[4.1] Hello ';

   $Router->route('default', function () {
      echo 'Default Profile!';
   });
   $Router->route('user/maria', function () {
      echo 'Maria!';
   });
   $Router->route('user/bob', function () {
      echo 'Bob!';
   });
}, GET);

$Route->Params->id = '[0-9]+';
//? Example 4.2 - Nested route with parameterized routes in callback - OK
$Router->route('/page/:*', function () use ($Router, $Route) {
   echo '[4.2] Hello User';

   $Router->route('user/:id', function () use ($Route) {
      echo ': ';
      echo $Route->Params->id;
   });

   echo '!';
}, GET);

//! ? - Catch-All - In progress
//? Catch-All parameterized - TODO
$Router->route('/search/:query*', function () use ($Route) {
   echo <<<HTML
[?.1] Catch-All parameterized:<br>
Search query: {$Route->Params->query}<br>
HTML;
});

//? Catch-All - 404 Not found
$Router->route('/*', function ($Response) {
   $Response->code = 404;
   $Response('pages/404')->send();
});
