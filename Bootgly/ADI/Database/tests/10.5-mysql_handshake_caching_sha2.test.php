<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function chr;
use function fclose;
use function fread;
use function fwrite;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_private_decrypt;
use function ord;
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
use Bootgly\ADI\Databases\SQL\Operation;


// ! Shared handshake fixtures
$capabilities = Capabilities::PROTOCOL_41
   | Capabilities::SECURE_CONNECTION
   | Capabilities::PLUGIN_AUTH
   | Capabilities::PLUGIN_AUTH_LENENC
   | Capabilities::DEPRECATE_EOF;
$greeting = "\x0A"
   . "8.4.2\0"
   . pack('V', 7)
   . 'ABCDEFGH' . "\0"
   . pack('v', $capabilities & 0xFFFF)
   . chr(Capabilities::CHARSET_UTF8MB4)
   . pack('v', 0)
   . pack('v', $capabilities >> 16)
   . chr(21)
   . str_repeat("\0", 10)
   . 'IJKLMNOPQRST' . "\0"
   . Authentication::SHA2 . "\0";
$boot = static function (array $secure = ['mode' => 'disable']) use ($greeting): array {
   [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
   stream_set_blocking($client, false);
   stream_set_blocking($server, false);

   $Config = new Config([
      'driver' => 'mysql',
      'username' => 'root',
      'password' => 'secret',
      'database' => '',
      'secure' => $secure,
   ]);
   $Connection = new Connection($Config);
   $Connection->attach($client);
   $MySQL = new MySQL($Config, $Connection);
   $Operation = new Operation($Connection, 'SELECT 1');
   $MySQL->prepare($Operation);
   $Operation->state = OperationStates::Connecting;

   $MySQL->advance($Operation);
   fwrite($server, $MySQL->Encoder->frame($greeting, 0));
   $MySQL->advance($Operation);

   return [$MySQL, $Operation, $server, $Connection];
};


return new Specification(
   description: 'MySQL: caching_sha2_password fast path and RSA full authentication',
   test: function () use ($boot) {
      // # Fast authentication path
      [$MySQL, $Operation, $server, $Connection] = $boot();
      $response = (string) fread($server, 8192);
      $scramble = $MySQL->Authentication->scramble(Authentication::SHA2, 'ABCDEFGHIJKLMNOPQRST');

      yield assert(
         assertion: substr($response, 4 + 32, 5) === "root\0"
            && $response[4 + 37] === chr(32)
            && substr($response, 4 + 38, 32) === $scramble,
         description: 'HandshakeResponse41 carries the 32-byte caching_sha2 scramble'
      );

      // @ AuthMoreData 0x03 — fast auth success, then OK
      fwrite($server, $MySQL->Encoder->frame("\x01\x03", 2));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $MySQL->Authentication->authenticated === false
            && $Operation->state === OperationStates::Authenticating,
         description: 'Fast-auth success keeps reading until the OK packet'
      );

      fwrite($server, $MySQL->Encoder->frame("\x00\x00\x00" . pack('v', 0) . pack('v', 0), 3));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $MySQL->Authentication->authenticated && $Operation->state === OperationStates::Reading,
         description: 'The OK packet completes the fast authentication path'
      );

      fclose($server);
      $Connection->disconnect();

      // # Full authentication path (pinned RSA key over plaintext)
      $Key = openssl_pkey_new([
         'private_key_bits' => 2048,
         'private_key_type' => OPENSSL_KEYTYPE_RSA,
      ]);
      $details = $Key === false ? false : openssl_pkey_get_details($Key);

      if ($Key === false || $details === false) {
         return;
      }

      [$MySQL, $Operation, $server, $Connection] = $boot([
         'mode' => 'disable',
         'key' => $details['key'],
      ]);
      fread($server, 8192);

      // @ AuthMoreData 0x04 — the pinned key answers without any key request
      fwrite($server, $MySQL->Encoder->frame("\x01\x04", 2));
      $MySQL->advance($Operation);

      $packet = (string) fread($server, 8192);
      $encrypted = substr($packet, 4);
      $decrypted = '';
      openssl_private_decrypt($encrypted, $decrypted, $Key, OPENSSL_PKCS1_OAEP_PADDING);
      $nonce = 'ABCDEFGHIJKLMNOPQRST';
      $password = '';
      $length = strlen($decrypted);

      for ($index = 0; $index < $length; $index++) {
         $password .= chr(ord($decrypted[$index]) ^ ord($nonce[$index % strlen($nonce)]));
      }

      yield assert(
         assertion: $packet[3] === "\x03" && strlen($packet) === 4 + 256,
         description: 'The pinned key answers with the 2048-bit RSA blob, skipping the key request'
      );

      yield assert(
         assertion: $password === "secret\0",
         description: 'The RSA-encrypted password decrypts to the nonce-masked credential'
      );

      fwrite($server, $MySQL->Encoder->frame("\x00\x00\x00" . pack('v', 0) . pack('v', 0), 4));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $MySQL->Authentication->authenticated && $Operation->state === OperationStates::Reading,
         description: 'The OK packet completes the full authentication path'
      );

      fclose($server);
      $Connection->disconnect();

      // # Full authentication without TLS nor pinned key is rejected
      [$MySQL, $Operation, $server, $Connection] = $boot();
      fread($server, 8192);

      fwrite($server, $MySQL->Encoder->frame("\x01\x04", 2));
      $MySQL->advance($Operation);

      yield assert(
         assertion: $Operation->finished
            && $Operation->quarantine
            && $Operation->error === 'MySQL caching_sha2_password full authentication over plaintext requires TLS or a pinned server public key (secure `key`).',
         description: 'Plaintext full authentication fails closed instead of trusting a server RSA key'
      );

      yield assert(
         assertion: fread($server, 8192) === '',
         description: 'No password material is written after the rejected full authentication'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
