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
use function chr;
use function extension_loaded;
use function fclose;
use function fread;
use function function_exists;
use function fwrite;
use function getrusage;
use function is_array;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function pack;
use function pcntl_fork;
use function pcntl_waitpid;
use function round;
use function str_repeat;
use function stream_context_create;
use function stream_get_contents;
use function stream_select;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function usleep;
use Throwable;

use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Authentication;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Encoder;


/**
 * A pending TLS handshake parks on READ, never on WRITE.
 *
 * Once the ClientHello is queued, progress needs the peer's ServerHello. WRITE
 * readiness is permanently true on a connected socket, so a driver that waited
 * on it re-entered advance() as fast as the loop could turn: `Pool::wait()`
 * spun a core — measured at 90 % — for as long as the peer took to answer.
 * READ wakes when its bytes land. This withholds the ServerHello for a while
 * and reads two things: the readiness the driver armed, and the CPU the
 * client burned while it waited.
 */
$supported = extension_loaded('openssl')
   && function_exists('pcntl_fork')
   && function_exists('stream_socket_enable_crypto');
// ! Seconds the fixture withholds the ServerHello for
$delay = 1.2;

// ! A MySQL greeting that advertises SSL, so the client asks for it
$greet = static function (): string {
   $capabilities = Capabilities::PROTOCOL_41
      | Capabilities::SECURE_CONNECTION
      | Capabilities::PLUGIN_AUTH
      | Capabilities::PLUGIN_AUTH_LENENC
      | Capabilities::SSL;

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

$drive = static function (string $driver) use ($delay, $greet): array {
   $Result = [
      'error' => '',
      'flag' => null,
      'state' => '',
      'wall' => 0.0,
      'cpu' => 0.0,
      'peer' => null,
      'client_error' => '',
   ];
   $Context = stream_context_create(['ssl' => [
      'local_cert' => __DIR__ . '/fixtures/postgresql_tls.pem',
      'verify_peer' => false,
      'allow_self_signed' => true,
   ]]);
   $Listener = stream_socket_server(
      'tcp://127.0.0.1:0',
      $errorCode,
      $error,
      STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
      $Context,
   );
   $Channel = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
   if (is_resource($Listener) === false || $Channel === false) {
      $Result['error'] = 'fixture could not create listener/channel';

      return $Result;
   }
   $address = stream_socket_get_name($Listener, false);
   $separator = is_string($address) ? strrpos($address, ':') : false;
   $port = $separator === false ? 0 : (int) substr($address, $separator + 1);

   $PID = pcntl_fork();
   if ($PID === -1 || $port < 1) {
      fclose($Channel[0]);
      fclose($Channel[1]);
      fclose($Listener);
      $Result['error'] = 'fixture could not fork/resolve listener';

      return $Result;
   }

   if ($PID === 0) {
      fclose($Channel[0]);
      $report = ['accepted' => false, 'request' => '', 'handshake' => false];
      $Peer = @stream_socket_accept($Listener, 5.0);
      fclose($Listener);

      if (is_resource($Peer)) {
         $report['accepted'] = true;
         stream_set_blocking($Peer, true);
         stream_set_timeout($Peer, 5);

         // @ The protocol's own TLS request, read to the byte — the ClientHello
         //   that follows it must stay in the socket for the handshake below.
         if ($driver === 'mysql') {
            @fwrite($Peer, (new Encoder)->frame($greet(), 0));
            $expected = 36;
         }
         else {
            $expected = 8;
         }
         $request = '';
         while (strlen($request) < $expected) {
            $chunk = @fread($Peer, $expected - strlen($request));
            if ($chunk === false || $chunk === '') {
               break;
            }
            $request .= $chunk;
         }
         $report['request'] = strlen($request) === $expected ? 'whole' : 'short';

         if ($driver === 'pgsql') {
            @fwrite($Peer, 'S');
         }

         // @ Withhold the ServerHello: the client's ClientHello is queued and
         //   its driver has nothing to do but wait for these bytes.
         usleep((int) ($delay * 1_000_000));
         $report['handshake'] = @stream_socket_enable_crypto($Peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;

         // @ Let the client's first encrypted bytes land, then hang up
         stream_set_timeout($Peer, 1);
         @fread($Peer, 8192);
         fclose($Peer);
      }

      @fwrite($Channel[1], (string) json_encode($report));
      fclose($Channel[1]);

      exit(0);
   }

   fclose($Channel[1]);
   fclose($Listener);

   $SQL = new SQL([
      'driver' => $driver,
      'host' => '127.0.0.1',
      'port' => $port,
      'database' => 'bootgly_tls_test',
      'username' => 'bootgly_tls_test',
      'password' => 'secret',
      'timeout' => 5.0,
      'secure' => [
         'mode' => 'require',
         'verify' => false,
         'name' => false,
      ],
      'pool' => ['max' => 1],
   ]);
   $Operation = $SQL->query('SELECT 1');

   // @ Reach the handshake by hand — one advance() past the state's arrival,
   //   so encrypt() has run and the driver has armed the wait it lives on
   $limit = microtime(true) + 2.0;
   while ($Operation->finished === false && $Operation->state !== OperationStates::SSLHandshake && microtime(true) < $limit) {
      $SQL->advance($Operation);
      $Readiness = $Operation->Readiness;
      if ($Readiness instanceof Readiness === false || is_resource($Readiness->socket) === false) {
         continue;
      }

      $read = [];
      $write = [];
      $except = [];
      if ($Readiness->flag === Scheduler::SCHEDULE_READ) {
         $read[] = $Readiness->socket;
      }
      else {
         $write[] = $Readiness->socket;
      }
      @stream_select($read, $write, $except, 0, 50_000);
   }
   if ($Operation->finished === false) {
      $SQL->advance($Operation);
   }
   $Readiness = $Operation->Readiness;
   $Result['flag'] = $Readiness instanceof Readiness ? $Readiness->flag : null;
   $Result['state'] = $Operation->state->value;

   // @ The synchronous wait, measured: wall against CPU
   $usage = getrusage();
   $before = is_array($usage)
      ? ($usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000)
      : 0.0;
   $start = microtime(true);
   try {
      if ($Operation->finished === false) {
         $SQL->await($Operation);
      }
   }
   catch (Throwable $Throwable) {
      $Result['client_error'] = $Throwable->getMessage();
   }
   finally {
      $SQL->Connection->disconnect();
   }
   $usage = getrusage();
   $after = is_array($usage)
      ? ($usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1_000_000 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1_000_000)
      : 0.0;
   $Result['wall'] = round(microtime(true) - $start, 3);
   $Result['cpu'] = round($after - $before, 3);

   stream_set_timeout($Channel[0], 8);
   $raw = stream_get_contents($Channel[0]);
   fclose($Channel[0]);
   pcntl_waitpid($PID, $status);
   $Result['peer'] = is_string($raw) ? json_decode($raw, true) : null;

   return $Result;
};

return new Test(
   description: 'SQL drivers: a pending TLS handshake parks on READ and the synchronous wait burns no CPU while the peer withholds the ServerHello',
   skip: $supported === false,
   test: function () use ($drive, $delay) {
      foreach (['pgsql', 'mysql'] as $driver) {
         $Result = $drive($driver);
         $peer = is_array($Result['peer']) ? $Result['peer'] : [];

         yield assert(
            assertion: $Result['error'] === ''
               && $Result['state'] === OperationStates::SSLHandshake->value
               && $Result['flag'] === Scheduler::SCHEDULE_READ,
            description: "{$driver}: once the ClientHello is queued the driver parks on READ, never on WRITE — "
               . json_encode($Result)
         );
         yield assert(
            assertion: $Result['error'] === ''
               && ($peer['request'] ?? '') === 'whole'
               && ($peer['handshake'] ?? false) === true
               && $Result['wall'] >= $delay * 0.9
               && $Result['cpu'] < $Result['wall'] * 0.2,
            description: "{$driver}: the withheld ServerHello still completes the handshake, and the {$delay}s wait costs the client next to no CPU — "
               . json_encode($Result)
         );
      }
   }
);
