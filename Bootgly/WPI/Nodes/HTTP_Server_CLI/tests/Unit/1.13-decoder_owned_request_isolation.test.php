<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;

/**
 * Contract — the cache-miss path decodes into the CONNECTION-OWNED Request
 * (`$Package->decoded`), never into another connection's instance and never
 * into a fresh per-request allocation. A connection whose own Request is
 * paused mid-body is unreachable here by construction (its installed body
 * decoder owns the dispatch); other connections' decodes must not disturb
 * it. On the same connection, consecutive misses reuse ONE instance, and
 * every Complete publishes it as the worker's response-phase pointer.
 */

if (! class_exists('U113Connection', false)) {
   class U113Connection extends Connection
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
   description: 'It should decode misses into the connection-owned Request, isolated per connection',
   test: new Assertions(Case: function (): Generator {
      $SocketA = fopen('php://memory', 'w+');
      $SocketB = fopen('php://memory', 'w+');
      if (! is_resource($SocketA) || ! is_resource($SocketB)) {
         yield new Assertion(description: 'U113 probe streams open')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      try {
         $ConnectionA = new U113Connection($SocketA);
         $PackageA = new class($ConnectionA) extends TCPPackages {};
         $ConnectionB = new U113Connection($SocketB);
         $PackageB = new class($ConnectionB) extends TCPPackages {};
         $Decoder = new Decoder_;

         // ! A query-bearing target is never L1-cached, so every decode of it
         //   reaches the miss path under test.
         $head = "GET /u113-own?probe=1 HTTP/1.1\r\nHost: localhost\r\n\r\n";

         // # Connection A: its own Request paused mid-body (its installed
         //   body decoder would own A's dispatch — this decoder never sees A).
         $Paused = new Request;
         $Paused->Body->waiting = true;
         $Paused->Body->raw = 'CLAIMED-BODY';
         $PackageA->decoded = $Paused;

         // @ Connection B decodes — into B's own instance.
         $PackageB->changed = true;
         $stateFirst = $Decoder->decode($PackageB, $head, strlen($head));
         $Owned = $PackageB->decoded;

         yield new Assertion(
            description: 'The miss decodes into the connection-owned instance; the paused connection stays intact',
         )
            ->expect([
               $stateFirst,
               $Owned instanceof Request,
               $Owned === $Paused,
               $Paused->Body->raw,
               $Paused->Body->waiting,
               HTTP_Server_CLI::$Request === $Owned,
               $PackageB->consumed,
            ])
            ->to->be([States::Complete, true, false, 'CLAIMED-BODY', true, true, strlen($head)])
            ->assert();

         // @ A second miss on B: same owned instance, no per-request allocation.
         $PackageB->changed = true;
         $stateSecond = $Decoder->decode($PackageB, $head, strlen($head));

         yield new Assertion(
            description: 'Consecutive misses on one connection reuse one owned instance',
         )
            ->expect([$stateSecond, $PackageB->decoded === $Owned, HTTP_Server_CLI::$Request === $Owned])
            ->to->be([States::Complete, true, true])
            ->assert();
      }
      finally {
         if (is_resource($SocketA)) {
            @fclose($SocketA);
         }
         if (is_resource($SocketB)) {
            @fclose($SocketB);
         }
      }
   })
);
