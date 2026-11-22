<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function fread;
use function fwrite;
use function microtime;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_pair;
use RuntimeException;

use Bootgly\ACI\Events\Readiness;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * Rendezvous between a spec (the test process) and one deferral (the worker).
 *
 * Built when the spec file loads — before the server forks — so both
 * processes inherit the socket pair. The worker's deferral calls `hold()`:
 * it tells the spec it parked, then suspends until the spec `release()`s it
 * (bounded). The spec `expect()`s the park before it sends the request that
 * must run while the deferral is suspended — no timing guess decides the
 * interleaving.
 */
final class Barrier
{
   // * Metadata
   /** @var resource Worker end: written on park, read on release. */
   private mixed $Inner;
   /** @var resource Spec end: read on park, written on release. */
   private mixed $Outer;


   public function __construct ()
   {
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($Pair === false) {
         throw new RuntimeException('Deferred fixture could not allocate the barrier pair.');
      }

      // * Metadata
      [$this->Inner, $this->Outer] = $Pair;
   }

   /**
    * Worker side: report the park, then suspend the deferral until the
    * spec releases it or `$seconds` pass.
    */
   public function hold (Response $Response, float $seconds = 4.0): void
   {
      $Inner = $this->Inner;
      stream_set_blocking($Inner, false);
      // ! Drop a release left by an earlier hold that timed out
      fread($Inner, 64);
      fwrite($Inner, 'P');

      $Response->wait(Readiness::read($Inner, microtime(true) + $seconds));

      // @ Consume the release byte (absent after a timeout)
      fread($Inner, 1);
   }

   /**
    * Spec side: wait until a deferral reports its park.
    */
   public function expect (float $seconds = 4.0): bool
   {
      $Outer = $this->Outer;
      stream_set_blocking($Outer, true);
      stream_set_timeout($Outer, (int) $seconds, (int) (($seconds - (int) $seconds) * 1_000_000));

      return fread($Outer, 1) === 'P';
   }

   /**
    * Spec side: release the parked deferral.
    */
   public function release (): void
   {
      fwrite($this->Outer, 'R');
   }
}
