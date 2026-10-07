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
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


/**
 * Phase 2 item 3 — the pre-101 response head is capped at the HTTP client's
 * response head cap (65536 bytes): a peer that never ends the head, or ends
 * it past the cap, is refused at once as a handshake reject (closing, no
 * re-dial, one "Handshake refused" warning) instead of growing the carry
 * until EOF. The cap counts the head, never the frames coalesced after a
 * valid 101.
 */
return new Test(
   description: 'Phase 2 item 3: WS client refuses a pre-101 response head past 65536 bytes',

   test: function () {
      // ! 65536 = HTTP_Client_CLI response Decoder_::MAX_HEADER_BYTES (pinned by value)
      $cap = 65536;
      $evidence = [
         'error' => '',
         'flood' => null,
         'exact' => null,
         'separated' => null,
         'tail' => null,
         'boundary' => null,
         'split' => null,
         'over' => null,
         'cookie' => null,
         'reconnect' => null,
         'control' => null,
      ];
      /** @var array<int,array{int,resource}> $mocks */
      $mocks = [];
      /** @var array<int,true> $reaped Mock PIDs already reaped: never signalled again (the PID may be reused) */
      $reaped = [];

      /**
       * Fork a mock WS server on 127.0.0.1:0 that serves every connection it
       * accepts (for 6 s) with `$mode`, and reports `port`, `accept`, `head`
       * and `sent` lines through a socket pair.
       *
       * - flood: `101` + `X-Junk: ` + `'A'` up to `$bytes` wire bytes, no CRLFCRLF
       * - legit: a valid 101 head of `$bytes` bytes (0 = minimal) plus a `hi` text frame
       */
      $Serve = static function (string $mode, int $bytes, float $hold, int $split = 0) use (&$mocks): array {
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($pair === false) {
            throw new RuntimeException('could not create the report channel');
         }

         $PID = pcntl_fork();
         // ? Fork failed
         if ($PID === -1) {
            throw new RuntimeException('could not fork the mock server');
         }
         // @ Child: the mock server (it never unwinds into the runner copy)
         if ($PID === 0) {
            try {
               fclose($pair[0]);
               $Report = $pair[1];
               $Server = @stream_socket_server('tcp://127.0.0.1:0', $code, $message);
               $address = $Server !== false ? stream_socket_get_name($Server, false) : false;
               if ($Server === false || $address === false) {
                  throw new RuntimeException('the mock server could not listen');
               }
               $port = (int) substr($address, strrpos($address, ':') + 1);
               fwrite($Report, "port {$port}\n");

               $accepted = 0;
               $until = microtime(true) + 6.0;
               // @@ Serve every dial (a re-dial is counted, never refused)
               while (($left = $until - microtime(true)) > 0) {
                  $Peer = @stream_socket_accept($Server, $left);
                  if ($Peer === false) {
                     continue;
                  }
                  $accepted++;
                  fwrite($Report, "accept {$accepted}\n");
                  stream_set_blocking($Peer, false);

                  // @ Read the upgrade GET head
                  $request = '';
                  $deadline = microtime(true) + 2.0;
                  while (strpos($request, "\r\n\r\n") === false && microtime(true) < $deadline) {
                     $read = [$Peer];
                     $write = $except = null;
                     if (@stream_select($read, $write, $except, 0, 50_000) > 0) {
                        $chunk = @fread($Peer, 8192);
                        if ($chunk === false || $chunk === '') {
                           break;
                        }
                        $request .= $chunk;
                     }
                  }
                  preg_match('/Sec-WebSocket-Key: (\S+)/i', $request, $matches);
                  $key = $matches[1] ?? '';
                  $accept = base64_encode(sha1("{$key}258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));

                  // @ Build the wire
                  if ($mode === 'flood') {
                     $prefix = "HTTP/1.1 101 Switching Protocols\r\nX-Junk: ";
                     $junk = str_repeat('A', $bytes - strlen($prefix));
                     $wire = "{$prefix}{$junk}";
                     $head = strlen($wire);
                  }
                  else {
                     $head = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n";
                     if ($bytes > 0) {
                        $pad = $bytes - strlen($head) - 18;
                        $value = str_repeat('v', max(0, $pad));
                        $head .= "Set-Cookie: c={$value}\r\n";
                     }
                     $head .= "\r\n";
                     $wire = "{$head}\x81\x02hi";
                     $head = strlen($head);
                  }
                  fwrite($Report, "head {$head}\n");

                  // @ Send it (optionally split), bounded by a deadline
                  $sent = 0;
                  $total = strlen($wire);
                  $deadline = microtime(true) + 3.0;
                  while ($sent < $total && microtime(true) < $deadline) {
                     if ($split > 0 && $sent === $split) {
                        usleep(300_000);
                     }
                     $read = $except = null;
                     $write = [$Peer];
                     if (@stream_select($read, $write, $except, 0, 50_000) < 1) {
                        continue;
                     }
                     // ! A flood goes out in 64 KiB chunks; a head goes out whole (or up to the split)
                     $limit = match (true) {
                        $split > 0 && $sent < $split => $split - $sent,
                        $mode === 'flood' => 65536,
                        default => $total - $sent,
                     };
                     $written = @fwrite($Peer, substr($wire, $sent, $limit));
                     if ($written === false || $written === 0) {
                        break;
                     }
                     $sent += $written;
                  }
                  fwrite($Report, "sent {$sent}\n");

                  // @ Hold: discard until the client hangs up or the hold ends
                  $deadline = microtime(true) + $hold;
                  while (microtime(true) < $deadline) {
                     $read = [$Peer];
                     $write = $except = null;
                     if (@stream_select($read, $write, $except, 0, 50_000) > 0) {
                        $chunk = @fread($Peer, 65536);
                        if ($chunk === false || $chunk === '') {
                           break;
                        }
                     }
                  }
                  @fclose($Peer);
               }
            }
            finally {
               // @ Die without running the parent's shutdown hooks
               posix_kill(posix_getpid(), SIGKILL);
            }
         }

         // @ Parent: wait for the port
         fclose($pair[1]);
         $mocks[] = [$PID, $pair[0]];
         stream_set_timeout($pair[0], 2);
         $line = fgets($pair[0]);
         $port = is_string($line) && str_starts_with($line, 'port ')
            ? (int) substr($line, 5)
            : 0;

         // :
         return [$PID, $pair[0], $port];
      };

      /**
       * Dial a mock with a real client (blocking connect()) and record what
       * the client saw and logged, plus what the mock reported.
       */
      $Dial = static function (array $mock, bool $reconnect = false) use (&$reaped): array {
         [$PID, $Channel, $port] = $mock;
         $leg = [
            'port' => $port,
            'connected' => false,
            'disconnects' => 0,
            'closing' => null,
            'carry' => null,
            'at' => null,
            'elapsed' => null,
            'messages' => [],
            'warnings' => [],
            'accepted' => 0,
            'head' => null,
         ];
         // ? A dead mock fails loud
         if ($port <= 0) {
            return $leg;
         }

         $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
         $Client->configure(new WS_Client_CLI\Configs(
            host: '127.0.0.1',
            port: $port,
            compression: false,
            reconnect: $reconnect,
            reconnectAttempts: 1,
            reconnectDelay: 1,
            reconnectTimeout: 5,
            handshakeTimeout: 0
         ));
         // ! Capture the node's warnings (a handshake refusal is logged once) through the live
         //   tap: local handlers are muted under the agent runner (Display::NONE), the tap never is
         $Warnings = new Memory(Level: Levels::Warning);
         $Tap = Logger::$Tap;
         $started = hrtime(true);
         $Client->on(Events::Connected, static function ($Session) use (&$leg) {
            $leg['connected'] = true;
         });
         $Client->on(Events::MessageReceived, static function ($Session, $Message) use (&$leg) {
            $leg['messages'][] = $Message->payload;
            $Session->close(1000);
         });
         $Client->on(Events::Disconnected, static function ($Session) use (&$leg, $started) {
            $leg['disconnects']++;
            $leg['closing'] ??= $Session->closing;
            $leg['carry'] ??= strlen($Session->carry);
            $leg['at'] ??= round((hrtime(true) - $started) / 1e9, 3);
         });

         // ! Runner guard: a client that never hangs up cannot pin the suite
         $guard = $Client->Event->defer(
            (int) hrtime(true) + 8_000_000_000,
            static function () use ($Client): void {
               $Client->Event->destroy();
            }
         );
         Logger::$Tap = $Warnings;
         try {
            $Client->connect('/');
         }
         finally {
            Logger::$Tap = $Tap;
         }
         $leg['elapsed'] = round((hrtime(true) - $started) / 1e9, 3);
         $Client->Event->cancel($guard);
         $Client->Session?->Connection->close();
         $Client->reset();
         // @@ Collect the warnings this node logged
         foreach ($Warnings->Records as $Record) {
            if ($Record->channel === 'WS.Client.CLI') {
               $leg['warnings'][] = $Record->message;
            }
         }

         // @ Reap the mock once (it is never signalled again), then read everything it reported
         posix_kill($PID, SIGKILL);
         pcntl_waitpid($PID, $status);
         $reaped[$PID] = true;
         stream_set_blocking($Channel, false);
         $report = (string) stream_get_contents($Channel);
         foreach (explode("\n", $report) as $line) {
            if (str_starts_with($line, 'accept ')) {
               $leg['accepted'] = (int) substr($line, 7);
            }
            else if (str_starts_with($line, 'head ')) {
               $leg['head'] ??= (int) substr($line, 5);
            }
         }

         // :
         return $leg;
      };

      try {
         // # (a) An endless head (1 MiB, no CRLFCRLF) with no handshake timeout
         $evidence['flood'] = $Dial($Serve('flood', 1_048_576 + 42, 3.0));
         // # (b) Exactly the cap, no separator: refused at >=, not at the hold's EOF
         $evidence['exact'] = $Dial($Serve('flood', $cap, 3.0));
         // # (c) A 100 KiB head WITH the separator: one write, then split at 65535 so the
         //   separator arrives in the read that crosses the cap (never the unterminated branch).
         //   This leg and (e) reach the separator-present check only because the client reads
         //   in 65535-byte chunks (TCP_Client_CLI Packages fread): smaller reads would let the
         //   separator-absent check refuse first (still refused, so never a false failure).
         $evidence['separated'] = $Dial($Serve('legit', 102_400, 2.0));
         $evidence['tail'] = $Dial($Serve('legit', 102_400, 2.0, $cap - 1));
         // # (d) A head of exactly the cap plus a coalesced frame: one write, then split at 65535
         $evidence['boundary'] = $Dial($Serve('legit', $cap, 2.0));
         $evidence['split'] = $Dial($Serve('legit', $cap, 2.0, $cap - 1));
         // # (e) One byte past the cap
         $evidence['over'] = $Dial($Serve('legit', $cap + 1, 2.0));
         // # (f) A legit long Set-Cookie head
         $evidence['cookie'] = $Dial($Serve('legit', 20_145, 2.0));
         // # (g) A reject never re-dials, even with reconnect on
         $evidence['reconnect'] = $Dial($Serve('flood', 1_048_576 + 42, 1.0), reconnect: true);
         // # (h) The minimal 101 control
         $evidence['control'] = $Dial($Serve('legit', 0, 2.0));
      }
      catch (Throwable $Throwable) {
         $class = $Throwable::class;
         $evidence['error'] = "{$class}: {$Throwable->getMessage()}";
      }
      finally {
         // @@ Reap only the mocks $Dial did not reap; close every report channel
         foreach ($mocks as [$PID, $Channel]) {
            if (isSet($reaped[$PID]) === false) {
               posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status);
               $reaped[$PID] = true;
            }
            if (is_resource($Channel)) {
               fclose($Channel);
            }
         }
      }

      /** A leg the mock served once and the client refused (logging one warning) before the hold ended. */
      $Refused = static fn (null|array $leg, float $within = 0.5): bool => $leg !== null
         && $leg['port'] > 0
         && $leg['accepted'] === 1
         && $leg['connected'] === false
         && $leg['disconnects'] === 1
         && $leg['closing'] === true
         && $leg['carry'] === 0
         && $leg['at'] !== null && $leg['at'] < $within
         && $leg['elapsed'] < $within
         && count($leg['warnings']) === 1
         && str_starts_with($leg['warnings'][0], 'Handshake refused: handshake response head too large');
      /** A leg the mock served once and the client connected, read `hi` and logged no warning. */
      $Connected = static fn (null|array $leg, int $head): bool => $leg !== null
         && $leg['port'] > 0
         && $leg['accepted'] === 1
         && $leg['head'] === $head
         && $leg['connected'] === true
         && $leg['messages'] === ['hi']
         && $leg['warnings'] === []
         && $leg['disconnects'] === 1
         && $leg['elapsed'] < 1.5;

      // :
      yield assert(
         assertion: $evidence['error'] === '',
         description: 'the mocks forked and reported: ' . json_encode($evidence)
      );
      yield assert(
         assertion: $Refused($evidence['flood']) && $evidence['flood']['head'] === 1_048_618,
         description: '(a) an unterminated 1 MiB head is refused at the cap as a reject (closing, empty carry, one warning), not at EOF: '
            . json_encode($evidence['flood'])
      );
      yield assert(
         assertion: $Refused($evidence['exact']) && $evidence['exact']['head'] === $cap,
         description: '(b) exactly 65536 unterminated bytes are refused (>=), without waiting for the hold: '
            . json_encode($evidence['exact'])
      );
      yield assert(
         assertion: $Refused($evidence['separated']) && $evidence['separated']['head'] === 102_400
            && $Refused($evidence['tail'], 0.8) && $evidence['tail']['head'] === 102_400,
         description: '(c) a terminated 100 KiB head is refused, whole or with the separator in the read past the cap: '
            . json_encode([$evidence['separated'], $evidence['tail']])
      );
      yield assert(
         assertion: $Connected($evidence['boundary'], $cap) && $Connected($evidence['split'], $cap),
         description: '(d) a 65536-byte head plus a coalesced frame connects (cap the head, not the buffer), whole or split at 65535: '
            . json_encode([$evidence['boundary'], $evidence['split']])
      );
      yield assert(
         assertion: $Refused($evidence['over']) && $evidence['over']['head'] === $cap + 1,
         description: '(e) a 65537-byte head is refused: ' . json_encode($evidence['over'])
      );
      yield assert(
         assertion: $Connected($evidence['cookie'], 20_145),
         description: '(f) a legit 20,145-byte Set-Cookie head connects: ' . json_encode($evidence['cookie'])
      );
      yield assert(
         assertion: $Refused($evidence['reconnect']),
         description: '(g) a refused head never re-dials under reconnect (the mock counts 1 connection): '
            . json_encode($evidence['reconnect'])
      );
      yield assert(
         assertion: $Connected($evidence['control'], 129),
         description: '(h) the minimal 129-byte 101 connects: ' . json_encode($evidence['control'])
      );
   }
);
