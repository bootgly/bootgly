<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Authentication;


return new Specification(
   description: 'Database: PostgreSQL SCRAM-SHA-256 authentication proof',
   test: function () {
      $Config = new Config([
         'password' => 'pencil',
         'username' => 'user',
      ]);
      $Authentication = new Authentication($Config);
      $Authentication->clientNonce = 'fyko+d2lbbFgONRv9qkxdawL';

      $Initial = $Authentication->start(['SCRAM-SHA-256']);

      yield assert(
         assertion: $Initial === [
            'mechanism' => 'SCRAM-SHA-256',
            'response' => 'n,,n=user,r=fyko+d2lbbFgONRv9qkxdawL',
         ],
         description: 'Authentication starts SCRAM with deterministic client-first-message'
      );

      $final = $Authentication->resume('r=fyko+d2lbbFgONRv9qkxdawL3rfcNHYJY1ZVvWVs7j,s=QSXCR+Q6sek8bf92,i=4096');

      yield assert(
         assertion: $final === 'c=biws,r=fyko+d2lbbFgONRv9qkxdawL3rfcNHYJY1ZVvWVs7j,p=qQRLRHGPDGjB+7iVAE7NNi5xEoHKHuLCHPNQ8BTmvds=',
         description: 'Authentication builds SCRAM client-final-message with proof'
      );

      yield assert(
         assertion: $Authentication->finish('v=XKW6VuW1FANROQabnJBz1KaeCnQL/HZByQtX/iU+o30='),
         description: 'Authentication verifies SCRAM server signature'
      );
   }
);
