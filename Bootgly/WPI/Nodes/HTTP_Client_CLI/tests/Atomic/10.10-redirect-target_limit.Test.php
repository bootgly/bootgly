<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


// ? A redirect target is bounded: a hostile Location — or relative references
//   compounding hop after hop — never grows the request-target past TARGET_LIMIT.
return new Test(
   description: 'It should refuse a redirect whose request-target is past TARGET_LIMIT',
   test: function () {
      $A = 19921;
      $log = (string) tempnam(sys_get_temp_dir(), 'hcli1010');
      $serve = require __DIR__ . '/fixtures/origin.php';
      $limit = HTTP_Client_CLI::TARGET_LIMIT;

      $request = static function (string $URI) use ($A): array {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $A));
         $Client->timeout = 3;
         $Client->connectTimeout = 2;
         $Response = $Client->request('GET', $URI);

         return [$Response->code, $Response->status];
      };

      $PIDs = [];
      try {
         $PIDs[] = $serve("127.0.0.1:{$A}", static fn (array $request): string => match ($request['target']) {
            // ! Exactly at the limit, and one byte past it
            '/at' => "HTTP/1.1 302 Found\r\nLocation: /" . str_repeat('a', $limit - 1)
               . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
            '/past' => "HTTP/1.1 302 Found\r\nLocation: /" . str_repeat('a', $limit)
               . "\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
            // ! A relative reference that grows by itself on every hop
            default => str_starts_with($request['target'], '/grow')
               ? "HTTP/1.1 302 Found\r\nLocation: " . substr($request['target'], 1) . '/' . str_repeat('g', 3000)
                  . "\r\nContent-Length: 0\r\n\r\n"
               : "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK",
         }, $log);
         usleep(200000);

         $at = $request('/at');
         $past = $request('/past');
         $grow = $request('/grow');

         $lengths = array_map(
            static fn (string $line): int => strlen(((array) json_decode($line, true))['target']),
            file($log, FILE_IGNORE_NEW_LINES) ?: []
         );
      }
      finally {
         foreach ($PIDs as $PID) {
            posix_kill($PID, SIGTERM);
            pcntl_waitpid($PID, $status);
         }
         @unlink($log);
      }

      yield assert(
         assertion: $at === [200, 'OK'],
         description: "A target of exactly {$limit} bytes is followed: " . json_encode($at)
      );

      yield assert(
         assertion: $past === [0, 'Redirect Refused'],
         description: 'One byte past the limit is refused: ' . json_encode($past)
      );

      yield assert(
         assertion: $grow === [0, 'Redirect Refused'] && max($lengths) <= $limit,
         description: 'A self-growing relative chain is cut before its target passes the limit: '
            . json_encode([$grow, max($lengths)])
      );
   }
);
