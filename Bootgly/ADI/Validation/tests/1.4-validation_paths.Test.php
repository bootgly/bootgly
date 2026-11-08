<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validation\Path;


return new Test(
   description: 'It should parse, escape and resolve rule paths',
   test: new Assertions(Case: function (): Generator {
      // @ Parse
      $Path = new Path('items.*.id');

      yield new Assertion(description: 'Segments split on dots; `*` is a wildcard (null)')
         ->expect($Path->segments)
         ->to->be(['items', null, 'id'])
         ->assert();

      yield new Assertion(description: 'The prefix is the first wildcard container')
         ->expect([$Path->wildcard, $Path->prefix])
         ->to->be([true, 'items'])
         ->assert();

      yield new Assertion(description: 'A flat key is one segment and its own prefix')
         ->expect([new Path('email')->segments, new Path('email')->wildcard, new Path('email')->prefix])
         ->to->be([['email'], false, 'email'])
         ->assert();

      yield new Assertion(description: 'Escapes keep `.`, `*` and `\\` literal')
         ->expect(new Path('a\\.b.\\*.c\\\\d')->segments)
         ->to->be(['a.b', '*', 'c\\d'])
         ->assert();

      yield new Assertion(description: 'Unknown escapes and a trailing backslash stay as written')
         ->expect([new Path('a\\x')->segments, new Path('a\\')->segments])
         ->to->be([['a\\x'], ['a\\']])
         ->assert();

      yield new Assertion(description: 'Empty segments are the empty key; parsing never fails')
         ->expect([new Path('')->segments, new Path('a..b')->segments, new Path('.')->segments])
         ->to->be([[''], ['a', '', 'b'], ['', '']])
         ->assert();

      yield new Assertion(description: '`*` inside a segment is literal; a root wildcard has an empty prefix')
         ->expect([new Path('a*.b')->segments, new Path('*.id')->prefix])
         ->to->be([['a*', 'b'], ''])
         ->assert();

      // @ Escape round-trips through parse
      $keys = ['plain', 'a.b', '*', '\\', 'x\\.y', '', 3, '**'];
      $escaped = array_map(Path::escape(...), $keys);
      $segments = new Path(implode('.', $escaped))->segments;

      yield new Assertion(description: 'escape() gives every key back through parsing')
         ->expect($segments)
         ->to->be(['plain', 'a.b', '*', '\\', 'x\\.y', '', '3', '**'])
         ->assert();

      yield new Assertion(description: 'escape() keeps valid UTF-8 and writes invalid bytes as `\\xHH`')
         ->expect([Path::escape("ação"), Path::escape("a\xFFb.\xC3"), json_encode(Path::escape("\xFF"))])
         ->to->be(["ação", 'a\\xFFb\\.\\xC3', '"\\\\xFF"'])
         ->assert();

      yield new Assertion(description: 'escape() writes `*` as `\\*` and dots as `\\.`')
         ->expect([Path::escape('*'), Path::escape('a.b'), Path::escape(7)])
         ->to->be(['\\*', 'a\\.b', '7'])
         ->assert();

      // @ Resolve
      $source = ['user' => ['password_confirmation' => 'p', 'list' => [10, 20]], 'a.b' => 1, 'scalar' => 'x'];

      yield new Assertion(description: 'resolve() walks named segments, integer keys included')
         ->expect([new Path('user.password_confirmation')->resolve($source), new Path('user.list.1')->resolve($source)])
         ->to->be([[true, 'p'], [true, 20]])
         ->assert();

      yield new Assertion(description: 'resolve() reports missing keys, non-array containers and wildcards as missing')
         ->expect([
            new Path('user.nope')->resolve($source),
            new Path('scalar.x')->resolve($source),
            new Path('user.list.*')->resolve($source),
         ])
         ->to->be([[false, null], [false, null], [false, null]])
         ->assert();

      yield new Assertion(description: 'resolve() reads an escaped literal-dot key')
         ->expect([new Path('a\\.b')->resolve($source), new Path('a.b')->resolve($source)])
         ->to->be([[true, 1], [false, null]])
         ->assert();
   })
);
