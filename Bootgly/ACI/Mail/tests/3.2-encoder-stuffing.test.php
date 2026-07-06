<?php

use Bootgly\ACI\Mail\SMTP_Client\Encoder;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SMTP_Client\Encoder: dot-stuffing and EOL normalization',
   test: function () {
      $Encoder = new Encoder();

      // @ Dot-stuffing
      yield assert(
         assertion: $Encoder->stuff(".hello\r\nworld\r\n") === "..hello\r\nworld\r\n",
         description: 'a leading dot at payload start is doubled'
      );
      yield assert(
         assertion: $Encoder->stuff("line1\r\n.\r\nline2\r\n") === "line1\r\n..\r\nline2\r\n",
         description: 'a lone-dot line is doubled'
      );
      yield assert(
         assertion: $Encoder->stuff("a\r\n.b\r\n.c\r\n") === "a\r\n..b\r\n..c\r\n",
         description: 'every line-start dot is doubled'
      );
      yield assert(
         assertion: $Encoder->stuff("..already\r\n") === "...already\r\n",
         description: 'an already-doubled leading dot gets one more (transparent transform)'
      );
      yield assert(
         assertion: $Encoder->stuff("no dots here\r\n") === "no dots here\r\n",
         description: 'a payload without line-start dots is unchanged'
      );
      yield assert(
         assertion: $Encoder->stuff("mid.line.dots stay\r\n") === "mid.line.dots stay\r\n",
         description: 'dots inside a line are not stuffed'
      );

      // @ EOL normalization
      yield assert(
         assertion: $Encoder->stuff("a\nb\rc\r\nd") === "a\r\nb\r\nc\r\nd\r\n",
         description: 'mixed LF/CR/CRLF EOLs are normalized to CRLF'
      );
      yield assert(
         assertion: $Encoder->stuff("bare LF\n.dot after LF") === "bare LF\r\n..dot after LF\r\n",
         description: 'dot-stuffing applies after normalization (LF-separated dots are caught)'
      );

      // @ Trailing CRLF
      yield assert(
         assertion: $Encoder->stuff('no trailing newline') === "no trailing newline\r\n",
         description: 'a missing final CRLF is appended'
      );
      yield assert(
         assertion: $Encoder->stuff("already terminated\r\n") === "already terminated\r\n",
         description: 'an existing final CRLF is not duplicated'
      );
      yield assert(
         assertion: $Encoder->stuff('') === "\r\n",
         description: 'an empty payload becomes a single CRLF'
      );
   }
);
