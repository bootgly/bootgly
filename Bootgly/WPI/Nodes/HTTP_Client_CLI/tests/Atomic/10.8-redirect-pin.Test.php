<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should pin redirects to the configured origin with pin()',
   test: function () {
      $A = 19914;
      $B = 19915;
      $log = (string) tempnam(sys_get_temp_dir(), 'hcli108');
      $serve = require __DIR__ . '/fixtures/origin.php';
      $route = static fn (array $request): string => match ($request['target']) {
         // ! Spelled differently from the configured `[::1]`, still the same origin
         '/same' => "HTTP/1.1 302 Found\r\nLocation: http://[0:0::1]:{$A}/landing\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n",
         '/cross' => "HTTP/1.1 307 Temporary Redirect\r\nLocation: http://[::1]:{$B}/landing\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n",
         '/scheme' => "HTTP/1.1 302 Found\r\nLocation: https://[::1]:{$A}/landing\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n",
         // ! A relative hop: it stays on whichever origin answered
         '/stay' => "HTTP/1.1 302 Found\r\nLocation: /landing\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
         default => "HTTP/1.1 200 OK\r\nContent-Length: 6\r\nConnection: close\r\n\r\nLANDED",
      };
      $client = static function (int $port): HTTP_Client_CLI {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '[::1]', port: $port));
         $Client->timeout = 3;
         $Client->connectTimeout = 2;

         return $Client;
      };
      $request = static function (HTTP_Client_CLI $Client, string $URI): array {
         $Response = $Client->request('POST', $URI, ['X-API-Key' => 'key-SECRET'], 'secret=body');

         return [$Response->code, $Response->status];
      };

      $PIDs = [];
      try {
         $PIDs[] = $serve("[::1]:{$A}", $route, $log);
         $PIDs[] = $serve("[::1]:{$B}", $route, $log);
         usleep(200000);

         $Client = $client($A);
         $Pinned = $Client->pin();
         $same = $request($Client, '/same');
         $cross = $request($client($A)->pin(), '/cross');
         $scheme = $request($client($A)->pin(), '/scheme');

         // @ The pin does not move with a later configure(): a hop within the new origin is refused
         $Moved = $client($A)->pin();
         $Moved->configure(new HTTP_Client_CLI\Configs(host: '[::1]', port: $B));
         $moved = $request($Moved, '/stay');

         // @ An unconfigured client has nothing to pin
         $unconfigured = null;
         try {
            new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST)->pin();
         }
         catch (LogicException $Exception) {
            $unconfigured = $Exception->getMessage();
         }

         $targets = array_map(
            static fn (string $line): string => ((array) json_decode($line, true))['target'],
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
         assertion: $same[0] === 200 && $Pinned === $Client && $Client->Redirection instanceof Closure,
         description: 'pin() returns the client and follows a same-origin hop: ' . json_encode($same)
      );

      yield assert(
         assertion: $cross === [0, 'Redirect Refused'] && $scheme === [0, 'Redirect Refused'],
         description: 'Another port or another scheme is refused: ' . json_encode([$cross, $scheme])
      );

      yield assert(
         assertion: $moved === [0, 'Redirect Refused'],
         description: 'The pin keeps the origin it captured, whatever configure() does later: ' . json_encode($moved)
      );

      yield assert(
         assertion: $targets === ['/same', '/landing', '/cross', '/scheme', '/stay'],
         description: 'Nothing reached another origin, nor the moved one\'s landing: ' . json_encode($targets)
      );

      yield assert(
         assertion: is_string($unconfigured) && str_contains($unconfigured, 'configure()'),
         description: 'pin() refuses a client without an origin: ' . var_export($unconfigured, true)
      );
   }
);
