<?php

namespace projects\Bootgly\WPI\examples\app;

use const Bootgly\WPI;

if ( (WPI->Server ?? false) === false) {
   return;
}

$Router = WPI->Router;

$Router->boot(__DIR__, [
   'routes',
   #'routes.requesting',
   #'routes.responsing',
   #'routes.templating'
]);
