<?php
return [
   'default' => 0,
   'projects' => [
      [
         'name' => 'Bootgly.CLI.Demo',
         'interface' => 'CLI',
         'paths' => [
            'Bootgly/CLI/examples/Terminal'
         ]
      ],

      [
         'name' => 'Bootgly.WPI.API',
         'interface' => 'WPI',
         'paths' => [
            'Bootgly/WPI/examples/api/'
         ]
      ],
      [
         'name' => 'Bootgly.WPI.App',
         'interface' => 'WPI',
         'paths' => [
            'Bootgly/WPI/examples/app/'
         ]
      ],
   ]
];
