<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response\Decoders\Decoder_Waiting;


// ? H-HCLI-4 — once the head is parsed, a Content-Length or close-delimited body is
//   collected read by read: each read appends only its own body bytes, a pipelined
//   tail is handed back as leftover, and whatever never completed is drained once.
return new Test(
   description: 'It should collect a waiting body across reads without re-reading it',
   test: function () {
      // # Content-Length fed across several reads
      $Decoder = new Decoder_Waiting(10, 'ab');
      $first = $Decoder->decode('cde', 3);
      $second = $Decoder->decode('fgh', 3);
      $last = $Decoder->decode('ij', 2);

      yield assert(
         assertion: $first === null && $second === null
            && $last === ['complete' => true, 'body' => 'abcdefghij', 'bodyLength' => 10, 'consumed' => 2, 'leftover' => ''],
         description: 'a sized body completes on its last byte, seed included: ' . json_encode($last)
      );

      // # Completion with a pipelined tail in the same read
      $Decoder = new Decoder_Waiting(5, 'ab');
      $done = $Decoder->decode("cdeHTTP/1.1 200 OK\r\n", 20);

      yield assert(
         assertion: $done !== null && $done['body'] === 'abcde' && $done['consumed'] === 3
            && $done['leftover'] === "HTTP/1.1 200 OK\r\n",
         description: 'bytes past the declared length are the next response: ' . json_encode($done)
      );

      // # Completion with the tail arriving in a later read
      $Decoder = new Decoder_Waiting(6);
      $none = $Decoder->decode('abc', 3);
      $done = $Decoder->decode('defNEXT', 7);

      yield assert(
         assertion: $none === null && $done !== null && $done['body'] === 'abcdef' && $done['leftover'] === 'NEXT',
         description: 'a later read splits body and tail once: ' . json_encode($done)
      );

      // # A read ending exactly on the declared length
      $Decoder = new Decoder_Waiting(4, 'a');
      $done = $Decoder->decode('bcd', 3);

      yield assert(
         assertion: $done !== null && $done['body'] === 'abcd' && $done['leftover'] === '' && $done['consumed'] === 3,
         description: 'an exact read leaves no tail: ' . json_encode($done)
      );

      // # Nothing left to wait for after the seed: the next read is all tail
      $Decoder = new Decoder_Waiting(3, 'abc');
      $done = $Decoder->decode('XY', 2);

      yield assert(
         assertion: $done !== null && $done['body'] === 'abc' && $done['consumed'] === 0 && $done['leftover'] === 'XY',
         description: 'a seed that already holds the body completes on the next read: ' . json_encode($done)
      );

      // # An empty read keeps waiting
      $Decoder = new Decoder_Waiting(3, 'a');

      yield assert(
         assertion: $Decoder->decode('', 0) === null,
         description: 'an empty read is not a completion'
      );

      // # Close-delimited: never completes by itself
      $Decoder = new Decoder_Waiting(null, 'ab');
      $results = [$Decoder->decode('cd', 2), $Decoder->decode(str_repeat('e', 70000), 70000)];

      yield assert(
         assertion: $results === [null, null],
         description: 'a close-delimited body waits for the connection close'
      );

      // # drain() hands every byte over once, then holds nothing
      $drained = $Decoder->drain();
      $again = $Decoder->drain();

      yield assert(
         assertion: $drained['length'] === null && $drained['body'] === 'abcd' . str_repeat('e', 70000),
         description: 'drain returns every collected byte of a close-delimited body (' . strlen($drained['body']) . ' bytes)'
      );
      yield assert(
         assertion: $again === ['body' => '', 'length' => null],
         description: 'a drained collector holds nothing'
      );

      // # drain() of a sized body cut short keeps its declared length
      $Decoder = new Decoder_Waiting(100, 'ab');
      $Decoder->decode('cd', 2);

      yield assert(
         assertion: $Decoder->drain() === ['body' => 'abcd', 'length' => 100],
         description: 'a truncated sized body drains its bytes and its declared length'
      );
   }
);
