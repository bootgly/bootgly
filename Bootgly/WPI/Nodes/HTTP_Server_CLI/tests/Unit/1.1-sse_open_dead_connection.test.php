<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;


return new Specification(
   description: 'It should tear down when the accepted head write lands on a closing connection',
   test: new Assertions(Case: function (): Generator {
      // ! A Connection double whose accepted write also closes the
      //   connection — `Packages::writing()` does exactly this when a
      //   pending `closeAfterDrain` applies on the drained write: it
      //   returns TRUE and the connection is already past ESTABLISHED.
      $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($pair === false) {
         throw new RuntimeException('Could not create the socket pair.');
      }
      [$Socket, $PeerSocket] = $pair;

      $Connection = new class($Socket, '127.0.0.1', 1) extends Connection {
         public function writing (&$Socket, null|int $length = null, string $buffer = ''): bool
         {
            $this->status = Connections::STATUS_CLOSED;

            return true;
         }

         // ! No event loop in this process — detach from `Server::$Event`
         public function close (): true
         {
            $this->status = Connections::STATUS_CLOSED;

            return true;
         }
      };
      $Connection->status = Connections::STATUS_ESTABLISHED;
      $Connection->Connection = $Connection;

      $hooked = 0;
      $Response = new Response;
      $SSE = new SSE($Response);
      $SSE->heartbeat = 0;
      $SSE->bind($Connection, null);

      $SSE->open(Close: static function () use (&$hooked): void {
         $hooked++;
      });

      // @ The unit must be torn down: Close ran exactly once, no supervisor
      //   lifecycle survives on the dead connection
      yield new Assertion(
         description: 'A true-but-closing head write tears the unit down (Close ran once)',
      )
         ->expect($SSE->closed === true && $SSE->opened === true && $hooked === 1)
         ->to->be(true)
         ->assert();

      // @ The dead unit stays inert
      yield new Assertion(
         description: 'send() on the torn-down unit is rejected',
      )
         ->expect($SSE->send('x') === false && $hooked === 1)
         ->to->be(true)
         ->assert();

      // ! Defensive cleanup — a regression that installs the supervisor
      //   would otherwise leave a pending SIGALRM task in this process
      Timer::del();
      fclose($Socket);
      fclose($PeerSocket);
   })
);
