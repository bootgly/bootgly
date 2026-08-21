<?php

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ABI\Data\RESP\Decoder\Push;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'RESP\Decoder: RESP3 push frames keep their type and their place in the stream',
   test: function () {
      // # A push carries its items and is not an array
      //   The reader cannot recover the type afterwards: a push decodes to
      //   exactly the array a `*` of the same shape would.
      $replies = new Decoder()->decode(">2\r\n\$10\r\ninvalidate\r\n*1\r\n\$6\r\npoc:k2\r\n");

      yield assert(
         assertion: count($replies) === 1
            && $replies[0] instanceof Push
            && $replies[0]->items === ['invalidate', ['poc:k2']],
         description: 'A push frame decodes to a Push carrying its items'
      );

      yield assert(
         assertion: new Decoder()->decode("*2\r\n\$1\r\na\r\n\$1\r\nb\r\n") === [['a', 'b']]
            && new Decoder()->decode("~2\r\n\$1\r\na\r\n\$1\r\nb\r\n") === [['a', 'b']],
         description: 'Array and set replies still decode to plain arrays'
      );

      // # Order is preserved
      //   Removing pushes from the returned stream would destroy the ordering
      //   the reader needs: under RESP3 a subscribe confirmation is a push AND
      //   the answer to the command that asked for it.
      $replies = new Decoder()->decode(
         "+FIRST\r\n>3\r\n\$7\r\nmessage\r\n\$4\r\nchan\r\n\$4\r\nbody\r\n+LAST\r\n"
      );

      yield assert(
         assertion: count($replies) === 3
            && $replies[0] === 'FIRST'
            && $replies[1] instanceof Push
            && $replies[1]->items === ['message', 'chan', 'body']
            && $replies[2] === 'LAST',
         description: 'A push keeps its position among the replies around it'
      );

      // # A partial push stays buffered until its bytes arrive
      $Decoder = new Decoder();
      $partial = $Decoder->decode(">3\r\n\$9\r\nsubscribe\r\n");
      $completed = $Decoder->decode("\$4\r\nchan\r\n:1\r\n");

      yield assert(
         assertion: $partial === []
            && count($completed) === 1
            && $completed[0] instanceof Push
            && $completed[0]->items === ['subscribe', 'chan', 1],
         description: 'A push split across reads is held until it is whole'
      );

      // # A null push is a null, not an empty frame
      yield assert(
         assertion: new Decoder()->decode(">-1\r\n") === [null],
         description: 'A negative push count decodes to null, unwrapped'
      );
   }
);
