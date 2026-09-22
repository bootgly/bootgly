<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;


return new Test(
   description: 'Connection: establish() settles the non-blocking dial — a peer, a dial still in flight, or a failure naming the endpoint and its cause',
   test: function () {
      // ! Park until the dial's socket is writable — connected or failed
      $wait = static function (mixed $socket): void {
         $read = [];
         $write = [$socket];
         $except = [];
         stream_select($read, $write, $except, 2);
      };
      $port = static function (mixed $Server): int {
         $name = (string) stream_socket_get_name($Server, false);

         return (int) substr($name, (int) strrpos($name, ':') + 1);
      };

      // # A listening peer
      $Server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
      $listening = $port($Server);
      $Config = new Config([
         'host' => '127.0.0.1',
         'port' => $listening,
         'timeout' => 2,
         'secure' => ['mode' => 'disable'],
      ]);
      $Connection = new Connection($Config);
      $Connection->connect();
      $wait($Connection->socket);

      yield assert(
         assertion: $Connection->establish() === true && $Connection->failure === '',
         description: 'A dial that reached its peer is established'
      );

      $Connection->disconnect();

      // # The same port once nobody listens on it
      fclose($Server);
      $Connection->connect();
      $wait($Connection->socket);
      $established = $Connection->establish();
      $expected = extension_loaded('sockets')
         ? "127.0.0.1:{$listening} refused the connection (ECONNREFUSED)"
         : "the connection to 127.0.0.1:{$listening} failed: refused, unreachable, reset or timed out";

      yield assert(
         assertion: $established === false && $Connection->failure === $expected,
         description: 'A refused dial fails naming the endpoint and the cause, got '
            . var_export($Connection->failure, true)
      );

      $Connection->disconnect();
      $Connection->connect();

      yield assert(
         assertion: $Connection->failure === '',
         description: 'A new dial clears the previous failure'
      );

      $Connection->disconnect();

      // # A peer that resets the connection before the dial is settled
      //   (SO_LINGER 0 needs ext-sockets)
      if (extension_loaded('sockets')) {
         $Server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
         $resetting = $port($Server);
         $Connection = new Connection(new Config([
            'host' => '127.0.0.1',
            'port' => $resetting,
            'timeout' => 2,
            'secure' => ['mode' => 'disable'],
         ]));
         $Connection->connect();
         $Peer = stream_socket_accept($Server, 1);
         if (is_resource($Peer)) {
            $Raw = socket_import_stream($Peer);
            if ($Raw !== false) {
               socket_set_option($Raw, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
            }
            fclose($Peer);
         }
         usleep(50_000);
         $established = $Connection->establish();

         yield assert(
            assertion: $established === false
               && $Connection->failure === "127.0.0.1:{$resetting} reset the connection (ECONNRESET)",
            description: 'A reset before the dial is settled fails naming the endpoint and the cause, got '
               . var_export($Connection->failure, true)
         );

         $Connection->disconnect();
         fclose($Server);
      }

      // # A dial still in flight: a listener whose accept queue is full drops
      //   the SYN, and the kernel keeps retransmitting it
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

      $Connection = new Connection(new Config([
         'host' => '127.0.0.1',
         'port' => $full,
         'timeout' => 2,
         'secure' => ['mode' => 'disable'],
      ]));
      $Connection->connect();

      yield assert(
         assertion: $Connection->establish() === null && $Connection->failure === '',
         description: 'A dial still in flight is neither established nor failed'
      );

      $Connection->disconnect();
      foreach ($Fillers as $Filler) {
         if (is_resource($Filler)) {
            fclose($Filler);
         }
      }
      fclose($Full);

      // # Nothing to settle
      $thrown = false;
      try {
         (new Connection($Config))->establish();
      }
      catch (InvalidArgumentException) {
         $thrown = true;
      }

      yield assert(
         assertion: $thrown,
         description: 'A connection without a socket refuses to settle a dial'
      );
   }
);
