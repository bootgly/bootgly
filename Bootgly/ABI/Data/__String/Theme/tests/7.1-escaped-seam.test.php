<?php

use Bootgly\ABI\Data\__String\Theme;
use Bootgly\ABI\Templates\Template\Escaped;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'TemplateEscaped resolves @:semantic: through the active UI Theme (Theme::$Current)',
   test: function () {
      // Preserve the global UI theme so this test leaves no side effect.
      $saved = Theme::$Current->active;

      // # dark — @:error: carries the bright-red code
      Theme::$Current->select(Theme::DARK);
      $dark = Escaped::render('@:error:X');
      yield assert(
         assertion: str_contains($dark, "\e[91m"),
         description: 'dark render contains bright red: ' . str_replace("\e", '\\e', $dark)
      );

      // # mono — no color code (input has no @; reset, so the content is bare)
      Theme::$Current->select(Theme::MONO);
      $mono = Escaped::render('@:error:X');
      yield assert(
         assertion: $mono === 'X',
         description: 'mono render = ' . str_replace("\e", '\\e', $mono)
      );

      // # restore
      Theme::$Current->select($saved);
      yield assert(
         assertion: Theme::$Current->active === $saved,
         description: 'restored active = ' . (string) $saved
      );
   }
);
