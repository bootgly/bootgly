<?php

use Bootgly\ABI\Events\Selector;
use Bootgly\ABI\Events\Selector\Interests;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Selector: a stream watched for read is reported once it has data, or its peer closed',
   test: function () {
      $Selector = new Selector();
      [$near, $far] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $id = (int) $near;

      $Selector->watch($near, Interests::Read);

      yield assert(
         assertion: $Selector->reads === [$id => $near] && $Selector->writes === [],
         description: 'watch() lists the stream under read interest only, by its id'
      );

      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 0 && $Selector->readable === [] && $Selector->interrupted === false,
         description: 'Nothing to read: the wait reports no ready stream'
      );

      fwrite($far, 'ping');
      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 1 && $Selector->readable === [$id => $near] && $Selector->writable === [],
         description: 'Data arrived: the stream is in the readable set, keyed by its id'
      );

      // # Level-triggered: unread data keeps the stream ready
      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 1,
         description: 'Unread data keeps the stream readable on the next wait'
      );

      fread($near, 16);
      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 0 && $Selector->readable === [],
         description: 'Once drained, the stream leaves the readable set'
      );

      // # End of stream is read readiness
      fclose($far);
      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 1 && isSet($Selector->readable[$id]) && fread($near, 16) === '',
         description: 'A peer that closed makes the stream readable (the read then returns nothing)'
      );

      fclose($near);
   }
);
