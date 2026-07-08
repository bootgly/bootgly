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
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Authentication;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;
use Bootgly\ADI\Databases\SQL\Operation;


return new Specification(
   description: 'MySQL: full handshake with mysql_native_password and a text query round trip',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config([
         'driver' => 'mysql',
         'database' => 'bootgly',
         'username' => 'root',
         'password' => 'secret',
         'secure' => ['mode' => 'disable'],
      ]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);

      $capabilities = Capabilities::PROTOCOL_41
         | Capabilities::CONNECT_WITH_DB
         | Capabilities::SECURE_CONNECTION
         | Capabilities::TRANSACTIONS
         | Capabilities::MULTI_RESULTS
         | Capabilities::PLUGIN_AUTH
         | Capabilities::PLUGIN_AUTH_LENENC
         | Capabilities::DEPRECATE_EOF;
      $greeting = "\x0A"
         . "8.4.2\0"
         . pack('V', 99)
         . 'ABCDEFGH' . "\0"
         . pack('v', $capabilities & 0xFFFF)
         . chr(Capabilities::CHARSET_UTF8MB4)
         . pack('v', Capabilities::STATUS_AUTOCOMMIT)
         . pack('v', $capabilities >> 16)
         . chr(21)
         . str_repeat("\0", 10)
         . 'IJKLMNOPQRST' . "\0"
         . Authentication::NATIVE . "\0";

      $Operation = new Operation($Connection, 'SELECT id, name FROM fruits');
      $Operation->state = OperationStates::Connecting;
      $MySQL->prepare($Operation);
      $Operation->state = OperationStates::Connecting;

      // @ Server speaks first
      $MySQL->advance($Operation);

      yield assert(
         assertion: $Operation->state === OperationStates::Startup && fread($server, 8192) === '',
         description: 'Client waits for the server greeting without writing'
      );

      fwrite($server, $MySQL->Encoder->frame($greeting, 0));
      $MySQL->advance($Operation);

      $response = (string) fread($server, 8192);
      $payload = substr($response, 4);
      $scramble = $MySQL->Authentication->scramble(Authentication::NATIVE, 'ABCDEFGHIJKLMNOPQRST');

      yield assert(
         assertion: $MySQL->thread === 99
            && $MySQL->version === '8.4.2'
            && $MySQL->plugin === Authentication::NATIVE,
         description: 'Greeting fills the server identity'
      );

      yield assert(
         assertion: $response[3] === "\x01"
            && substr($payload, 32, 5) === "root\0"
            && $payload[37] === "\x14"
            && substr($payload, 38, 20) === $scramble
            && substr($payload, 58, 8) === "bootgly\0"
            && substr($payload, 66) === Authentication::NATIVE . "\0",
         description: 'HandshakeResponse41 answers with the native scramble, database and plugin'
      );

      yield assert(
         assertion: ($MySQL->capabilities & Capabilities::SSL) === 0
            && ($MySQL->capabilities & Capabilities::DEPRECATE_EOF) !== 0,
         description: 'Negotiated capabilities honor secure=disable and adopt DEPRECATE_EOF'
      );

      // @ Authentication OK
      $ok = "\x00\x00\x00" . pack('v', Capabilities::STATUS_AUTOCOMMIT) . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($ok, 2));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $MySQL->Authentication->authenticated
            && $Operation->state === OperationStates::Reading
            && fread($server, 8192) === "\x1C\x00\x00\x00\x03SELECT id, name FROM fruits",
         description: 'Authentication completes and the queued COM_QUERY hits the wire'
      );

      // @ Result set (DEPRECATE_EOF: rows end with an OK packet)
      $column = static function (string $name, int $type): string {
         return "\x03def\x02db\x06fruits\x06fruits"
            . chr(strlen($name)) . $name
            . chr(strlen($name)) . $name
            . "\x0C" . pack('v', 45) . pack('V', 255) . chr($type) . pack('v', 0) . "\x00\x00\x00";
      };
      fwrite($server, $MySQL->Encoder->frame("\x02", 1));
      fwrite($server, $MySQL->Encoder->frame($column('id', Decoder::TYPE_LONG), 2));
      fwrite($server, $MySQL->Encoder->frame($column('name', Decoder::TYPE_VAR_STRING), 3));
      fwrite($server, $MySQL->Encoder->frame("\x011\x05apple", 4));
      fwrite($server, $MySQL->Encoder->frame("\x012\x05grape", 5));
      fwrite($server, $MySQL->Encoder->frame("\xFE\x00\x00" . pack('v', 0) . pack('v', 0), 6));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $Operation->finished
            && $Operation->error === null
            && $Operation->Result?->status === 'SELECT 2'
            && $Operation->Result->columns === ['id', 'name']
            && $Operation->Result->rows === [
               ['id' => 1, 'name' => 'apple'],
               ['id' => 2, 'name' => 'grape'],
            ],
         description: 'The text result set hydrates cast rows and the command tag'
      );

      yield assert(
         assertion: $MySQL->check() === false,
         description: 'The completed command leaves the request-response queue empty'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
