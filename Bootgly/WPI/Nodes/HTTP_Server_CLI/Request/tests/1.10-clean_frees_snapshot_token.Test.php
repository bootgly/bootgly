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
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies\Refusal;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;


/**
 * A deferred snapshot (`Request::capture()`) hands its worker-ledger token to
 * two holders: the snapshot itself and the `Cookies` of its own head, which
 * charges what the deferred work parses from the `Cookie` field. The head
 * and its `Cookies` refer to each other, so a token the `Cookies` still held
 * after `clean()` lived until a cycle-collector run — and the token and its
 * `Buffers` carry destructors, which make that run a second pass.
 *
 * Driven on decoded snapshots with the cycle collector disabled. Pinned:
 *
 * - the control: the collector counter is live — a collection over a garbage
 *   cycle is counted — so the unchanged count below means no collection ran;
 * - `clean()` drops both edges: the token is gone the moment it returns — the
 *   snapshot itself still alive — with no collector run, after the cookies
 *   were parsed and charged through it;
 * - the Inbound share is back to its baseline after `clean()`, and a later
 *   collection of the dropped snapshot moves it no further (the release is
 *   never credited twice);
 * - a cookie parse after `clean()` is not charged and is never refused, even
 *   with no budget left — while the same parse on a snapshot still in use is
 *   refused (the leg is not vacuous);
 * - only the snapshot's own token is dropped: one the `Cookies` came to hold
 *   in its place is left untouched;
 * - a live Request carrying uploads is cleaned without its cookies ever
 *   being parsed: its head never builds a `Cookies` (`clean()` reads it only
 *   for a snapshot);
 * - a second `clean()` moves nothing.
 */
return new Test(
   description: 'A deferred snapshot frees its ledger token at clean() by refcount and gives the ledger back exactly once',
   test: function () {
      // ! A decoded per-connection Request, its head in strings of its own
      $Decode = static function (string $wire): Request {
         $Connection = new ReflectionClass(Connection::class)->newInstanceWithoutConstructor();
         $Connection->ip = '127.0.0.1';
         $Connection->port = 40110;
         $Connection->encrypted = false;
         $Connection->writes = 1;
         $Package = new class extends TCPPackages {
            // ! decode() only reads the connection truth and the reject slot
            public function __construct () {}

            public function reject (string $raw): void
            {
               throw new RuntimeException("A 1.10 head was rejected: {$raw}");
            }
         };
         $Package->Connection = $Connection;
         $Request = new Request;
         $Request->reset();
         $buffer = $wire;
         $Request->decode($Package, $buffer, strlen($buffer));
         $Request->scrub();

         return $Request;
      };
      $Token = new ReflectionProperty(Request::class, 'Bodies');
      $Jar = new ReflectionProperty(Header::class, 'Cookies');
      $Held = static fn (): int => new ReflectionProperty(Buffers::class, 'held')->getValue()[Shares::Inbound->value];
      $Total = static fn (): int => new ReflectionProperty(Bodies::class, 'total')->getValue();
      $runs = static fn (): int => gc_status()['runs'];
      $wire = "GET /u110 HTTP/1.1\r\nHost: s\r\nCookie: a=1; b=2; c=3\r\n\r\n";
      $parsed = [['a' => '1', 'b' => '2', 'c' => '3']];

      $budget = TCP_Server_CLI::$maxWorkerPendingBytes;
      $collectable = gc_enabled();

      try {
         TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
         // ! No cycle collection from here on: only refcounting can free
         //   what the legs below observe
         gc_disable();

         // @ The control behind the count below: a collection with garbage
         //   to scan is counted, so an unchanged count means none ran — and
         //   what went was freed by refcounting
         $Cycle = new stdClass;
         $Cycle->self = $Cycle;
         $Control = WeakReference::create($Cycle);
         unset($Cycle);
         $before = $runs();
         gc_collect_cycles();
         $counted = ['counted' => $runs() > $before, 'collected' => $Control->get() === null];

         yield assert(
            assertion: $counted === ['counted' => true, 'collected' => true],
            description: 'the collector counter is live: a collection over a garbage cycle is counted — the control that makes the unchanged count below mean no collection ran — observed: '
               . json_encode($counted)
         );
         unset($Control);

         // @@ A + B) The snapshot's token goes at clean(); the ledger is
         //    back and a later collection credits nothing again
         // ! A snapshot still in use holds its own charge throughout, so the
         //   baseline is above zero: a release credited twice would show
         $Anchor = $Decode($wire)->capture();
         $Live = $Decode($wire);
         $held = $Held();
         $total = $Total();
         $Snapshot = $Live->capture();
         // @ The live Request is answered and drops its head
         $Live->shed();
         // @ The deferred work parses its cookies: charged through the token
         $cookies = $Snapshot->cookies;
         $charged = $Held() - $held;
         $Weak = WeakReference::create($Token->getValue($Snapshot));
         $before = $runs();

         $Snapshot->clean();

         $cleaned = [
            'gone' => $Weak->get() === null,
            'runs' => $runs() - $before,
            'held' => $Held() - $held,
            'total' => $Total() - $total,
         ];

         yield assert(
            assertion: $cookies === $parsed
               && $charged > 0
               && $cleaned === ['gone' => true, 'runs' => 0, 'held' => 0, 'total' => 0],
            description: 'clean() frees the snapshot token by refcount — the snapshot still alive, the collector count unchanged (see the control) — after its cookies were charged through it, and the Inbound share is back to its baseline — observed: '
               . json_encode(['cookies' => $cookies, 'charged' => $charged, 'cleaned' => $cleaned])
         );

         // @ The snapshot goes, and a collection runs over what it left
         unset($Snapshot, $Live, $cookies);
         gc_collect_cycles();
         $collected = [
            'held' => $Held() - $held,
            'total' => $Total() - $total,
         ];

         yield assert(
            assertion: $held > 0 && $collected === ['held' => 0, 'total' => 0],
            description: 'a later collection of the dropped snapshot moves the ledger no further: the release is credited once — observed: '
               . json_encode(['baseline' => $held, 'collected' => $collected])
         );
         $Anchor->clean();
         unset($Anchor);

         // @@ C) A cookie parse after clean() is free and never refused —
         //    the same parse on a snapshot still in use is refused
         $Cleaned = $Decode($wire)->capture();
         $Busy = $Decode($wire)->capture();
         $Cleaned->clean();
         $held = $Held();
         $refusals = [];

         // ! No budget left: any charge from here on is refused
         TCP_Server_CLI::$maxWorkerPendingBytes = 0;
         try {
            try {
               $late = $Cleaned->cookies;
            }
            catch (Refusal $Refusal) {
               $late = null;
               $refusals['cleaned'] = $Refusal->getMessage();
            }
            try {
               $Busy->cookies;
               $refusals['busy'] = null;
            }
            catch (Refusal $Refusal) {
               $refusals['busy'] = $Refusal->getMessage();
            }
            $moved = $Held() - $held;
         }
         finally {
            TCP_Server_CLI::$maxWorkerPendingBytes = 64 * 1024 * 1024;
         }
         $Busy->clean();

         yield assert(
            assertion: $late === $parsed
               && isset($refusals['cleaned']) === false
               && $refusals['busy'] === Bodies::REFUSAL
               && $moved === 0,
            description: 'a cookie parse after clean() is neither charged nor refused with no budget left, while a snapshot still in use is refused the same parse — observed: '
               . json_encode(['late' => $late, 'refusals' => $refusals, 'moved' => $moved])
         );
         unset($Cleaned, $Busy, $late);

         // @@ E) Only the snapshot's own token is dropped
         $Snapshot = $Decode($wire)->capture();
         $Foreign = new Bodies;
         $Snapshot->Header->Cookies->Bodies = $Foreign;
         $Own = WeakReference::create($Token->getValue($Snapshot));

         $Snapshot->clean();

         $kept = [
            'foreign' => $Snapshot->Header->Cookies->Bodies === $Foreign,
            'own' => $Own->get() === null,
         ];

         yield assert(
            assertion: $kept === ['foreign' => true, 'own' => true],
            description: 'clean() leaves a token the snapshot Cookies came to hold in place of its own untouched, and still frees its own — observed: '
               . json_encode($kept)
         );
         unset($Snapshot, $Foreign);

         // @@ D) A live Request with uploads: cleaned, its cookies never parsed
         $Live = $Decode("POST /u110 HTTP/1.1\r\nHost: s\r\nCookie: a=1\r\nContent-Length: 0\r\n\r\n");
         // ! An upload record with no temporary path: nothing to reclaim
         $Live->files = ['upload' => ['name' => 'u110.txt', 'error' => 0]];
         $owned = $Token->getValue($Live) !== null;
         $built = $Jar->isInitialized($Live->Header);

         $Live->clean();

         $upload = [
            'owned' => $owned,
            'built' => [$built, $Jar->isInitialized($Live->Header)],
            'files' => [$Live->files, $Live->hasFiles],
         ];

         yield assert(
            assertion: $upload === ['owned' => false, 'built' => [false, false], 'files' => [[], false]],
            description: 'a live Request carrying uploads is cleaned without its head ever building a Cookies — observed: '
               . json_encode($upload)
         );
         unset($Live);

         // @@ G) A second clean() moves nothing
         $Snapshot = $Decode($wire)->capture();
         $Snapshot->cookies;
         $Snapshot->clean();
         $held = $Held();
         $total = $Total();
         $escaped = null;
         try {
            $Snapshot->clean();
         }
         catch (Throwable $Throwable) {
            $escaped = $Throwable->getMessage();
         }
         $twice = [
            'escaped' => $escaped,
            'held' => $Held() - $held,
            'total' => $Total() - $total,
         ];

         yield assert(
            assertion: $twice === ['escaped' => null, 'held' => 0, 'total' => 0],
            description: 'a second clean() of the same snapshot moves nothing — observed: '
               . json_encode($twice)
         );
         unset($Snapshot);
      }
      finally {
         TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
         if ($collectable) {
            gc_enable();
         }

         // ! What the legs left behind — the heads and their Cookies refer
         //   to each other — is reclaimed here, not in a later case
         gc_collect_cycles();
      }
   },
);
