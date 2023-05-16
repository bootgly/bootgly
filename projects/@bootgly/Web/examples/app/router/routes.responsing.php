<?php
$Router('/http-cache-time-based-1', function ($Response, $Request) {
   // * Data
   // ! HTTP
   $Response->Header->set('Last-Modified', 'Sun, 23 Oct 2022 14:50:00 GMT');
   #return $Response->Json->send($Response->Header->headers);

   if ($Request->fresh) {
      return $Response->send(304);
   } else {
      return $Response->Json->send([
         'requested_at' => $Request->at
      ]);
   }
   // * Meta
}, [GET, POST]);

$Router('/send-headers-as-json-1', function ($Response) {
   // * Data
   // ! HTTP
   return $Response->Json->send($Response->headers);
   // * Meta
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

$Router('/simple-view-render-1', function ($Response) {
   return $Response->View->render('test', [
      'meta' => ['title' => 'Testing Response->View->render(...) in Bootgly!']
   ])->send();
}, GET);
