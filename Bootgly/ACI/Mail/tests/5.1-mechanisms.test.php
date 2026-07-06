<?php

use Bootgly\ACI\Mail\SMTP_Client\Mechanisms;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'SMTP_Client\Mechanisms: AUTH initial-response encoding',
   test: function () {
      // @ PLAIN (RFC 4616): base64("\0username\0password")
      yield assert(
         assertion: Mechanisms::Plain->encode('user', 'pass') === 'AHVzZXIAcGFzcw==',
         description: 'PLAIN encodes the exact NUL-separated base64 blob'
      );
      yield assert(
         assertion: base64_decode(Mechanisms::Plain->encode('u@x.com', 's3cr3t'), true)
            === "\0u@x.com\0s3cr3t",
         description: 'PLAIN decodes back to \0username\0password'
      );

      // @ LOGIN: base64(username) — the password answer is encoded by the caller
      yield assert(
         assertion: Mechanisms::Login->encode('user', '') === 'dXNlcg==',
         description: 'LOGIN encodes the bare base64 username'
      );

      // @ XOAUTH2: base64("user=<u>\x01auth=Bearer <token>\x01\x01")
      yield assert(
         assertion: base64_decode(Mechanisms::XOAuth2->encode('u@x.com', 'tok-123'), true)
            === "user=u@x.com\x01auth=Bearer tok-123\x01\x01",
         description: 'XOAUTH2 decodes back to the SASL bearer blob'
      );

      // @ Wire names
      yield assert(
         assertion: Mechanisms::Plain->value === 'PLAIN'
            && Mechanisms::Login->value === 'LOGIN'
            && Mechanisms::XOAuth2->value === 'XOAUTH2',
         description: 'enum backing values match the advertised mechanism names'
      );
   }
);
