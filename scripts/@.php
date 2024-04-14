<?php
return [
   'scripts' => [
      'built-in' => [ # Relative to scripts/ (bootgly's root directory)
         'http-server-cli',
         'tcp-server-cli',
         'tcp-client-cli',
      ],
      'imported' => [ # Relative to working directory (your root directory)
         'vendor/bin/phpstan'
      ],
      'user' => [ # Relative to scripts/ (your working directory)
         // Define your scripts filenames here
      ]
   ]
];
