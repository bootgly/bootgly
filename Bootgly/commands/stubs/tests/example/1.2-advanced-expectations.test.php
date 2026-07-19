<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;

// Advanced API — fluent Assertions: expectation chains, operators and
// throwers. Each `new Assertion(...)` names the check (and an optional
// `fallback:` failure message), builds its expectations and MUST end
// with `->assert()`.
//
// Docs: https://docs.bootgly.com/testing/core/assertions/overview/
return new Specification(
   description: 'Advanced API: fluent expectation chains',
   test: new Assertions(Case: function (): Generator
   {
      // # Expectation chain — expect()->to->be()
      yield new Assertion(
         description: 'expect()->to->be() compares identity',
      )
         ->expect(21 * 2)
         ->to->be(42)
         ->assert();

      // # Operators — Op::* comparators, with a failure fallback message
      yield new Assertion(
         description: 'Op::GreaterThan compares magnitude',
         fallback: 'The answer must be greater than 40!'
      )
         ->expect(42, Op::GreaterThan, 40)
         ->assert();

      // # Throwers — call a callable and validate the exception it throws
      yield new Assertion(
         description: 'to->call()->to->throw() validates exceptions',
      )
         ->expect(function () {
            throw new Exception('Boom');
         })
         ->to->call()
         ->to->throw(new Exception('Boom'))
         ->assert();
   })
);
// There is more: matchers (regex), finders (contains/starts/ends with),
// delimiters (intervals), waiters (timeouts), snapshots and doubles —
// https://docs.bootgly.com/testing/deep/snapshots/overview/
