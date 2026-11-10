<?php

use Bootgly\ABI\Events\Selector;
use Bootgly\ABI\Events\Selector\Reasons;
use Bootgly\ABI\Events\Selector\Refusal;
use Bootgly\ACI\Tests\Suite\Test;


// ! Descriptors this case needs the process to be allowed to open: enough to
//   pass the default select() bound (FD_SETSIZE, 1024).
const SELECTOR_BOUND_DESCRIPTORS = 1200;

$limits = function_exists('posix_getrlimit') ? posix_getrlimit() : [];
$hard = $limits['hard openfiles'] ?? 0;
$reachable = $hard === 'unlimited' || (int) $hard >= SELECTOR_BOUND_DESCRIPTORS;

return new Test(
   description: 'Selector: a descriptor beyond the select() bound is refused at watch(), not at wait()',
   skip: $reachable === false,
   test: function () {
      // ! Let the process open enough descriptors, then give the limit back
      $limits = posix_getrlimit();
      $soft = $limits['soft openfiles'];
      $hard = $limits['hard openfiles'];
      $raised = false;
      if ($soft !== 'unlimited' && (int) $soft < SELECTOR_BOUND_DESCRIPTORS) {
         $raised = posix_setrlimit(
            POSIX_RLIMIT_NOFILE,
            SELECTOR_BOUND_DESCRIPTORS,
            $hard === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $hard
         );
      }

      $Selector = new Selector();
      [$low, $lowPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $Selector->watch($low);

      // @ Hold descriptors until the next stream lands beyond the bound
      $fillers = [];
      $Refusal = null;
      $high = null;
      $highPeer = null;
      try {
         for ($i = 0; $i < SELECTOR_BOUND_DESCRIPTORS - 64; $i++) {
            $filler = @fopen('/dev/null', 'r');
            if ($filler === false) {
               break;
            }
            $fillers[] = $filler;
         }

         $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($pair !== false) {
            [$high, $highPeer] = $pair;

            try {
               $Selector->watch($high);
            }
            catch (Refusal $Caught) {
               $Refusal = $Caught;
            }
         }
      }
      finally {
         foreach ($fillers as $filler) {
            fclose($filler);
         }
         if ($raised) {
            posix_setrlimit(
               POSIX_RLIMIT_NOFILE,
               (int) $soft,
               $hard === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $hard
            );
         }
      }

      yield assert(
         assertion: count($fillers) >= 1024 && $high !== null,
         description: 'The process holds enough descriptors to pass the select() bound'
      );
      yield assert(
         assertion: $Refusal?->Reason === Reasons::Overflow && $Refusal->streams === [(int) $high],
         description: 'A stream whose descriptor is beyond the bound is refused as Overflow at watch()'
      );
      yield assert(
         assertion: array_keys($Selector->reads) === [(int) $low],
         description: 'The refused stream is not admitted; the set stays waitable'
      );

      fwrite($lowPeer, 'x');
      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 1,
         description: 'wait() still serves the streams admitted before'
      );

      foreach ([$low, $lowPeer, $high, $highPeer] as $stream) {
         if (is_resource($stream)) {
            fclose($stream);
         }
      }
   }
);
