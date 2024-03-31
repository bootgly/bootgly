<?php
return [
   'scripts' => [
      'bootstrap' => [
         'bootgly',
         '/usr/local/bin/bootgly'
      ],
      'built-in' => [
         'http-server-cli',
         'tcp-server-cli',
         'tcp-client-cli',
      ],
      'imported' => [
         'vendor/bin/phpstan'
      ],
   ]
];
