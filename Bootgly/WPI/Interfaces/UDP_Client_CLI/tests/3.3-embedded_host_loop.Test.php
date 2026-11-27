<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use const STREAM_SERVER_BIND;
use function assert;
use function explode;
use function fclose;
use function getrusage;
use function hrtime;
use function is_resource;
use function json_encode;
use function max;
use function microtime;
use function stream_select;
use function stream_socket_get_name;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function stream_socket_server;
use function usleep;
use Closure;
use Fiber;
use LogicException;
use ReflectionProperty;
use Throwable;

use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections\Connection;


/**
 * An embedded client inside a server worker shares the worker's loop: a
 * Fiber whose datagram wait parks through the wait bridge leaves the loop
 * serving its other descriptors (another flow's datagrams are dispatched
 * while the wait is parked), and wakes on its own datagram. A parked wait the
 * peer never answers expires at its bound while the loop keeps running. An
 * adopted reactor never blocks: a wait without the bridge or outside a Fiber
 * is refused by name.
 */
return new Test(
   description: 'UDP_Client_CLI: an embedded datagram wait parks on a host loop that keeps serving',
   test: function () {
      $Peer = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      $Clients = [];
      // ! The worker's wait bridge: suspend the calling Fiber with what it waits on
      $Bridge = static fn (mixed $value = null): mixed => Fiber::suspend($value);
      // ! A server worker's reactor
      $Worker = static fn (): Select => new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST)->Event;

      try {
         yield assert(
            assertion: is_resource($Peer),
            description: 'the loopback peer is bound'
         );
         if (is_resource($Peer) === false) {
            return;
         }
         [$host, $port] = explode(':', (string) stream_socket_get_name($Peer, false));
         // ! An embedded client on the worker's loop, one flow, its address known to the peer
         $dial = function (Select $Loop, null|Closure $Wait) use (&$Clients, $host, $port, $Peer): array {
            $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
            $Client->react($Loop);
            if ($Wait !== null) {
               $Client->schedule($Wait);
            }
            $Client->configure(new Configs(host: $host, port: (int) $port));
            $Clients[] = $Client;
            $Socket = $Client->connect();
            /** @var Connection $Flow */
            $Flow = $Client->Connections->Flows[(int) $Socket];
            $Flow->output = 'hello';
            $Flow->writing($Socket);
            $read = [$Peer];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 1);
            stream_socket_recvfrom($Peer, 100, 0, $address);

            return [$Client, $Flow, $address];
         };

         // # A parked wait while the loop serves another descriptor
         $Loop = $Worker();
         [$Waiting, $FlowA, $addressA] = $dial($Loop, $Bridge);
         [$Serving, $FlowB, $addressB] = $dial($Loop, null);
         $timeline = [];
         $Serving->on(Events::DatagramRead, static function ($Socket, Connection $Flow) use (&$timeline): void {
            $timeline[] = "served:{$Flow->input}";
         });
         // ! Flow B is a persistent reader of the worker's loop
         $Loop->add($FlowB->Socket, Select::EVENT_READ, $FlowB);

         $Outcome = null;
         $elapsed = null;
         $Fiber = new Fiber(function () use ($FlowA, &$Outcome, &$elapsed, &$timeline): void {
            $started = hrtime(true);
            $Outcome = $FlowA->receive(2.0);
            $elapsed = (hrtime(true) - $started) / 1e9;
            $timeline[] = "received:{$FlowA->input}";
         });
         $Loop->schedule($Fiber, $Fiber->start());
         $now = microtime(true);
         foreach ([[0.05, $addressB, 'B-1'], [0.10, $addressB, 'B-2'], [0.30, $addressA, 'A-1']] as [$at, $to, $answer]) {
            $Loop->defer($now + $at, static function () use ($Peer, $to, $answer): void {
               stream_socket_sendto($Peer, $answer, 0, $to);
            });
         }
         // @ Fail-closed bound: a lost wakeup ends the loop, never hangs the suite
         $Loop->defer($now + 0.6, static function () use ($Loop): void {
            $Loop->destroy();
         });
         $Loop->loop();

         yield assert(
            assertion: $Outcome === Outcomes::Received
               && $timeline === ['served:B-1', 'served:B-2', 'received:A-1']
               && $elapsed !== null && $elapsed >= 0.25 && $elapsed < 0.55,
            description: "the parked wait let the loop serve another flow first, then woke on its own datagram ({$elapsed}s): "
               . json_encode($timeline)
         );

         // # A parked wait the peer never answers expires at its bound
         $Loop = $Worker();
         [$Silent, $FlowS] = $dial($Loop, $Bridge);
         $Outcome = null;
         $elapsed = null;
         $ticked = 0;
         $Fiber = new Fiber(function () use ($FlowS, &$Outcome, &$elapsed): void {
            $started = hrtime(true);
            $Outcome = $FlowS->receive(0.3);
            $elapsed = (hrtime(true) - $started) / 1e9;
         });
         $Loop->schedule($Fiber, $Fiber->start());
         $now = microtime(true);
         $Loop->repeat(0.05, static function () use (&$ticked): void {
            $ticked++;
         });
         $Loop->defer($now + 0.6, static function () use ($Loop): void {
            $Loop->destroy();
         });
         $Loop->loop();

         yield assert(
            assertion: $Outcome === Outcomes::Expired
               && $elapsed !== null && $elapsed >= 0.29 && $elapsed < 0.55
               && $ticked >= 4
               && is_resource($FlowS->Socket)
               && $Silent->Connections->Flows !== [],
            description: "an unanswered parked wait expires at its bound ({$elapsed}s) while the loop keeps ticking ({$ticked}), the flow left open for the next attempt"
         );

         // # A parked wait longer than one slice re-parks until its bound, sleeping
         $Loop = $Worker();
         $slices = 0;
         $longest = 0.0;
         [, $FlowL] = $dial($Loop, static function (mixed $value = null) use (&$slices, &$longest): mixed {
            $slices++;
            // ! How far ahead this slice's reactor deadline lies
            if ($value instanceof Readiness) {
               $longest = max($longest, $value->deadline - microtime(true));
            }

            return Fiber::suspend($value);
         });
         $Outcome = null;
         $elapsed = null;
         $spent = null;
         $Fiber = new Fiber(function () use ($FlowL, &$Outcome, &$elapsed, &$spent): void {
            $Usage = getrusage();
            $baseline = $Usage['ru_utime.tv_sec'] + $Usage['ru_utime.tv_usec'] / 1e6
               + $Usage['ru_stime.tv_sec'] + $Usage['ru_stime.tv_usec'] / 1e6;
            $started = hrtime(true);
            $Outcome = $FlowL->receive(1.5);
            $elapsed = (hrtime(true) - $started) / 1e9;
            $Usage = getrusage();
            $spent = $Usage['ru_utime.tv_sec'] + $Usage['ru_utime.tv_usec'] / 1e6
               + $Usage['ru_stime.tv_sec'] + $Usage['ru_stime.tv_usec'] / 1e6 - $baseline;
         });
         $Loop->schedule($Fiber, $Fiber->start());
         $Loop->defer(microtime(true) + 2.5, static function () use ($Loop): void {
            $Loop->destroy();
         });
         $Loop->loop();
         yield assert(
            assertion: $Outcome === Outcomes::Expired
               && $elapsed !== null && $elapsed >= 1.45 && $elapsed < 2.0
               && $spent !== null && $spent < 0.05
               && $slices === 2 && $longest > 0.4 && $longest <= 1.0,
            description: "a parked wait parks in two slices of at most 1 s ({$slices}, the longest {$longest}s) and expires at its bound ({$elapsed}s, {$spent}s of CPU)"
         );

         // # A reactor that cannot watch the socket rejects the wait by name
         $Loop = $Worker();
         [$Crowded, $FlowR] = $dial($Loop, $Bridge);
         // ! The worker's read table at its capacity
         $Reads = new ReflectionProperty(Select::class, 'reads');
         $Fill = [];
         for ($i = 0; $i < Select::CAPACITY; $i++) {
            $Fill[1_000_000 + $i] = $Peer;
         }
         $Reads->setValue($Loop, $Fill);
         $Outcome = null;
         $Fiber = new Fiber(function () use ($FlowR, &$Outcome): void {
            $Outcome = $FlowR->receive(1.0);
         });
         $started = hrtime(true);
         $Loop->schedule($Fiber, $Fiber->start());
         $rejected = (hrtime(true) - $started) / 1e9;
         $Reads->setValue($Loop, []);
         yield assert(
            assertion: $Outcome === Outcomes::Rejected && $rejected < 0.1
               && is_resource($FlowR->Socket) && $Crowded->Connections->Flows !== [],
            description: "a wait the reactor cannot admit ends Rejected at once ({$rejected}s), the flow left open for the caller"
         );

         // # Slow wakes between fast ones reset the tripwire
         $calls = 0;
         [, $FlowT] = $dial($Worker(), static function () use (&$calls): mixed {
            // ! Seven instant returns, then one that took real time
            if (++$calls % 7 === 0) {
               usleep(300);
            }

            return null;
         });
         $Outcome = null;
         $Fiber = new Fiber(function () use ($FlowT, &$Outcome): void {
            $Outcome = $FlowT->receive(0.05);
         });
         $Fiber->start();
         yield assert(
            assertion: $Outcome === Outcomes::Expired && $calls > 8,
            description: "fast wakes broken by slow ones never trip the wait: it expires at its bound ({$calls} wakes)"
         );

         // # An adopted reactor never blocks
         $Loop = $Worker();
         [, $FlowN] = $dial($Loop, null);
         $refusals = [];
         $Fiber = new Fiber(function () use ($FlowN, &$refusals): void {
            try {
               $FlowN->receive(1.0);
            }
            catch (LogicException) {
               $refusals[] = 'no bridge';
            }
         });
         $Fiber->start();
         [, $FlowF] = $dial($Loop, $Bridge);
         $started = hrtime(true);
         try {
            $FlowF->receive(1.0);
         }
         catch (LogicException) {
            $refusals[] = 'no Fiber';
         }
         $refused = (hrtime(true) - $started) / 1e9;
         yield assert(
            assertion: $refusals === ['no bridge', 'no Fiber'] && $refused < 0.05,
            description: 'a wait on an adopted reactor without the bridge, or outside a Fiber, is refused at once: '
               . json_encode($refusals)
         );
      }
      finally {
         // @ Cleanup
         foreach ($Clients as $Client) {
            try {
               $Client->stop();
            }
            catch (Throwable) {
               // ...
            }
         }
         if (is_resource($Peer)) {
            fclose($Peer);
         }
      }
   }
);
