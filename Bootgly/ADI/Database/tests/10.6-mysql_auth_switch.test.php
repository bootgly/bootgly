<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function chr;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Authentication;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Operation;


return new Specification(
   description: 'MySQL: AuthSwitchRequest restarts the scramble with the requested plugin',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config([
         'driver' => 'mysql',
         'username' => 'root',
         'password' => 'secret',
         'database' => '',
         'secure' => ['mode' => 'disable'],
      ]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);

      $capabilities = Capabilities::PROTOCOL_41
         | Capabilities::SECURE_CONNECTION
         | Capabilities::PLUGIN_AUTH
         | Capabilities::PLUGIN_AUTH_LENENC;
      $greeting = "\x0A"
         . "8.0.36\0"
         . pack('V', 5)
         . 'ABCDEFGH' . "\0"
         . pack('v', $capabilities & 0xFFFF)
         . chr(Capabilities::CHARSET_UTF8MB4)
         . pack('v', 0)
         . pack('v', $capabilities >> 16)
         . chr(21)
         . str_repeat("\0", 10)
         . 'IJKLMNOPQRST' . "\0"
         . Authentication::SHA2 . "\0";

      $Operation = new Operation($Connection, 'SELECT 1');
      $MySQL->prepare($Operation);
      $Operation->state = OperationStates::Connecting;

      $MySQL->advance($Operation);
      fwrite($server, $MySQL->Encoder->frame($greeting, 0));
      $MySQL->advance($Operation);
      fread($server, 8192);

      // @ AuthSwitchRequest — switch to mysql_native_password with a new nonce
      $switch = "\xFE" . Authentication::NATIVE . "\0" . 'uvwxyzabcdefghijklmn' . "\0";
      fwrite($server, $MySQL->Encoder->frame($switch, 2));
      $MySQL->advance($Operation);

      $reply = (string) fread($server, 8192);
      $scramble = $MySQL->Authentication->scramble(Authentication::NATIVE, 'uvwxyzabcdefghijklmn');

      yield assert(
         assertion: $MySQL->plugin === Authentication::NATIVE
            && $reply[3] === "\x03"
            && substr($reply, 4) === $scramble,
         description: 'The switch reply answers the new plugin scramble with the new nonce'
      );

      fwrite($server, $MySQL->Encoder->frame("\x00\x00\x00" . pack('v', 0) . pack('v', 0), 4));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $MySQL->Authentication->authenticated && $Operation->state === OperationStates::Reading,
         description: 'Authentication completes after the plugin switch'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
