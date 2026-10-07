<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Logs\Data\Levels;
use Bootgly\ACI\Logs\Handlers\Memory;
use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Configs;


/**
 * Phase 2 item 3 (H2-CLIENT-OUT) — every inbound HTTP/2 PING and SETTINGS
 * frame forces the client to queue an acknowledgement of the same size. A
 * server that floods them and never reads made the client hold the whole
 * flood unsent until the process died. The answers a peer forces are now
 * budgeted per connection (`maxControlBytes`, 1 MiB by default): past it the
 * connection is closed and its request fails with code 0 `'Control Flood'`,
 * never retried. A peer that reads, and an upload the client decided to send,
 * are never charged. Over TLS the default config offers h2 by ALPN, so the
 * budget holds without any setting. The budget is the user's own value: a
 * small one trips even on a peer that reads, a large one absorbs a flood the
 * default would refuse.
 *
 * Each leg forks its own mock peer on a free port. A flooding mock never
 * reads (SO_RCVBUF 2048), floods at most 8 MiB and holds at most 2.5 s, so a
 * client without the budget fails here on an assertion, never by OOM or hang.
 */
return new Test(
   description: 'It should close an HTTP/2 connection whose peer forces control answers it never reads',
   test: function () {
      $Evidence = [
         'error' => '',
         'ping' => [],
         'settings' => [],
         'default' => [],
         'draining' => [],
         'upload' => [],
         'TLS' => [],
         'small' => [],
         'large' => [],
         'knob' => null,
      ];
      $directory = Temporaries::reserve('hcli-h2-control-flood');
      $files = [];
      $PIDs = [];
      $budget = 262_144;
      $flood = 8_388_608;

      /** One HTTP/2 frame (RFC 9113 §4.1). */
      $Frame = static fn (int $type, int $flags, int $stream, string $payload = ''): string
         => substr(pack('N', strlen($payload)), 1) . chr($type) . chr($flags) . pack('N', $stream) . $payload;

      /**
       * Fork one mock peer on a free loopback port; returns [PID, port, report file].
       *
       * Modes: `ping` / `settings` flood `$flood` bytes of that frame and never
       * read; `drain` floods 4 MiB of PINGs reading after every chunk, then
       * answers 200; `upload` grants a 2^31-1 window, pings 100 times before it
       * reads, hashes the request body and answers 200. Every mode counts the
       * connections it accepts until it is told to stop (SIGUSR1).
       */
      $Serve = static function (string $mode, null|string $certificate = null) use (
         $directory, $Frame, $flood, &$files, &$PIDs
      ): array {
         $starve = $mode === 'ping' || $mode === 'settings';
         $Context = stream_context_create($certificate === null ? [] : [
            'ssl' => ['local_cert' => $certificate, 'verify_peer' => false, 'alpn_protocols' => 'h2'],
         ]);
         $Listener = @stream_socket_server(
            'tcp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $Context
         );
         if ($Listener === false) {
            throw new RuntimeException("The {$mode} mock could not listen: {$message}");
         }
         // ! A peer that never reads keeps its receive window tiny
         if ($starve) {
            $Socket = socket_import_stream($Listener);
            if ($Socket === false || socket_set_option($Socket, SOL_SOCKET, SO_RCVBUF, 2048) === false) {
               throw new RuntimeException("The {$mode} mock could not shrink SO_RCVBUF");
            }
         }
         $name = (string) stream_socket_get_name($Listener, false);
         $port = (int) substr($name, strrpos($name, ':') + 1);
         $report = "{$directory}/{$mode}-{$port}.json";
         $files[] = $report;
         $parent = getmypid();

         // ! The stop signal is held until the child installed its handler
         pcntl_sigprocmask(SIG_BLOCK, [SIGUSR1], $mask);
         $PID = pcntl_fork();
         if ($PID !== 0) {
            pcntl_sigprocmask(SIG_SETMASK, $mask);
            // ? A failed fork must never hand back -1: reaping it signals every process we own
            if ($PID < 0) {
               throw new RuntimeException("The {$mode} mock could not fork");
            }
            fclose($Listener);
            $PIDs[] = $PID;

            return [$PID, $port, $report];
         }

         // # Mock peer (child)
         $stop = false;
         pcntl_async_signals(true);
         pcntl_signal(SIGUSR1, static function () use (&$stop): void {
            $stop = true;
         });
         pcntl_signal(SIGPIPE, SIG_IGN);
         pcntl_sigprocmask(SIG_UNBLOCK, [SIGUSR1]);

         $Report = [
            'port' => $port,
            'connections' => 0,
            'rcvbuf' => null,
            'ALPN' => null,
            'flooded' => 0,
            'closed' => false,
            'pings' => 0,
            'acks' => 0,
            'request' => false,
            'complete' => false,
            'body' => 0,
            'hash' => null,
            'responded' => false,
            'error' => null,
         ];
         $deadline = microtime(true) + ($starve ? 2.5 : 8.0);
         $Alive = static function () use (&$stop, $deadline, $parent): bool {
            return $stop === false && microtime(true) < $deadline && posix_getppid() === $parent;
         };
         $Peers = [];

         try {
            // @ The client's connection
            $Peer = false;
            while ($Peer === false && $Alive()) {
               $Peer = @stream_socket_accept($Listener, 0.1);
            }
            if ($Peer === false) {
               throw new RuntimeException('no connection');
            }
            $Report['connections']++;
            $Peers[] = $Peer;
            $Accepted = socket_import_stream($Peer);
            $Report['rcvbuf'] = $Accepted === false ? null : socket_get_option($Accepted, SOL_SOCKET, SO_RCVBUF);

            if ($certificate !== null) {
               stream_set_blocking($Peer, true);
               stream_set_timeout($Peer, 2);
               if (@stream_socket_enable_crypto($Peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                  throw new RuntimeException('TLS handshake failed');
               }
               $Report['ALPN'] = stream_get_meta_data($Peer)['crypto']['alpn_protocol'] ?? null;
            }
            stream_set_blocking($Peer, false);

            /** Write all of `$bytes` (bounded by the mock's life); false once the client is gone. */
            $Send = static function (string $bytes) use ($Peer, $Alive): bool {
               while ($bytes !== '') {
                  if ($Alive() === false) {
                     return false;
                  }
                  $Read = null;
                  $Write = [$Peer];
                  $Except = null;
                  if (@stream_select($Read, $Write, $Except, 0, 100_000) < 1) {
                     continue;
                  }
                  $written = @fwrite($Peer, $bytes);
                  if ($written === false || ($written === 0 && feof($Peer))) {
                     return false;
                  }
                  $bytes = substr($bytes, $written);
               }

               return true;
            };

            // ! Inbound frame parser (client preface, then frames)
            $inbox = '';
            $magic = false;
            $Hash = hash_init('sha256');
            $Pull = static function () use ($Peer, &$inbox, &$magic, &$Report, $Hash): void {
               while (($chunk = @fread($Peer, 262_144)) !== false && $chunk !== '') {
                  $inbox .= $chunk;
               }
               if ($magic === false) {
                  if (strlen($inbox) < 24) {
                     return;
                  }
                  $inbox = substr($inbox, 24);
                  $magic = true;
               }
               $offset = 0;
               $size = strlen($inbox);
               while ($size - $offset >= 9) {
                  $length = unpack('N', "\0" . substr($inbox, $offset, 3))[1];
                  if ($size - $offset < 9 + $length) {
                     break;
                  }
                  $type = ord($inbox[$offset + 3]);
                  $flags = ord($inbox[$offset + 4]);
                  $stream = unpack('N', substr($inbox, $offset + 5, 4))[1] & 0x7FFFFFFF;
                  if ($type === 6 && ($flags & 0x1) !== 0) {
                     $Report['acks']++;
                  }
                  else if ($type === 1 && $stream === 1) {
                     $Report['request'] = true;
                     $Report['complete'] = $Report['complete'] || ($flags & 0x1) !== 0;
                  }
                  else if ($type === 0 && $stream === 1) {
                     hash_update($Hash, substr($inbox, $offset + 9, $length));
                     $Report['body'] += $length;
                     $Report['complete'] = $Report['complete'] || ($flags & 0x1) !== 0;
                  }
                  $offset += 9 + $length;
               }
               $inbox = substr($inbox, $offset);
            };
            /** Wait for readable input (bounded), then parse it. */
            $Await = static function () use ($Peer, $Pull): void {
               $Read = [$Peer];
               $Write = null;
               $Except = null;
               if (@stream_select($Read, $Write, $Except, 0, 50_000) > 0) {
                  $Pull();
               }
            };
            $Answer = static function (string $body) use ($Send, $Frame, &$Report): void {
               // @ :status 200 (static table index 8), then the body
               $Report['responded'] = $Send(
                  $Frame(1, 0x4, 1, "\x88") . $Frame(0, 0x1, 1, $body)
               );
            };

            // @ Server preface
            if ($mode === 'upload') {
               $Send(
                  $Frame(4, 0, 0, pack('nN', 4, 2_147_483_647))
                  . $Frame(8, 0, 0, pack('N', 2_147_483_647 - 65_535))
               );
            }
            else {
               $Send($Frame(4, 0, 0));
            }

            switch ($mode) {
               case 'ping':
               case 'settings':
               case 'drain':
                  $unit = $mode === 'settings' ? $Frame(4, 0, 0) : $Frame(6, 0, 0, '12345678');
                  $chunk = str_repeat($unit, intdiv(65_536, strlen($unit)));
                  $target = $mode === 'drain' ? 4_194_304 : $flood;
                  // @@ The flood — a draining peer reads after every chunk
                  while ($Report['flooded'] < $target) {
                     if ($Send($chunk) === false) {
                        $Report['closed'] = $Alive();
                        break;
                     }
                     $Report['flooded'] += strlen($chunk);
                     if ($mode === 'drain') {
                        $Pull();
                     }
                  }
                  $Report['pings'] = $mode === 'settings' ? 0 : intdiv($Report['flooded'], strlen($unit));

                  if ($mode === 'drain') {
                     while ($Report['complete'] === false && $Alive()) {
                        $Await();
                     }
                     $Answer('drained');
                  }
                  break;

               case 'upload':
                  // @@ 100 PINGs while the upload backs up unread
                  for ($ping = 1; $ping <= 100; $ping++) {
                     $Send($Frame(6, 0, 0, pack('J', $ping)));
                     $Report['pings']++;
                     usleep(5_000);
                  }
                  while ($Report['complete'] === false && $Alive()) {
                     $Await();
                  }
                  $Report['hash'] = hash_final($Hash);
                  $Answer('uploaded');
                  break;
            }

            // @@ Hold until told to stop: keep reading if this peer reads,
            //    and count every connection a re-dial makes
            while ($Alive()) {
               $Extra = @stream_socket_accept($Listener, $starve ? 0.05 : 0);
               if ($Extra !== false) {
                  $Report['connections']++;
                  $Peers[] = $Extra;
               }
               if ($starve === false) {
                  $Await();
               }
            }
            while (($Extra = @stream_socket_accept($Listener, 0)) !== false) {
               $Report['connections']++;
               $Peers[] = $Extra;
            }
         }
         catch (Throwable $Throwable) {
            $Report['error'] = $Throwable->getMessage();
         }

         file_put_contents($report, json_encode($Report));
         // ! Never run the runner's shutdown path in this fork
         posix_kill(posix_getpid(), SIGKILL);
         exit(1);
      };

      /** Stop one mock (bounded) and read its report. */
      $Collect = static function (int $PID, string $report) use (&$PIDs): array {
         posix_kill($PID, SIGUSR1);
         $until = microtime(true) + 3.0;
         while (pcntl_waitpid($PID, $status, WNOHANG) === 0) {
            if (microtime(true) > $until) {
               posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status);
               break;
            }
            usleep(10_000);
         }
         $PIDs = array_values(array_filter($PIDs, static fn (int $child): bool => $child !== $PID));
         $data = @file_get_contents($report);
         $decoded = is_string($data) ? json_decode($data, true) : null;

         return is_array($decoded) ? $decoded : ['port' => null, 'error' => 'no report'];
      };

      /** One request through a fresh client against one mock; the leg's outcome. */
      $Run = static function (
         string $mode, Closure $Setup, null|array $secure = null, string $method = 'GET', null|string $body = null,
         null|string $certificate = null
      ) use ($Serve, $Collect): array {
         [$PID, $port, $report] = $Serve($mode, $certificate);
         $Client = null;
         $State = ['port' => $port, 'code' => null, 'status' => null, 'body' => null];

         try {
            $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
            // ! h2c needs the opt-in; TLS keeps the default config (h2 offered by ALPN)
            $Client->configure(new Configs(
               host: '127.0.0.1', port: $port, secure: $secure, enableHTTP2: $secure === null ? true : null
            ));
            $Client->timeout = 1.5;
            $Client->connectTimeout = 2;
            $Setup($Client);

            // ! The node's warnings, through the live tap (local handlers are muted
            //   under the agent runner, the tap never is)
            $Warnings = new Memory(Level: Levels::Warning);
            $Tap = Logger::$Tap;
            Logger::$Tap = $Warnings;
            $base = memory_get_usage();
            memory_reset_peak_usage();
            $started = microtime(true);
            try {
               $Response = $Client->request($method, '/', $body === null ? [] : ['Content-Type' => 'application/octet-stream'], $body);
            }
            finally {
               Logger::$Tap = $Tap;
            }
            $State['elapsed'] = round(microtime(true) - $started, 3);
            // @@ One control-flood warning per flooded connection, none otherwise
            $State['warnings'] = 0;
            foreach ($Warnings->Records as $Record) {
               if (str_starts_with($Record->message, 'HTTP/2 peer control flood (')) {
                  $State['warnings']++;
               }
            }
            $State['heap'] = memory_get_peak_usage() - $base;
            $State['code'] = $Response->code;
            $State['status'] = $Response->status;
            $State['body'] = substr((string) $Response->Body->raw, 0, 32);
            // ! What the client still holds after request() returned
            $State['open'] = count($Client->Connections->Connections);
            $State['pooled'] = count($Client->Pool->idle) + count($Client->Pool->busy);
            $State['sessions'] = count($Client->Sessions);
         }
         catch (Throwable $Throwable) {
            $State['error'] = $Throwable->getMessage();
         }
         finally {
            $Client?->abort();
            $State['mock'] = $Collect($PID, $report);
         }

         return $State;
      };

      /** The flood outcome every flooding leg must show. */
      $Flooded = static function (array $State, float $within): bool {
         return $State['code'] === 0
            && $State['status'] === 'Control Flood'
            && ($State['warnings'] ?? -1) === 1
            && ($State['elapsed'] ?? INF) < $within
            && ($State['mock']['connections'] ?? 0) === 1
            && ($State['open'] ?? -1) === 0
            && ($State['pooled'] ?? -1) === 0
            && ($State['sessions'] ?? -1) === 0;
      };
      /** The mock really flooded this leg's client (a dead mock fails loud). */
      $Reported = static function (array $State): bool {
         return ($State['mock']['port'] ?? null) === $State['port']
            && array_key_exists('error', $State['mock']) && $State['mock']['error'] === null
            && ($State['mock']['flooded'] ?? 0) > 0;
      };

      /** A leg setup with the user's own budget (retries on, so a re-dial would show). */
      $Budget = static function (int $bytes): Closure {
         return static function (HTTP_Client_CLI $Client) use ($bytes): void {
            $Client->maxRetries = 2;
            $Client->retryDelay = 0.05;
            // ? The knob does not exist without the fix: the leg then runs unbudgeted
            if (property_exists($Client, 'maxControlBytes')) {
               $Client->maxControlBytes = $bytes;
            }
         };
      };
      $Budgeted = $Budget($budget);

      try {
         // # (a) PING flood, a peer that never reads
         $Evidence['ping'] = $Run('ping', $Budgeted);
         // # (b) Empty-SETTINGS flood
         $Evidence['settings'] = $Run('settings', $Budgeted);
         // # (c) The default budget (1 MiB) is wired without any setting
         $Evidence['default'] = $Run('ping', static function (HTTP_Client_CLI $Client): void {
            $Client->maxRetries = 2;
            $Client->retryDelay = 0.05;
         });
         $Evidence['knob'] = property_exists(HTTP_Client_CLI::class, 'maxControlBytes')
            ? (new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST))->maxControlBytes
            : 'missing';
         // # (d) Control: a peer that reads its acknowledgements is never charged
         $Evidence['draining'] = $Run('drain', static function (HTTP_Client_CLI $Client): void {
         });
         // # (e) Control: an 8 MiB upload backed up behind a late reader is never charged
         $upload = random_bytes(8_388_608);
         $Evidence['upload'] = $Run('upload', static function (HTTP_Client_CLI $Client): void {
            $Client->timeout = 6;
         }, method: 'POST', body: $upload);
         $Evidence['upload']['expected'] = hash('sha256', $upload);
         unset($upload);

         // # (f) TLS with the default config: ALPN offers h2 with only `secure` set
         $Key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
         $CSR = openssl_csr_new(['commonName' => '127.0.0.1'], $Key, ['digest_alg' => 'sha256']);
         $Certificate = openssl_csr_sign($CSR, null, $Key, 1, ['digest_alg' => 'sha256']);
         openssl_x509_export($Certificate, $certificate);
         openssl_pkey_export($Key, $key);
         $PEM = "{$directory}/origin.pem";
         file_put_contents($PEM, "{$certificate}{$key}");
         $files[] = $PEM;
         $secure = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];
         $Evidence['TLS'] = $Run('ping', static function (HTTP_Client_CLI $Client): void {
         }, secure: $secure, certificate: $PEM);

         // # (g) The user's small budget trips even on a peer that reads
         //       (each read packs thousands of PINGs; the default lets it pass, see (d))
         $Evidence['small'] = $Run('drain', $Budget(4_096));
         // # (h) The user's large budget absorbs a flood the 1 MiB default refuses
         $Large = $Budget(67_108_864);
         $Evidence['large'] = $Run('ping', static function (HTTP_Client_CLI $Client) use ($Large): void {
            $Large($Client);
            $Client->maxRetries = 0;
            // ! The whole 8 MiB flood lands in well under 0.5 s
            $Client->timeout = 1.0;
         });
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable->getMessage();
      }
      finally {
         foreach ($PIDs as $PID) {
            posix_kill($PID, SIGKILL);
            pcntl_waitpid($PID, $status);
         }
         foreach ($files as $file) {
            @unlink($file);
         }
         @rmdir($directory);
      }

      yield assert(
         assertion: $Evidence['error'] === ''
            && $Reported($Evidence['ping'])
            && $Reported($Evidence['settings'])
            && $Reported($Evidence['default'])
            && $Reported($Evidence['draining'])
            && ($Evidence['upload']['mock']['port'] ?? null) === $Evidence['upload']['port']
            && ($Evidence['upload']['mock']['pings'] ?? 0) === 100
            && $Reported($Evidence['TLS'])
            && ($Evidence['TLS']['mock']['ALPN'] ?? null) === 'h2'
            && $Reported($Evidence['small'])
            && $Reported($Evidence['large']),
         description: 'Every mock peer ran and reported (port, flood, h2 negotiated over TLS): ' . json_encode($Evidence)
      );

      yield assert(
         assertion: $Flooded($Evidence['ping'], 1.0) && $Evidence['ping']['heap'] < 4_194_304,
         description: 'A PING flood past maxControlBytes fails with code 0 Control Flood within 1 s, on one '
            . 'connection, never retried, nothing left open, heap bounded: ' . json_encode($Evidence['ping'])
      );

      yield assert(
         assertion: $Flooded($Evidence['settings'], 1.0) && $Evidence['settings']['heap'] < 4_194_304,
         description: 'An empty-SETTINGS flood fails the same way: ' . json_encode($Evidence['settings'])
      );

      yield assert(
         assertion: $Evidence['knob'] === 1_048_576 && $Flooded($Evidence['default'], 1.5),
         description: 'The default budget is 1 MiB and is handed to every connection: '
            . json_encode([$Evidence['knob'], $Evidence['default']])
      );

      yield assert(
         assertion: $Evidence['draining']['code'] === 200
            && $Evidence['draining']['body'] === 'drained'
            && ($Evidence['draining']['mock']['flooded'] ?? 0) >= 4_194_304
            && ($Evidence['draining']['mock']['acks'] ?? 0) > 0
            && ($Evidence['draining']['mock']['connections'] ?? 0) === 1
            && ($Evidence['draining']['warnings'] ?? -1) === 0,
         description: 'Control — 4 MiB of PINGs to a peer that reads its acknowledgements never trips: '
            . json_encode($Evidence['draining'])
      );

      yield assert(
         assertion: $Evidence['upload']['code'] === 200
            && $Evidence['upload']['body'] === 'uploaded'
            && ($Evidence['upload']['mock']['body'] ?? 0) === 8_388_608
            && ($Evidence['upload']['mock']['hash'] ?? null) === $Evidence['upload']['expected']
            && ($Evidence['upload']['mock']['connections'] ?? 0) === 1
            && ($Evidence['upload']['warnings'] ?? -1) === 0,
         description: 'Control — an 8 MiB upload to a late reader that pings meanwhile is never charged: '
            . json_encode($Evidence['upload'])
      );

      yield assert(
         assertion: $Flooded($Evidence['TLS'], 1.5),
         description: 'Over TLS with the default config (h2 by ALPN) a PING flood fails with Control Flood: '
            . json_encode($Evidence['TLS'])
      );

      yield assert(
         assertion: $Flooded($Evidence['small'], 1.0),
         description: 'A small maxControlBytes (4 KiB) is honoured: a reading peer that pings in bulk trips '
            . 'Control Flood within 1 s, on one connection: ' . json_encode($Evidence['small'])
      );

      yield assert(
         assertion: $Evidence['large']['code'] === 0
            && $Evidence['large']['status'] === 'Timeout'
            && ($Evidence['large']['mock']['flooded'] ?? 0) >= 4_194_304
            && ($Evidence['large']['mock']['connections'] ?? 0) === 1,
         description: 'A large maxControlBytes (64 MiB) is honoured: a flood past 4 MiB, four times the default, '
            . 'never trips and the request times out instead: ' . json_encode($Evidence['large'])
      );
   }
);
