<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;


return new Test(
   description: 'It should ask the Redirection policy before every hop and fail loudly when it refuses',
   test: function () {
      $A = 19900;
      $B = 19901;
      $logA = (string) tempnam(sys_get_temp_dir(), 'hcli101a');
      $logB = (string) tempnam(sys_get_temp_dir(), 'hcli101b');
      $serve = require __DIR__ . '/fixtures/origin.php';
      $count = static fn (string $log): int => count(file($log, FILE_IGNORE_NEW_LINES) ?: []);

      $request = static function (null|Closure $Redirection, string $method, string $URI) use ($A): Response {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $A));
         $Client->timeout = 3;
         $Client->connectTimeout = 2;
         $Client->Redirection = $Redirection;

         return $Client->request(
            $method,
            $URI,
            ['X-API-Key' => 'key-SECRET'],
            $method === 'POST' ? 'secret=body' : null
         );
      };

      $PIDs = [];
      try {
         // ! A: a same-origin hop on a keep-alive connection, and hops to B
         $PIDs[] = $serve("127.0.0.1:{$A}", static fn (array $request): string => match ($request['target']) {
            '/same' => "HTTP/1.1 302 Found\r\nLocation: /landing\r\nContent-Length: 0\r\n\r\n",
            '/landing' => "HTTP/1.1 200 OK\r\nContent-Length: 8\r\nConnection: close\r\n\r\nA-LANDED",
            default => "HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1:{$B}/internal\r\n"
               . "Content-Length: 0\r\nConnection: close\r\n\r\n",
         }, $logA);
         $PIDs[] = $serve("127.0.0.1:{$B}", static fn (array $request): string =>
            "HTTP/1.1 200 OK\r\nContent-Length: 8\r\nConnection: close\r\n\r\nINTERNAL", $logB);
         usleep(200000);

         // @ (a) Every hop is asked — the same origin included
         $calls = [];
         $Same = $request(static function (string $host, int $port, bool $secure) use (&$calls): bool {
            $calls[] = [$host, $port, $secure];

            return true;
         }, 'GET', '/same');

         // @ (b) A refusal fails the request before anything reaches B
         $asked = [];
         $Refused = $request(static function (string $host, int $port, bool $secure) use (&$asked, $A): bool {
            $asked[] = [$host, $port, $secure];

            return $port === $A;
         }, 'POST', '/cross');
         $reachedB = $count($logB);

         // @ (c) A policy that throws refuses too
         $Thrown = $request(static function (string $host, int $port, bool $secure): bool {
            throw new RuntimeException('policy exploded');
         }, 'POST', '/cross');
         $reachedAfterThrow = $count($logB);

         // @ (d) Only a strict `true` follows: an untyped policy answering 1 refuses
         $Truthy = $request(static fn ($host, $port, $secure) => 1, 'POST', '/cross');
         $reachedAfterTruthy = $count($logB);

         // @ (e) Control: no policy follows the hop
         $Followed = $request(null, 'POST', '/cross');
         $reachedControl = $count($logB);
      }
      finally {
         foreach ($PIDs as $PID) {
            posix_kill($PID, SIGTERM);
            pcntl_waitpid($PID, $status);
         }
         @unlink($logA);
         @unlink($logB);
      }

      yield assert(
         assertion: $Same->code === 200 && $Same->Body->raw === 'A-LANDED'
            && $calls === [['127.0.0.1', $A, false]],
         description: 'A same-origin hop is followed after the policy saw it: '
            . json_encode([$Same->code, $calls])
      );

      yield assert(
         assertion: $Refused->code === 0 && $Refused->status === 'Redirect Refused'
            && $asked === [['127.0.0.1', $B, false]] && $reachedB === 0,
         description: 'A refused hop fails with Redirect Refused and never reaches its target: '
            . json_encode([$Refused->code, $Refused->status, $asked, $reachedB])
      );

      yield assert(
         assertion: $Refused->Header->get('Location') === "http://127.0.0.1:{$B}/internal",
         description: 'The refused redirect head stays readable: '
            . var_export($Refused->Header->get('Location'), true)
      );

      yield assert(
         assertion: $Thrown->code === 0 && $Thrown->status === 'Redirect Refused' && $reachedAfterThrow === 0,
         description: 'A policy that throws refuses the hop: '
            . json_encode([$Thrown->code, $Thrown->status, $reachedAfterThrow])
      );

      yield assert(
         assertion: $Truthy->code === 0 && $Truthy->status === 'Redirect Refused' && $reachedAfterTruthy === 0,
         description: 'Only a strict true follows — a truthy 1 refuses: '
            . json_encode([$Truthy->code, $Truthy->status, $reachedAfterTruthy])
      );

      yield assert(
         assertion: $Followed->code === 200 && $Followed->Body->raw === 'INTERNAL' && $reachedControl === 1,
         description: 'Control — without a policy the hop is followed: '
            . json_encode([$Followed->code, $reachedControl])
      );
   }
);
