<?php
#$Router->pause();

// * Commons
$Router('*', function ($Response) {
  $Response->code = 404;
  return '';
}, OPTIONS);

$Router('/favicon.ico', function ($Response) {
  return $Response('statics/favicon.ico')->send();
}, GET);
