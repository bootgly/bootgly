<?php
#$Router->pause();

// * Commons
$Router->route('*', function ($Response) {
   return $Response->code(404);
}, OPTIONS);

$Router->route('/favicon.ico', function ($Response) {
   return $Response('statics/favicon.ico')->send();
}, GET);
