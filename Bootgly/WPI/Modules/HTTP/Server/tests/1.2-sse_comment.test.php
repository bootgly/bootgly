<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP\Server\SSE;


return new Specification(
   description: 'It should serialize event-stream comment lines (keep-alive heartbeat)',
   test: new Assertions(Case: function (): Generator {
      // @ Bare heartbeat comment
      yield new Assertion(
         description: 'Empty comment is a lone `:` line + terminator',
      )
         ->expect(SSE::comment())
         ->to->be(":\n\n")
         ->assert();

      // @ Text comment
      yield new Assertion(
         description: 'Comment text follows `: `',
      )
         ->expect(SSE::comment('keep-alive'))
         ->to->be(": keep-alive\n\n")
         ->assert();

      // @ CR/LF injection stripped (field forgery guard)
      yield new Assertion(
         description: 'Newlines inside the comment are stripped',
      )
         ->expect(SSE::comment("a\r\nb"))
         ->to->be(": ab\n\n")
         ->assert();
   })
);
