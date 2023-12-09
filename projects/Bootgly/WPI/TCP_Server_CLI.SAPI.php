<?php

namespace projects\Bootgly\WPI;


return static function ($input)
{
   return <<<HTTP_RAW
   HTTP/1.1 200 OK
   Server: Bootgly
   Content-Type: text/plain; charset=UTF-8
   Content-Length: 13

   Hello, World!
   HTTP_RAW;
};
