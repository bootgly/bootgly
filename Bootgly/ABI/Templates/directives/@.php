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
      '.>.' => '3.1-output.wrap_breakline',
      '.>' => '3.2-output.breakline_before',
      '>.' => '3.3-output.breakline_after',
      '>' => '3.z-output',

      // _
   ]
];
