<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Endpoints\Servers\Disconnecting;
use Bootgly\WPI\Endpoints\Servers\Ownership;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


/**
 * BG-20 at the transport: the idle reaper must not close a connection that
 * still owns pending work (a parked deferred exchange registered through
 * `Ownership`), and the knob that sizes its window must reach the timer.
 * Nothing here is permanent — once the owner detaches, silence reaps.
 */
return new Test(
   description: 'Connection::expire() should spare a connection with retained work and honour the idle-timeout knob',
   test: new Assertions(Case: function (): Generator {
      $Sockets = [];
      $Established = [];
      $Listener = null;
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $Scopes = new ReflectionProperty(Ownership::class, 'Scopes');
      $registry = $Scopes->getValue();
      $Tasks = new ReflectionProperty(Timer::class, 'tasks');

      $Owner = static fn (): object => new class implements Disconnecting {
         public int $disconnects = 0;

         public function disconnect (): void
         {
            $this->disconnects++;
         }
      };

      try {
         // ! Hermetic timer + server statics (same defensive pattern as 1.4)
         Timer::del();
         TCP_Server_CLI::$context = [];
         $Server = new TCP_Server_CLI;
         $Connections = new Connections($Server);
         TCP_Server_CLI::$Event = new Select($Connections);

         $Listener = stream_socket_server('tcp://127.0.0.1:0');
         if ($Listener === false) {
            throw new RuntimeException('Could not create the loopback listener.');
         }
         $address = stream_socket_get_name($Listener, false);
         if ($address === false) {
            throw new RuntimeException('Could not resolve the loopback listener address.');
         }

         // ! A real established loopback pair per Connection — a unix
         //   socketpair has no peer name and self-closes on construct
         $accept = static function () use ($Listener, $address, &$Sockets, &$Established): Connection {
            $Client = stream_socket_client("tcp://{$address}", $code, $message, 2.0);
            if ($Client === false) {
               throw new RuntimeException("Could not connect the loopback client: {$code} {$message}");
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
            $Sockets[] = $Client;
            $Sockets[] = $Accepted;

            $Connection = new Connection($Accepted, $IP, $port);
            $Established[] = $Connection;

            return $Connection;
         };
         $bucket = static function (Connection $Connection) use ($Tasks): null|int {
            $id = $Connection->timers[0] ?? null;
            if ($id === null) {
               return null;
            }
            foreach ($Tasks->getValue() as $runtime => $tasks) {
               if (array_key_exists($id, $tasks)) {
                  return $runtime;
               }
            }

            return null;
         };

         // @@ A) The knob seeds the window and the timer wheel
         TCP_Server_CLI::$connectionIdleTimeout = 7;
         $Seeded = $accept();
         $runtime = $bucket($Seeded);
         $ahead = $runtime === null ? null : $runtime - time();

         yield assert(
            assertion: $Seeded->expiration === 7 && $ahead !== null && $ahead >= 6 && $ahead <= 8,
            description: 'connectionIdleTimeout seeds the connection window and its timer bucket — expiration: '
               . $Seeded->expiration . ', bucket ahead: ' . var_export($ahead, true)
         );

         TCP_Server_CLI::$connectionIdleTimeout = 0;
         $Unguarded = $accept();

         yield assert(
            assertion: $Unguarded->expiration === 0 && $Unguarded->timers === [],
            description: '0 disables the reaper: no timer is armed — timers: ' . count($Unguarded->timers)
         );

         TCP_Server_CLI::$connectionIdleTimeout = 15;

         // @@ B) Retained work spares a silent connection and renews its lease
         $Busy = $accept();
         $Work = $Owner();
         Ownership::attach($Busy, $Work);
         $Busy->used = time() - 100;

         $closed = $Busy->expire(15);

         yield assert(
            assertion: $closed === false
               && $Busy->status === Connections::STATUS_ESTABLISHED
               && $Busy->used >= time() - 1,
            description: 'a silent connection with an attached owner is spared and its lease renewed — closed: '
               . var_export($closed, true) . ', status: ' . $Busy->status . ', lease age: ' . (time() - $Busy->used)
         );

         // @@ C) The exemption ends with the work: detached + silent = reaped
         Ownership::detach($Busy, $Work);
         $Busy->used = time() - 100;

         $closed = $Busy->expire(15);

         yield assert(
            assertion: $closed === true
               && $Busy->status === Connections::STATUS_CLOSED
               && $Work->disconnects === 0,
            description: 'once the owner detached, silence reaps the connection without notifying it — closed: '
               . var_export($closed, true) . ', status: ' . $Busy->status . ', disconnects: ' . $Work->disconnects
         );

         // @@ D) The write path is unchanged
         $Writer = $accept();
         $Writer->used = time() - 100;
         $Writer->writes++;

         $closed = $Writer->expire(15);

         yield assert(
            assertion: $closed === false && $Writer->used >= time() - 1,
            description: 'a completed write still renews the lease — closed: ' . var_export($closed, true)
         );

         $Writer->used = time() - 100;

         yield assert(
            assertion: $Writer->expire(15) === true && $Writer->status === Connections::STATUS_CLOSED,
            description: 'and silence after it still reaps'
         );
      }
      finally {
         foreach ($Established as $Connection) {
            $Connection->close();
         }
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
         if (is_resource($Listener)) {
            @fclose($Listener);
         }
         Timer::del();
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         $Scopes->setValue(null, $registry);
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }
   })
);
