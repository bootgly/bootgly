<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;

/**
 * Regression matrix — the connection-owned Request reused across misses must
 * carry NOTHING between requests:
 *  - a previous POST body must not leak into the next body-less request
 *    (`decode()` writes only `Body->position` without a Content-Length), and
 *    the Body flags must land on constructor defaults (a stale
 *    `length`/`waiting` pair would defer the next response forever);
 *  - the lazily-parsed cookie memo must re-bind to the new header fields
 *    (`Header::adopt()` drops it) — no session fixation across requests;
 *  - handler mutations (attributes, auth state) and the Router-written
 *    `base` must be scrubbed by `reset()`.
 */

if (! class_exists('U117Connection', false)) {
   class U117Connection extends Connection
   {
      /** @param resource $Socket */
      public function __construct (mixed &$Socket)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = 12345;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 1;
      }
   }
}


return new Specification(
   description: 'It should scrub every per-request surface when reusing the owned Request across misses',
   test: new Assertions(Case: function (): Generator {
      $Socket = fopen('php://memory', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U117 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      try {
         $Connection = new U117Connection($Socket);
         $Package = new class($Connection) extends TCPPackages {};
         $Decoder = new Decoder_;

         // ! Query-bearing targets are never L1-cached — every decode below
         //   reaches the miss path (reuse + reset) under test.
         $body = 'first-body';
         $length = strlen($body);
         $post = "POST /u117?step=1 HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Cookie: sid=AAA\r\n"
            . "Content-Length: {$length}\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "\r\n"
            . $body;
         $get = "GET /u117?step=2 HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Cookie: sid=BBB\r\n"
            . "\r\n";

         // @ Request 1: POST with body and cookie.
         $Package->changed = true;
         $statePost = $Decoder->decode($Package, $post, strlen($post));
         /** @var Request $Request */
         $Request = $Package->decoded;

         yield new Assertion(
            description: 'The POST decodes with its body and cookie on the owned instance',
         )
            ->expect([
               $statePost,
               $Request->Body->raw,
               $Request->Cookies->get('sid'),
            ])
            ->to->be([States::Complete, $body, 'AAA'])
            ->assert();

         // @ Handler-style mutations that must NOT survive the next request.
         $Request->handled = 'leak-probe';        // __set → attributes
         $Request->username = 'root';             // hook → private authUsername
         $Request->base = '/leaked-base';

         // @ Request 2: body-less GET with a different cookie.
         $Package->changed = true;
         $stateGet = $Decoder->decode($Package, $get, strlen($get));

         yield new Assertion(
            description: 'The reused instance decodes the GET with every per-request surface scrubbed',
         )
            ->expect([
               $stateGet,
               $Package->decoded === $Request,
               // # D2 — body leak / deferred-response hang guards
               $Request->Body->raw,
               $Request->Body->length,
               $Request->Body->waiting,
               $Request->Body->streaming,
               // # D1 — cookie memo re-binds to the new fields
               $Request->Cookies->get('sid'),
               // # reset() scrub
               isSet($Request->handled),
               $Request->username,
               $Request->base,
               $Request->method,
            ])
            ->to->be([
               States::Complete,
               true,
               '',
               null,
               false,
               false,
               'BBB',
               false,
               '',
               '',
               'GET',
            ])
            ->assert();
      }
      finally {
         if (is_resource($Socket)) {
            @fclose($Socket);
         }
      }
   })
);
