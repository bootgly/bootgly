<?php

use Bootgly\ABI\Events\Selector;
use Bootgly\ABI\Events\Selector\Reasons;
use Bootgly\ABI\Events\Selector\Refusal;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Selector: a stream or a wait it cannot serve is refused by name, never hung or spun on',
   test: function () {
      $refuse = function (Closure $Action): null|Refusal {
         try {
            $Action();
         }
         catch (Refusal $Refusal) {
            return $Refusal;
         }

         return null;
      };

      $Selector = new Selector();
      [$near, $far] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

      // # watch(): not an open stream
      [$closed, $closedPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      fclose($closed);

      $Refusal = $refuse(fn () => $Selector->watch($closed));
      yield assert(
         assertion: $Refusal?->Reason === Reasons::Invalid && $Refusal->streams === [],
         description: 'A closed stream is refused as Invalid'
      );

      $Refusal = $refuse(fn () => $Selector->watch('php://stdin'));
      yield assert(
         assertion: $Refusal?->Reason === Reasons::Invalid && str_contains($Refusal->getMessage(), 'string'),
         description: 'A value that is not a resource is refused as Invalid, naming its type'
      );

      $Refusal = $refuse(fn () => $Selector->watch(stream_context_create()));
      yield assert(
         assertion: $Refusal?->Reason === Reasons::Invalid,
         description: 'A resource that is not a stream is refused as Invalid'
      );

      // # watch(): a stream without a descriptor
      $memory = fopen('php://memory', 'r+');
      $Refusal = $refuse(fn () => $Selector->watch($memory));
      yield assert(
         assertion: $Refusal?->Reason === Reasons::Unselectable && $Refusal->streams === [(int) $memory],
         description: 'A stream with no descriptor is refused as Unselectable, naming its id'
      );
      yield assert(
         assertion: $Selector->reads === [] && $Selector->writes === [],
         description: 'A refused stream is never admitted'
      );

      // # wait(): nothing watched and no deadline
      $Refusal = $refuse(fn () => $Selector->wait());
      yield assert(
         assertion: $Refusal?->Reason === Reasons::Empty,
         description: 'An indefinite wait on nothing is refused as Empty instead of hanging'
      );

      // # wait(): streams closed while still watched
      [$stale, $stalePeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $Selector->watch($near, payload: 'kept');
      $Selector->watch($stale, payload: 'stale');
      $staleID = (int) $stale;
      fwrite($far, 'x');
      fclose($stale);

      $Refusal = $refuse(fn () => $Selector->wait(0));
      yield assert(
         assertion: $Refusal?->Reason === Reasons::Closed && $Refusal->streams === [$staleID],
         description: 'A stream closed without forget() refuses the wait as Closed, naming its id'
      );
      yield assert(
         assertion: array_keys($Selector->reads) === [(int) $near],
         description: 'The closed stream was dropped; the others stay watched'
      );

      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 1 && $Selector->readable === [(int) $near => 'kept'],
         description: 'The next wait runs on what is left — no repeated refusal, no spin'
      );

      // # ...and with a deadline the refusal is immediate, not a hang
      [$late, $latePeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      fread($near, 16);
      $Selector->watch($late);
      fclose($late);

      $started = hrtime(true);
      $Refusal = $refuse(fn () => $Selector->wait($Selector->Clock->tick + 2_000_000_000));
      $elapsed = hrtime(true) - $started;
      yield assert(
         assertion: $Refusal?->Reason === Reasons::Closed && $elapsed < 500_000_000,
         description: 'A blocking wait on a set with a closed stream is refused at once'
      );

      foreach ([$near, $far, $closedPeer, $stalePeer, $latePeer, $memory] as $stream) {
         fclose($stream);
      }
   }
);
