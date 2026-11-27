<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Tests\Cancel;


use function assert;
use function count;
use function json_encode;
use function microtime;
use Fiber;
use Throwable;

use Bootgly\ABI\Data\DNS;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Nodes\DNS_Client_CLI;
use Bootgly\WPI\Nodes\DNS_Client_CLI\tests\fixtures\Harness;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\HTTP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;


return new Test(
   description: 'DNS_Client_CLI under a dial: a deferral timeout thrown into the parked lookup unwinds the dial',
   test: function () {
      $Harness = Harness::start('dns-dial-cancel');
      $NS = $Harness->Nameserver;

      try {
         // ! The nameserver holds its answers 2 seconds
         $NS->script('held.test', DNS::TYPE_A, ['answers' => [['held.test', DNS::TYPE_A, 60, '127.0.0.1']], 'delay' => 2.0]);
         $NS->script('held.test', DNS::TYPE_AAAA, ['answers' => [], 'delay' => 2.0]);
         $DNS = new DNS_Client_CLI()->configure($Harness->configure(['timeout' => 3, 'hostname' => 'cancel.example']));

         $Caught = null;
         $took = 0.0;
         $Parked = null;
         $resumed = false;
         Harness::run([
            static function () use (&$Upstream, &$Caught, &$took, &$Parked, &$resumed): void {
               $Parked = Fiber::getCurrent();
               // ! A live deferred context, as defer() opens one
               $Token = Cancellation::open($Parked);
               $start = microtime(true);
               try {
                  $Upstream->request('GET', '/');
                  $resumed = true;
               }
               catch (Throwable $Throwable) {
                  $Caught = $Throwable;
               }
               $took = microtime(true) - $start;
               $Token->finish();
            },
         ], 4.0, static function (Select $Loop) use (&$Upstream, $DNS, &$Parked): void {
            // ! The resource on this loop, resolving through the node, parking on the loop
            $Upstream = new HTTP(host: 'held.test', port: 9, connectTimeout: 3, timeout: 3);
            $Upstream->Client->Resolution = $DNS->resolve(...);
            $Upstream->schedule(static fn (mixed $value = null): mixed => Fiber::suspend($value));

            // ! The deferral's budget ends while the lookup is parked
            $Loop->defer(microtime(true) + 0.3, static function () use (&$Parked): void {
               if ($Parked instanceof Fiber && $Parked->isSuspended()) {
                  $Parked->throw(new Timeout(0.3));
               }
            });
         });

         $asked = count($NS->log());
         yield assert(
            assertion: $Caught instanceof Timeout && $resumed === false && $took >= 0.25 && $took < 1.0 && $asked === 2,
            description: 'the Timeout reaches the deferral from inside the lookup: '
               . json_encode([$Caught === null ? null : [$Caught::class, $Caught->getMessage()], $resumed, $took, $asked])
         );
      }
      finally {
         $Harness->stop();
      }
   }
);
