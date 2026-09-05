<?php

namespace Bootgly\CLI\UI\Components;


use function assert;
use function mb_strwidth;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function trim;

use const Bootgly\CLI;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\CLI\Terminal;


return new Test(
   description: 'Alert: the crop measures what the terminal shows — markup costs no columns and is never cut mid-token',
   test: function () {
      // ! The crop budget is Terminal::$width minus the badge; pin a narrow
      //   terminal for the case and restore the runner's own width after
      $had = Terminal::$width;
      Terminal::$width = 80;
      // ! Type is an enum static shared by every Alert — restore it too
      $Type = (new Alert(CLI->Terminal->Output))->Type->get();

      $render = static function (string $message): string {
         $Alert = new Alert(CLI->Terminal->Output);
         $Alert->Type::Failure->set();
         $Alert->message = $message;

         return (string) $Alert->render(Alert::RETURN_OUTPUT);
      };
      $visible = static fn (string $rendered): string => trim((string) preg_replace('/\e\[[0-9;]*m/', '', $rendered));

      try {
         // # A) 60 visible columns wrapped in markup that pushes the RAW
         //   length past the 71-column budget — must survive whole
         $rendered = $render("Project @#cyan:Demo/HTTP_Server_CLI@; is not running on port @#cyan:19077@;.@.;");

         yield assert(
            assertion: $visible($rendered) === 'FAIL  Project Demo/HTTP_Server_CLI is not running on port 19077.',
            description: 'markup costs no columns: a 58-column message is not cropped at 80 — got: ' . $visible($rendered)
         );

         // # B) A message that really overflows — cropped by VISIBLE width,
         //   with the ellipsis, and never inside a `@#color:` token
         $rendered = $render("Cannot verify instance(s) @#cyan:19077, 19078, 19079@; of @#cyan:Demo/HTTP_Server_CLI@; — nothing stopped, nothing reclaimed.@.;");
         $shown = $visible($rendered);

         // ! The returned line carries the badge too — the invariant is that
         //   the WHOLE line fits the terminal, so it never wraps
         yield assert(
            assertion: str_ends_with($shown, '…') && mb_strwidth($shown) >= 76 && mb_strwidth($shown) <= 78,
            description: 'an overflowing message is cropped with an ellipsis to the badge budget (76–78 columns) — got ' . mb_strwidth($shown) . ' columns: ' . $shown
         );
         yield assert(
            assertion: preg_match('/@#|@;/', $shown) !== 1,
            description: 'no markup fragment reaches the terminal as text — got: ' . $shown
         );
         yield assert(
            assertion: str_contains($shown, '19077, 19078, 19079 of Demo/HTTP_Server_CLI'),
            description: 'the payload before the cut is intact — got: ' . $shown
         );

         // # C) The cut falls exactly where a colour token starts: the token
         //   is dropped whole, never sliced into visible text
         $filler = str_repeat('x', 68);
         $rendered = $render("{$filler}@#cyan:tail@;@.;");
         $shown = $visible($rendered);

         yield assert(
            assertion: str_contains($shown, '@') === false && str_ends_with($shown, '…'),
            description: 'a token at the cut is never sliced into text — got: ' . $shown
         );

         // # D) Every renderer directive family costs nothing: semantic colour,
         //   style, newline — while a bare `@_` in text is text and is charged
         $rendered = $render("@:success:ok@; @*:bold@; user@_host " . str_repeat('y', 40) . "@.;");
         $shown = $visible($rendered);

         yield assert(
            assertion: str_contains($shown, 'ok bold user@_host ' . str_repeat('y', 40)),
            description: 'semantic/style/newline directives are zero-width and a literal @_ survives as text — got: ' . $shown
         );

         $rendered = $render('user@_host ' . str_repeat('z', 70) . '@.;');
         $shown = $visible($rendered);

         yield assert(
            assertion: str_ends_with($shown, '…') && mb_strwidth($shown) <= 78,
            description: 'a literal @_ is charged as text, so the line still fits — got ' . mb_strwidth($shown) . ' columns'
         );
      }
      finally {
         Terminal::$width = $had;
         $Type->set();
      }
   }
);
