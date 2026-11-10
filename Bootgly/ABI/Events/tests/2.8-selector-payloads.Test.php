<?php

use Bootgly\ABI\Events\Selector;
use Bootgly\ABI\Events\Selector\Interests;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Selector: the ready sets carry the payload each stream was watched with',
   test: function () {
      $Selector = new Selector();
      [$a, $aPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      [$b, $bPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      [$c, $cPeer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

      $Reader = new stdClass();
      $Writer = new stdClass();

      $Selector->watch($a, Interests::Read, $Reader);
      $Selector->watch($a, Interests::Write, $Writer);
      $Selector->watch($b, Interests::Read, 'second');
      $Selector->watch($c);

      fwrite($aPeer, 'x');
      fwrite($bPeer, 'x');
      fwrite($cPeer, 'x');
      $ready = $Selector->wait(0);

      yield assert(
         assertion: $ready === 4,
         description: 'Three readable streams and one writable one are counted'
      );
      yield assert(
         assertion: $Selector->readable[(int) $a] === $Reader && $Selector->writable[(int) $a] === $Writer,
         description: 'One stream carries a separate payload per interest'
      );
      yield assert(
         assertion: $Selector->readable[(int) $b] === 'second',
         description: 'Each stream maps to its own payload'
      );
      yield assert(
         assertion: $Selector->readable[(int) $c] === $c,
         description: 'Without a payload, the ready set carries the stream itself'
      );
      yield assert(
         assertion: array_keys($Selector->readable) === [(int) $a, (int) $b, (int) $c],
         description: 'The ready set is keyed by stream id, in registration order'
      );

      // # Watching again replaces the payload
      $Selector->watch($b, Interests::Read, 'replaced');
      $Selector->wait(0);
      yield assert(
         assertion: $Selector->readable[(int) $b] === 'replaced' && count($Selector->reads) === 3,
         description: 'Watching a stream again for the same interest replaces its payload'
      );

      // # The sets are the last wait's answer only
      fread($a, 16);
      fread($b, 16);
      fread($c, 16);
      $Selector->forget($a, Interests::Write);
      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 0 && $Selector->readable === [] && $Selector->writable === [],
         description: 'Each wait starts from empty ready sets'
      );

      foreach ([$a, $aPeer, $b, $bPeer, $c, $cPeer] as $stream) {
         fclose($stream);
      }
   }
);
