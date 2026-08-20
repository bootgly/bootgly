<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Driver;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'Database: the pool holds only what it counts, and parks only live operations',
   test: function () {
      /**
       * Reports every violation of the pool's bookkeeping invariants.
       *
       * I1 — `pending` holds only unfinished operations.
       * I2 — `idle`, `busy` and `locked` hold only counted connections, and
       *      `created` agrees with `counted`.
       *
       * @return array<int,string>
       */
      $audit = static function (Pool $Pool): array {
         $counted = (new ReflectionProperty(Pool::class, 'counted'))->getValue($Pool);
         $locked = (new ReflectionProperty(Pool::class, 'locked'))->getValue($Pool);
         $broken = [];

         foreach (['idle' => $Pool->idle, 'busy' => $Pool->busy, 'locked' => $locked] as $set => $members) {
            foreach (array_keys($members) as $id) {
               if (isset($counted[$id]) === false) {
                  $broken[] = "I2: {$set} holds uncounted connection {$id}";
               }
            }
         }

         // ? Membership is two-way: a counted connection the pool holds nowhere
         //   is a slot spent on nothing, and one held in both sets can be handed
         //   out while somebody is using it.
         foreach (array_keys($counted) as $id) {
            $held = isset($Pool->idle[$id]) ? 1 : 0;
            $held += isset($Pool->busy[$id]) ? 1 : 0;

            if ($held === 0) {
               $broken[] = "I2: counted connection {$id} is in neither idle nor busy";
            }

            if ($held === 2) {
               $broken[] = "I2: connection {$id} is in idle and busy at once";
            }
         }

         // ? A reservation only means anything while the connection is held.
         foreach (array_keys($locked) as $id) {
            if (isset($Pool->busy[$id]) === false) {
               $broken[] = "I2: reserved connection {$id} is not held busy";
            }
         }

         if ($Pool->created !== count($counted)) {
            $broken[] = "I2: created is {$Pool->created} while counted holds " . count($counted);
         }

         $parked = [];

         foreach ($Pool->pending as $Pending) {
            if ($Pending->finished) {
               $broken[] = 'I1: a finished operation is parked in pending';
            }

            // ? An operation the pool already assigned is not waiting for one.
            if ($Pending->Protocol !== null) {
               $broken[] = 'I1: an assigned operation is parked in pending';
            }

            $id = spl_object_id($Pending);

            if (isset($parked[$id])) {
               $broken[] = 'I1: the same operation is parked twice';
            }

            $parked[$id] = true;
         }

         return $broken;
      };
      // ! A complete backend answer: CommandComplete then ReadyForQuery.
      $complete = static function (string $command): string {
         $command = "{$command}\0";

         return 'C' . pack('N', strlen($command) + 4) . $command . 'Z' . pack('N', 5) . 'I';
      };
      /**
       * Opens a pooled database over a socketpair, with the peer alongside.
       *
       * @return array{SQL, resource}
       */
      $open = static function (int $max): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => $max]]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };

      // # I1 — cancelling a parked operation must not leave it parked
      //   `cancel()` has no protocol to reach for an operation the pool parked,
      //   so it refuses. Failing it while `pending` still holds it leaves
      //   promote() free to shift it, and fallback() then revives the object and
      //   dispatches the very work the caller cancelled.
      $Database = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 0]]);
      $Pool = $Database->Pool;

      $Parked = $Database->query('INSERT INTO t (id) VALUES (1)');
      $parked = $Parked->state->name;

      $Cancelled = $Database->cancel($Parked);

      yield assert(
         assertion: $parked === 'Pending' && $Pool->pending === [] && $audit($Pool) === [],
         description: 'A cancelled parked operation leaves the pending queue'
      );

      // # …and can never come back
      //   Leaving the queue is only half of it. A refused cancel reaches no
      //   wire, so `cancelled` stays false — which is what Pool::cancel() reads
      //   to decide it must still reconcile the driver. `revoked` answers the
      //   other question fallback() actually needs: the caller withdrew this
      //   work, so a later advance() must never retry it.
      $Database->advance($Cancelled);

      yield assert(
         assertion: $Cancelled->revoked
            && $Cancelled->cancelled === false
            && $Cancelled->fallback === false
            && $Cancelled->finished
            && $Cancelled->error === 'Database operation has no protocol to cancel.',
         description: 'A refused cancel is never revived by a later advance'
      );

      // # The same rule through the driver's door
      //   Every driver refusal leaves `cancelled` false too — and two of them
      //   never override the refusal at all, so for those drivers every cancel
      //   takes this path. The withdrawal is the caller's, not the wire's, so
      //   it is recorded whatever the driver answered.
      [$Database, $server] = $open(1);

      $Flight = $Database->query('SELECT 1 AS v');
      $Database->advance($Flight);
      fread($server, 8192);

      // ! No handshake ever completed on this socket, so the driver has no
      //   BackendKeyData to cancel with and must refuse.
      $Refused = $Database->cancel($Flight);

      yield assert(
         assertion: $Refused->revoked
            && $Refused->cancelled === false
            && $Refused->error === 'PostgreSQL cancellation requires BackendKeyData.',
         description: 'A cancel the driver refuses still records the withdrawal'
      );

      // ! A read routed to a replica carries a FallbackPool, which is the only
      //   state in which fallback() would revive anything at all.
      $Replica = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 0]]);
      $Refused->FallbackPool = $Replica->Pool;

      $Database->advance($Refused);

      yield assert(
         assertion: $Refused->fallback === false && $Refused->state->name === 'Failed',
         description: 'A withdrawn operation is not re-dispatched to its fallback pool'
      );

      // ? And the withdrawal outlives a retry. A cancel that DID reach the wire
      //   sets both flags, so start from that shape: `retry()` re-arms an
      //   operation for another attempt and clears `cancelled` with everything
      //   else, while the caller's decision to drop the work survives it.
      $Refused->cancelled = true;
      $Refused->retry();

      yield assert(
         assertion: $Refused->revoked && $Refused->cancelled === false,
         description: 'Retrying clears the wire flag and keeps the withdrawal'
      );

      // # A driver that throws instead of answering
      //   Every refusal above is a clean return, but a driver can also raise:
      //   MySQL's cancel opens a side channel and decodes a greeting on it, and
      //   both the greeting decoder and the authentication scrambler throw on
      //   input they do not support. Recording the withdrawal after the driver
      //   answered would skip it exactly there, and `fallback()` would then
      //   re-dispatch the statement the caller withdrew.
      $Database = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 0]]);
      $Thrown = $Database->query('SELECT id FROM accounts');
      $Thrown->Protocol = new class ($Database->Config, $Database->Connection) extends Driver {
         public function prepare (Operation $Operation): Operation
         {
            return $Operation;
         }

         public function advance (Operation $Operation): Operation
         {
            return $Operation;
         }

         public function cancel (Operation $Operation): Operation
         {
            throw new InvalidArgumentException('Driver raised instead of answering.');
         }
      };

      $raised = null;

      try {
         $Database->cancel($Thrown);
      }
      catch (Throwable $Raised) {
         $raised = $Raised->getMessage();
      }

      yield assert(
         assertion: $raised === 'Driver raised instead of answering.' && $Thrown->revoked,
         description: 'A cancel the driver raises on still records the withdrawal'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # I2 — a connection the pool dropped is not re-admitted
      //   The pool drops a connection whose socket died, and a driver that still
      //   holds the same Connection object reconnects it on its own. Admitting
      //   it back would serve work from a connection nothing counts against
      //   `max`, so the cap would describe fewer sockets than are open.
      [$Database, $server] = $open(1);
      $Pool = $Database->Pool;

      $First = $Database->query('SELECT 1 AS v');
      $Database->advance($First);
      fread($server, 8192);

      // ! Assigned, so it holds the Connection — but never advanced, so the
      //   teardown below does not reach it through the driver's FIFO.
      $Second = $Database->query('SELECT 2 AS v');

      fclose($server);
      $Database->advance($First);

      $dropped = $Pool->created === 0;

      // @ The survivor reconnects the shared Connection and finishes on it —
      //   driven, not hand-released, so the release below is the one the
      //   framework itself performs.
      [$fresh, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($fresh, false);
      stream_set_blocking($peer, false);
      $Database->Connection->attach($fresh);

      $Database->advance($Second);
      fread($peer, 8192);
      fwrite($peer, $complete('SELECT 1'));
      $Database->advance($Second);

      yield assert(
         assertion: $dropped
            && $Second->finished
            && $Second->error === null
            && $Pool->created === 0
            && $Pool->idle === []
            && $audit($Pool) === [],
         description: 'A dropped connection a driver revived is not re-admitted'
      );

      // ? Refusing it is not enough on its own: nothing else would ever close
      //   it, so the socket would outlive the pool's knowledge of it.
      yield assert(
         assertion: is_resource($Database->Connection->socket) === false,
         description: 'The refused connection is closed rather than left open'
      );

      fclose($peer);
      $Database->Connection->disconnect();

      // # I1 through the other door — a saturated await
      //   `wait()` refuses a parked operation when the pool has no capacity,
      //   and it must take it out of the queue as it does. This is the sibling
      //   of the cancel path above, and the invariant is the same one.
      $Database = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 0]]);
      $Pool = $Database->Pool;

      $Refused = $Database->query('SELECT 1 AS v');
      $refused = null;

      try {
         $Database->await($Refused);
      }
      catch (Throwable $Thrown) {
         $refused = $Thrown->getMessage();
      }

      yield assert(
         assertion: $refused === 'Database pool has no capacity for the operation.'
            && $Pool->pending === []
            && $audit($Pool) === [],
         description: 'A saturated await leaves nothing parked behind it'
      );
   }
);