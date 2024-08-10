<?php

use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;
use Bootgly\WPI\Modules\HTTP\Server\Router;

use Bootgly\WPI\Nodes\HTTP_Server_\Request;
use Bootgly\WPI\Nodes\HTTP_Server_\Response;


/** @var Router $Router */

$Router->route('/Response/test.a', function (Request $Request, Response $Response) {
   return $Response->send('Test!');
}, GET);

// @ Extending
$Router->route('/Response/test.b', function (Request $Request, Response $Response) {
   return $Response->append('test')->send();
}, GET);
$Router->route('/Response/test.c', function (Request $Request, Response $Response) {
   $test1 = 'abc';

   return $Response
      ->export(
         ['test1' => $test1],
         ['test2' => '123']
      )
      ->render(view: 'test', data: [
         'title' => 'Bootgly',
         'list' => ['a', 'b', 'c']
      ])
      ->send();
}, GET);
$Router->route('/Response/test.v1', function (Request $Request, Response $Response) {
   return $Response
      ->upload('statics/alphanumeric.txt');
}, GET);
$Router->route('/Response/test.x', function (Request $Request, Response $Response) {
   if ($Request->username && $Request->password === 'bingo'){
      return $Response->send('Welcome back, ' . $Request->username . '!');
   }

   return $Response
      ->authenticate(new Authentication\Basic(realm: "Bootgly Protected Area"))
      ->send('Test with body!');
}, GET);
$Router->route('/Response/test.y1', function (Request $Request, Response $Response) {
   return $Response
      ->redirect('https://docs.bootgly.com/?param=1#test');
}, GET);
$Router->route('/Response/test.y2', function (Request $Request, Response $Response) {
   return $Response
      ->redirect('https://docs.bootgly.com/?param=1#test', 302);
}, GET);
$Router->route('/Response/test.z', function (Request $Request, Response $Response) {
   $Response->end(200);
   return $Response;
}, GET);

$Router->route('/http-cache-time-based-1', function (Request $Request, Response $Response) {
   // * Data
   // ! HTTP
   // on HTTP Client set If-Modified-Since
   $Response->Header->set('Last-Modified', 'Sun, 20 Oct 2024 14:50:00 GMT');

   if ($Request->fresh) {
      return $Response(code: 304);
   }
   else {
      return $Response->JSON->send([
         'requested_at' => $Request->at
      ]);
   }
   // * Metadata
}, [GET, POST]);

$Router->route('/send-headers-as-json-1', function (Request $Request, Response $Response) {
   // * Data
   // ! HTTP
   return $Response->JSON->send($Request->headers);
   // * Metadata
}, GET);

$Router->route('/simple-file-render-1', function (Request $Request, Response $Response) {
   return $Response
      ->render(
         view: 'pages/test.php',
         data: ['meta' => ['title' => 'Bootgly']],
         callback: function ($HTML, $Exception) {
            if ($Exception === null) {
               echo strlen($HTML);
            }
         }
      )->send();
}, GET);

$Router->route('/simple-view-render-1', function (Request $Request, Response $Response) {
   return $Response->render('test', [
      'meta' => ['title' => 'Testing Response->View->render(...) in Bootgly!']
   ])->send();
}, GET);
