<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should wait for the response under a timeout beyond the nanosecond range',
   test: function () {
      $A = 19926;
      $log = (string) tempnam(sys_get_temp_dir(), 'hcli131');
      $serve = require __DIR__ . '/fixtures/origin.php';

      $PID = $serve("127.0.0.1:{$A}", static function (array $request): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok";
      }, $log);
      usleep(200000);

      // @ A response timeout far past the monotonic clock's range: never an overflowed deadline
      $answers = [];
      try {
         foreach ([1e10, INF] as $timeout) {
            $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
            $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $A));
            $Client->timeout = $timeout;
            $Client->connectTimeout = 2;
            $Response = @$Client->request('GET', '/');
            $answers[] = [$Response->code, $Response->Body->raw];
         }
      }
      finally {
         posix_kill($PID, SIGTERM);
         pcntl_waitpid($PID, $status);
         @unlink($log);
      }

      yield assert(
         assertion: $answers === [[200, 'ok'], [200, 'ok']],
         description: 'A far or infinite response timeout still gets the response: ' . json_encode($answers)
      );
   }
);
