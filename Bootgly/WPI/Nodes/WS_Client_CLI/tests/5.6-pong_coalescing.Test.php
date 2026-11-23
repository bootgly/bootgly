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
 * A server that pings without reading must not grow the
 * client's output queue. While output is backpressured a PING is parked (only
 * the latest payload is kept) and answered once — ahead of the next frame the
 * client queues (a send or its close frame), or when the queue drains — so
 * steady sending never starves it and nothing follows the close frame; a PING
 * on an idle queue is answered at once.
 *
 * The peer is a forked mock (ext-sockets, SO_RCVBUF 2048) driven in lock-step
 * with the real client through a socket pair: the mock sends TEXT commands,
 * the client answers on the pair. Every wait ends on an event, bounded only by
 * a hang guard:
 *
 * - Preload: each flood (and the close sequence) is written while the client
 *   holds its loop in the command's handler and answers nothing; the handler
 *   returns once a MSG_PEEK sees the whole batch in its kernel queue. A batch
 *   fits one read of the client (65,535 bytes), so it is decoded in a single
 *   pass, with no write dispatch between its PINGs and its terminator.
 * - The mock writes only into a client that is silent (a preload) or after it
 *   has read every byte the client sent up to a content boundary. A mock whose
 *   receive side is full never writes: on kernels that retract its window
 *   (7.0), the client's ACKs are dropped and a write would wait for them.
 * - Counts come from the client's own byte ledger (bytes the kernel accepted
 *   plus its queued suffix), never from idle cutoffs: a retransmission paced
 *   by the client's backoff only delays a leg, it cannot split its count.
 */
return new Test(
   description: 'It should coalesce PONG answers while output is backpressured and keep close ordering',
   test: function () {
      // ! Hang guard of every event wait, and of the client's watchdog (seconds)
      $hang = 30.0;
      // ! Workload — each flood fits one 65,535-byte read with its terminator
      $pings = 515;      // 515 × 127 + 11 = 65,416 bytes
      $last = sprintf('%0125d', $pings - 1);
      $empties = 32_761; // 32,761 × 2 + 12 = 65,534 bytes
      $big = str_repeat('0123456789abcdef', 16_384);
      // ! Kernel buffers requested (Linux doubles each one it accepts)
      $mockReceive = 2048;
      $clientSend = 4096;
      $clientReceive = 262_144;
      /** WebSocket TEXT frame (server side: unmasked). */
      $Text = static function (string $payload): string {
         $size = chr(strlen($payload));

         return "\x81{$size}{$payload}";
      };

      // ! Batches (each one decoded in a single pass by the client)
      $flood = '';
      for ($index = 0; $index < $pings; $index++) {
         $counter = sprintf('%0125d', $index);
         $flood .= "\x89\x7D{$counter}";
      }
      $flood = "{$flood}{$Text('flood-end')}";
      $flood2 = str_repeat("\x89\x00", $empties);
      $flood2 = "{$flood2}{$Text('flood2-end')}";
      $closing = "{$Text('send-two')}\x89\x07e-early\x89\x06e-ping{$Text('send-third')}";
      $closing = "{$closing}\x89\x00{$Text('send-fourth')}\x89\x06f-ping{$Text('close-now')}{$Text('late-text')}";

      $Evidence = [
         'error' => '',
         'guard' => false,
         'port' => 0,
         'mock' => null,
         'buffers' => null,
         'preloads' => [],
         'a' => null,
         'b' => null,
         'idle' => null,
         'c' => null,
         'd' => null,
         'dmark' => null,
         'e' => [],
         'events' => [],
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
         socket_set_option($Listener, SOL_SOCKET, SO_RCVBUF, $mockReceive);
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
            stream_set_read_buffer($Control, 0);
            $report = [
               'port' => (int) $port,
               'accepted' => false,
               'handshake' => false,
               'receive' => 0,
               'pings' => 0,
               'empties' => 0,
               'a' => ['written' => 0, 'output' => 0],
               'b' => ['pongs' => 0, 'first' => null, 'last' => null, 'ordered' => true, 'end' => false, 'other' => []],
               'c' => ['idle' => [], 'drain' => []],
               'd' => ['written' => 0, 'output' => 0, 'pongs' => 0, 'end' => false, 'other' => []],
               'e' => ['frames' => [], 'eof' => false],
               'signals' => '',
               'error' => '',
            ];
            // ! Bytes of a frame split across two reads
            $pending = '';

            try {
               /**
                * One-letter client signal on the control pair (plus `$extra` bytes),
                * within the hang guard.
                */
               $Await = static function (string $signal, int $extra = 0) use ($Control, &$report, $hang): string {
                  $deadline = hrtime(true) + (int) ($hang * 1e9);
                  $got = '';
                  // @@ A stray signal may interrupt the select: retry until the deadline
                  while (strlen($got) < 1 + $extra) {
                     if (hrtime(true) >= $deadline) {
                        throw new RuntimeException("no '{$signal}' signal from the client within {$hang} s");
                     }
                     $Read = [$Control];
                     $Write = null;
                     $Except = null;
                     if (@stream_select($Read, $Write, $Except, 0, 100_000) !== 1) {
                        continue;
                     }
                     $chunk = (string) fread($Control, 1 + $extra - strlen($got));
                     if ($chunk === '' && feof($Control)) {
                        throw new RuntimeException("the control pair closed before the '{$signal}' signal");
                     }
                     $got .= $chunk;
                  }
                  $report['signals'] .= $got[0];
                  if ($got[0] !== $signal) {
                     throw new RuntimeException("expected the '{$signal}' signal, got '{$got[0]}'");
                  }

                  return substr($got, 1);
               };
               /** Write every byte (blocking; SO_SNDTIMEO only flags a wedged peer). */
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
               /**
                * Read and parse client frames (masked) until `$Until` holds, or until the
                * peer's EOF when `$closing`; every frame of a read goes to `$On`, those
                * after `$Until` held included.
                *
                * @return bool true on peer EOF
                */
               $Collect = static function (Socket $Peer, Closure $On, Closure $Until, string $leg, bool $closing = false) use (&$pending, $hang): bool {
                  $deadline = hrtime(true) + (int) ($hang * 1e9);
                  while ($Until() === false) {
                     if (hrtime(true) >= $deadline) {
                        throw new RuntimeException("an awaited frame never came within {$hang} s (leg {$leg})");
                     }
                     $Read = [$Peer];
                     $Write = null;
                     $Except = null;
                     if (@socket_select($Read, $Write, $Except, 0, 100_000) < 1) {
                        continue;
                     }
                     $chunk = @socket_read($Peer, 65_536, PHP_BINARY_READ);
                     if ($chunk === false || $chunk === '') {
                        if ($closing === false) {
                           throw new RuntimeException("the client closed in leg {$leg}");
                        }

                        return true;
                     }
                     $pending .= $chunk;

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
                  }

                  return false;
               };

               // @ Accept the client (bounded)
               $Ready = [$Listener];
               $Write = null;
               $Except = null;
               if (socket_select($Ready, $Write, $Except, (int) $hang) !== 1) {
                  throw new RuntimeException('the client never dialed');
               }
               $Peer = socket_accept($Listener);
               if ($Peer === false) {
                  throw new RuntimeException('accept failed');
               }
               $report['accepted'] = true;
               socket_set_option($Peer, SOL_SOCKET, SO_RCVBUF, $mockReceive);
               socket_set_option($Peer, SOL_SOCKET, SO_SNDTIMEO, ['sec' => (int) $hang, 'usec' => 0]);
               socket_set_option($Peer, SOL_SOCKET, SO_RCVTIMEO, ['sec' => (int) $hang, 'usec' => 0]);
               $report['receive'] = (int) socket_get_option($Peer, SOL_SOCKET, SO_RCVBUF);

               // @ Upgrade
               $request = '';
               while (strpos($request, "\r\n\r\n") === false) {
                  $chunk = socket_read($Peer, 4096, PHP_BINARY_READ);
                  if ($chunk === false || $chunk === '') {
                     throw new RuntimeException('EOF before the upgrade request');
                  }
                  $request .= $chunk;
               }
               if (str_ends_with($request, "\r\n\r\n") === false) {
                  throw new RuntimeException('the client wrote past its upgrade request');
               }
               preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $request, $matches);
               $key = $matches[1] ?? '';
               $accept = base64_encode(sha1("{$key}258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
               $Send($Peer, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
               $report['handshake'] = true;

               // # (a) 515 counter PINGs preloaded into a client that answers nothing yet
               $Send($Peer, $Text('a-arm'));
               $Await('P');
               $report['pings'] = $pings;
               $Send($Peer, $flood);
               // @ The client answers into a mock that does not read: wait for its ledger
               $ledger = $Await('A', 17);
               $report['a']['written'] = (int) substr($ledger, 0, 8);
               $report['a']['output'] = (int) substr($ledger, 8, 8);
               $frames = $report['a']['written'] + $report['a']['output'];
               if ($frames % 131 !== 0) {
                  throw new RuntimeException("leg a: {$frames} bytes are not whole 131-byte PONG frames");
               }
               $frames = intdiv($frames, 131);
               // ! A PING parked at the terminator owes one answer on drain
               $owed = $frames + (substr($ledger, 16, 1) === '1' ? 1 : 0);

               // # (b) Drain: every PONG the client produced, then its drain answer
               $OnB = static function (int $opcode, string $payload) use (&$report): void {
                  if ($opcode === 0x0A) {
                     $report['b']['ordered'] = $report['b']['ordered']
                        && ($report['b']['last'] === null || strcmp($payload, $report['b']['last']) > 0);
                     $report['b']['pongs']++;
                     $report['b']['first'] ??= $payload;
                     $report['b']['last'] = $payload;
                  }
                  else if ($opcode === 0x01 && $payload === 'b-end') {
                     $report['b']['end'] = true;
                  }
                  else if (count($report['b']['other']) < 8) {
                     $report['b']['other'][] = "opcode-{$opcode}";
                  }
               };
               // @ ...the drain answer included: it must leave on its own, before the mark
               $Collect($Peer, $OnB, static function () use (&$report, $owed): bool {
                  return $report['b']['pongs'] >= $owed;
               }, 'b');
               $Send($Peer, $Text('b-mark'));
               $Collect($Peer, $OnB, static function () use (&$report): bool {
                  return $report['b']['end'];
               }, 'b');

               // # (c) A PING on an idle queue is answered at once...
               $Send($Peer, "{$Text('idle-arm')}\x89\x09idle-ping{$Text('idle-check')}");
               $OnIdle = static function (int $opcode, string $payload) use (&$report): void {
                  if (count($report['c']['idle']) < 8) {
                     $report['c']['idle'][] = match ($opcode) {
                        0x01 => "text:{$payload}",
                        0x0A => "pong:{$payload}",
                        default => "opcode-{$opcode}",
                     };
                  }
               };
               $Collect($Peer, $OnIdle, static function () use (&$report): bool {
                  return in_array('text:idle-done', $report['c']['idle'], true);
               }, 'c');
               // @ ...and a later backpressured send that drains ships no stale PONG
               $Send($Peer, $Text('send-big'));
               $Await('B');
               $OnDrain = static function (int $opcode, string $payload) use (&$report, $big): void {
                  if (count($report['c']['drain']) < 8) {
                     $report['c']['drain'][] = match ($opcode) {
                        0x02 => $payload === $big ? 'binary' : 'binary-corrupt',
                        0x01 => "text:{$payload}",
                        0x0A => "pong:{$payload}",
                        default => "opcode-{$opcode}",
                     };
                  }
               };
               $Collect($Peer, $OnDrain, static function () use (&$report): bool {
                  return in_array('text:c-end', $report['c']['drain'], true);
               }, 'c');

               // # (d) 32,761 empty PINGs preloaded the same way
               $Send($Peer, $Text('d-arm'));
               $Await('P');
               $report['empties'] = $empties;
               $Send($Peer, $flood2);
               $ledger = $Await('D', 17);
               $report['d']['written'] = (int) substr($ledger, 0, 8);
               $report['d']['output'] = (int) substr($ledger, 8, 8);
               $frames = $report['d']['written'] + $report['d']['output'];
               if ($frames % 6 !== 0) {
                  throw new RuntimeException("leg d: {$frames} bytes are not whole 6-byte PONG frames");
               }
               $frames = intdiv($frames, 6);
               // ! A PING parked at the terminator owes one answer on drain
               $owed = $frames + (substr($ledger, 16, 1) === '1' ? 1 : 0);
               $OnD = static function (int $opcode, string $payload) use (&$report): void {
                  if ($opcode === 0x0A && $payload === '') {
                     $report['d']['pongs']++;
                  }
                  else if ($opcode === 0x01 && $payload === 'd-end') {
                     $report['d']['end'] = true;
                  }
                  else if (count($report['d']['other']) < 8) {
                     $report['d']['other'][] = $opcode === 0x0A ? "pong:{$payload}" : "opcode-{$opcode}";
                  }
               };
               $Collect($Peer, $OnD, static function () use (&$report, $owed): bool {
                  return $report['d']['pongs'] >= $owed;
               }, 'd');
               $Send($Peer, $Text('d-mark'));
               $Collect($Peer, $OnD, static function () use (&$report): bool {
                  return $report['d']['end'];
               }, 'd');

               // # (e) send, backpressured send, PINGs, sends, close — one preloaded pass
               $Send($Peer, $Text('e-arm'));
               $Await('P');
               $Send($Peer, $closing);
               $Await('E');
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
                  static function (): bool {
                     return false;
                  },
                  'e',
                  closing: true
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
         stream_set_read_buffer($Control, 0);

         $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
         $Client->configure(new WS_Client_CLI\Configs(
            host: '127.0.0.1',
            port: (int) $port,
            heartbeatInterval: 0,
            compression: false,
            handshakeTimeout: (int) $hang,
            closeTimeout: $hang
         ));

         // ! Count the bytes the kernel accepts: the legs report them to the mock
         $Connections = $Client->Connections;
         $Connections->stats = true;

         $phase = 'a';
         $Socket = null;
         $snapshot = 0;
         // ! Last progress of the exchange (a message handled), for the watchdog
         $progress = hrtime(true);
         $Client->on(Events::Connected, static function (Session $Session) use (&$Evidence, &$Socket, $clientSend, $clientReceive): void {
            // ! Before any large segment arrives: a small send buffer makes the
            //   backpressure deterministic; a large receive buffer holds a preload
            $Socket = socket_import_stream($Session->Connection->Socket);
            if ($Socket === false) {
               $Socket = null;
               return;
            }
            socket_set_option($Socket, SOL_SOCKET, SO_SNDBUF, $clientSend);
            socket_set_option($Socket, SOL_SOCKET, SO_RCVBUF, $clientReceive);
            $Evidence['buffers'] = [
               'send' => (int) socket_get_option($Socket, SOL_SOCKET, SO_SNDBUF),
               'receive' => (int) socket_get_option($Socket, SOL_SOCKET, SO_RCVBUF),
            ];
         });
         $Client->on(Events::Disconnected, static function (Session $Session) use (&$Evidence, &$phase): void {
            $Evidence['events'][] = "disconnected@{$phase}";
         });
         /**
          * Hold the loop (answering nothing) until the mock's whole batch of `$size`
          * bytes sits in the kernel queue, so it is decoded in one pass.
          */
         $Preload = static function (Session $Session, string $leg, int $size) use (&$Evidence, &$Socket, &$snapshot, &$progress, $Connections, $Control, $hang): void {
            $started = hrtime(true);
            $Evidence['preloads'][$leg] = [
               'output' => strlen($Session->Connection->output),
               'pong' => $Session->pong ?? null,
               'size' => $size,
               'peeked' => 0,
               'ms' => null,
            ];
            $snapshot = $Connections->written;
            fwrite($Control, 'P');

            // @@ Peek (never consume) until the batch is whole: hang-guarded
            $deadline = $started + (int) ($hang * 1e9);
            while ($Socket !== null && hrtime(true) < $deadline) {
               $peeked = @socket_recv($Socket, $buffer, $size + 1, MSG_PEEK | MSG_DONTWAIT);
               if ($peeked === false) {
                  socket_clear_error($Socket);
                  $peeked = 0;
               }
               // ? Peer EOF: the batch never comes
               else if ($peeked === 0) {
                  break;
               }
               $Evidence['preloads'][$leg]['peeked'] = $peeked;
               if ($peeked >= $size) {
                  break;
               }
               usleep(1000);
            }
            $Evidence['preloads'][$leg]['ms'] = intdiv(hrtime(true) - $started, 1_000_000);
            // ! The hold is progress too: the watchdog restarts from its release
            $progress = hrtime(true);
         };
         $Client->on(
            Events::MessageReceived,
            static function (Session $Session, Message $Message) use (&$Evidence, &$phase, &$snapshot, &$progress, $Preload, $Connections, $Control, $big, $flood, $flood2, $closing): void {
               $progress = hrtime(true);
               $output = strlen($Session->Connection->output);
               // ! Bytes this leg produced: accepted by the kernel, plus the queued suffix
               $sent = $Connections->written - $snapshot + $output;

               switch ($Message->payload) {
                  // # (a)
                  case 'a-arm':
                     $Preload($Session, 'a', strlen($flood));
                     break;
                  case 'flood-end':
                     // ! Every PING of the pass was decoded before this frame
                     $written = $Connections->written - $snapshot;
                     $Evidence['a'] = [
                        'written' => $written,
                        'output' => $output,
                        'pong' => $Session->pong ?? null,
                        'established' => $Session->established,
                        'disconnected' => $Session->disconnected,
                     ];
                     $phase = 'b';
                     fwrite($Control, sprintf('A%08d%08d%d', $written, $output, (int) ($Session->pong !== null)));
                     break;
                  // # (b)
                  case 'b-mark':
                     // ! The drain answer ran in the write that emptied the queue
                     $Evidence['b'] = ['pong' => $Session->pong ?? null, 'sent' => $sent];
                     $Session->send('b-end');
                     $phase = 'c';
                     break;
                  // # (c)
                  case 'idle-arm':
                     $snapshot = $Connections->written;
                     $Evidence['idle'] = ['before' => $output];
                     break;
                  case 'idle-check':
                     // ! The idle PING just before this frame: answered, not parked
                     $Evidence['idle'] += [
                        'pong' => $Session->pong ?? null,
                        'output' => $output,
                        'written' => $Connections->written - $snapshot,
                     ];
                     $Session->send('idle-done');
                     break;
                  case 'send-big':
                     $Session->send($big, binary: true);
                     $Session->send('c-end');
                     $Evidence['c'] = ['output' => strlen($Session->Connection->output)];
                     fwrite($Control, 'B');
                     break;
                  // # (d)
                  case 'd-arm':
                     $phase = 'd';
                     $Preload($Session, 'd', strlen($flood2));
                     break;
                  case 'flood2-end':
                     // ! The parked state at the end of the pass: an empty payload
                     $written = $Connections->written - $snapshot;
                     $Evidence['d'] = [
                        'written' => $written,
                        'output' => $output,
                        'pong' => $Session->pong ?? null,
                     ];
                     fwrite($Control, sprintf('D%08d%08d%d', $written, $output, (int) ($Session->pong !== null)));
                     break;
                  case 'd-mark':
                     $Evidence['dmark'] = ['pong' => $Session->pong ?? null, 'sent' => $sent];
                     $Session->send('d-end');
                     break;
                  // # (e)
                  case 'e-arm':
                     $phase = 'e';
                     $Preload($Session, 'e', strlen($closing));
                     break;
                  case 'send-two':
                     $Evidence['e']['parked'] = $Session->pong ?? null;
                     $Session->send($big, binary: true);
                     $Session->send('second');
                     $Evidence['e']['output'] = strlen($Session->Connection->output);
                     break;
                  case 'send-third':
                     // ! Still backpressured: the latest parked answer rides ahead of this send
                     $Evidence['e']['third'] = $Session->pong ?? null;
                     $Session->send('third');
                     $Evidence['e']['cleared'] = $Session->pong ?? null;
                     $Session->send('third-b');
                     break;
                  case 'send-fourth':
                     $Evidence['e']['fourth'] = $Session->pong ?? null;
                     $Session->send('fourth');
                     break;
                  case 'close-now':
                     $Evidence['e']['pong'] = $Session->pong ?? null;
                     $Evidence['e']['close'] = $Session->close(1000, 'bye');
                     break;
                  case 'late-text':
                     // ! Decoded after the close in the same pass: nothing may follow it
                     $Evidence['e']['late'] = $Session->send('late');
                     fwrite($Control, 'E');
                     break;
               }
            }
         );

         // ! Watchdog — a wedged exchange fails an assertion, never the runner. It
         //   trips on a lack of progress (no message handled for $hang s), never
         //   on the total time: a starved or paused runner only delays the run
         $Watch = null;
         $Watch = static function () use (&$Watch, &$Evidence, &$progress, $Client, $hang): void {
            if (hrtime(true) - $progress < (int) ($hang * 1e9)) {
               $Client->Event->defer((int) hrtime(true) + 1_000_000_000, $Watch);

               return;
            }
            $Evidence['guard'] = true;
            $Client->Session?->disconnect();
            $Client->Session?->Connection->close();
            $Client->Event->destroy();
         };
         $Client->Event->defer((int) hrtime(true) + 1_000_000_000, $Watch);

         // @ Run the real client until the graceful close tears the loop down
         $Client->connect('/');

         // @@ Collect the mock report (hang-guarded; a stray signal may interrupt the select)
         $deadline = hrtime(true) + (int) ($hang * 1e9);
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
      // ! The 125-digit counters are long: keep the evidence short
      $brief = $Evidence;
      if (is_string($brief['a']['pong'] ?? null)) {
         $brief['a']['pong'] = substr($brief['a']['pong'], -8);
      }
      if (is_string($brief['b']['pong'] ?? null)) {
         $brief['b']['pong'] = substr($brief['b']['pong'], -8);
      }
      foreach (['first', 'last'] as $key) {
         if (is_string($brief['mock']['b'][$key] ?? null)) {
            $brief['mock']['b'][$key] = substr($brief['mock']['b'][$key], -8);
         }
      }
      $brief = json_encode($brief);
      // ! Upper bound of PONG bytes two kernels absorb while the mock does not read:
      //   the client's send buffer plus twice the mock's receive buffer (its window
      //   and one overshooting segment), with a quarter of margin
      $absorbed = intdiv(5 * (($Evidence['buffers']['send'] ?? 0) + 2 * ($mock['receive'] ?? 0)), 4);
      // ! PONG frames each flood leg produced before its terminator (client ledger)
      $aFrames = intdiv(($mock['a']['written'] ?? 0) + ($mock['a']['output'] ?? 0), 131);
      $dFrames = intdiv(($mock['d']['written'] ?? 0) + ($mock['d']['output'] ?? 0), 6);
      // ! Each batch was whole in the kernel queue before the client decoded it
      $preloaded = static function (string $leg) use ($Evidence): bool {
         $preload = $Evidence['preloads'][$leg] ?? null;

         return is_array($preload)
            && $preload['peeked'] === $preload['size']
            && $preload['output'] === 0
            && $preload['pong'] === null
            && $preload['ms'] !== null;
      };

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
            && ($mock['signals'] ?? '') === 'PABPDPE'
            && ($Evidence['buffers']['send'] ?? 0) >= $clientSend
            && ($Evidence['buffers']['receive'] ?? 0) >= 4 * 65_535
            && ($mock['receive'] ?? 0) >= $mockReceive
            && $preloaded('a') && $preloaded('d') && $preloaded('e'),
         description: "the mock must run every leg in lock-step with the client, each batch preloaded whole: {$brief}"
      );
      yield assert(
         assertion: is_array($Evidence['a'])
            && $Evidence['a']['output'] >= 1
            && $Evidence['a']['output'] <= 131
            && ($Evidence['a']['written'] + $Evidence['a']['output']) % 131 === 0
            && $Evidence['a']['pong'] === $last
            && $Evidence['a']['established'] === true
            && $Evidence['a']['disconnected'] === false,
         description: "(a) a PING flood that is never read must leave at most one PONG frame queued and park the latest PING, the session established: {$brief}"
      );
      yield assert(
         assertion: is_array($Evidence['b'])
            && $aFrames >= 1
            && $aFrames + 1 <= intdiv($absorbed, 131) + 2
            && ($mock['b']['pongs'] ?? 0) === $aFrames + 1
            && ($mock['b']['first'] ?? null) === sprintf('%0125d', 0)
            && ($mock['b']['last'] ?? null) === $last
            && ($mock['b']['ordered'] ?? false) === true
            && ($mock['b']['other'] ?? null) === []
            && $Evidence['b']['pong'] === null
            && $Evidence['b']['sent'] === $aFrames * 131 + 131,
         description: "(b) once drained, exactly one more PONG must answer the last PING, and the PONGs must be far fewer than the PINGs: {$brief}"
      );
      yield assert(
         assertion: $Evidence['idle'] === ['before' => 0, 'pong' => null, 'output' => 0, 'written' => 15]
            && ($mock['c']['idle'] ?? null) === ['pong:idle-ping', 'text:idle-done']
            && ($Evidence['c']['output'] ?? 0) > 0
            && ($mock['c']['drain'] ?? null) === ['binary', 'text:c-end'],
         description: "(c) a PING on an idle queue must be answered at once, and a later drained send must ship no stale PONG: {$brief}"
      );
      yield assert(
         assertion: is_array($Evidence['d'])
            && $Evidence['d']['output'] >= 1
            && $Evidence['d']['output'] <= 6
            && ($Evidence['d']['written'] + $Evidence['d']['output']) % 6 === 0
            && $Evidence['d']['pong'] === ''
            && $dFrames >= 1
            && $dFrames + 1 <= intdiv($absorbed, 6) + 2
            && ($mock['d']['pongs'] ?? 0) === $dFrames + 1
            && ($mock['d']['other'] ?? null) === []
            && is_array($Evidence['dmark'])
            && $Evidence['dmark']['pong'] === null
            && $Evidence['dmark']['sent'] === $dFrames * 6 + 6,
         description: "(d) an empty-PING flood must leave at most one PONG frame queued, and the parked empty PING must be answered exactly once on drain: {$brief}"
      );
      yield assert(
         assertion: array_key_exists('parked', $Evidence['e'])
            && $Evidence['e']['parked'] === null
            && ($Evidence['e']['output'] ?? 0) > 0
            && ($Evidence['e']['third'] ?? null) === 'e-ping'
            && array_key_exists('cleared', $Evidence['e'])
            && $Evidence['e']['cleared'] === null
            && ($Evidence['e']['fourth'] ?? null) === ''
            && ($Evidence['e']['pong'] ?? null) === 'f-ping'
            && ($Evidence['e']['close'] ?? false) === true
            && ($Evidence['e']['late'] ?? null) === false
            && ($mock['e']['frames'] ?? null) === [
               'binary', 'text:second', 'pong:e-ping', 'text:third', 'text:third-b',
               'pong:', 'text:fourth', 'pong:f-ping', 'close:1000:bye'
            ]
            && ($mock['e']['eof'] ?? false) === true
            && $Evidence['events'] === ['disconnected@e'],
         description: "(e) backpressured sends must reach the peer byte-exact and in order, each latest parked PONG ahead of the next frame queued (the close frame too), nothing after the close: {$brief}"
      );
   }
);
