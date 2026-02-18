<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

return [
   // @ configure
   'describe' => 'It should test Session check method',

   // @ simulate
   'request' => function ($host) {
      $request = <<<HTTP
      GET / HTTP/1.1\r
      Host: {$host}\r
      \r\n
      HTTP;

      return $request;
   },
   'response' => function (Request $Request, Response $Response) {
      $Session = $Request->Session;
      $Session->set('null_val', null);

      return $Response->JSON->send([
         'exists_null' => $Session->check('null_val') === true,
         'has_null'    => $Session->has('null_val') === false
      ]);
   },

   // @ test
   'test' => function ($response) {
      $body = substr($response, strpos($response, "\r\n\r\n") + 4);
      $results = json_decode($body, true);

      return ($results['exists_null'] ?? false) && ($results['has_null'] ?? false);
   }
];
