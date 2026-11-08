<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Validation\Descriptor;
use Bootgly\ADI\Validators\Boolean;
use Bootgly\ADI\Validators\Confirmed;
use Bootgly\ADI\Validators\Date;
use Bootgly\ADI\Validators\Email;
use Bootgly\ADI\Validators\Extension;
use Bootgly\ADI\Validators\In;
use Bootgly\ADI\Validators\Integer;
use Bootgly\ADI\Validators\Maximum;
use Bootgly\ADI\Validators\MIME;
use Bootgly\ADI\Validators\Minimum;
use Bootgly\ADI\Validators\Regex;
use Bootgly\ADI\Validators\Required;
use Bootgly\ADI\Validators\Size;
use Bootgly\ADI\Validators\URL;


return new Test(
   description: 'It should describe the shipped rules as JSON Schema keywords',
   test: new Assertions(Case: function (): Generator {
      // @ Type and format rules
      yield new Assertion(description: 'Type/format rules map to type and format')
         ->expect([
            new Boolean()->describe(),
            new Integer()->describe(),
            new Email()->describe(),
            new URL()->describe(),
            new Required()->describe(),
         ])
         ->to->be([
            ['type' => 'boolean'],
            ['type' => 'integer'],
            ['type' => 'string', 'format' => 'email'],
            ['type' => 'string', 'format' => 'uri'],
            ['required' => true],
         ])
         ->assert();

      yield new Assertion(description: 'Date maps RFC 3339 formats; others are plain strings')
         ->expect([
            new Date()->describe(),
            new Date('Y-m-d')->describe(),
            new Date(DATE_ATOM)->describe(),
            new Date('Y-m-d\TH:i:sp')->describe(),
            new Date('d/m/Y')->describe(),
         ])
         ->to->be([
            ['type' => 'string'],
            ['type' => 'string', 'format' => 'date'],
            ['type' => 'string', 'format' => 'date-time'],
            ['type' => 'string', 'format' => 'date-time'],
            ['type' => 'string'],
         ])
         ->assert();

      // @ In
      yield new Assertion(description: 'In maps scalar values to enum; non-scalars and NAN are not expressible')
         ->expect([
            new In(['a', 1, 1.5, true, null])->describe(),
            new In([['nested']])->describe(),
            new In([NAN])->describe(),
         ])
         ->to->be([
            ['enum' => ['a', 1, 1.5, true, null]],
            null,
            null,
         ])
         ->assert();

      // @ Minimum / Maximum
      yield new Assertion(description: 'Minimum/Maximum emit numeric, length, item and property bounds')
         ->expect([new Minimum(3)->describe(), new Maximum(10)->describe()])
         ->to->be([
            ['minimum' => 3, 'minLength' => 3, 'minItems' => 3, 'minProperties' => 3],
            ['maximum' => 10, 'maxLength' => 10, 'maxItems' => 10, 'maxProperties' => 10],
         ])
         ->assert();

      yield new Assertion(description: 'Fractional limits round to the whole counts they admit')
         ->expect([new Minimum(2.5)->describe(), new Maximum(2.5)->describe()])
         ->to->be([
            ['minimum' => 2.5, 'minLength' => 3, 'minItems' => 3, 'minProperties' => 3],
            ['maximum' => 2.5, 'maxLength' => 2, 'maxItems' => 2, 'maxProperties' => 2],
         ])
         ->assert();

      yield new Assertion(description: 'Non-positive minimums and negative maximums bound numbers only')
         ->expect([new Minimum(0)->describe(), new Minimum(-5)->describe(), new Maximum(-1)->describe()])
         ->to->be([['minimum' => 0], ['minimum' => -5], ['maximum' => -1]])
         ->assert();

      yield new Assertion(description: 'Infinite limits are not expressible; huge ones bound numbers only')
         ->expect([new Minimum(INF)->describe(), new Maximum(-INF)->describe(), new Maximum(1e19)->describe()])
         ->to->be([null, null, ['maximum' => 1e19]])
         ->assert();

      // @ Regex → ECMA-262
      $patterns = [];
      foreach ([
         '/\A[a-z0-9-]+\z/',
         '#^a/b\#c$#',
         '~^\d{3}(?:-\d{4})?$~u',
         '/(?<year>\d{4})-(?=\d)/D',
         '{^[^]]$}',
         '/[\]a]\Z/',
         '/^x\/y$/',
      ] as $pattern) {
         $patterns[] = new Regex($pattern)->describe();
      }

      yield new Assertion(description: 'Regex translates delimiters, anchors and escaped delimiters')
         ->expect($patterns)
         ->to->be([
            ['pattern' => '^[a-z0-9-]+$'],
            ['pattern' => '^a/b#c$'],
            ['pattern' => '^\d{3}(?:-\d{4})?$'],
            ['pattern' => '(?<year>\d{4})-(?=\d)'],
            null,
            ['pattern' => '[\]a]$'],
            ['pattern' => '^x\/y$'],
         ])
         ->assert();

      $refused = [];
      foreach ([
         '/abc/i',
         '/a/x',
         '/a++/',
         '/a{2}+/',
         '/(?>a)/',
         '/(?P<n>a)/',
         '/(?i)a/',
         '/a\Kb/',
         '/\R/',
         '/\h/',
         '/\Qa.b\E/',
         '/[[:alpha:]]/',
         '/\x{41}/',
         '/(?#comment)a/',
      ] as $pattern) {
         $refused[] = new Regex($pattern)->describe();
      }

      yield new Assertion(description: 'Regex refuses modifiers and PCRE-only constructs (null)')
         ->expect($refused)
         ->to->be(array_fill(0, 14, null))
         ->assert();

      // @ Escapes: kept, made plain (valid under the `u` flag) or refused
      $escapes = [];
      foreach ([
         '/\A\d{3}\-\d{4}\z/',
         '/[a\-z]/',
         '-\A[a\-z]+\z-',
         '/\#\@\:/',
         '/\cA\x41/',
         '/\p{Lu}+/u',
         '/(?<n>a)\k<n>/',
         '/(a)\1/',
         '/\xE9/u',
         '/[é]/u',
         '/(?=a)b/',
      ] as $pattern) {
         $escapes[] = new Regex($pattern)->describe();
      }

      yield new Assertion(description: 'Regex keeps shared escapes and writes escaped punctuation plainly')
         ->expect($escapes)
         ->to->be([
            ['pattern' => '^\d{3}-\d{4}$'],
            ['pattern' => '[a\-z]'],
            ['pattern' => '^[a\-z]+$'],
            ['pattern' => '#@:'],
            ['pattern' => '\cA\x41'],
            ['pattern' => '\p{Lu}+'],
            ['pattern' => '(?<n>a)\k<n>'],
            ['pattern' => '(a)\1'],
            ['pattern' => '\xE9'],
            ['pattern' => '[é]'],
            ['pattern' => '(?=a)b'],
         ])
         ->assert();

      $refused = [];
      foreach ([
         '/\v/',
         '/\012/',
         '/\o{101}/',
         '/\x4/',
         '/(a)\10/',
         '/a{,3}/',
         '/a}/',
         '/a]/',
         '/(*UTF)a/',
         '/(?=a)*b/',
         '/(?<!a)?b/',
         '/\p{Lc}/u',
         '/\p{Lu}/',
         '/\xE9/',
         '/é/',
         "/\xFF/",
      ] as $pattern) {
         $refused[] = new Regex($pattern)->describe();
      }

      yield new Assertion(description: 'Regex refuses escapes, braces, verbs, quantified lookarounds and bytes ECMA-262 reads differently')
         ->expect($refused)
         ->to->be(array_fill(0, 16, null))
         ->assert();

      // @ Rules that are not expressible do not implement the contract
      yield new Assertion(description: 'Confirmed, Extension, MIME and Size are not Descriptors')
         ->expect([
            new Confirmed() instanceof Descriptor,
            new Extension('jpg') instanceof Descriptor,
            new MIME('image/png') instanceof Descriptor,
            new Size(1) instanceof Descriptor,
         ])
         ->to->be([false, false, false, false])
         ->assert();
   })
);
