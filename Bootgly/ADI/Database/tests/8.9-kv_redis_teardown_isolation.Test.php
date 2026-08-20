<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\KV;
use Bootgly\ADI\Databases\KV\Drivers\Redis;


return new Test(
   description: 'KV(Redis): a torn-down session leaves nothing behind for the next one',
   test: function () {
      /**
       * Builds a Redis driver over a socketpair standing in for the server.
       *
       * @return array{Redis, Connection, resource}
       */
      $connect = static function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Config = new Config(['driver' => 'redis', 'secure' => ['mode' => 'disable']]);
         $Connection = new Connection($Config);
         $Connection->attach($client);

         return [new Redis($Config, $Connection), $Connection, $server];
      };
      $expired = 'Database operation timed out after 1 seconds.';

      // # A — a drained stand-in stops counting against the reader census
      //   The census decides between a stand-in and killing the session. Keyed
      //   by object id it outlived the stand-in, PHP recycled the id into a live
      //   command, and that command then read as abandoned: the census fell to
      //   zero and a healthy session was torn down.
      [$Redis, $Connection, $server] = $connect();

      $lost = null;

      for ($cycle = 1; $cycle <= 6; $cycle++) {
         $Head = $Redis->command('GET', ["head-{$cycle}"]);
         $Reader = $Redis->command('GET', ["reader-{$cycle}"]);
         $Redis->advance($Head);
         $Redis->advance($Reader);
         fread($server, 8192);

         $Head->fail($expired);
         $Redis->abandon($Head);

         if ($Reader->finished) {
            $lost = $cycle;

            break;
         }

         fwrite($server, "\$5\r\nSTAND\r\n\$4\r\nREAD\r\n");
         $Redis->advance($Reader);
         $Redis->drain();
         gc_collect_cycles();
      }

      yield assert(
         assertion: $lost === null,
         description: 'Repeated abandonment never mistakes a live command for a stand-in'
      );

      fclose($server);
      $Connection->disconnect();

      // # B — the half-written command's buffer dies with its socket
      //   A command joins the FIFO only once its frame is whole, so a mid-write
      //   one is nowhere else and nothing failed it. Keeping its buffer let the
      //   next advance flush the tail of a frame onto a NEW socket, where Redis
      //   reads a truncated value as inline commands and runs them.
      [$Redis, $Connection, $server] = $connect();

      $Writer = $Redis->command('SET', ['big', str_repeat('x', 8 * 1024 * 1024)]);
      $Redis->advance($Writer);
      fread($server, 8192);

      $held = $Writer->write !== '';

      $Redis->advance($Redis->command('GET', ['trigger']));
      fclose($server);
      $Redis->advance($Writer);

      yield assert(
         assertion: $held
            && $Writer->write === ''
            && $Writer->finished
            && $Writer->quarantine,
         description: 'Tearing the session down discards the half-written command'
      );

      $Connection->disconnect();

      // # C — a driver that tore its own transport down drives nothing more
      //   `disconnect()` unbinds the Connection, so the pool builds a fresh
      //   driver on it. An operation assigned before the teardown still points
      //   here, and driving it would reconnect the shared Connection through
      //   this object: two FIFOs and two decoders on one wire, each taking
      //   replies meant for the other.
      [$Redis, $Connection, $server] = $connect();

      $Doomed = $Redis->command('GET', ['doomed']);
      $Stranded = $Redis->command('GET', ['stranded']);
      $Redis->advance($Doomed);
      fread($server, 8192);
      fclose($server);
      $Redis->advance($Doomed);

      $retired = $Connection->Protocol === null;

      $Redis->advance($Stranded);

      yield assert(
         assertion: $retired
            && $Stranded->finished
            && $Stranded->error === 'Redis connection was torn down before the command was sent.'
            && $Stranded->quarantine,
         description: 'An operation left pointing at a torn-down driver is refused, not re-driven'
      );

      $Connection->disconnect();

      // # D — reconnecting discards the previous socket's writer too
      //   The same buffer, reached through the other teardown path.
      [$Redis, $Connection, $server] = $connect();

      $Writer = $Redis->command('SET', ['big', str_repeat('x', 8 * 1024 * 1024)]);
      $Redis->advance($Writer);
      fread($server, 8192);

      $Other = $Redis->command('GET', ['other']);
      $Other->state = OperationStates::Connecting;
      $Redis->advance($Other);

      yield assert(
         assertion: $Writer->write === '' && $Writer->finished,
         description: 'A reconnect leaves no bytes of the dead socket to replay'
      );

      fclose($server);
      $Connection->disconnect();

      // # E — a claim on a torn-down session never reaches the connection that
      //   replaced it
      //   The pool reuses one Connection object, so an operation assigned before
      //   a teardown carries a claim whose object identity still matches after
      //   the rebuild. Honouring it drops a connection somebody else is holding.
      $Listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $listenError);

      if (is_resource($Listener)) {
         [$listenHost, $listenPort] = explode(':', stream_socket_get_name($Listener, false));
         stream_set_blocking($Listener, false);

         $KV = new KV([
            'driver'  => 'redis',
            'host'    => $listenHost,
            'port'    => (int) $listenPort,
            'timeout' => 2.0,
            'secure'  => ['mode' => 'disable'],
            'pool'    => ['min' => 0, 'max' => 1],
         ]);

         // ! A command large enough to stall mid-write, so it holds the stream
         //   and the next one parks behind it without ever being queued.
         $Writer = $KV->command('SET', ['big', str_repeat('z', 8 * 1024 * 1024)]);
         $KV->advance($Writer);
         $Accepted = @stream_socket_accept($Listener, 0.2);
         $KV->advance($Writer);

         $Parked = $KV->command('GET', ['parked']);
         $KV->advance($Parked);

         // @ The peer goes away: the writer's next advance tears the session down.
         if (is_resource($Accepted)) {
            fclose($Accepted);
         }

         $KV->advance($Writer);

         // @ The pool rebuilds on the same Connection object.
         $Fresh = $KV->command('GET', ['fresh']);
         $KV->advance($Fresh);
         $Rebuilt = @stream_socket_accept($Listener, 0.2);
         $KV->advance($Fresh);

         $held = $KV->Pool->created === 1 && count($KV->Pool->busy) === 1;

         // @ Only now is the parked command advanced, carrying its stale claim.
         $KV->advance($Parked);

         yield assert(
            assertion: $held
               && $KV->Pool->created === 1
               && count($KV->Pool->busy) === 1
               && is_resource($KV->Connection->socket),
            description: 'A stale claim never hands back the connection that replaced its session'
         );


         if (is_resource($Rebuilt)) {
            fclose($Rebuilt);
         }

         $KV->Connection->disconnect();

         // # F — a teardown hands its siblings back before the pool moves on
         //   A sibling whose command is whole on the wire is in the FIFO, so a
         //   teardown fails it and returns it through the driver. Left there,
         //   it is collected on some later advance — by which time the pool has
         //   rebuilt, and its release belongs to a connection it never held.
         $KV = new KV([
            'driver'  => 'redis',
            'host'    => $listenHost,
            'port'    => (int) $listenPort,
            'timeout' => 2.0,
            'secure'  => ['mode' => 'disable'],
            'pool'    => ['min' => 0, 'max' => 1],
         ]);

         $Sibling = $KV->command('GET', ['queued']);
         $KV->advance($Sibling);
         $Session = @stream_socket_accept($Listener, 0.2);
         $KV->advance($Sibling);

         $Stalling = $KV->command('SET', ['big', str_repeat('q', 8 * 1024 * 1024)]);
         $KV->advance($Stalling);

         $Retired = $KV->Connection->Protocol;

         (new ReflectionProperty(DatabaseOperation::class, 'deadline'))
            ->setValue($Stalling, microtime(true) - 1.0);
         $KV->advance($Stalling);

         $stranded = $Retired === null
            ? -1
            : count((new ReflectionProperty($Retired, 'completed'))->getValue($Retired));

         yield assert(
            assertion: $stranded === 0 && $Sibling->finished,
            description: 'A teardown hands its siblings back before the pool moves on'
         );

         if (is_resource($Session)) {
            fclose($Session);
         }

         $KV->Connection->disconnect();
         fclose($Listener);
      }
   }
);
