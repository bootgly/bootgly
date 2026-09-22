<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\KV;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'Database drivers: a failed dial names the endpoint and its cause, a dial in flight is waited on, and a peer that hangs up before answering is named',
   test: function () {
      $port = static function (mixed $Server): int {
         $name = (string) stream_socket_get_name($Server, false);

         return (int) substr($name, (int) strrpos($name, ':') + 1);
      };

      // # Refused — through the synchronous await(), under the default `prefer`
      $Probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
      $closed = $port($Probe);
      fclose($Probe);
      $cause = extension_loaded('sockets')
         ? "127.0.0.1:{$closed} refused the connection (ECONNREFUSED)"
         : "the connection to 127.0.0.1:{$closed} failed: refused, unreachable, reset or timed out";

      $Messages = [];
      foreach (['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL'] as $driver => $label) {
         $SQL = new SQL(['driver' => $driver, 'host' => '127.0.0.1', 'port' => $closed, 'timeout' => 2.0]);

         try {
            $SQL->await($SQL->query('SELECT 1'));
            $Messages[$label] = null;
         }
         catch (RuntimeException $Exception) {
            $Messages[$label] = $Exception->getMessage();
         }
      }
      $KV = new KV(['driver' => 'redis', 'host' => '127.0.0.1', 'port' => $closed, 'timeout' => 2.0]);
      try {
         $KV->await($KV->command('GET', ['key']));
         $Messages['Redis'] = null;
      }
      catch (RuntimeException $Exception) {
         $Messages['Redis'] = $Exception->getMessage();
      }

      yield assert(
         assertion: $Messages === [
            'MySQL' => "MySQL connection failed: {$cause}.",
            'PostgreSQL' => "PostgreSQL connection failed: {$cause}.",
            'Redis' => "Redis connection failed: {$cause}.",
         ],
         description: 'A refused dial names the endpoint and the cause in every driver, got '
            . json_encode($Messages)
      );

      // # In flight — re-entered before write-readiness, as Pool::wait() does
      //   every second: a full accept queue drops the SYN and keeps the dial open
      $Full = stream_socket_server(
         'tcp://127.0.0.1:0',
         $errno,
         $error,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         stream_context_create(['socket' => ['backlog' => 0]])
      );
      $full = $port($Full);
      $Fillers = [];
      for ($index = 0; $index < 3; $index++) {
         $Fillers[] = stream_socket_client(
            "tcp://127.0.0.1:{$full}",
            $errno,
            $error,
            1,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
         );
      }
      usleep(100_000);

      $States = [];
      $Pending = [];
      foreach (['mysql' => 'MySQL', 'pgsql' => 'PostgreSQL'] as $driver => $label) {
         $SQL = new SQL([
            'driver' => $driver,
            'host' => '127.0.0.1',
            'port' => $full,
            'timeout' => 6.0,
            'secure' => ['mode' => 'disable'],
         ]);
         $Operation = $SQL->query('SELECT 1');
         $SQL->advance($Operation);
         $SQL->advance($Operation);
         $States[$label] = [$Operation->finished, $Operation->error];
         $Pending[$label] = [$SQL, $Operation];
      }
      $KV = new KV([
         'driver' => 'redis',
         'host' => '127.0.0.1',
         'port' => $full,
         'timeout' => 6.0,
         'secure' => ['mode' => 'disable'],
      ]);
      $Operation = $KV->command('GET', ['key']);
      $KV->advance($Operation);
      $KV->advance($Operation);
      $States['Redis'] = [$Operation->finished, $Operation->error];
      $Pending['Redis'] = [$KV, $Operation];

      yield assert(
         assertion: $States === [
            'MySQL' => [false, null],
            'PostgreSQL' => [false, null],
            'Redis' => [false, null],
         ],
         description: 'A dial still in flight is waited on, never reported as a failure, got '
            . json_encode($States)
      );

      // @ The listener goes away: the next SYN retransmit is refused, so the
      //   dial that was waited on now fails — and must still be named
      foreach ($Fillers as $Filler) {
         if (is_resource($Filler)) {
            fclose($Filler);
         }
      }
      fclose($Full);

      for ($attempt = 0; $attempt < 50; $attempt++) {
         $waiting = false;
         foreach ($Pending as [$Database, $Operation]) {
            if ($Operation->finished === false) {
               $Database->advance($Operation);
               $waiting = $waiting || $Operation->finished === false;
            }
         }
         if ($waiting === false) {
            break;
         }
         usleep(100_000);
      }

      $cause = extension_loaded('sockets')
         ? "127.0.0.1:{$full} refused the connection (ECONNREFUSED)"
         : "the connection to 127.0.0.1:{$full} failed: refused, unreachable, reset or timed out";
      $Messages = [];
      foreach ($Pending as $label => [$Database, $Operation]) {
         $Messages[$label] = $Operation->error;
         $Database->Connection->disconnect();
      }

      yield assert(
         assertion: $Messages === [
            'MySQL' => "MySQL connection failed: {$cause}.",
            'PostgreSQL' => "PostgreSQL connection failed: {$cause}.",
            'Redis' => "Redis connection failed: {$cause}.",
         ],
         description: 'A dial refused after it was waited on is named like any refused dial, got '
            . json_encode($Messages)
      );

      // # The peer takes the connection and hangs up before answering — what a
      //   published Docker port does while the server behind it is not listening
      $Listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
      $listening = $port($Listener);
      $endpoint = "127.0.0.1:{$listening}";

      $expected = [
         'mysql/disable' => "MySQL connection failed: {$endpoint} closed the connection before sending its greeting; the server may still be starting.",
         'pgsql/disable' => "PostgreSQL connection failed: {$endpoint} closed the connection during startup; the server may still be starting.",
         'pgsql/prefer' => "PostgreSQL connection failed: {$endpoint} closed the connection during SSL negotiation; the server may still be starting.",
      ];
      // ! A close (FIN) and a reset (RST) — a real proxy resets when what the
      //   client already sent is left unread; SO_LINGER 0 needs ext-sockets
      $Shapes = extension_loaded('sockets') ? ['close', 'reset'] : ['close'];

      foreach ($Shapes as $shape) {
         $Messages = [];
         foreach ([['mysql', 'disable'], ['pgsql', 'disable'], ['pgsql', 'prefer']] as [$driver, $mode]) {
            $SQL = new SQL([
               'driver' => $driver,
               'host' => '127.0.0.1',
               'port' => $listening,
               'timeout' => 2.0,
               'secure' => ['mode' => $mode],
            ]);
            $Operation = $SQL->query('SELECT 1');
            $SQL->advance($Operation);

            $Peer = @stream_socket_accept($Listener, 1);
            if (is_resource($Peer)) {
               if ($shape === 'close') {
                  fclose($Peer);
               }
               else {
                  // @ Past the dial and waiting on the server's first answer
                  $SQL->advance($Operation);
                  $Raw = socket_import_stream($Peer);
                  if ($Raw !== false) {
                     socket_set_option($Raw, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
                  }
                  fclose($Peer);
               }
            }

            for ($attempt = 0; $attempt < 50 && $Operation->finished === false; $attempt++) {
               $SQL->advance($Operation);
               usleep(10_000);
            }

            $Messages["{$driver}/{$mode}"] = $Operation->error;
            $SQL->Connection->disconnect();
         }

         yield assert(
            assertion: $Messages === $expected,
            description: "A peer that hangs up ({$shape}) before answering is named with its endpoint, got "
               . json_encode($Messages)
         );
      }

      // # A server that DID answer and then hung up is not "still starting":
      //   part of a MySQL greeting, a PostgreSQL `N` to the SSLRequest
      $Answers = [
         // ! A packet header announcing 80 bytes, and 10 of them
         'mysql' => "\x50\x00\x00\x00" . str_repeat("\x0a", 10),
         'pgsql' => 'N',
      ];
      $Messages = [];
      foreach ($Answers as $driver => $answer) {
         $SQL = new SQL([
            'driver' => $driver,
            'host' => '127.0.0.1',
            'port' => $listening,
            'timeout' => 2.0,
            'secure' => ['mode' => 'prefer'],
         ]);
         $Operation = $SQL->query('SELECT 1');
         $SQL->advance($Operation);

         $Peer = @stream_socket_accept($Listener, 1);
         if (is_resource($Peer)) {
            // @ Past the dial: the SSLRequest is on the wire before the answer
            $SQL->advance($Operation);
            stream_set_timeout($Peer, 1);
            if ($driver === 'pgsql') {
               fread($Peer, 8);
            }
            fwrite($Peer, $answer);
            fclose($Peer);
         }

         for ($attempt = 0; $attempt < 50 && $Operation->finished === false; $attempt++) {
            $SQL->advance($Operation);
            usleep(10_000);
         }

         $Messages[$driver] = $Operation->error;
         $SQL->Connection->disconnect();
      }
      fclose($Listener);

      yield assert(
         assertion: $Messages === [
            'mysql' => 'MySQL socket closed.',
            'pgsql' => 'PostgreSQL socket closed.',
         ],
         description: 'A hang-up after the server answered keeps the plain transport message, got '
            . json_encode($Messages)
      );
   }
);
