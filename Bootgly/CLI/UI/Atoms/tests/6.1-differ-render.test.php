<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function rewind;
use function str_contains;
use function str_ends_with;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render unified diffs through the ABI engine',
   test: function () {
      $from = "line one\nline two\nline three\nline four\n";
      $to   = "line one\nline 2\nline three\nline four\nline five\n";

      // @ RETURN_OUTPUT — plain unified structure
      $Output = new Output('php://memory');
      $Differ = new Differ($Output);
      $Differ->decoration = false;
      $Differ->from = $from;
      $Differ->to = $to;

      $rendered = (string) $Differ->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "--- Original\n+++ New\n") === true
            && str_contains($rendered, "@@ -1,4 +1,5 @@\n") === true,
         description: 'Unified diffs open with labeled headers and numbered hunks'
      );

      yield assert(
         assertion: str_contains($rendered, "-line two\n") === true
            && str_contains($rendered, "+line 2\n") === true
            && str_contains($rendered, " line three\n") === true,
         description: 'Hunks carry removed, added and context lines'
      );

      yield assert(
         assertion: str_contains($rendered, "\e") === false,
         description: 'decoration = false renders escape-free output'
      );

      yield assert(
         assertion: str_ends_with($rendered, "\n") === true,
         description: 'Rendered output ends with a newline'
      );

      // @ Custom side labels
      $Differ->fromFile = 'a/config.php';
      $Differ->toFile = 'b/config.php';

      $rendered = (string) $Differ->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "--- a/config.php\n+++ b/config.php\n") === true,
         description: 'Side labels feed the unified header'
      );

      // @ Decorated unified — line-prefix painting
      $Differ->fromFile = 'Original';
      $Differ->toFile = 'New';
      $Differ->decoration = true;

      $rendered = (string) $Differ->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($rendered, "\e[31m-line two\e[0m") === true
            && str_contains($rendered, "\e[32m+line 2\e[0m") === true
            && str_contains($rendered, "\e[36m@@ -1,4 +1,5 @@\e[0m") === true,
         description: 'Decorated unified diffs paint removed red, added green and hunks cyan'
      );

      // @ Equal inputs — header only, no hunks
      $Differ = new Differ(new Output('php://memory'));
      $Differ->decoration = false;
      $Differ->from = "same\n";
      $Differ->to = "same\n";

      $rendered = (string) $Differ->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: $rendered === "--- Original\n+++ New\n"
            && str_contains($rendered, '@@') === false,
         description: 'Equal inputs render the headers with no hunks'
      );

      // @ WRITE_OUTPUT — stream read-back
      $Output = new Output('php://memory');
      $Differ = new Differ($Output);
      $Differ->decoration = false;
      $Differ->from = "a\n";
      $Differ->to = "b\n";

      $Differ->render();

      rewind($Output->stream);
      $written = (string) stream_get_contents($Output->stream);

      yield assert(
         assertion: str_contains($written, "-a\n") === true
            && str_contains($written, "+b\n") === true,
         description: 'WRITE_OUTPUT writes the diff to the Output stream'
      );
   }
);
