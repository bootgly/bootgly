<?php

namespace Bootgly\ABI\Templates\Template\Escaped;


return [
   // * Config
   'autoBoot' => __DIR__,
   'autoInstance' => true,
   'autoReport' => true,
   'autoSummarize' => true,
   'exitOnFailure' => true,
   // * Data
   'suiteName' => __NAMESPACE__,
   'tests' => [
      '1.1-render-color_name_to_color',
      '2.1-render-dots_to_EOL',
      '3.1-render-log_level_to_style',
      '4.1-render-style_symbol_to_style',
      '9.1.1-render-end_of_to_reset_format',
      '9.1.2-render-end_of_to_reset_format',
   ]
];
