<?php
$Router->route('/http-cache-time-based-1', function ($Response, $Request) {
   // * Data
   // ! HTTP
   $Response->Raw->Header->set('Last-Modified', 'Sun, 23 Oct 2022 14:50:00 GMT');

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

$Router->route('/send-headers-as-json-1', function ($Response) {
   // * Data
   // ! HTTP
   return $Response->JSON->send($Response->headers);
   // * Metadata
}, GET);

$Router->get('/simple-file-render-1', function ($Response) {
   return $Response('pages/test.php')
      ->render(
         ['meta' => ['title' => 'Bootgly']],
         function ($HTML, $Exception) {
            if ($Exception === null) {
               echo strlen($HTML);
            }
         }
      )->send();
})->name = '';

$Router->route('/simple-view-render-1', function ($Response) {
   return $Response->View->render('test', [
      'meta' => ['title' => 'Testing Response->View->render(...) in Bootgly!']
   ])->send();
}, GET);
