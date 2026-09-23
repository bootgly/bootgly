<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? H-HCLI-4 — interim (1xx) responses are bounded by count: an origin that streams
//   103s forever never reaches a final response. Up to INTERIM_LIMIT per response leg
//   is accepted; one more fails as 'Invalid Response', which is never retried.
$interims = static function (int $count): string {
   return str_repeat("HTTP/1.1 103 Early Hints\r\nLink: </style.css>; rel=preload\r\n\r\n", $count);
};

return new Test(
   description: 'It should cap the interim responses accepted before the final one',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function () use ($interims): string {
         return $interims(HTTP_Client_CLI::INTERIM_LIMIT) . "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok";
      },
      function () use ($interims): string {
         return $interims(HTTP_Client_CLI::INTERIM_LIMIT + 1) . "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 6\r\nConnection: close\r\n\r\nmarker";
      },
      // @ A redirect: each leg carries its own interims, counted per leg
      function () use ($interims): string {
         return $interims(40) . "HTTP/1.1 302 Found\r\nLocation: /interim/leg2\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
      },
      function () use ($interims): string {
         return $interims(40) . "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nConnection: close\r\n\r\nleg2";
      },
      // @ A retry: a truncated attempt and its retry, each with its own interims
      function () use ($interims): string {
         return $interims(40) . "HTTP/1.1 200 OK\r\nContent-Length: 10\r\nConnection: close\r\n\r\nabc";
      },
      function () use ($interims): string {
         return $interims(40) . "HTTP/1.1 200 OK\r\nContent-Length: 7\r\nConnection: close\r\n\r\nretried";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/interim/limit');
      },
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxRetries = 1;
         $Client->retryDelay = 0.05;
         $Response = $Client->request(method: 'GET', URI: '/interim/over');
         $Client->maxRetries = 0; // @ Restore default
         $Client->retryDelay = 1.0; // @ Restore default

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/interim/marker');
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/interim/leg1');
      },
      function (): Response {
         $Response = new Response;
         $Response->code = -1;

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxRetries = 1;
         $Client->retryDelay = 0.05;
         $Response = $Client->request(method: 'GET', URI: '/interim/retry');
         $Client->maxRetries = 0; // @ Restore default
         $Client->retryDelay = 1.0; // @ Restore default

         return $Response;
      },
      function (): Response {
         $Response = new Response;
         $Response->code = -1;

         return $Response;
      },
   ],

   test: function (Response $Limit, Response $Over, Response $Marker, Response $Redirected, Response $Leg2, Response $Retried) {
      yield assert(
         assertion: HTTP_Client_CLI::INTERIM_LIMIT === 64,
         description: 'the documented limit is 64 interims per response leg, found: ' . HTTP_Client_CLI::INTERIM_LIMIT
      );
      yield assert(
         assertion: $Limit->code === 200 && $Limit->Body->raw === 'ok',
         description: "exactly INTERIM_LIMIT interims still reach the final response: {$Limit->code} {$Limit->status}"
      );
      yield assert(
         assertion: $Over->code === 0 && $Over->status === 'Invalid Response',
         description: "one interim more fails the request: {$Over->code} {$Over->status}"
      );
      yield assert(
         assertion: $Marker->code === 200 && $Marker->Body->raw === 'marker',
         description: "the refusal is not retried (the next request gets its own response): {$Marker->code} {$Marker->Body->raw}"
      );
      yield assert(
         assertion: $Redirected->code === 200 && $Redirected->Body->raw === 'leg2',
         description: "interims are counted per redirect leg: {$Redirected->code} {$Redirected->status} {$Redirected->Body->raw}"
      );
      yield assert(
         assertion: $Retried->code === 200 && $Retried->Body->raw === 'retried',
         description: "interims are counted per retry: {$Retried->code} {$Retried->status} {$Retried->Body->raw}"
      );
   }
);
