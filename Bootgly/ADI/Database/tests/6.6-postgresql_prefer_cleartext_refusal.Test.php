<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use const STREAM_CRYPTO_METHOD_TLS_SERVER;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const STREAM_SOCK_STREAM;
use function assert;
use function base64_encode;
use function extension_loaded;
use function fclose;
use function fread;
use function fwrite;
use function is_resource;
use function is_string;
use function json_encode;
use function md5;
use function microtime;
use function pack;
use function preg_match;
use function str_contains;
use function stream_context_create;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function usleep;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Operation;


/**
 * ADI-SQL-4 — under the shipped default `secure.mode = prefer`, a server (or
 * an on-path attacker) that answers the SSLRequest with `N` and then asks for
 * a cleartext (`R=3`) or MD5 (`R=5`) password must not receive it: the driver
 * fails the connection attempt, quarantined, naming `secure.mode => 'disable'`
 * as the declared way to accept plaintext authentication. SCRAM (`R=10`)
 * never sends the password and still proceeds; loopback hosts are exempt; the
 * same exchanges over TLS (`S`) and under `disable` are untouched.
 *
 * Every exchange is scripted from this process over a socket pair; the TLS
 * leg drives both ends of a loopback handshake with the suite's self-signed
 * certificate, the client attached under a non-loopback host name.
 */
// ! Backend `AuthenticationXxx` message: code plus its payload
$R = static function (int $code, string $payload = ''): string {
   $length = pack('N', 8 + strlen($payload));
   $kind = pack('N', $code);

   return "R{$length}{$kind}{$payload}";
};
// ! Drive one plaintext exchange: SSLRequest (unless `disable`), `N`, the `R`
$exchange = static function (Config $Config, string $request, null|PostgreSQL $Driver = null) {
   [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
   stream_set_blocking($client, false);
   stream_set_blocking($server, false);

   $Connection = $Driver?->Connection ?? new Connection($Config);
   $Connection->attach($client);
   $Driver ??= new PostgreSQL($Config, $Connection);
   $Operation = new Operation($Connection, 'SELECT 1');
   $Operation->state = OperationStates::Connecting;

   $Driver->advance($Operation);
   if ($Config->secure['mode'] !== Config::SECURE_DISABLE) {
      fread($server, 8192);
      fwrite($server, 'N');
      $Driver->advance($Operation);
   }
   fread($server, 8192);
   fwrite($server, $request);
   $Driver->advance($Operation);
   $reply = (string) fread($server, 8192);

   fclose($server);
   $Connection->disconnect();

   return [
      'reply' => $reply,
      'state' => $Operation->state->name,
      'error' => (string) $Operation->error,
      'quarantine' => $Operation->quarantine,
   ];
};
// ! Drive a SCRAM exchange (after `N` unless `disable`): the server-first
//   message ends with these attributes (the count), after the nonce and salt
$scram = static function (Config $Config, string $tail, string $suffix = 'server', null|string $final = null) use ($R) {
   [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
   stream_set_blocking($client, false);
   stream_set_blocking($server, false);

   $Connection = new Connection($Config);
   $Connection->attach($client);
   $Driver = new PostgreSQL($Config, $Connection);
   $Operation = new Operation($Connection, 'SELECT 1');
   $Operation->state = OperationStates::Connecting;

   $Driver->advance($Operation);
   if ($Config->secure['mode'] !== Config::SECURE_DISABLE) {
      fread($server, 8192);
      fwrite($server, 'N');
      $Driver->advance($Operation);
   }
   fread($server, 8192);
   fwrite($server, $R(10, "SCRAM-SHA-256\0\0"));
   $Driver->advance($Operation);
   $first = (string) fread($server, 8192);
   $nonce = preg_match('/r=([^,\x00]+)/', $first, $matches) === 1 ? $matches[1] : '';
   $salt = base64_encode('bootgly-scram-salt');
   fwrite($server, $R(11, "r={$nonce}{$suffix},s={$salt},{$tail}"));
   $Driver->advance($Operation);
   $reply = (string) fread($server, 8192);
   // ? The server-final message, when one is scripted
   if ($final !== null) {
      fwrite($server, $R(12, $final));
      $Driver->advance($Operation);
   }

   fclose($server);
   $Connection->disconnect();

   return [
      'reply' => $reply,
      'state' => $Operation->state->name,
      'error' => (string) $Operation->error,
      'quarantine' => $Operation->quarantine,
   ];
};
// ! A stop: nothing written after the message, the session failed and quarantined
$stopped = static fn (array $result): bool
   => $result['reply'] === ''
      && $result['state'] === OperationStates::Failed->name
      && $result['quarantine'] === true;
// ! A refusal: nothing written after the StartupMessage, quarantined, the way out named
$refused = static fn (array $result): bool
   => $result['reply'] === ''
      && $result['state'] === OperationStates::Failed->name
      && $result['quarantine'] === true
      && str_contains($result['error'], "secure.mode => 'disable'");
// ! A `PasswordMessage` carrying exactly this secret
$sent = static fn (array $result, string $secret): bool
   => $result['reply'] !== ''
      && $result['reply'][0] === 'p'
      && substr($result['reply'], 5) === "{$secret}\0"
      && $result['error'] === '';
// ! Read whatever a non-blocking peer has within a short window
$drain = static function ($stream): string {
   $bytes = '';
   $deadline = microtime(true) + 2.0;
   while (microtime(true) < $deadline) {
      $read = [$stream];
      $write = [];
      $except = [];
      if (@stream_select($read, $write, $except, 0, 50_000) === 1) {
         $chunk = fread($stream, 8192);
         if (is_string($chunk) && $chunk !== '') {
            $bytes .= $chunk;
            break;
         }
      }
   }

   return $bytes;
};

return new Test(
   description: 'Database: PostgreSQL refuses cleartext and MD5 passwords after a prefer downgrade to plaintext',
   test: function () use ($R, $exchange, $scram, $stopped, $refused, $sent, $drain) {
      $credentials = ['username' => 'app', 'password' => 'secret'];
      $salt = "\x01\x02\x03\x04";
      $inner = md5('secretapp');
      $hex = md5("{$inner}{$salt}");
      $hash = "md5{$hex}";
      $remote = new Config(['host' => 'db.internal'] + $credentials);

      // @ Leg 1 — `N` then cleartext: the password never reaches the peer
      $cleartext = $exchange($remote, $R(3));
      $evidence = json_encode($cleartext);
      yield assert(
         assertion: $refused($cleartext),
         description: "prefer + N + AuthenticationCleartextPassword on a remote host: refused, quarantined, names secure.mode => disable — {$evidence}"
      );

      // @ Leg 2 — `N` then MD5: the replayable hash never reaches the peer
      $digest = $exchange($remote, $R(5, $salt));
      $evidence = json_encode($digest);
      yield assert(
         assertion: $refused($digest),
         description: "prefer + N + AuthenticationMD5Password on a remote host: refused, quarantined, names secure.mode => disable — {$evidence}"
      );

      // @ Leg 3 — `N` then SCRAM: the password is never sent, so it proceeds
      $SCRAM = $exchange($remote, $R(10, "SCRAM-SHA-256\0\0"));
      $evidence = json_encode($SCRAM);
      yield assert(
         assertion: $SCRAM['error'] === ''
            && $SCRAM['reply'] !== '' && $SCRAM['reply'][0] === 'p'
            && str_contains($SCRAM['reply'], 'SCRAM-SHA-256')
            && str_contains($SCRAM['reply'], 'n,,n=app,r='),
         description: "prefer + N + SASL (SCRAM-SHA-256) proceeds with the client-first message — {$evidence}"
      );

      // @ Leg 3b — after `N`, SCRAM below PostgreSQL's iteration floor is
      //   refused (its proof would be as crackable offline as MD5); at the
      //   floor it proceeds; loopback and `disable` keep any count
      $cheap = $scram($remote, 'i=1');
      $below = $scram($remote, 'i=4095');
      $floor = $scram($remote, 'i=4096');
      $local = $scram(new Config(['host' => '127.0.0.1'] + $credentials), 'i=1');
      $declared = $scram(new Config(['host' => 'db.internal', 'secure' => ['mode' => 'disable']] + $credentials), 'i=1');
      $final = static fn (array $result): bool => $result['error'] === ''
         && $result['reply'] !== '' && $result['reply'][0] === 'p'
         && str_contains($result['reply'], 'c=biws,r=');
      $evidence = json_encode([$cheap, $below, $floor, $local, $declared]);
      yield assert(
         assertion: $refused($cheap) && $refused($below) && $final($floor) && $final($local) && $final($declared),
         description: "prefer + N + SCRAM with i=1 or i=4095 on a remote host: refused; i=4096, loopback and disable proceed — {$evidence}"
      );

      // @ Leg 3c — the count the floor judges is the count the key is derived
      //   from: a repeated, malformed or missing count stops the session
      $ambiguous = [];
      foreach ([
         'repeated' => ['i=4096,i=1', 'server'],
         'repeated, the floor last' => ['i=1,i=4096', 'server'],
         'repeated after a lookalike' => ['i=4096,i:1', 'server'],
         'trailing junk' => ['i=1x', 'server'],
         'malformed' => ['i:1', 'server'],
         'malformed at the floor' => ['i:4096', 'server'],
         'trailing junk at the floor' => ['i=4096x', 'server'],
         'missing' => ['x=4096', 'server'],
         'above the ceiling' => ['i=10000001', 'server'],
         'nonce lookalike' => ['i=1', 'xi=99999'],
      ] as $label => [$tail, $suffix]) {
         $result = $scram($remote, $tail, $suffix);
         // ! Only a well-formed count below the floor names the floor
         $floored = str_contains($result['error'], 'iterations (below');
         if ($stopped($result) === false || $floored !== ($label === 'nonce lookalike')) {
            $ambiguous[$label] = $result;
         }
      }
      // ! A declared plaintext session (no floor) still stops on an ambiguous
      //   or oversized count
      foreach (['declared repeated' => 'i=4096,i=1', 'declared above the ceiling' => 'i=10000001'] as $label => $tail) {
         $result = $scram(new Config(['host' => 'db.internal', 'secure' => ['mode' => 'disable']] + $credentials), $tail);
         if ($stopped($result) === false) {
            $ambiguous[$label] = $result;
         }
      }
      $evidence = json_encode($ambiguous);
      yield assert(
         assertion: $ambiguous === [],
         description: "A repeated, malformed or missing SCRAM count stops a downgraded session before any proof — {$evidence}"
      );

      // @ Leg 3d — a SASL offer without SCRAM-SHA-256 and a malformed
      //   server-final message each fail the session, without throwing
      $mechanism = $exchange($remote, $R(10, "SCRAM-SHA-1\0\0"));
      $signature = $scram(new Config(['host' => 'db.internal', 'secure' => ['mode' => 'disable']] + $credentials), 'i=4096', 'server', 'garbage');
      $evidence = json_encode([$mechanism, $signature]);
      yield assert(
         assertion: $stopped($mechanism)
            && str_contains($mechanism['error'], 'SCRAM-SHA-256 mechanism is not available')
            && $signature['state'] === OperationStates::Failed->name
            && str_contains($signature['error'], 'SASL server signature is invalid'),
         description: "A SASL offer without SCRAM-SHA-256 stops the session; a malformed server-final fails it — {$evidence}"
      );

      // @ Leg 4 — `disable` declared plaintext: no SSLRequest, cleartext answered
      $disable = $exchange(new Config(['host' => 'db.internal', 'secure' => ['mode' => 'disable']] + $credentials), $R(3));
      $evidence = json_encode($disable);
      yield assert(
         assertion: $sent($disable, 'secret'),
         description: "disable + cleartext on a remote host still answers (the declared opt-out) — {$evidence}"
      );

      // @ Leg 5 — loopback hosts are exempt; look-alike names are not
      $exempt = [];
      foreach (['127.0.0.1', '127.8.9.10', 'localhost', 'LOCALHOST', '::1', '[::1]'] as $host) {
         $result = $exchange(new Config(['host' => $host] + $credentials), $R(3));
         if ($sent($result, 'secret') === false) {
            $exempt[$host] = $result;
         }
      }
      $evidence = json_encode($exempt);
      yield assert(
         assertion: $exempt === [],
         description: "Loopback hosts (127.0.0.0/8, ::1, [::1], localhost) keep answering cleartext after N — {$evidence}"
      );
      $lookalikes = [];
      foreach (['localhost.example', '127.0.0.1.example', '10.0.0.5', '::ffff:10.0.0.5'] as $host) {
         $result = $exchange(new Config(['host' => $host] + $credentials), $R(3));
         if ($refused($result) === false) {
            $lookalikes[$host] = $result;
         }
      }
      $evidence = json_encode($lookalikes);
      yield assert(
         assertion: $lookalikes === [],
         description: "Look-alike and private non-loopback hosts are refused like any remote one — {$evidence}"
      );

      // @ Leg 6 — a replica judges its OWN endpoint and mode
      $Primary = new Config([
         'host' => '127.0.0.1',
         'replicas' => [
            ['host' => 'replica.internal'],
            ['host' => 'legacy.internal', 'secure' => ['mode' => 'disable']],
         ],
      ] + $credentials);
      $replica = $exchange(new Config($Primary->replicas[0]), $R(3));
      $legacy = $exchange(new Config($Primary->replicas[1]), $R(3));
      $evidence = json_encode([$replica, $legacy]);
      yield assert(
         assertion: $refused($replica) && $sent($legacy, 'secret'),
         description: "A remote replica inherits prefer and refuses; one declaring disable answers — {$evidence}"
      );

      // @ Leg 7 — the downgrade belongs to ONE dial: a driver whose previous
      //   dial was refused, re-dialed, still refuses; a fresh dial is judged anew
      $Connection = new Connection($remote);
      $Driver = new PostgreSQL($remote, $Connection);
      $first = $exchange($remote, $R(5, $salt), $Driver);
      $second = $exchange($remote, $R(3), $Driver);
      $evidence = json_encode([$first, $second]);
      yield assert(
         assertion: $refused($first) && $refused($second),
         description: "Re-dialing the same driver after N re-applies the refusal on each dial — {$evidence}"
      );

      // @ Leg 8 — the same MD5 exchange over TLS (`S`) on a remote host still
      //   answers, on the driver whose previous dials were downgraded
      if (extension_loaded('openssl') === false) {
         yield (new Assertion(description: 'the TLS leg needs ext-openssl'))->skip();

         return;
      }
      $directory = __DIR__;
      $fixtures = "{$directory}/fixtures";
      $Listener = stream_socket_server(
         'tcp://127.0.0.1:0',
         $errorCode,
         $error,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         stream_context_create(['ssl' => ['local_cert' => "{$fixtures}/postgresql_tls.pem"]]),
      );
      $address = (string) stream_socket_get_name($Listener, false);
      $port = (int) substr($address, (int) strrpos($address, ':') + 1);
      $client = stream_socket_client(
         "tcp://127.0.0.1:{$port}",
         $errorCode,
         $error,
         2.0,
         context: stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]),
      );
      $Peer = stream_socket_accept($Listener, 2.0);
      stream_set_blocking($client, false);
      stream_set_blocking($Peer, false);

      // ! The SAME driver whose two previous dials were downgraded and refused:
      //   nothing of those dials may follow it into this encrypted one
      $Connection = $Driver->Connection;
      $Connection->attach($client);
      $Operation = new Operation($Connection, 'SELECT 1');
      $Operation->state = OperationStates::Connecting;

      $Driver->advance($Operation);
      $drain($Peer);
      fwrite($Peer, 'S');
      $Driver->advance($Operation);

      // @@ Drive both ends of the handshake until the client sent its StartupMessage
      $server = 0;
      $deadline = microtime(true) + 3.0;
      while ($Operation->state !== OperationStates::Authenticating && $Operation->finished === false && microtime(true) < $deadline) {
         $Driver->advance($Operation);
         if ($server !== true) {
            $server = @stream_socket_enable_crypto($Peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
         }
         usleep(1_000);
      }
      $startup = $drain($Peer);
      fwrite($Peer, $R(5, $salt));
      $reply = '';
      $deadline = microtime(true) + 2.0;
      while ($reply === '' && microtime(true) < $deadline) {
         $Driver->advance($Operation);
         $chunk = fread($Peer, 8192);
         $reply = is_string($chunk) ? $chunk : '';
         usleep(1_000);
      }
      $TLS = [
         'reply' => $reply,
         'state' => $Operation->state->name,
         'error' => (string) $Operation->error,
         'quarantine' => $Operation->quarantine,
      ];

      if (is_resource($Peer)) {
         fclose($Peer);
      }
      fclose($Listener);
      $Connection->disconnect();

      // @ Leg 9 — the same driver, dialed again after its TLS session and
      //   downgraded: a finished TLS session never leaves `encrypted` behind
      $after = $exchange($remote, $R(3), $Driver);

      $evidence = json_encode($TLS);
      yield assert(
         assertion: $startup !== '' && $sent($TLS, $hash),
         description: "prefer + S (TLS) + MD5 on a remote host answers the hash over TLS, even on a driver whose earlier dials were downgraded — {$evidence}"
      );

      $evidence = json_encode($after);
      yield assert(
         assertion: $refused($after),
         description: "A TLS dial does not leave encrypted=true for the next, downgraded dial on the same driver — {$evidence}"
      );

      // @ Leg 8b — over TLS the iteration floor does not apply: SCRAM with i=1
      //   on a remote host proceeds to the client-final message
      $Listener = stream_socket_server(
         'tcp://127.0.0.1:0',
         $errorCode,
         $error,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         stream_context_create(['ssl' => ['local_cert' => "{$fixtures}/postgresql_tls.pem"]]),
      );
      $address = (string) stream_socket_get_name($Listener, false);
      $port = (int) substr($address, (int) strrpos($address, ':') + 1);
      $client = stream_socket_client(
         "tcp://127.0.0.1:{$port}",
         $errorCode,
         $error,
         2.0,
         context: stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]),
      );
      $Peer = stream_socket_accept($Listener, 2.0);
      stream_set_blocking($client, false);
      stream_set_blocking($Peer, false);
      $Connection = new Connection($remote);
      $Connection->attach($client);
      $Encrypted = new PostgreSQL($remote, $Connection);
      $Operation = new Operation($Connection, 'SELECT 1');
      $Operation->state = OperationStates::Connecting;
      $Encrypted->advance($Operation);
      $drain($Peer);
      fwrite($Peer, 'S');
      $Encrypted->advance($Operation);
      $server = 0;
      $deadline = microtime(true) + 3.0;
      while ($Operation->state !== OperationStates::Authenticating && $Operation->finished === false && microtime(true) < $deadline) {
         $Encrypted->advance($Operation);
         if ($server !== true) {
            $server = @stream_socket_enable_crypto($Peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
         }
         usleep(1_000);
      }
      $drain($Peer);
      fwrite($Peer, $R(10, "SCRAM-SHA-256\0\0"));
      $first = '';
      $deadline = microtime(true) + 2.0;
      while ($first === '' && microtime(true) < $deadline) {
         $Encrypted->advance($Operation);
         $chunk = fread($Peer, 8192);
         $first = is_string($chunk) ? $chunk : '';
         usleep(1_000);
      }
      $nonce = preg_match('/r=([^,\x00]+)/', $first, $matches) === 1 ? $matches[1] : '';
      $salt64 = base64_encode('bootgly-scram-salt');
      fwrite($Peer, $R(11, "r={$nonce}server,s={$salt64},i=1"));
      $reply = '';
      $deadline = microtime(true) + 2.0;
      while ($reply === '' && microtime(true) < $deadline) {
         $Encrypted->advance($Operation);
         $chunk = fread($Peer, 8192);
         $reply = is_string($chunk) ? $chunk : '';
         usleep(1_000);
      }
      $SCRAM = [
         'reply' => $reply,
         'state' => $Operation->state->name,
         'error' => (string) $Operation->error,
      ];
      if (is_resource($Peer)) {
         fclose($Peer);
      }
      fclose($Listener);
      $Connection->disconnect();

      $evidence = json_encode($SCRAM);
      yield assert(
         assertion: $SCRAM['error'] === '' && $SCRAM['reply'] !== '' && str_contains($SCRAM['reply'], 'c=biws,r='),
         description: "prefer + S (TLS) + SCRAM with i=1 on a remote host proceeds — the floor is for downgraded sessions only — {$evidence}"
      );
   }
);
