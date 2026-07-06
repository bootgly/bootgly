<?php

use Bootgly\ACI\Mail\SMTP_Client\Decoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SMTP_Client\Decoder: complete replies (single, multiline, status)',
   test: function () {
      // @ Single line
      $Decoder = new Decoder();
      $Replies = $Decoder->decode("250 OK\r\n");

      yield assert(
         assertion: count($Replies) === 1,
         description: 'single reply line emits one Reply'
      );
      yield assert(
         assertion: $Replies[0]->code === 250 && $Replies[0]->lines === ['OK'],
         description: 'code and text are parsed'
      );
      yield assert(
         assertion: $Replies[0]->status === '',
         description: 'no enhanced status means empty status'
      );
      yield assert(
         assertion: $Decoder->buffer === '',
         description: 'buffer is fully consumed'
      );

      // @ Bare code final line (valid per RFC 5321)
      $Replies = new Decoder()->decode("354\r\n");
      yield assert(
         assertion: $Replies[0]->code === 354 && $Replies[0]->lines === [''],
         description: 'bare 3-digit line is a final line with empty text'
      );

      // @ Multiline reply
      $Replies = new Decoder()->decode(
         "250-smtp.example.com greets client\r\n250-SIZE 1024\r\n250 HELP\r\n"
      );
      yield assert(
         assertion: count($Replies) === 1,
         description: 'multiline reply emits a single Reply'
      );
      yield assert(
         assertion: $Replies[0]->lines === ['smtp.example.com greets client', 'SIZE 1024', 'HELP'],
         description: 'multiline reply accumulates every line text'
      );

      // @ Enhanced status
      $Replies = new Decoder()->decode("250 2.1.0 Sender ok\r\n");
      yield assert(
         assertion: $Replies[0]->status === '2.1.0',
         description: 'enhanced status is extracted from the first line'
      );
      yield assert(
         assertion: $Replies[0]->lines === ['2.1.0 Sender ok'],
         description: 'enhanced status prefix is kept in the line text'
      );

      $Replies = new Decoder()->decode("250-2.1.0 first\r\n250 second\r\n");
      yield assert(
         assertion: $Replies[0]->status === '2.1.0',
         description: 'enhanced status comes from the FIRST line of a multiline reply'
      );

      // @ Several buffered replies in one decode
      $Replies = new Decoder()->decode("250 first\r\n354 go ahead\r\n");
      yield assert(
         assertion: count($Replies) === 2
            && $Replies[0]->code === 250
            && $Replies[1]->code === 354,
         description: 'multiple complete replies in one buffer are all emitted in order'
      );
   }
);
