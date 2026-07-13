<?php
return [
   'directives' => [
      '0.0-php',
      'break' => '0.1-break',
      'continue' => '0.2-continue',
      '0.3-metavar',

      // 1 - Conditionals
      'if' => '1.1-ifs',
      'else' => '1.2-elses',
      'switch' => '1.3-switchs',

      // 2 - Loops
      'for' => '2.1-for',
      'foreach' => '2.2-foreach',
      'while' => '2.3-while',

      // 3 - Outputs
      // '>>' must precede '>' (sequential pass; '@>' would match '@>>' first)
      '>>' => '3.0-output.escape',
      '.>.' => '3.1-output.wrap_breakline',
      '.>' => '3.2-output.breakline_before',
      '>.' => '3.3-output.breakline_after',
      '>' => '3.z-output',
      'translate' => '3.4-output.translate',

      // 4 - Inheritance
      'extends' => '4.1-extends',
      'section' => '4.2-section',
      'yield' => '4.3-yield',

      // 5 - Composition
      'include' => '5.1-include',
      'component' => '5.2-component',
      'slot' => '5.3-slot',

      // _
   ]
];
