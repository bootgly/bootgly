<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

return [
   // @ configure
   'describe' => 'It should test Session has method',

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
      $Session->set('foo', 'bar');

      return $Response->JSON->send([
         'has_true'  => $Session->has('foo') === true,
         'has_false' => $Session->has('baz') === false
      ]);
   },

   // @ test
   'test' => function ($response) {
      $body = substr($response, strpos($response, "\r\n\r\n") + 4);
      $results = json_decode($body, true);

      return ($results['has_true'] ?? false) && ($results['has_false'] ?? false);
   }
];
