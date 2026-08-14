<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Endpoints\Servers\Decoder;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Ownership;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


return new Test(
   description: 'Connection should notify attached teardown owners once while honoring detach and containment',
   test: new Assertions(Case: function (): Generator {
      $Connection = null;
      $Client = null;
      $Accepted = null;
      $Listener = null;
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context)
         ? TCP_Server_CLI::$context
         : null;

      try {
         Timer::del();
         TCP_Server_CLI::$context = [];

         $Server = new TCP_Server_CLI;
         $Connections = new Connections($Server);
         $Event = new class($Connections) extends Select {
            public int $cancels = 0;
            public int $deletes = 0;

            public function cancel (int $ID): bool
            {
               $this->cancels++;
               throw new RuntimeException('contained scheduler cancel');
            }

            public function del ($Socket, int $flag): bool
            {
               $this->deletes++;
               throw new RuntimeException('contained selector delete');
            }
         };
         TCP_Server_CLI::$Event = $Event;

         $Listener = stream_socket_server('tcp://127.0.0.1:0');
         if ($Listener === false) {
            throw new RuntimeException('Could not create the loopback listener.');
         }

         $address = stream_socket_get_name($Listener, false);
         if ($address === false) {
            throw new RuntimeException('Could not resolve the loopback listener address.');
         }

         $Client = stream_socket_client("tcp://{$address}", $code, $message, 2.0);
         if ($Client === false) {
            throw new RuntimeException(
               "Could not connect the loopback client: {$code} {$message}",
            );
         }

         $Accepted = stream_socket_accept($Listener, 2.0);
         if ($Accepted === false) {
            throw new RuntimeException('Could not accept the loopback client.');
         }

         $peer = stream_socket_get_name($Accepted, true);
         if ($peer === false) {
            throw new RuntimeException('Could not resolve the accepted peer identity.');
         }
         [$IP, $port] = Peer::parse($peer);

         $Connection = new Connection($Accepted, $IP, $port);

         $Prototype = new class implements Disconnecting {
            public int $calls = 0;
            public bool $throws = false;

            public function disconnect (): void
            {
               $this->calls++;
               if ($this->throws) {
                  throw new RuntimeException('contained connection owner');
               }
            }
         };
         $Throwing = clone $Prototype;
         $Throwing->throws = true;
         $Survivor = clone $Prototype;
         $Detached = clone $Prototype;
         $OwnerB = clone $Prototype;
         $OwnerB->throws = true;
         $Reentrant = new class($Connection, $OwnerB) implements Disconnecting {
            public int $calls = 0;
            public bool $slotsCleared = false;

            public function __construct (
               private Connection $Connection,
               private Disconnecting $OwnerB,
            ) {}

            public function disconnect (): void
            {
               $this->calls++;
               $this->slotsCleared = $this->Connection->Decoder === null
                  && $this->Connection->decoded === null;

               // ! CLOSING is committed before this callback. Owner B must
               //   therefore be notified immediately, never retained in the
               //   replacement storage, and its failure must be contained.
               Ownership::attach($this->Connection, $this->OwnerB);
               $this->Connection->close();
            }
         };
         $Self = new class($Connection) implements Disconnecting {
            public int $calls = 0;

            public function __construct (private Connection $Connection) {}

            public function disconnect (): void
            {
               $this->calls++;
               Ownership::attach($this->Connection, $this);
            }
         };
         $Decoder = new class implements Decoder, Disconnecting {
            public int $calls = 0;

            public function decode (
               ServerPackages $Package,
               string $buffer,
               int $size,
            ): States {
               return States::Incomplete;
            }

            public function disconnect (): void
            {
               $this->calls++;
               throw new RuntimeException('contained connection decoder');
            }
         };
         $Decoded = clone $Prototype;
         $Decoded->throws = true;

         Ownership::attach($Connection, $Throwing);
         Ownership::attach($Connection, $Reentrant);
         Ownership::attach($Connection, $Self);
         Ownership::attach($Connection, $Survivor);
         // ! SplObjectStorage attachment is identity-idempotent.
         Ownership::attach($Connection, $Survivor);
         Ownership::attach($Connection, $Detached);
         Ownership::detach($Connection, $Detached);
         $Connection->Decoder = $Decoder;
         $Connection->decoded = $Decoded;
         // ! Exercise the exception boundary around scheduler cancellation.
         $Connection->handshakeTimer = 9_001;

         $Connection->close();
         $first = [
            'throwing' => $Throwing->calls,
            'reentrant' => $Reentrant->calls,
            'self' => $Self->calls,
            'owner_b' => $OwnerB->calls,
            'survivor' => $Survivor->calls,
            'detached' => $Detached->calls,
            'decoder' => $Decoder->calls,
            'decoded' => $Decoded->calls,
            'slots_released' => $Connection->Decoder === null
               && $Connection->decoded === null,
            'slots_cleared_before_owners' => $Reentrant->slotsCleared,
            'scheduler_cancel_attempts' => $Event->cancels,
            'selector_delete_attempts' => $Event->deletes,
            'socket_closed' => is_resource($Connection->Socket) === false,
            'closed' => $Connection->status === Connections::STATUS_CLOSED,
         ];

         $Connection->close();
         $duplicate = [
            'throwing' => $Throwing->calls,
            'reentrant' => $Reentrant->calls,
            'self' => $Self->calls,
            'owner_b' => $OwnerB->calls,
            'survivor' => $Survivor->calls,
            'detached' => $Detached->calls,
            'decoder' => $Decoder->calls,
            'decoded' => $Decoded->calls,
         ];

         $Late = new class($Connection) implements Disconnecting {
            public int $calls = 0;

            public function __construct (private Connection $Connection) {}

            public function disconnect (): void
            {
               $this->calls++;
               Ownership::attach($this->Connection, $this);
            }
         };
         Ownership::attach($Connection, $Late);
         Ownership::detach($Connection, $Late);
         Ownership::attach($Connection, $Late);
         Ownership::attach($Connection, $OwnerB);

         yield new Assertion(
            description: 'close contains failures and makes re-entrant attach and close exactly once',
         )
            ->expect([
               'first' => $first,
               'duplicate' => $duplicate,
               'late' => $Late->calls,
               'owner_b_after_reattach' => $OwnerB->calls,
            ])
            ->to->be([
               'first' => [
                  'throwing' => 1,
                  'reentrant' => 1,
                  'self' => 1,
                  'owner_b' => 1,
                  'survivor' => 1,
                  'detached' => 0,
                  'decoder' => 1,
                  'decoded' => 1,
                  'slots_released' => true,
                  'slots_cleared_before_owners' => true,
                  'scheduler_cancel_attempts' => 1,
                  'selector_delete_attempts' => 2,
                  'socket_closed' => true,
                  'closed' => true,
               ],
               'duplicate' => [
                  'throwing' => 1,
                  'reentrant' => 1,
                  'self' => 1,
                  'owner_b' => 1,
                  'survivor' => 1,
                  'detached' => 0,
                  'decoder' => 1,
                  'decoded' => 1,
               ],
               // @ An owner attached after teardown observes the terminal
               //   connection once, including self/repeated attachment.
               'late' => 1,
               'owner_b_after_reattach' => 1,
            ])
            ->assert();
      }
      finally {
         $Connection?->close();
         foreach ([$Accepted, $Client, $Listener] as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
         Timer::del();
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }
   }),
);
