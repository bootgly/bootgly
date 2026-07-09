<?php

use Bootgly\ABI\Debugging\Page;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Page::render() produces a self-contained, escaped debug document',
   test: function () {
      $thrower = function (string $payload): Exception {
         return new Exception("boom $payload");
      };
      $Throwable = $thrower('<script>alert(1)</script>');

      $with = Page::render($Throwable, [
         'Request' => ['method' => 'GET', 'URI' => '/probe']
      ]);
      $without = Page::render($Throwable);

      yield assert(
         assertion: str_starts_with($with, '<?php') === false && str_contains($with, '<!DOCTYPE html>'),
         description: 'output is a full HTML document'
      );
      yield assert(
         assertion: str_contains($with, '<style>') && str_contains($with, '<script>'),
         description: 'CSS and JS are inlined'
      );
      yield assert(
         assertion: str_contains($with, 'boom &lt;script&gt;alert(1)&lt;/script&gt;'),
         description: 'the message is HTML-escaped'
      );
      yield assert(
         assertion: str_contains($with, 'boom <script>alert(1)</script>') === false,
         description: 'no raw payload survives'
      );

      $panels = substr_count($with, 'class="panel"');
      yield assert(
         assertion: $panels === count($Throwable->getTrace()) + 1,
         description: "one panel per trace frame plus the throw frame (got $panels)"
      );

      yield assert(
         assertion: str_contains($with, 'id="context"') && str_contains($with, '/probe'),
         description: 'the context section renders the injected request data'
      );
      yield assert(
         assertion: str_contains($without, 'id="context"') === false,
         description: 'no context section without injected context'
      );

      yield assert(
         assertion: preg_match('/\bsrc\s*=|<link\b|url\(/i', $with) === 0,
         description: 'no external asset references'
      );
   }
);
