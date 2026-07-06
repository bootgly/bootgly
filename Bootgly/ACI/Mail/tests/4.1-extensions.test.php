<?php

use Bootgly\ACI\Mail\Reply;
use Bootgly\ACI\Mail\SMTP_Client\Extensions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SMTP_Client\Extensions: EHLO capability parsing',
   test: function () {
      // @ Full EHLO reply
      $Extensions = new Extensions(new Reply(250, '', [
         'smtp.example.com greets client.example.com',
         'SIZE 35882577',
         'AUTH PLAIN LOGIN',
         'STARTTLS',
         'PIPELINING',
         '8BITMIME',
         'SMTPUTF8',
         'ENHANCEDSTATUSCODES'
      ]));

      yield assert(
         assertion: $Extensions->check('STARTTLS') === true,
         description: 'STARTTLS is detected'
      );
      yield assert(
         assertion: $Extensions->check('starttls') === true,
         description: 'check() is case-insensitive'
      );
      yield assert(
         assertion: $Extensions->fetch('SIZE') === '35882577',
         description: 'SIZE parameter is fetched'
      );
      yield assert(
         assertion: $Extensions->fetch('AUTH') === 'PLAIN LOGIN',
         description: 'AUTH mechanism list is fetched'
      );
      yield assert(
         assertion: $Extensions->fetch('PIPELINING') === '',
         description: 'a parameterless capability fetches an empty string'
      );
      yield assert(
         assertion: $Extensions->check('8BITMIME') && $Extensions->check('SMTPUTF8'),
         description: '8BITMIME and SMTPUTF8 are detected'
      );
      yield assert(
         assertion: $Extensions->check('CHUNKING') === false && $Extensions->fetch('CHUNKING') === null,
         description: 'an unadvertised capability checks false and fetches null'
      );
      yield assert(
         assertion: $Extensions->check('smtp.example.com') === false,
         description: 'the greeting line (line 0) is not parsed as a capability'
      );

      // @ Legacy AUTH= form
      $Extensions = new Extensions(new Reply(250, '', [
         'smtp.example.com',
         'AUTH=PLAIN LOGIN'
      ]));
      yield assert(
         assertion: $Extensions->fetch('AUTH') === 'PLAIN LOGIN',
         description: 'legacy AUTH=MECH form folds into the AUTH keyword'
      );

      // @ Both AUTH forms merge
      $Extensions = new Extensions(new Reply(250, '', [
         'smtp.example.com',
         'AUTH PLAIN',
         'AUTH=LOGIN'
      ]));
      yield assert(
         assertion: $Extensions->fetch('AUTH') === 'PLAIN LOGIN',
         description: 'repeated AUTH lines merge their mechanism lists'
      );

      // @ HELO fallback
      $Extensions = new Extensions(null);
      yield assert(
         assertion: $Extensions->extensions === []
            && $Extensions->check('STARTTLS') === false,
         description: 'HELO fallback (null) advertises no capabilities'
      );

      // @ Lowercase keywords from lax servers
      $Extensions = new Extensions(new Reply(250, '', [
         'smtp.example.com',
         'size 1024'
      ]));
      yield assert(
         assertion: $Extensions->fetch('SIZE') === '1024',
         description: 'keywords are uppercased during parsing'
      );
   }
);
