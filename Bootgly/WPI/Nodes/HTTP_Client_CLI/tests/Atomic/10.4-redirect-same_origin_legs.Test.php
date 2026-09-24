<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should merge relative Locations and keep every caller header on a same-origin 303',
   test: function () {
      $A = 19906;
      $log = (string) tempnam(sys_get_temp_dir(), 'hcli104');
      $serve = require __DIR__ . '/fixtures/origin.php';

      $PID = $serve("127.0.0.1:{$A}", static fn (array $request): string => match ($request['target']) {
         // # A relative reference with dot-segments and its own query (keep-alive)
         '/dir/leaf?q=1' => "HTTP/1.1 302 Found\r\nLocation: ../up/./x?y=1\r\nContent-Length: 0\r\n\r\n",
         // # 303 after a POST: once through follow() (close), once on the kept connection
         '/pay' => "HTTP/1.1 303 See Other\r\nLocation: /after\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
         '/pay-alive' => "HTTP/1.1 303 See Other\r\nLocation: /after-alive\r\nContent-Length: 0\r\n\r\n",
         default => "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK",
      }, $log);
      usleep(200000);

      $request = static function (string $method, string $URI, array $headers = [], null|string $body = null) use ($A): int {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $A));
         $Client->timeout = 3;
         $Client->connectTimeout = 2;

         return $Client->request($method, $URI, $headers, $body)->code;
      };
      $headers = [
         'Authorization' => 'Bearer SECRET',
         'X-API-Key' => 'key-SECRET',
         'Accept' => 'application/json',
      ];

      $Received = [];
      try {
         $codes = [
            $request('GET', '/dir/leaf?q=1'),
            $request('POST', '/pay', $headers, 'card=4111'),
            $request('POST', '/pay-alive', $headers, 'card=4111'),
         ];

         foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $request = (array) json_decode($line, true);
            $Received[$request['target']] = $request;
         }
      }
      finally {
         posix_kill($PID, SIGTERM);
         pcntl_waitpid($PID, $status);
         @unlink($log);
      }

      yield assert(
         assertion: $codes === [200, 200, 200],
         description: 'Every chain completed: ' . json_encode($codes)
      );

      yield assert(
         assertion: isset($Received['/up/x?y=1']),
         description: 'A relative Location is merged and its dot-segments removed: '
            . json_encode(array_keys($Received))
      );

      foreach (['/after', '/after-alive'] as $target) {
         $Leg = $Received[$target] ?? ['method' => null, 'body' => null, 'headers' => []];

         yield assert(
            assertion: $Leg['method'] === 'GET'
               && ($Leg['headers']['authorization'] ?? null) === 'Bearer SECRET'
               && ($Leg['headers']['x-api-key'] ?? null) === 'key-SECRET'
               && ($Leg['headers']['accept'] ?? null) === 'application/json',
            description: "{$target}: a same-origin 303 keeps the caller's headers (HCLI-13): "
               . json_encode($Leg['headers'])
         );

         yield assert(
            assertion: $Leg['body'] === ''
               && isset($Leg['headers']['content-type']) === false
               && isset($Leg['headers']['content-length']) === false,
            description: "{$target}: only the payload leaves with the method change"
         );
      }
   }
);
