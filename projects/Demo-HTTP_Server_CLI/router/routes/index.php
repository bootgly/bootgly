<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Routes;

return new Routes(
   description: 'HTTP Server CLI Routes',

   routes: function () {
      yield from require __DIR__ . '/static.php';
      yield from require __DIR__ . '/dynamic.php';
      yield from require __DIR__ . '/catch_all.php';
   }
);
