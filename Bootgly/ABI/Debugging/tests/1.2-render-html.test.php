<?php

use Bootgly\ABI\Debugging;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'render() with TARGET_HTML produces an escaped HTML block',
   test: function () {
      $Throwable = new Exception('HTML probe <script>alert(1)</script>');

      $output = Throwables::render($Throwable, Debugging::TARGET_HTML);

      yield assert(
         assertion: str_contains($output, '<pre class="bootgly-throwable">'),
         description: 'output opens with the themed <pre> wrapper'
      );
      yield assert(
         assertion: str_contains($output, '</pre>'),
         description: 'output closes the <pre> wrapper'
      );
      yield assert(
         assertion: str_contains($output, '<span class="message">'),
         description: 'message is wrapped in a classed span'
      );
      yield assert(
         assertion: str_contains($output, '&lt;script&gt;alert(1)&lt;/script&gt;'),
         description: 'HTML payload in the message is escaped'
      );
      yield assert(
         assertion: str_contains($output, '<script>alert(1)</script>') === false,
         description: 'no raw script tag survives in the output'
      );
      yield assert(
         assertion: str_contains($output, "\e[") === false,
         description: 'HTML output contains no ANSI escape sequences'
      );
   }
);
