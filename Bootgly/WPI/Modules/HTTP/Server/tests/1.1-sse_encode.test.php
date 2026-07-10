<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP\Server\SSE;


return new Specification(
   description: 'It should serialize `text/event-stream` events per the WHATWG spec',
   test: new Assertions(Case: function (): Generator {
      // @ Simple data-only event
      yield new Assertion(
         description: 'Data-only event: one `data:` line + blank-line terminator',
      )
         ->expect(SSE::encode('hello'))
         ->to->be("data: hello\n\n")
         ->assert();

      // @ Multi-line data splits into one `data:` line per line
      yield new Assertion(
         description: 'LF-separated data emits one `data:` line per line',
      )
         ->expect(SSE::encode("a\nb"))
         ->to->be("data: a\ndata: b\n\n")
         ->assert();

      // @ CRLF and lone CR normalize to LF before splitting
      yield new Assertion(
         description: 'CRLF/CR line endings normalize to LF',
      )
         ->expect(SSE::encode("a\r\nb\rc"))
         ->to->be("data: a\ndata: b\ndata: c\n\n")
         ->assert();

      // @ Full event: field order retry, event, id, data
      yield new Assertion(
         description: 'retry/event/id lines precede the data lines',
      )
         ->expect(SSE::encode('x', event: 'tick', id: '7', retry: 3000))
         ->to->be("retry: 3000\nevent: tick\nid: 7\ndata: x\n\n")
         ->assert();

      // @ Field-only frame (`null` data) emits no `data:` line — clients
      //   never dispatch it (the transport uses it for the lone `retry:`)
      yield new Assertion(
         description: 'null data with retry yields a field-only frame',
      )
         ->expect(SSE::encode(null, retry: 5000))
         ->to->be("retry: 5000\n\n")
         ->assert();

      // @ Empty string is a real (empty) event — one empty `data:` line;
      //   clients dispatch an event whose data is ''
      yield new Assertion(
         description: 'Empty-string data emits one empty `data:` line',
      )
         ->expect(SSE::encode(''))
         ->to->be("data: \n\n")
         ->assert();

      // @ Named empty event still dispatches
      yield new Assertion(
         description: 'Empty-string data keeps its `event:` line',
      )
         ->expect(SSE::encode('', event: 'poke'))
         ->to->be("event: poke\ndata: \n\n")
         ->assert();

      // @ Non-positive retry is omitted
      yield new Assertion(
         description: 'retry <= 0 emits no `retry:` line',
      )
         ->expect(SSE::encode('x', retry: 0))
         ->to->be("data: x\n\n")
         ->assert();

      // @ Empty event type is omitted
      yield new Assertion(
         description: 'Empty event name emits no `event:` line',
      )
         ->expect(SSE::encode('x', event: ''))
         ->to->be("data: x\n\n")
         ->assert();

      // @ CR/LF injection in `event` is stripped (field forgery guard)
      yield new Assertion(
         description: 'Newlines inside the event name are stripped',
      )
         ->expect(SSE::encode('x', event: "tick\nid: 9"))
         ->to->be("event: tickid: 9\ndata: x\n\n")
         ->assert();

      // @ CR/LF injection in `id` is stripped (field forgery guard)
      yield new Assertion(
         description: 'Newlines inside the id are stripped',
      )
         ->expect(SSE::encode('x', id: "7\r\nretry: 1"))
         ->to->be("id: 7retry: 1\ndata: x\n\n")
         ->assert();

      // @ An id carrying U+0000 is dropped entirely (clients ignore it)
      yield new Assertion(
         description: 'NUL inside the id drops the whole `id:` line',
      )
         ->expect(SSE::encode('x', id: "4\x002"))
         ->to->be("data: x\n\n")
         ->assert();

      // @ Data with a trailing newline yields a trailing empty data line
      yield new Assertion(
         description: 'Trailing LF in data emits a final empty `data:` line',
      )
         ->expect(SSE::encode("a\n"))
         ->to->be("data: a\ndata: \n\n")
         ->assert();
   })
);
