<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;


/**
 * A scripted database peer for specs that block in `SQL::await()`.
 *
 * `SQL::transact()` waits inside the pool, so nothing in the spec's own
 * process can answer it: the peer is a forked child on the other end of a
 * socket pair. It speaks just enough of the PostgreSQL simple-query protocol
 * or of the MySQL text protocol to answer every statement with a success —
 * or with a server error when the statement starts with one of `$errors` —
 * and it records every statement in the order the wire carried it.
 *
 * Returns `[$open, $close, $supported]`:
 * - `$open(string $driver, array $errors = [], array $config = []): array` — a
 *   facade attached to a fresh peer: `[$Database, $Peer]`; `$config` is merged
 *   over the facade's (replicas, routing). The facade is a
 *   double whose `await()` throws, leaving the operation in flight, when the
 *   statement equals `$Database->trap` — the shape of a wait that throws.
 * - `$close(array $Peer): array` — the statements the peer received, in
 *   order; ends the peer.
 * - `$supported` — whether this platform can fork the peer.
 */

$supported = function_exists('pcntl_fork') && function_exists('posix_kill');

$serve = static function ($server, $control, string $driver, array $errors): void {
   $buffer = '';
   $statements = [];

   // @ PostgreSQL: CommandComplete + ReadyForQuery, or ErrorResponse + ReadyForQuery
   $complete = static function (string $SQL): string {
      $tag = match (true) {
         str_starts_with($SQL, 'INSERT') => 'INSERT 0 1',
         str_starts_with($SQL, 'ROLLBACK TO') => 'ROLLBACK',
         str_starts_with($SQL, 'RELEASE') => 'RELEASE',
         default => (string) strtok($SQL, ' ')
      };
      $tag = "{$tag}\0";

      return 'C' . pack('N', strlen($tag) + 4) . $tag . 'Z' . pack('N', 5) . 'I';
   };
   $refuse = static function (string $message): string {
      $fields = "SERROR\0C40001\0M{$message}\0\0";

      return 'E' . pack('N', strlen($fields) + 4) . $fields . 'Z' . pack('N', 5) . 'I';
   };
   // @ MySQL: OK packet, or ERR packet
   $frame = static fn (string $payload): string => substr(pack('V', strlen($payload)), 0, 3) . "\x01{$payload}";
   $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);

   $answer = static function (string $SQL) use ($driver, $errors, $complete, $refuse, $frame, $ok): string {
      foreach ($errors as $prefix => $message) {
         if (str_starts_with($SQL, (string) $prefix)) {
            return $driver === 'pgsql'
               ? $refuse($message)
               : $frame("\xff" . pack('v', 1213) . "#40001{$message}");
         }
      }

      return $driver === 'pgsql' ? $complete($SQL) : $frame($ok);
   };

   // @ Parse whole statements out of the buffer and answer each one
   $parse = static function () use (&$buffer, &$statements, $driver, $server, $answer): void {
      while (true) {
         if ($driver === 'pgsql') {
            if (strlen($buffer) < 5) {
               return;
            }

            /** @var array{1:int} $header */
            $header = unpack('N', substr($buffer, 1, 4));
            $length = $header[1] + 1;

            if (strlen($buffer) < $length) {
               return;
            }

            $type = $buffer[0];
            $SQL = rtrim(substr($buffer, 5, $length - 5), "\0");
            $buffer = substr($buffer, $length);

            if ($type !== 'Q') {
               continue;
            }
         }
         else {
            if (strlen($buffer) < 4) {
               return;
            }

            /** @var array{1:int} $header */
            $header = unpack('V', substr($buffer, 0, 3) . "\0");
            $length = $header[1] + 4;

            if (strlen($buffer) < $length) {
               return;
            }

            $type = $buffer[4];
            $SQL = substr($buffer, 5, $length - 5);
            $buffer = substr($buffer, $length);

            if ($type !== "\x03") {
               continue;
            }
         }

         $statements[] = $SQL;
         @fwrite($server, $answer($SQL));
      }
   };

   // @@ Serve until the spec asks for the transcript
   while (true) {
      $read = [$server, $control];
      $write = [];
      $except = [];

      if (@stream_select($read, $write, $except, 1) === false) {
         continue;
      }

      if (in_array($server, $read, true)) {
         $buffer .= (string) fread($server, 65536);
         $parse();
      }

      if (in_array($control, $read, true)) {
         // ! What the facade wrote before it asked is already in the socket.
         stream_set_blocking($server, false);
         $buffer .= (string) fread($server, 65536);
         $parse();

         fwrite($control, json_encode($statements) . "\n");

         return;
      }
   }
};

$open = static function (string $driver, array $errors = [], array $config = []) use ($serve): array {
   $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
   $channel = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

   if ($pair === false || $channel === false) {
      throw new RuntimeException('The scripted peer could not open its sockets.');
   }

   [$client, $server] = $pair;
   [$control, $remote] = $channel;

   $PID = pcntl_fork();

   if ($PID === -1) {
      throw new RuntimeException('The scripted peer could not be forked.');
   }

   if ($PID === 0) {
      // ! The child never returns into the test runner: it ends without the
      //   runner's shutdown path, which would report the suite a second time.
      try {
         fclose($client);
         fclose($control);

         $serve($server, $remote, $driver, $errors);
      }
      finally {
         posix_kill((int) getmypid(), SIGKILL);
      }
   }

   fclose($server);
   fclose($remote);
   stream_set_blocking($client, false);

   $Database = new class ([
      'driver' => $driver,
      'secure' => ['mode' => 'disable'],
      'timeout' => 5.0,
      'pool' => ['min' => 0, 'max' => 1],
      ...$config,
   ]) extends SQL {
      // * Data
      public string $trap = '';

      public function await (Operation $Operation): Operation
      {
         // ? The wait itself throws: the statement is on the wire, unfinished.
         if ($this->trap !== '' && $Operation->SQL === $this->trap && $Operation->finished === false) {
            $this->advance($Operation);

            throw new RuntimeException('Database operation readiness wait failed.');
         }

         return parent::await($Operation);
      }
   };
   $Database->Connection->attach($client);

   return [$Database, [$control, $PID]];
};

$close = static function (array $Peer): array {
   [$control, $PID] = $Peer;

   fwrite($control, '?');
   stream_set_timeout($control, 5);
   $line = fgets($control);
   fclose($control);

   pcntl_waitpid($PID, $status);

   /** @var array<int,string> $statements */
   $statements = $line === false ? [] : (array) json_decode($line, true);

   return $statements;
};

return [$open, $close, $supported];
