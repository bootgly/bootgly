<?php

use Bootgly\ABI\Events\Selector;
use Bootgly\ABI\Events\Selector\Interests;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Selector: a stream watched for write is reported while its send buffer has room',
   test: function () {
      $Selector = new Selector();
      [$near, $far] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($near, false);
      $id = (int) $near;

      $Selector->watch($near, Interests::Write);

      yield assert(
         assertion: $Selector->writes === [$id => $near] && $Selector->reads === [],
         description: 'watch() lists the stream under write interest only'
      );

      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 1 && $Selector->writable === [$id => $near] && $Selector->readable === [],
         description: 'An idle socket is writable at once'
      );

      // # Fill the send buffer until the kernel refuses more
      $chunk = str_repeat('x', 65536);
      $written = 0;
      for ($i = 0; $i < 4096; $i++) {
         $bytes = @fwrite($near, $chunk);
         if ($bytes === false || $bytes === 0) {
            break;
         }
         $written += $bytes;
      }

      $ready = $Selector->wait(0);
      yield assert(
         assertion: $written > 0 && $ready === 0 && $Selector->writable === [],
         description: 'A full send buffer takes the stream out of the writable set'
      );

      // # The peer drains: room again
      stream_set_blocking($far, false);
      $drained = 0;
      while ($drained < $written) {
         $data = fread($far, 1048576);
         if ($data === false || $data === '') {
            break;
         }
         $drained += strlen($data);
      }

      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 1 && isSet($Selector->writable[$id]),
         description: 'Once the peer drains it, the stream is writable again'
      );

      // # Both interests on one stream
      $Selector->watch($near, Interests::Read);
      fwrite($far, 'pong');
      $ready = $Selector->wait(0);
      yield assert(
         assertion: $ready === 2 && isSet($Selector->readable[$id]) && isSet($Selector->writable[$id]),
         description: 'A stream watched for both interests is counted once per ready interest'
      );

      fclose($near);
      fclose($far);
   }
);
