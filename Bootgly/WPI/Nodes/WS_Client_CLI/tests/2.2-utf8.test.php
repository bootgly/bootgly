<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\UTF8;


return new Specification(
   description: 'It should validate UTF-8 incrementally, carrying split multibyte sequences',
   test: new Assertions(Case: function (): Generator {
      // @ Valid ASCII — nothing to carry.
      yield new Assertion(description: 'valid ASCII leaves no pending tail')
         ->expect(UTF8::validate('', 'hello'))
         ->to->be('')
         ->assert();

      // @ Complete multibyte (é=C3A9, 你=E4BDA0, 🌍=F09F8C8D) — nothing to carry.
      yield new Assertion(description: 'complete multibyte leaves no pending tail')
         ->expect(UTF8::validate('', "h\xC3\xA9llo \xE4\xBD\xA0 \xF0\x9F\x8C\x8D"))
         ->to->be('')
         ->assert();

      // @ A 2-byte sequence split across chunks is carried, then completed.
      yield new Assertion(description: 'a split 2-byte lead is carried as pending')
         ->expect(UTF8::validate('', "\xC3"))
         ->to->be("\xC3")
         ->assert();
      yield new Assertion(description: 'the carried 2-byte sequence completes to empty')
         ->expect(UTF8::validate("\xC3", "\xA9"))
         ->to->be('')
         ->assert();

      // @ A 4-byte sequence split with 3 bytes carried, then completed.
      yield new Assertion(description: 'a split 4-byte sequence carries its lead bytes')
         ->expect(UTF8::validate('', "\xF0\x9F\x8C"))
         ->to->be("\xF0\x9F\x8C")
         ->assert();
      yield new Assertion(description: 'the carried 4-byte sequence completes to empty')
         ->expect(UTF8::validate("\xF0\x9F\x8C", "\x8D"))
         ->to->be('')
         ->assert();

      // @ Invalid lead byte → reject.
      yield new Assertion(description: 'an invalid lead byte is rejected')
         ->expect(UTF8::validate('', "\xFF") === null)
         ->to->be(true)
         ->assert();

      // @ Lone continuation byte → reject.
      yield new Assertion(description: 'a lone continuation byte is rejected')
         ->expect(UTF8::validate('', "\x80") === null)
         ->to->be(true)
         ->assert();

      // @ Lead followed by a non-continuation → reject.
      yield new Assertion(description: 'an ill-formed sequence is rejected')
         ->expect(UTF8::validate('', "\xC3\x28") === null)
         ->to->be(true)
         ->assert();
   })
);
