<?php

namespace projects\Bootgly\WPI\examples\app;

use const Bootgly\WPI;
use Bootgly\WPI\Nodes\HTTP_Server_;

if ( (WPI->Server ?? false) === false) {
   if (PHP_SAPI !== 'cli') {
      new HTTP_Server_;
   }
   else {
      return;
   }
}

$Router = WPI->Router;

$Router->boot(__DIR__, [
   'routes',
   #'routes.requesting',
   #'routes.responsing',
   #'routes.templating'
]);
