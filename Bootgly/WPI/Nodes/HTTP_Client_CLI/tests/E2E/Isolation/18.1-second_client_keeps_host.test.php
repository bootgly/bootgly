<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   Separator: new Separator(line: 'Isolation'),
   description: 'It should keep advertising its own authority after a second client is configured',

   response: function (string $input) {
      $host = '(none)';
      if (preg_match('/^Host:\s*(.+)\r$/mi', $input, $matches) === 1) {
         $host = $matches[1];
      }

      return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
         . "X-Seen-Host: {$host}\r\n"
         . "Content-Length: 2\r\nConnection: close\r\n\r\nOK";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      // ! A second client aimed somewhere else. It is configured and nothing
      //   more — never dialed, never asked for a request — so the only state it
      //   can touch is the one `configure()` writes (HCLI-2).
      $Other = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Other->configure('127.0.0.1', 19809);

      return $Client->request(method: 'GET', URI: '/who');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 200,
         description: "Request still served by its own origin: {$Response->code}"
      );

      yield assert(
         assertion: $Response->Header->get('X-Seen-Host') === '127.0.0.1:9999',
         description: 'Host header belongs to the dialed origin, not the other client: '
            . var_export($Response->Header->get('X-Seen-Host'), true)
      );
   }
);
