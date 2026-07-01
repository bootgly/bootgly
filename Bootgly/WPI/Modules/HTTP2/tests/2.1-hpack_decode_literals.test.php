<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Type;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Modules\HTTP2\HPACK;


return new Specification(
   description: 'It should decode the RFC 7541 Appendix C.2 literal representations',
   test: new Assertions(Case: function (): Generator {
      // @ C.2.1 — Literal with incremental indexing: custom-key: custom-header
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'C.2.1 literal with indexing → [custom-key, custom-header]',
      )
         ->expect($HPACK->decode(hex2bin('400a637573746f6d2d6b65790d637573746f6d2d686561646572'), PHP_INT_MAX))
         ->to->be([['custom-key', 'custom-header']])
         ->assert();

      // @ C.2.1 side effect — the entry entered the dynamic table (index 62)
      yield new Assertion(
         description: 'C.2.1 entry is referenceable as dynamic index 62 (0xbe)',
      )
         ->expect($HPACK->decode("\xbe", PHP_INT_MAX))
         ->to->be([['custom-key', 'custom-header']])
         ->assert();

      // @ C.2.2 — Literal without indexing: :path /sample/path
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'C.2.2 literal without indexing → [:path, /sample/path]',
      )
         ->expect($HPACK->decode(hex2bin('040c2f73616d706c652f70617468'), PHP_INT_MAX))
         ->to->be([[':path', '/sample/path']])
         ->assert();

      // @ C.2.2 side effect — nothing was indexed (62 is unknown)
      yield new Assertion(
         description: 'C.2.2 leaves the dynamic table empty → 0xbe is a decode error',
      )
         ->expect($HPACK->decode("\xbe", PHP_INT_MAX))
         ->to->be(Type::Null)
         ->assert();

      // @ C.2.3 — Literal never indexed: password: secret
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'C.2.3 never-indexed literal → [password, secret]',
      )
         ->expect($HPACK->decode(hex2bin('100870617373776f726406736563726574'), PHP_INT_MAX))
         ->to->be([['password', 'secret']])
         ->assert();

      // @ C.2.4 — Indexed field: :method GET (static index 2)
      $HPACK = new HPACK;
      yield new Assertion(
         description: 'C.2.4 indexed static field 0x82 → [:method, GET]',
      )
         ->expect($HPACK->decode("\x82", PHP_INT_MAX))
         ->to->be([[':method', 'GET']])
         ->assert();
   })
);
