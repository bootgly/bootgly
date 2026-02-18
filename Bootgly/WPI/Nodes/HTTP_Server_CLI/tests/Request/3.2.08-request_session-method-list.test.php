<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

return [
   // @ configure
   'describe' => 'It should test Session list method',

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
      $Session->flush();
      $Session->set('a', 1);
      $Session->set('b', 2);
      $all = $Session->list();

      return $Response->JSON->send([
         'passed' => $all === ['a' => 1, 'b' => 2]
      ]);
   },

   // @ test
   'test' => function ($response) {
      $body = substr($response, strpos($response, "\r\n\r\n") + 4);
      $results = json_decode($body, true);

      return $results['passed'] ?? false;
   }
];
