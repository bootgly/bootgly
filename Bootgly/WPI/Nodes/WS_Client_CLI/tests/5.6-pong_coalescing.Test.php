<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message;
use Bootgly\WPI\Nodes\WS_Client_CLI\Session;


/**
 * Phase 2 item 3 — a server that pings without reading must not grow the
 * client's output queue. While output is backpressured a PING is parked (only
 * the latest payload is kept) and answered once — ahead of the next frame the
 * client queues (a send or its close frame), or when the queue drains — so
 * steady sending never starves it and nothing follows the close frame; a PING
 * on an idle queue is answered at once.
 *
 * The peer is a forked mock (ext-sockets, SO_RCVBUF 2048) driven in lock-step
 * with the real client through a socket pair: the mock sends TEXT commands,
 * the client answers on the pair with one-byte signals.
 */
return new Test(
   description: 'It should coalesce PONG answers while output is backpressured and keep close ordering',
   test: function () {
      // ! Workload — 8 MiB of 125-byte PINGs, then 2 MiB of empty PINGs
      $pings = intdiv(8_388_608, 127);
      $last = sprintf('%0125d', $pings - 1);
      $empties = intdiv(2_097_152, 2);
      $big = str_repeat('0123456789abcdef', 16_384);

      $Evidence = [
         'error' => '',
         'guard' => false,
         'port' => 0,
         'mock' => null,
         'a' => null,
         'c' => null,
         'd' => null,
         'e' => null,
         'events' => [],
         'max' => ['a' => 0, 'b' => 0, 'd' => 0],
         'heap' => 0,
      ];
      $forked = 0;
      $Pair = false;
      $Listener = false;
      $Client = null;

      try {
         // ! Listener with a small receive window, inherited by the accepted socket
         $Listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
         if ($Listener === false) {
            throw new RuntimeException('could not create the mock listener');
         }
         socket_set_option($Listener, SOL_SOCKET, SO_REUSEADDR, 1);
         socket_set_option($Listener, SOL_SOCKET, SO_RCVBUF, 2048);
         if (socket_bind($Listener, '127.0.0.1', 0) === false || socket_listen($Listener, 4) === false) {
            throw new RuntimeException('could not bind the mock listener');
         }
         socket_getsockname($Listener, $address, $port);
         $Evidence['port'] = (int) $port;

         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('could not create the control pair');
         }

         $forked = pcntl_fork();
         if ($forked === -1) {
            throw new RuntimeException('could not fork the mock');
         }
         // # Mock (child)
         if ($forked === 0) {
            fclose($Pair[0]);
            $Control = $Pair[1];
            $report = [
               'port' => (int) $port,
               'accepted' => false,
               'handshake' => false,
               'pings' => 0,
               'empties' => 0,
               'b' => ['pongs' => 0, 'last' => null, 'other' => []],
               'c' => ['pongs' => [], 'answers' => 0, 'elapsed' => null, 'drain' => []],
               'd' => ['pongs' => 0, 'held' => 0, 'after' => 0, 'last' => null, 'other' => []],
               'e' => ['frames' => [], 'eof' => false],
               'signals' => '',
               'error' => '',
            ];
            // ! Bytes of a frame split across two collections
            $pending = '';

            try {
               /** One-byte client signal on the control pair, within `$limit` seconds. */
               $Await = static function (string $signal, float $limit) use ($Control, &$report): void {
                  $Read = [$Control];
                  $Write = null;
                  $Except = null;
                  $seconds = (int) $limit;
                  if (stream_select($Read, $Write, $Except, $seconds, (int) (($limit - $seconds) * 1_000_000)) !== 1) {
                     throw new RuntimeException("no '{$signal}' signal from the client");
                  }
                  $got = (string) fread($Control, 1);
                  $report['signals'] .= $got;
                  if ($got !== $signal) {
                     throw new RuntimeException("expected the '{$signal}' signal, got '{$got}'");
                  }
               };
               /** Write every byte (blocking, bounded by SO_SNDTIMEO). */
               $Send = static function (Socket $Peer, string $data): void {
                  $offset = 0;
                  $length = strlen($data);
                  while ($offset < $length) {
                     $written = @socket_write($Peer, $offset === 0 ? $data : substr($data, $offset));
                     if ($written === false) {
                        $reason = socket_strerror(socket_last_error($Peer));
                        throw new RuntimeException("mock write failed: {$reason}");
                     }
                     $offset += $written;
                  }
               };
               $Text = static function (string $payload): string {
                  $size = chr(strlen($payload));

                  return "\x81{$size}{$payload}";
               };
               /**
                * Read and parse client frames (masked) for at most `$limit` seconds: stop
                * once `$Until` holds (or is null) and `$idle` seconds pass with no byte.
                *
                * @return bool true on peer EOF
                */
               $Collect = static function (Socket $Peer, Closure $On, float $limit, float $idle, null|Closure $Until = null) use (&$pending): bool {
                  $started = hrtime(true) / 1e9;
                  $quiet = $started;
                  while (hrtime(true) / 1e9 - $started < $limit) {
                     $Read = [$Peer];
                     $Write = null;
                     $Except = null;
                     if (@socket_select($Read, $Write, $Except, 0, 20_000) > 0) {
                        $chunk = @socket_read($Peer, 65_536, PHP_BINARY_READ);
                        if ($chunk === false || $chunk === '') {
                           return true;
                        }
                        $pending .= $chunk;
                        $quiet = hrtime(true) / 1e9;

                        // @@ Parse every complete frame with a cursor (no per-frame copy)
                        $cursor = 0;
                        $size = strlen($pending);
                        while ($size - $cursor >= 2) {
                           $opcode = ord($pending[$cursor]) & 0x0F;
                           $length = ord($pending[$cursor + 1]) & 0x7F;
                           $masked = (ord($pending[$cursor + 1]) & 0x80) !== 0;
                           $offset = $cursor + 2;
                           if ($length === 126) {
                              if ($size - $cursor < 4) {
                                 break;
                              }
                              $length = unpack('n', $pending, $offset)[1];
                              $offset += 2;
                           }
                           else if ($length === 127) {
                              if ($size - $cursor < 10) {
                                 break;
                              }
                              $length = unpack('J', $pending, $offset)[1];
                              $offset += 8;
                           }
                           $mask = '';
                           if ($masked) {
                              if ($size - $offset < 4) {
                                 break;
                              }
                              $mask = substr($pending, $offset, 4);
                              $offset += 4;
                           }
                           if ($size - $offset < $length) {
                              break;
                           }
                           $payload = substr($pending, $offset, $length);
                           if ($masked && $length > 0) {
                              $payload ^= str_repeat($mask, intdiv($length, 4) + 1);
                           }
                           $On($opcode, $payload);
                           $cursor = $offset + $length;
                        }
                        $pending = substr($pending, $cursor);
                        continue;
                     }
                     if ($Until !== null && $Until() === false) {
                        continue;
                     }
                     if (hrtime(true) / 1e9 - $quiet >= $idle) {
                        break;
                     }
                  }

                  return false;
               };

               // @ Accept the client (bounded)
               $Ready = [$Listener];
               $Write = null;
               $Except = null;
               if (socket_select($Ready, $Write, $Except, 5) !== 1) {
                  throw new RuntimeException('the client never dialed');
               }
               $Peer = socket_accept($Listener);
               if ($Peer === false) {
                  throw new RuntimeException('accept failed');
               }
               $report['accepted'] = true;
               socket_set_option($Peer, SOL_SOCKET, SO_RCVBUF, 2048);
               socket_set_option($Peer, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 5, 'usec' => 0]);
               socket_set_option($Peer, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 5, 'usec' => 0]);

               // @ Upgrade
               $request = '';
               while (strpos($request, "\r\n\r\n") === false) {
                  $chunk = socket_read($Peer, 4096, PHP_BINARY_READ);
                  if ($chunk === false || $chunk === '') {
                     throw new RuntimeException('EOF before the upgrade request');
                  }
                  $request .= $chunk;
               }
               preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $request, $matches);
               $key = $matches[1] ?? '';
               $accept = base64_encode(sha1("{$key}258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
               $Send($Peer, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
               $report['handshake'] = true;

               // # (a) 8 MiB of counter PINGs; the mock never reads
               $batch = '';
               for ($index = 0; $index < $pings; $index++) {
                  $counter = sprintf('%0125d', $index);
                  $batch .= "\x89\x7D{$counter}";
                  if (strlen($batch) >= 65_024 || $index === $pings - 1) {
                     $Send($Peer, $batch);
                     $batch = '';
                  }
                  $report['pings']++;
               }
               $Send($Peer, $Text('flood-end'));
               $Await('A', 8.0);

               // # (b) Drain: every PONG until the one answering the last PING
               $Collect(
                  $Peer,
                  static function (int $opcode, string $payload) use (&$report): void {
                     if ($opcode === 0x0A) {
                        $report['b']['pongs']++;
                        $report['b']['last'] = $payload;
                     }
                     else if (count($report['b']['other']) < 8) {
                        $report['b']['other'][] = $opcode;
                     }
                  },
                  5.0,
                  0.3,
                  static function () use (&$report, $last): bool {
                     return $report['b']['last'] === $last;
                  }
               );

               // # (c) A PING on an idle queue is answered at once
               $started = hrtime(true);
               $Send($Peer, "\x89\x09idle-ping");
               $Collect(
                  $Peer,
                  static function (int $opcode, string $payload) use (&$report, $started): void {
                     if ($opcode === 0x0A) {
                        // ! Keep the first few payloads: a runaway answerer must not flood the report
                        if (++$report['c']['answers'] <= 4) {
                           $report['c']['pongs'][] = substr($payload, -12);
                        }
                        $report['c']['elapsed'] ??= intdiv(hrtime(true) - $started, 1_000_000);
                     }
                  },
                  1.0,
                  0.2,
                  static function () use (&$report): bool {
                     return $report['c']['pongs'] !== [];
                  }
               );
               // @ ...and a later backpressured send that drains ships no stale PONG
               $Send($Peer, $Text('send-big'));
               $Await('B', 5.0);
               $Collect(
                  $Peer,
                  static function (int $opcode, string $payload) use (&$report, $big): void {
                     if (count($report['c']['drain']) >= 8) {
                        return;
                     }
                     $report['c']['drain'][] = $opcode === 0x02
                        ? ($payload === $big ? 'binary' : 'binary-corrupt')
                        : "opcode-{$opcode}";
                  },
                  5.0,
                  0.3,
                  static function () use (&$report): bool {
                     return $report['c']['drain'] !== [];
                  }
               );

               // # (d) 2 MiB of empty PINGs; the mock never reads
               $Send($Peer, $Text('flood2-start'));
               $chunk = str_repeat("\x89\x00", 32_768);
               for ($sent = 0; $sent < $empties; $sent += 32_768) {
                  $Send($Peer, $chunk);
                  $report['empties'] += 32_768;
               }
               $Send($Peer, $Text('flood2-end'));
               $Await('D', 8.0);
               // @ The client holds its loop: drain only what the kernels already carry...
               $OnEmpty = static function (string $phase) use (&$report): Closure {
                  return static function (int $opcode, string $payload) use (&$report, $phase): void {
                     if ($opcode === 0x0A) {
                        $report['d']['pongs']++;
                        $report['d'][$phase]++;
                        $report['d']['last'] = $payload;
                     }
                     else if (count($report['d']['other']) < 8) {
                        $report['d']['other'][] = $opcode;
                     }
                  };
               };
               $Collect($Peer, $OnEmpty('held'), 3.0, 0.3);
               // @ ...then release it: the queued tail drains and the parked PING is answered
               fwrite($Control, 'G');
               $Collect($Peer, $OnEmpty('after'), 5.0, 0.3);

               // # (e) send, send (backpressured), PING, send, PING, close
               $Send($Peer, $Text('send-two'));
               $Await('E', 5.0);
               $third = $Text('send-third');
               $Send($Peer, "\x89\x06e-ping{$third}");
               $Await('T', 5.0);
               $close = $Text('close-now');
               $Send($Peer, "\x89\x06f-ping{$close}");
               $Await('C', 5.0);
               $report['e']['eof'] = $Collect(
                  $Peer,
                  static function (int $opcode, string $payload) use (&$report, $big): void {
                     if (count($report['e']['frames']) >= 16) {
                        return;
                     }
                     if ($opcode === 0x08 && strlen($payload) >= 2) {
                        $code = unpack('n', $payload)[1];
                        $reason = substr($payload, 2);
                        $report['e']['frames'][] = "close:{$code}:{$reason}";
                        return;
                     }
                     $report['e']['frames'][] = match ($opcode) {
                        0x02 => $payload === $big ? 'binary' : 'binary-corrupt',
                        0x01 => "text:{$payload}",
                        0x08 => 'close',
                        0x0A => "pong:{$payload}",
                        default => "opcode-{$opcode}",
                     };
                  },
                  5.0,
                  5.0
               );

               socket_close($Peer);
            }
            catch (Throwable $Throwable) {
               $class = $Throwable::class;
               $message = $Throwable->getMessage();
               $report['error'] = "{$class}: {$message}";
            }

            // : Report to the client side, then leave without the runner's teardown
            $line = json_encode($report);
            @fwrite($Control, "{$line}\n");
            fclose($Control);
            posix_kill(posix_getpid(), SIGKILL);
         }

         // # Client (parent)
         socket_close($Listener);
         $Listener = false;
         fclose($Pair[1]);
         $Control = $Pair[0];

         $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
         $Client->configure(new WS_Client_CLI\Configs(
            host: '127.0.0.1',
            port: (int) $port,
            heartbeatInterval: 0,
            compression: false,
            handshakeTimeout: 5
         ));

         $phase = 'a';
         $baseline = 0;
         $Client->on(Events::Connected, static function (Session $Session) use (&$baseline): void {
            $baseline = memory_get_usage();

            // ! A small kernel send buffer makes the backpressure deterministic
            $Socket = socket_import_stream($Session->Connection->Socket);
            if ($Socket !== false) {
               socket_set_option($Socket, SOL_SOCKET, SO_SNDBUF, 4096);
            }
         });
         $Client->on(Events::Disconnected, static function (Session $Session) use (&$Evidence, &$phase): void {
            $Evidence['events'][] = "disconnected@{$phase}";
         });
         $Client->on(
            Events::MessageReceived,
            static function (Session $Session, Message $Message) use (&$Evidence, &$phase, &$baseline, $Control, $big): void {
               $output = strlen($Session->Connection->output);

               switch ($Message->payload) {
                  case 'flood-end':
                     $Evidence['max']['a'] = max($Evidence['max']['a'], $output);
                     $Evidence['a'] = [
                        'output' => $output,
                        'established' => $Session->established,
                        'disconnected' => $Session->disconnected,
                        'pong' => $Session->pong ?? null,
                     ];
                     $Evidence['heap'] = max($Evidence['heap'], memory_get_usage() - $baseline);
                     $phase = 'b';
                     fwrite($Control, 'A');
                     break;
                  case 'send-big':
                     $Session->send($big, binary: true);
                     $Evidence['c'] = ['output' => strlen($Session->Connection->output)];
                     fwrite($Control, 'B');
                     break;
                  case 'flood2-start':
                     $phase = 'd';
                     break;
                  case 'flood2-end':
                     // ! The parked state before the drain: an empty payload, a queued tail
                     $Evidence['max']['d'] = max($Evidence['max']['d'], $output);
                     $Evidence['d'] = ['output' => $output, 'pong' => $Session->pong ?? null, 'go' => ''];
                     $phase = 'e';
                     fwrite($Control, 'D');

                     // @@ Hold the loop until the mock drained the kernel buffers
                     //    (bounded; a timer SIGALRM may interrupt the select)
                     $deadline = hrtime(true) + 5_000_000_000;
                     while ($Evidence['d']['go'] === '' && hrtime(true) < $deadline) {
                        $Read = [$Control];
                        $Write = null;
                        $Except = null;
                        if (@stream_select($Read, $Write, $Except, 0, 100_000) === 1) {
                           $Evidence['d']['go'] = (string) fread($Control, 1);
                        }
                     }
                     break;
                  case 'send-two':
                     // ! After the drain, nothing may stay parked
                     $Evidence['e'] = ['parked' => $Session->pong ?? null];
                     $Session->send($big, binary: true);
                     $Session->send('second');
                     $Evidence['e']['output'] = strlen($Session->Connection->output);
                     fwrite($Control, 'E');
                     break;
                  case 'send-third':
                     // ! Still backpressured: the parked answer rides ahead of this send
                     $Evidence['e']['third'] = $Session->pong ?? null;
                     $Session->send('third');
                     fwrite($Control, 'T');
                     break;
                  case 'close-now':
                     $Evidence['e']['pong'] = $Session->pong ?? null;
                     $Evidence['e']['close'] = $Session->close(1000, 'bye');
                     fwrite($Control, 'C');
                     break;
               }
            }
         );

         // ! Sampler — the userspace queue and the heap while the floods run
         $Sample = null;
         $Sample = static function () use (&$Sample, &$Evidence, &$phase, &$baseline, $Client): void {
            $Session = $Client->Session;
            if ($Session === null || $Session->disconnected) {
               return;
            }
            if (isSet($Evidence['max'][$phase])) {
               $Evidence['max'][$phase] = max($Evidence['max'][$phase], strlen($Session->Connection->output));
            }
            if ($phase === 'a' && $baseline > 0) {
               $Evidence['heap'] = max($Evidence['heap'], memory_get_usage() - $baseline);
            }
            $Client->Event->defer((int) hrtime(true) + 5_000_000, $Sample);
         };
         $Client->Event->defer((int) hrtime(true) + 5_000_000, $Sample);

         // ! Guard — a stuck exchange fails an assertion, never the runner
         $Client->Event->defer((int) hrtime(true) + 9_000_000_000, static function () use (&$Evidence, $Client): void {
            $Evidence['guard'] = true;
            $Client->Session?->disconnect();
            $Client->Session?->Connection->close();
            $Client->Event->destroy();
         });

         // @ Run the real client until the graceful close tears the loop down
         $Client->connect('/');

         // @@ Collect the mock report (bounded; a timer SIGALRM may interrupt the select)
         $deadline = hrtime(true) + 3_000_000_000;
         while ($Evidence['mock'] === null && hrtime(true) < $deadline) {
            $Read = [$Control];
            $Write = null;
            $Except = null;
            if (@stream_select($Read, $Write, $Except, 0, 100_000) === 1) {
               $line = (string) fgets($Control);
               $Evidence['mock'] = json_decode($line, true) ?? false;
            }
         }
      }
      catch (Throwable $Throwable) {
         $class = $Throwable::class;
         $message = $Throwable->getMessage();
         $Evidence['error'] = "{$class}: {$message}";
      }
      finally {
         if ($Client !== null && $Client->Session !== null && $Client->Session->disconnected === false) {
            $Client->Session->disconnect();
            $Client->Session->Connection->close();
         }
         if ($forked > 0) {
            posix_kill($forked, SIGKILL);
            pcntl_waitpid($forked, $status);
         }
         if ($Listener instanceof Socket) {
            socket_close($Listener);
         }
         if ($Pair !== false) {
            foreach ($Pair as $Stream) {
               if (is_resource($Stream)) {
                  fclose($Stream);
               }
            }
         }
      }

      $mock = is_array($Evidence['mock']) ? $Evidence['mock'] : [];
      // ! The report body is large: keep the evidence short
      $brief = $Evidence;
      if (isSet($brief['mock']['b']['last'])) {
         $brief['mock']['b']['last'] = substr((string) $brief['mock']['b']['last'], -8);
      }
      if (isSet($brief['a']['pong'])) {
         $brief['a']['pong'] = substr((string) $brief['a']['pong'], -8);
      }
      // ! Empty client PONGs are 6 bytes (masked): the queued tail at flood2-end
      //   completes ceil(output / 6) frames, then exactly one answers the parked PING
      $tail = intdiv((int) ($Evidence['d']['output'] ?? 0) + 5, 6);

      yield assert(
         assertion: $Evidence['error'] === ''
            && $Evidence['guard'] === false
            && ($mock['error'] ?? null) === ''
            && ($mock['port'] ?? 0) === $Evidence['port']
            && $Evidence['port'] > 0
            && ($mock['accepted'] ?? false) === true
            && ($mock['handshake'] ?? false) === true
            && ($mock['pings'] ?? 0) === $pings
            && ($mock['empties'] ?? 0) === $empties
            && ($mock['signals'] ?? '') === 'ABDETC',
         description: 'the mock must run every leg in lock-step with the client: ' . json_encode($brief)
      );
      yield assert(
         assertion: is_array($Evidence['a'])
            && $Evidence['max']['a'] <= 65_536
            && $Evidence['a']['established'] === true
            && $Evidence['a']['disconnected'] === false
            && $Evidence['heap'] < 4_194_304,
         description: '(a) an 8 MiB PING flood that is never read must keep the output queue and the heap bounded, the session established: '
            . json_encode($brief)
      );
      yield assert(
         assertion: ($mock['b']['last'] ?? null) === $last
            && ($mock['b']['pongs'] ?? 0) >= 1
            && ($mock['b']['pongs'] ?? PHP_INT_MAX) < intdiv($pings, 50)
            && ($mock['b']['other'] ?? null) === [],
         description: '(b) once drained, the last PONG must answer the last PING and the PONGs must be far fewer than the PINGs: '
            . json_encode($brief)
      );
      yield assert(
         assertion: ($mock['c']['pongs'] ?? null) === ['idle-ping']
            && ($mock['c']['answers'] ?? 0) === 1
            && ($mock['c']['elapsed'] ?? PHP_INT_MAX) < 500
            && ($Evidence['c']['output'] ?? 0) > 0
            && ($mock['c']['drain'] ?? null) === ['binary'],
         description: '(c) a PING on an idle queue must be answered at once, and a later drained send must ship no stale PONG: '
            . json_encode($brief)
      );
      yield assert(
         assertion: is_array($Evidence['d'])
            && $Evidence['max']['d'] <= 65_536
            && $Evidence['d']['output'] > 0
            && $Evidence['d']['pong'] === ''
            && $Evidence['d']['go'] === 'G'
            && ($mock['d']['pongs'] ?? PHP_INT_MAX) < intdiv($empties, 50)
            && ($mock['d']['after'] ?? 0) === $tail + 1
            && ($mock['d']['last'] ?? null) === ''
            && ($mock['d']['other'] ?? null) === []
            && is_array($Evidence['e'])
            && array_key_exists('parked', $Evidence['e'])
            && $Evidence['e']['parked'] === null,
         description: '(d) a 2 MiB flood of empty PINGs must keep the output queue bounded, and the parked empty PING must be answered exactly once on drain: '
            . json_encode($brief)
      );
      yield assert(
         assertion: ($Evidence['e']['output'] ?? 0) > 0
            && ($Evidence['e']['close'] ?? false) === true
            && ($Evidence['e']['third'] ?? null) === 'e-ping'
            && ($Evidence['e']['pong'] ?? null) === 'f-ping'
            && ($mock['e']['frames'] ?? null) === [
               'binary', 'text:second', 'pong:e-ping', 'text:third', 'pong:f-ping', 'close:1000:bye'
            ]
            && ($mock['e']['eof'] ?? false) === true
            && $Evidence['events'] === ['disconnected@e'],
         description: '(e) backpressured sends must reach the peer byte-exact and in order, each parked PONG ahead of the next frame queued (the close frame too), nothing after the close: '
            . json_encode($brief)
      );
   }
);
