<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function chr;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function str_contains;
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


// ! Greeting builder — with or without the server SSL capability
$greet = static function (bool $ssl): string {
   $capabilities = Capabilities::PROTOCOL_41
      | Capabilities::SECURE_CONNECTION
      | Capabilities::PLUGIN_AUTH
      | Capabilities::PLUGIN_AUTH_LENENC
      | ($ssl ? Capabilities::SSL : 0);

   return "\x0A"
      . "8.4.2\0"
      . pack('V', 1)
      . 'ABCDEFGH' . "\0"
      . pack('v', $capabilities & 0xFFFF)
      . chr(Capabilities::CHARSET_UTF8MB4)
      . pack('v', 0)
      . pack('v', $capabilities >> 16)
      . chr(21)
      . str_repeat("\0", 10)
      . 'IJKLMNOPQRST' . "\0"
      . Authentication::NATIVE . "\0";
};
$boot = static function (string $mode): array {
   [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
   stream_set_blocking($client, false);
   stream_set_blocking($server, false);

   $Config = new Config([
      'driver' => 'mysql',
      'username' => 'root',
      'password' => 'secret',
      'database' => '',
      'secure' => ['mode' => $mode],
   ]);
   $Connection = new Connection($Config);
   $Connection->attach($client);
   $MySQL = new MySQL($Config, $Connection);
   $Operation = new Operation($Connection, 'SELECT 1');
   $MySQL->prepare($Operation);
   $Operation->state = OperationStates::Connecting;
   $MySQL->advance($Operation);

   return [$MySQL, $Operation, $server, $Connection];
};


return new Specification(
   description: 'MySQL: TLS negotiation honors the secure mode against server capabilities',
   test: function () use ($greet, $boot) {
      // # require + server without SSL → hard failure
      [$MySQL, $Operation, $server, $Connection] = $boot('require');
      fwrite($server, $MySQL->Encoder->frame($greet(false), 0));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $Operation->finished
            && $Operation->error === 'MySQL server refused required TLS.'
            && $Operation->quarantine,
         description: 'Required TLS fails when the server lacks the SSL capability'
      );

      fclose($server);
      $Connection->disconnect();

      // # prefer + server without SSL → plaintext fallback
      [$MySQL, $Operation, $server, $Connection] = $boot('prefer');
      fwrite($server, $MySQL->Encoder->frame($greet(false), 0));
      $MySQL->advance($Operation);
      $response = (string) fread($server, 8192);

      yield assert(
         assertion: $Operation->state === OperationStates::Authenticating
            && str_contains($response, "root\0")
            && ($MySQL->capabilities & Capabilities::SSL) === 0,
         description: 'Preferred TLS falls back to a plaintext handshake response'
      );

      fclose($server);
      $Connection->disconnect();

      // # require + server with SSL → SSLRequest before credentials
      [$MySQL, $Operation, $server, $Connection] = $boot('require');
      fwrite($server, $MySQL->Encoder->frame($greet(true), 0));
      $MySQL->advance($Operation);
      $request = (string) fread($server, 8192);

      yield assert(
         assertion: substr($request, 0, 4) === "\x20\x00\x00\x01"
            && substr($request, 4, 4) === pack('V', $MySQL->capabilities)
            && ($MySQL->capabilities & Capabilities::SSL) !== 0
            && str_contains($request, 'root') === false,
         description: 'SSLRequest is written before any credential when TLS is available'
      );

      yield assert(
         assertion: $Operation->state === OperationStates::SSLHandshake
            || $Operation->finished,
         description: 'The state machine proceeds to the TLS handshake'
      );

      fclose($server);
      $Connection->disconnect();

      // # disable → no SSLRequest even when the server offers SSL
      [$MySQL, $Operation, $server, $Connection] = $boot('disable');
      fwrite($server, $MySQL->Encoder->frame($greet(true), 0));
      $MySQL->advance($Operation);
      $response = (string) fread($server, 8192);

      yield assert(
         assertion: str_contains($response, "root\0")
            && ($MySQL->capabilities & Capabilities::SSL) === 0,
         description: 'Disabled TLS answers the handshake directly with credentials'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
