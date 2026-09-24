<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should fail loudly past maxRedirects and never retry a redirect verdict',
   test: function () {
      $A = 19907;
      $log = (string) tempnam(sys_get_temp_dir(), 'hcli105');
      $serve = require __DIR__ . '/fixtures/origin.php';

      $PID = $serve("127.0.0.1:{$A}", static function (array $request): string {
         if (str_starts_with($request['target'], '/loop')) {
            $next = (int) substr($request['target'], 5) + 1;

            return "HTTP/1.1 302 Found\r\nLocation: /loop{$next}\r\nContent-Length: 0\r\n\r\n";
         }

         return "HTTP/1.1 307 Temporary Redirect\r\nLocation: gopher://127.0.0.1/x\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n";
      }, $log);
      usleep(200000);

      $request = static function (string $URI) use ($A): array {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $A));
         $Client->timeout = 3;
         $Client->connectTimeout = 2;
         $Client->maxRedirects = 3;
         // ! A retry budget on purpose: the verdicts, not the budget, must stop it
         $Client->maxRetries = 2;
         $Client->retryDelay = 0.05;
         $Client->retryJitter = 0.0;
         $Response = $Client->request('GET', $URI);

         return [$Response->code, $Response->status, $Response->Header->get('Location')];
      };

      $targets = [];
      try {
         $capped = $request('/loop0');
         $refused = $request('/refuse');

         foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $targets[] = ((array) json_decode($line, true))['target'];
         }
      }
      finally {
         posix_kill($PID, SIGTERM);
         pcntl_waitpid($PID, $status);
         @unlink($log);
      }

      yield assert(
         assertion: $capped === [0, 'Too Many Redirects', '/loop4'],
         description: 'The fourth 302 is past maxRedirects = 3: ' . json_encode($capped)
      );

      yield assert(
         assertion: $targets === ['/loop0', '/loop1', '/loop2', '/loop3', '/refuse'],
         description: 'Three hops followed, then nothing more — no retry re-walks the chain: '
            . json_encode($targets)
      );

      yield assert(
         assertion: $refused[0] === 0 && $refused[1] === 'Redirect Refused',
         description: 'A refused Location is never retried: ' . json_encode([$refused, $targets])
      );
   }
);
