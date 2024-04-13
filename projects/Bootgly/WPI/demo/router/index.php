<?php
#$Router->pause();

// * Commons
$Router->route('*', function ($Response) {
   $Response->code = 404;
   return '';
}, OPTIONS);

$Router->route('/favicon.ico', function ($Response) {
   return $Response('statics/favicon.ico')->send();
}, GET);
