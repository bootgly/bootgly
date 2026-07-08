<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function chr;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fread;
use function function_exists;
use function fwrite;
use function pack;
use function pcntl_fork;
use function pcntl_waitpid;
use function str_contains;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strrpos;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Authentication;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Encoder;


return new Specification(
   description: 'MySQL: cancel opens a side channel and issues KILL QUERY (requires pcntl)',
   skip: function_exists('pcntl_fork') === false,
   test: function () {
      // ! Greeting fixture shared by the main handshake and the side channel
      $capabilities = Capabilities::PROTOCOL_41
         | Capabilities::SECURE_CONNECTION
         | Capabilities::PLUGIN_AUTH
         | Capabilities::PLUGIN_AUTH_LENENC
         | Capabilities::DEPRECATE_EOF;
      $greeting = "\x0A"
         . "8.4.2\0"
         . pack('V', 42)
         . 'ABCDEFGH' . "\0"
         . pack('v', $capabilities & 0xFFFF)
         . chr(Capabilities::CHARSET_UTF8MB4)
         . pack('v', 0)
         . pack('v', $capabilities >> 16)
         . chr(21)
         . str_repeat("\0", 10)
         . 'IJKLMNOPQRST' . "\0"
         . Authentication::NATIVE . "\0";

      // # Main connection — handshake over a socket pair to capture the thread id
      [$client, $main] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($main, false);

      // ! Side-channel server on a loopback TCP port
      $Server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);

      if ($Server === false) {
         throw new RuntimeException('Could not open the loopback cancel server.');
      }

      $address = (string) stream_socket_get_name($Server, false);
      $port = (int) substr($address, strrpos($address, ':') + 1);
      $capture = sys_get_temp_dir() . '/bootgly-mysql-cancel-' . uniqid() . '.bin';

      $Config = new Config([
         'driver' => 'mysql',
         'host' => '127.0.0.1',
         'port' => $port,
         'username' => 'root',
         'password' => 'secret',
         'database' => '',
         'timeout' => 5.0,
         'secure' => ['mode' => 'disable'],
      ]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);

      $Operation = $MySQL->query('SELECT SLEEP(60)');
      $Operation->state = OperationStates::Connecting;
      $MySQL->prepare($Operation);
      $Operation->state = OperationStates::Connecting;
      $MySQL->advance($Operation);
      fwrite($main, $MySQL->Encoder->frame($greeting, 0));
      $MySQL->advance($Operation);
      fread($main, 8192);
      fwrite($main, $MySQL->Encoder->frame("\x00\x00\x00" . pack('v', 0) . pack('v', 0), 2));
      $MySQL->advance($Operation);
      fread($main, 8192);

      yield assert(
         assertion: $MySQL->thread === 42 && $Operation->state === OperationStates::Reading,
         description: 'The main session captured the greeting thread id'
      );

      // @ Fork the scripted side-channel server
      $pid = pcntl_fork();

      if ($pid === 0) {
         // # Child — accept, greet, read handshake, OK, capture KILL QUERY, OK
         $Peer = stream_socket_accept($Server, 10.0);

         if ($Peer !== false) {
            $Encoder = new Encoder;
            fwrite($Peer, $Encoder->frame($greeting, 0));
            fread($Peer, 8192);
            fwrite($Peer, $Encoder->frame("\x00\x00\x00" . pack('v', 0) . pack('v', 0), 2));
            $kill = (string) fread($Peer, 8192);
            file_put_contents($capture, $kill);
            fwrite($Peer, $Encoder->frame("\x00\x00\x00" . pack('v', 0) . pack('v', 0), 1));
            fclose($Peer);
         }

         exit(0);
      }

      // # Parent — run the advisory cancel
      $MySQL->cancel($Operation);
      pcntl_waitpid($pid, $status);

      $kill = file_exists($capture) ? (string) file_get_contents($capture) : '';

      yield assert(
         assertion: $Operation->cancelled === true,
         description: 'The cancel marks the operation as cancelled (advisory)'
      );

      yield assert(
         assertion: $kill !== '' && substr($kill, 4, 1) === "\x03"
            && str_contains($kill, 'KILL QUERY 42'),
         description: 'The side channel authenticates and issues KILL QUERY {thread}'
      );

      yield assert(
         assertion: $Operation->finished === false,
         description: 'The main operation still resolves or fails on its own socket'
      );

      if (file_exists($capture)) {
         unlink($capture);
      }
      fclose($Server);
      fclose($main);
      $Connection->disconnect();
   }
);
