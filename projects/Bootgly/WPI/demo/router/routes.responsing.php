<?php
use Bootgly\WPI\Nodes\HTTP_Server_\Request;
use Bootgly\WPI\Nodes\HTTP_Server_\Response;


$Router->route('/http-cache-time-based-1', function (Request $Request, Response $Response) {
   // * Data
   // ! HTTP
   // on HTTP Client set If-Modified-Since
   $Response->Raw->Header->set('Last-Modified', 'Sun, 20 Oct 2024 14:50:00 GMT');

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
   return $Response->View->render('test', [
      'meta' => ['title' => 'Testing Response->View->render(...) in Bootgly!']
   ])->send();
}, GET);
