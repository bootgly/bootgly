<?php

use Bootgly\ACI\Mail\SMTP_Client\Decoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SMTP_Client\Decoder: fragmented input and reset',
   test: function () {
      // @ Byte-at-a-time feeding
      $Decoder = new Decoder();
      $bytes = "250 OK\r\n";
      $Replies = [];
      $premature = false;

      for ($offset = 0, $length = strlen($bytes); $offset < $length; $offset++) {
         $Replies = $Decoder->decode($bytes[$offset]);

         if ($offset < $length - 1 && $Replies !== []) {
            $premature = true;
         }
      }

      yield assert(
         assertion: $premature === false,
         description: 'no Reply is emitted before the terminating LF arrives'
      );
      yield assert(
         assertion: count($Replies) === 1 && $Replies[0]->code === 250,
         description: 'the Reply is emitted exactly on the final byte'
      );

      // @ Split mid-CRLF
      $Decoder = new Decoder();
      $Replies = $Decoder->decode("250 OK\r");
      yield assert(
         assertion: $Replies === [] && $Decoder->buffer === "250 OK\r",
         description: 'a line split before the LF stays buffered'
      );
      $Replies = $Decoder->decode("\n");
      yield assert(
         assertion: count($Replies) === 1 && $Replies[0]->lines === ['OK'],
         description: 'the trailing CR is stripped when the LF completes the line'
      );

      // @ Bare LF tolerance
      $Replies = new Decoder()->decode("250 OK\n");
      yield assert(
         assertion: count($Replies) === 1 && $Replies[0]->lines === ['OK'],
         description: 'a bare-LF line is tolerated'
      );

      // @ Split across a multiline reply
      $Decoder = new Decoder();
      $first = $Decoder->decode("250-part one\r\n");
      $second = $Decoder->decode("250 done\r\n");
      yield assert(
         assertion: $first === [] && count($second) === 1
            && $second[0]->lines === ['part one', 'done'],
         description: 'a multiline reply split across reads is accumulated'
      );

      // @ reset() drops buffered bytes AND partial multiline state
      $Decoder = new Decoder();
      $Decoder->decode("250-pending\r\n250");
      $Decoder->reset();
      yield assert(
         assertion: $Decoder->buffer === '',
         description: 'reset() clears the byte buffer'
      );
      $Replies = $Decoder->decode("220 fresh\r\n");
      yield assert(
         assertion: count($Replies) === 1
            && $Replies[0]->code === 220
            && $Replies[0]->lines === ['fresh'],
         description: 'after reset() the next reply carries no leftover lines'
      );
   }
);
