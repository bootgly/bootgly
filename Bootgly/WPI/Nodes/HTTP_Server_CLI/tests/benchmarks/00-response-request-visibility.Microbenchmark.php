<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Benchmark\Microbenchmark;
use Bootgly\ACI\Tests\Benchmark\Microbenchmark\Comparison;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

// ! Stand-ins with the exact slot shape of Response (six typed object slots):
//   A keeps the property private, B makes it publicly readable. The store in
//   reset() and the in-class read are the opcodes the hot path executes once
//   per request; the delta between A and B is the engine cost of asymmetric
//   visibility, isolated from everything else reset() does.
if (! class_exists('BG15PrivateHolder', false)) {
   class BG15PrivateHolder
   {
      private null|object $Package = null;
      private null|Request $Request = null;
      private null|object $Router = null;
      private null|object $Route = null;
      private null|object $Exchange = null;
      private null|object $Cancellation = null;

      public function reset (Request $Request): void
      {
         $this->Request = $Request;
      }

      public function read (): null|Request
      {
         return $this->Request;
      }
   }

   class BG15AsymmetricHolder
   {
      private null|object $Package = null;
      public private(set) null|Request $Request = null;
      private null|object $Router = null;
      private null|object $Route = null;
      private null|object $Exchange = null;
      private null|object $Cancellation = null;

      public function reset (Request $Request): void
      {
         $this->Request = $Request;
      }

      public function read (): null|Request
      {
         return $this->Request;
      }
   }

   final class BG15Connection extends Connection
   {
      /** @param resource $Socket */
      public function __construct (mixed &$Socket)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = 12345;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 1;
      }

      public function close (): true
      {
         $this->status = Connections::STATUS_CLOSED;

         return true;
      }
   }
}

return new Microbenchmark(
   title: 'Response::$Request — private vs public private(set) on the per-request store/read',

   description: <<<TEXT
   BG-15 exposes the request a Response answers. Does promoting the property
   from `private` to `public private(set)` cost anything on the one store
   `Response::reset()` performs per request, or on the in-class reads?
   TEXT,

   inputs: [
      'reads' => 10,
   ],

   Gate: static function (array $inputs): bool {
      $Request = new Request;
      $A = new BG15PrivateHolder;
      $B = new BG15AsymmetricHolder;
      $A->reset($Request);
      $B->reset($Request);

      return $A->read() === $Request && $B->read() === $Request && $B->Request === $Request;
   },

   Comparisons: static function (array $inputs): array {
      $reads = (int) $inputs['reads'];
      $Request = new Request;
      $A = new BG15PrivateHolder;
      $B = new BG15AsymmetricHolder;

      // ! The real class, driven exactly as the encoder drives it per request
      $Socket = fopen('php://memory', 'w+');
      $Connection = new BG15Connection($Socket);
      $Package = new class($Connection) extends TCPPackages {};
      $Response = new Response;
      $Live = new Request;
      // ! In-class read (the scope every production read has today)
      $read = Closure::bind(static fn (Response $Response): null|Request => $Response->Request, null, Response::class);

      return [
         new Comparison(
            name: 'stand-ins with the Response slot shape',
            Cases: [
               'A private — reset() store' => static function () use ($A, $Request): void {
                  $A->reset($Request);
               },
               'B private(set) — reset() store' => static function () use ($B, $Request): void {
                  $B->reset($Request);
               },
               "A private — {$reads} in-class reads" => static function () use ($A, $reads): mixed {
                  $Seen = null;
                  for ($i = 0; $i < $reads; $i++) {
                     $Seen = $A->read();
                  }

                  return $Seen;
               },
               "B private(set) — {$reads} in-class reads" => static function () use ($B, $reads): mixed {
                  $Seen = null;
                  for ($i = 0; $i < $reads; $i++) {
                     $Seen = $B->read();
                  }

                  return $Seen;
               },
               "B private(set) — {$reads} public reads from outside" => static function () use ($B, $reads): mixed {
                  $Seen = null;
                  for ($i = 0; $i < $reads; $i++) {
                     $Seen = $B->Request;
                  }

                  return $Seen;
               },
            ],
            baseline: 'A private — reset() store',
            recommendation: 'decided by the delta: < 2 % of the 18.4 µs per-request budget keeps the property',
            verdict: 'The asymmetric-visibility check is a flag test on an already-typed store; reads are '
               . 'plain public-property fetches on the cached slot.',
         ),
         new Comparison(
            name: 'the real class: Response::reset() + in-class reads (run on both trees)',
            Cases: [
               "Response::reset() + {$reads} reads" => static function () use ($Response, $Package, $Live, $read, $reads): mixed {
                  $Response->reset($Package, $Live);
                  $Seen = null;
                  for ($i = 0; $i < $reads; $i++) {
                     $Seen = $read($Response);
                  }

                  return $Seen;
               },
            ],
            baseline: "Response::reset() + {$reads} reads",
            recommendation: 'compare this row between the baseline tree and the patched tree',
            verdict: 'Everything reset() does besides the one store is identical on both trees; a difference '
               . 'beyond the run-to-run spread is the promotion.',
         ),
      ];
   },
);
