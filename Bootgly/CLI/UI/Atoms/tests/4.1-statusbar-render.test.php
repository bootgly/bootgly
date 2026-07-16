<?php

namespace Bootgly\CLI\UI\Atoms;


use function assert;
use function mb_strlen;
use function preg_replace;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Component;
use Bootgly\CLI\Terminal\Output;


return new Specification(
   description: 'It should render left and right segments as one aligned row',
   test: function () {
      $Output = new Output('php://memory');
      $Statusbar = new Statusbar($Output);
      $Statusbar->decoration = false;
      $Statusbar->width = 40;
      $Statusbar->left = ['Dashboard', 'main'];
      $Statusbar->right = ['? help', 'q quit'];

      $row = (string) $Statusbar->render(Component::RETURN_OUTPUT);

      // @ Exact width — gap pads the middle
      yield assert(
         assertion: mb_strlen($row) === 40,
         description: 'The row fills the configured width exactly'
      );

      // @ Left segments divider-separated, right segments edge-aligned
      yield assert(
         assertion: str_starts_with($row, ' Dashboard  ▏ main') === true
            && str_ends_with($row, '? help  q quit ') === true,
         description: 'Left segments lead with the divider, right segments end at the edge'
      );

      // @ Overflow keeps the minimum gap — the row never drops segments
      $Statusbar->width = 10;
      $row = (string) $Statusbar->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: str_contains($row, 'Dashboard') === true
            && str_contains($row, 'q quit') === true,
         description: 'Overflowing segments keep a minimum single-space gap'
      );

      // @ Empty right — row still pads to the width
      $Statusbar->width = 20;
      $Statusbar->right = [];
      $row = (string) $Statusbar->render(Component::RETURN_OUTPUT);

      yield assert(
         assertion: mb_strlen($row) === 20
            && str_ends_with($row, ' ') === true,
         description: 'Without right segments the row pads to the width'
      );

      // @ Escape-aware measuring — SGR inside a segment occupies no columns
      $Statusbar->width = 30;
      $Statusbar->right = ["\e[32mok\e[0m"];
      $Statusbar->decoration = true;
      $row = (string) $Statusbar->render(Component::RETURN_OUTPUT);
      $visible = (string) preg_replace('/\e\[[0-9;]*m/', '', $row);

      yield assert(
         assertion: mb_strlen($visible) === 30,
         description: 'Embedded escapes never widen the measured row'
      );
   }
);
