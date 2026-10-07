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
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


/**
 * Phase 2 item 3 — an HTTP/2 stream that times out on a connection stalled on
 * writes (the peer stopped reading, the transport still holds output) and is the
 * connection's last open stream closes that connection instead of pooling it with
 * its backlog: the next request dials afresh. A connection that is not stalled
 * (output drained before the RST_STREAM was queued), or that still carries another
 * open stream, stays pooled and is reused.
 */
return new Test(
   description: 'It should close a write-stalled h2 connection whose last stream timed out, and pool every other one',
   test: function () {
      $Evidence = [
         'error' => '',
         'stalled' => null,
         'sibling' => null,
         'drained' => null,
      ];
      /** @var array<int,int> $PIDs */
      $PIDs = [];
      /** @var array<int,resource> $Pipes */
      $Pipes = [];
      /** @var array<int,HTTP_Client_CLI> $Clients */
      $Clients = [];

      /**
       * Fork an h2c peer on a free loopback port. Connection #1 is not read for
       * `$mute` seconds and never gets an answer on stream `$skip`; every other
       * stream that ends is answered `200 ok`. The peer reports each accept,
       * answer and RST_STREAM through a pipe, and exits after `$hold` seconds.
       */
      $Mock = static function (float $mute, int $skip, int $rcvbuf, float $hold) use (&$PIDs, &$Pipes): array {
         // ! Listener (ext-sockets: SO_RCVBUF must be set before listen)
         $Listener = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
         if ($Listener === false) {
            throw new RuntimeException('could not create the mock listener');
         }
         socket_set_option($Listener, SOL_SOCKET, SO_REUSEADDR, 1);
         if ($rcvbuf > 0) {
            socket_set_option($Listener, SOL_SOCKET, SO_RCVBUF, $rcvbuf);
         }
         if (@socket_bind($Listener, '127.0.0.1', 0) === false || @socket_listen($Listener, 8) === false) {
            throw new RuntimeException('could not bind the mock listener');
         }
         socket_getsockname($Listener, $address, $port);

         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('could not create the mock report pipe');
         }

         $PID = pcntl_fork();
         if ($PID === -1) {
            throw new RuntimeException('could not fork the mock peer');
         }
         // @ Child: the h2c peer
         if ($PID === 0) {
            fclose($Pair[0]);
            $Report = $Pair[1];
            $Frame = static fn (int $type, int $flags, int $stream, string $payload = ''): string
               => substr(pack('N', strlen($payload)), 1) . chr($type) . chr($flags) . pack('N', $stream) . $payload;

            /** @var array<int,Socket> $Peers */
            $Peers = [];
            $buffers = [];
            $readable = [];
            $accepted = 0;
            $end = microtime(true) + $hold;

            // @@ Serve until the hold ends
            while (microtime(true) < $end) {
               $read = [$Listener];
               foreach ($Peers as $number => $Peer) {
                  if (microtime(true) >= $readable[$number]) {
                     $read[] = $Peer;
                  }
               }
               $write = null;
               $except = null;
               if (@socket_select($read, $write, $except, 0, 20_000) === false) {
                  break;
               }

               foreach ($read as $Socket) {
                  // # Accept: big windows, so a POST body lands in the client output
                  if ($Socket === $Listener) {
                     $Peer = @socket_accept($Listener);
                     if ($Peer === false) {
                        continue;
                     }
                     $accepted++;
                     $Peers[$accepted] = $Peer;
                     $buffers[$accepted] = '';
                     $readable[$accepted] = $accepted === 1 ? microtime(true) + $mute : 0.0;
                     @socket_write(
                        $Peer,
                        $Frame(4, 0, 0, pack('nN', 4, 2_147_483_647))
                        . $Frame(8, 0, 0, pack('N', 2_147_483_647 - 65_535))
                     );
                     fwrite($Report, "accept {$accepted}\n");

                     continue;
                  }

                  // # Read and parse client frames
                  $number = (int) array_search($Socket, $Peers, true);
                  $chunk = @socket_read($Socket, 1_048_576);
                  if ($chunk === false || $chunk === '') {
                     socket_close($Socket);
                     unset($Peers[$number]);

                     continue;
                  }
                  $buffer = $buffers[$number] . $chunk;
                  $offset = 0;
                  if (str_starts_with($buffer, 'PRI * HTTP/2.0')) {
                     $offset = 24;
                  }

                  // @@ Whole frames only
                  while (strlen($buffer) - $offset >= 9) {
                     $length = unpack('N', "\0" . substr($buffer, $offset, 3))[1];
                     if (strlen($buffer) - $offset - 9 < $length) {
                        break;
                     }
                     $type = ord($buffer[$offset + 3]);
                     $flags = ord($buffer[$offset + 4]);
                     $stream = unpack('N', substr($buffer, $offset + 5, 4))[1] & 0x7FFF_FFFF;
                     $offset += 9 + $length;

                     // # SETTINGS: acknowledge the client's
                     if ($type === 4 && ($flags & 0x1) === 0) {
                        @socket_write($Socket, $Frame(4, 0x1, 0));
                     }
                     // # RST_STREAM: the client cancelled a stream
                     else if ($type === 3) {
                        fwrite($Report, "rst {$number}:{$stream}\n");
                     }
                     // # A request that ended (HEADERS or DATA with END_STREAM)
                     else if (($type === 0 || $type === 1) && ($flags & 0x1) !== 0) {
                        // ? The stream connection #1 never answers
                        if ($number === 1 && $stream === $skip) {
                           continue;
                        }
                        // @ HEADERS :status 200 (indexed 0x88) + DATA "ok"
                        @socket_write($Socket, $Frame(1, 0x4, $stream, "\x88") . $Frame(0, 0x1, $stream, 'ok'));
                        fwrite($Report, "answer {$number}:{$stream}\n");
                     }
                  }
                  $buffers[$number] = substr($buffer, $offset);
               }
            }

            exit(0);
         }

         // @ Parent: keep the report end only
         socket_close($Listener);
         fclose($Pair[1]);
         stream_set_blocking($Pair[0], false);
         $PIDs[] = $PID;
         $Pipes[] = $Pair[0];

         // :
         return [$port, $Pair[0]];
      };
      /** Every event the peer reported so far, grouped by kind. */
      $Read = static function ($Pipe): array {
         $events = ['accept' => [], 'answer' => [], 'rst' => []];
         $lines = '';
         while (($chunk = fread($Pipe, 65_536)) !== false && $chunk !== '') {
            $lines .= $chunk;
         }
         foreach (explode("\n", trim($lines)) as $line) {
            [$kind, $value] = explode(' ', "{$line} ") + ['', ''];
            if (isSet($events[$kind])) {
               $events[$kind][] = trim($value);
            }
         }

         // :
         return $events;
      };
      /** A sync h2c client on the mock port. */
      $Dial = static function (int $port) use (&$Clients): HTTP_Client_CLI {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $port, enableHTTP2: true));
         $Client->maxRetries = 0;
         $Clients[] = $Client;

         return $Client;
      };
      /** The client's open connections, pooled ones and h2 Sessions. */
      $Count = static fn (HTTP_Client_CLI $Client): array => [
         count($Client->Connections->Connections),
         count($Client->Pool->idle) + count($Client->Pool->busy),
         count($Client->Sessions),
      ];
      $body = str_repeat('b', 4 * 1_048_576);

      try {
         // # (a) Stalled: a peer that never reads, one 4 MiB upload
         [$port, $Pipe] = $Mock(mute: 60.0, skip: 0, rcvbuf: 2048, hold: 8.0);
         $Stalled = $Dial($port);
         $Stalled->timeout = 1;
         $started = microtime(true);
         $First = $Stalled->request('POST', '/upload', ['Content-Type' => 'application/octet-stream'], $body);
         $elapsed = round(microtime(true) - $started, 2);
         $after = $Count($Stalled);
         $Stalled->timeout = 2;
         $Second = $Stalled->request('GET', '/again');
         $events = $Read($Pipe);
         $Evidence['stalled'] = [
            'port' => $port,
            'first' => "{$First->code} {$First->status}",
            'elapsed' => $elapsed,
            'after' => $after,
            'second' => "{$Second->code} {$Second->status} {$Second->Body->raw}",
            'accept' => $events['accept'],
            'answer' => $events['answer'],
         ];

         // # (b) Sibling: a 4 MiB upload times out while the peer is not reading yet;
         //   a second stream on the same connection is answered once it reads
         [$port, $Pipe] = $Mock(mute: 1.6, skip: 1, rcvbuf: 2048, hold: 8.0);
         $Sibling = $Dial($port);
         $Sibling->batch();
         $Sibling->timeout = 1;
         $Upload = $Sibling->request('POST', '/upload', ['Content-Type' => 'application/octet-stream'], $body);
         $Sibling->timeout = 5;
         $Fast = $Sibling->request('GET', '/fast');
         // ! Sample the transport backlog every 10 ms until the upload times out:
         //   the last sample proves the connection was stalled on writes then
         $backlog = -1;
         $sampling = true;
         $Sample = static function () use (&$Sample, &$backlog, &$sampling, $Sibling, $Upload): void {
            // ? The upload timed out, or the drain ended: keep the last sample
            if ($sampling === false || $Upload->status === 'Timeout') {
               return;
            }

            $Connections = $Sibling->Connections->Connections;
            $key = array_key_first($Connections);
            $backlog = $key === null ? -1 : strlen($Connections[$key]->output);
            $Sibling->Event->defer(microtime(true) + 0.01, $Sample);
         };
         $Sample();
         $Sibling->drain();
         $sampling = false;
         $after = $Count($Sibling);
         $Sibling->timeout = 2;
         $Follow = $Sibling->request('GET', '/follow');
         $events = $Read($Pipe);
         $Evidence['sibling'] = [
            'port' => $port,
            'upload' => "{$Upload->code} {$Upload->status}",
            'backlog' => $backlog,
            'fast' => "{$Fast->code} {$Fast->status} {$Fast->Body->raw}",
            'after' => $after,
            'follow' => "{$Follow->code} {$Follow->status} {$Follow->Body->raw}",
            'accept' => $events['accept'],
            'answer' => $events['answer'],
            'rst' => $events['rst'],
         ];

         // # (c) Drained: a reading peer that never answers one small request
         [$port, $Pipe] = $Mock(mute: 0.0, skip: 1, rcvbuf: 0, hold: 8.0);
         $Drained = $Dial($port);
         $Drained->timeout = 1;
         $Slow = $Drained->request('GET', '/slow');
         $after = $Count($Drained);
         $Drained->timeout = 2;
         $Follow = $Drained->request('GET', '/follow');
         $events = $Read($Pipe);
         $Evidence['drained'] = [
            'port' => $port,
            'slow' => "{$Slow->code} {$Slow->status}",
            'after' => $after,
            'follow' => "{$Follow->code} {$Follow->status} {$Follow->Body->raw}",
            'accept' => $events['accept'],
            'answer' => $events['answer'],
            'rst' => $events['rst'],
         ];
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Clients as $Client) {
            $Client->abort();
         }
         foreach ($PIDs as $PID) {
            posix_kill($PID, SIGKILL);
            pcntl_waitpid($PID, $status);
         }
         foreach ($Pipes as $Pipe) {
            if (is_resource($Pipe)) {
               fclose($Pipe);
            }
         }
      }

      yield assert(
         assertion: $Evidence['error'] === ''
            && is_array($Evidence['stalled'])
            && $Evidence['stalled']['port'] > 0
            && $Evidence['stalled']['first'] === '0 Timeout'
            && $Evidence['stalled']['elapsed'] < 3.0
            && $Evidence['stalled']['after'] === [0, 0, 0],
         description: 'a write-stalled connection whose last stream timed out must be closed, never pooled: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: is_array($Evidence['stalled'])
            && $Evidence['stalled']['second'] === '200  ok'
            && $Evidence['stalled']['accept'] === ['1', '2']
            && $Evidence['stalled']['answer'] === ['2:1'],
         description: 'the next request after a stalled close must dial afresh (the peer counts 2 connections): '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: is_array($Evidence['sibling'])
            && $Evidence['sibling']['port'] > 0
            && $Evidence['sibling']['upload'] === '0 Timeout'
            && $Evidence['sibling']['backlog'] > 0
            && $Evidence['sibling']['fast'] === '200  ok'
            && $Evidence['sibling']['after'] === [1, 1, 1]
            && $Evidence['sibling']['follow'] === '200  ok'
            && $Evidence['sibling']['accept'] === ['1']
            && $Evidence['sibling']['answer'] === ['1:3', '1:5']
            && $Evidence['sibling']['rst'] === ['1:1'],
         description: 'a stalled connection that still carries another open stream must stay pooled and be reused: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: is_array($Evidence['drained'])
            && $Evidence['drained']['port'] > 0
            && $Evidence['drained']['slow'] === '0 Timeout'
            && $Evidence['drained']['after'] === [1, 1, 1]
            && $Evidence['drained']['follow'] === '200  ok'
            && $Evidence['drained']['accept'] === ['1']
            && $Evidence['drained']['answer'] === ['1:3']
            && $Evidence['drained']['rst'] === ['1:1'],
         description: 'a drained connection whose only stream timed out must stay pooled and be reused: '
            . json_encode($Evidence)
      );
   }
);
