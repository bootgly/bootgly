<?php

use Bootgly\ABI\Events\Selector;
use Bootgly\ABI\Events\Selector\Interests;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Selector: forget() stops watching a stream, for one interest or for both',
   test: function () {
      $Selector = new Selector();
      [$near, $far] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      [$other, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $id = (int) $near;

      yield assert(
         assertion: $Selector->forget($near) === false,
         description: 'Forgetting a stream that is not watched answers false'
      );

      // # One interest
      $Selector->watch($near, Interests::Read);
      $Selector->watch($near, Interests::Write);

      yield assert(
         assertion: $Selector->forget($id) === false
            && $Selector->forget((string) $id) === false
            && isSet($Selector->reads[$id])
            && isSet($Selector->writes[$id]),
         description: 'Only the stream itself is forgotten — never a value that merely equals its id'
      );
      fwrite($far, 'x');

      yield assert(
         assertion: $Selector->forget($near, Interests::Write) === true
            && $Selector->writes === []
            && isSet($Selector->reads[$id]),
         description: 'Forgetting one interest keeps the other'
      );
      yield assert(
         assertion: $Selector->forget($near, Interests::Write) === false,
         description: 'Forgetting an interest the stream is not watched for answers false'
      );

      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 1 && isSet($Selector->readable[$id]) && $Selector->writable === [],
         description: 'Only the remaining interest is reported'
      );

      // # Both interests
      $Selector->watch($near, Interests::Write);
      $Selector->watch($other, Interests::Read);
      fwrite($peer, 'y');

      yield assert(
         assertion: $Selector->forget($near) === true && count($Selector->reads) === 1 && $Selector->writes === [],
         description: 'Forgetting without an interest drops both'
      );

      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 1 && array_keys($Selector->readable) === [(int) $other],
         description: 'A forgotten stream is never reported again, even with data pending'
      );

      // # A stream closed first can still be forgotten
      fclose($other);
      yield assert(
         assertion: $Selector->forget($other) === true && $Selector->reads === [],
         description: 'A closed stream is forgotten by the value given to watch()'
      );

      $Selector->watch($near);
      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 1,
         description: 'After the closed stream is forgotten, the next wait is not refused'
      );

      fclose($near);
      fclose($far);
      fclose($peer);
   }
);
