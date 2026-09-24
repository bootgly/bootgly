<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should carry only the crossOriginHeaders allowlist to another origin, never back again',
   test: function () {
      $A = 19902;
      $B = 19903;
      $logA = (string) tempnam(sys_get_temp_dir(), 'hcli102a');
      $logB = (string) tempnam(sys_get_temp_dir(), 'hcli102b');
      $serve = require __DIR__ . '/fixtures/origin.php';
      $ok = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK";
      $hop = static fn (int $code, string $location): string =>
         "HTTP/1.1 {$code} Redirect\r\nLocation: {$location}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";

      $headers = [
         'Authorization' => 'Bearer SECRET',
         'cookie' => 'session=deadbeef',
         'X-API-Key' => 'key-SECRET',
         'X-Auth-Token' => 'tok-SECRET',
         'Accept' => 'application/json',
         'User-Agent' => 'custom-agent/1',
         'Host' => 'caller.example',
         'X-Forwarded-Host' => 'forwarded.example',
         'X-Trace-Id' => 't-1',
         // ! Fields that describe the body: they follow it on a 307, never on a 303
         'Content-Encoding' => 'identity',
         'Content-Language' => 'en',
      ];
      $request = static function (string $method, string $URI, null|array $portable = null) use ($A, $headers): int {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $A));
         $Client->timeout = 3;
         $Client->connectTimeout = 2;
         if ($portable === null) {
            // ! Extra names are matched case-insensitively
            $Client->crossOriginHeaders[] = 'X-Trace-Id';
            $Client->crossOriginHeaders[] = 'x-forwarded-HOST';
         }
         else {
            $Client->crossOriginHeaders = $portable;
         }

         return $Client->request($method, $URI, $headers, $method === 'POST' ? 'secret=body' : null)->code;
      };
      $read = static fn (string $log): array => array_map(
         static fn (string $line): array => (array) json_decode($line, true),
         file($log, FILE_IGNORE_NEW_LINES) ?: []
      );

      $PIDs = [];
      try {
         $PIDs[] = $serve("127.0.0.1:{$A}", static fn (array $request): string => match ($request['target']) {
            '/cross307' => $hop(307, "http://127.0.0.1:{$B}/landing307"),
            '/cross303' => $hop(303, "http://127.0.0.1:{$B}/landing303"),
            '/bare' => $hop(307, "http://127.0.0.1:{$B}/landing-bare"),
            '/round' => $hop(307, "http://127.0.0.1:{$B}/bounce"),
            default => $ok,
         }, $logA);
         $PIDs[] = $serve("127.0.0.1:{$B}", static fn (array $request): string => $request['target'] === '/bounce'
            ? $hop(307, "http://127.0.0.1:{$A}/home")
            : $ok, $logB);
         usleep(200000);

         $codes = [
            $request('POST', '/cross307'),
            $request('POST', '/cross303'),
            $request('POST', '/bare', portable: []),
            $request('POST', '/round'),
         ];
         $Received = [...$read($logA), ...$read($logB)];
      }
      finally {
         foreach ($PIDs as $PID) {
            posix_kill($PID, SIGTERM);
            pcntl_waitpid($PID, $status);
         }
         @unlink($logA);
         @unlink($logB);
      }

      $find = static function (string $target) use ($Received): array {
         foreach ($Received as $request) {
            if ($request['target'] === $target) {
               return $request;
            }
         }

         return ['headers' => [], 'body' => null, 'method' => null];
      };
      $First = $find('/cross307');
      $Landed307 = $find('/landing307');
      $Landed303 = $find('/landing303');
      $Bare = $find('/landing-bare');
      $Home = $find('/home');

      yield assert(
         assertion: $codes === [200, 200, 200, 200],
         description: 'Every chain completed: ' . json_encode($codes)
      );

      yield assert(
         assertion: ($First['headers']['authorization'] ?? null) === 'Bearer SECRET'
            && ($First['headers']['x-api-key'] ?? null) === 'key-SECRET'
            && ($First['headers']['host'] ?? null) === 'caller.example',
         description: 'Control — the origin that was asked receives every caller header'
      );

      $leaked = array_values(array_intersect(
         ['authorization', 'cookie', 'x-api-key', 'x-auth-token'],
         array_keys($Landed307['headers'])
      ));
      yield assert(
         assertion: $leaked === [],
         description: 'Credentials and API keys stay behind on a cross-origin 307: ' . json_encode($leaked)
      );

      yield assert(
         assertion: ($Landed307['headers']['host'] ?? null) === "127.0.0.1:{$B}"
            && ($Landed307['headers']['x-forwarded-host'] ?? null) === 'forwarded.example',
         description: 'The caller Host stays behind and the new origin gets its own, next to an allowed X-Forwarded-Host: '
            . json_encode([$Landed307['headers']['host'] ?? null, $Landed307['headers']['x-forwarded-host'] ?? null])
      );

      yield assert(
         assertion: ($Landed307['headers']['accept'] ?? null) === 'application/json'
            && ($Landed307['headers']['user-agent'] ?? null) === 'custom-agent/1'
            && ($Landed307['headers']['x-trace-id'] ?? null) === 't-1',
         description: 'The allowlist travels, an added mixed-case name included'
      );

      yield assert(
         assertion: $Landed307['method'] === 'POST' && $Landed307['body'] === 'secret=body'
            && ($Landed307['headers']['content-type'] ?? null) === 'text/plain'
            && ($Landed307['headers']['content-length'] ?? null) === '11'
            && ($Landed307['headers']['content-encoding'] ?? null) === 'identity'
            && ($Landed307['headers']['content-language'] ?? null) === 'en',
         description: 'A replayed 307 body keeps every field that describes it: '
            . json_encode([$Landed307['method'], $Landed307['body'], $Landed307['headers']])
      );

      yield assert(
         assertion: $Landed303['method'] === 'GET' && $Landed303['body'] === ''
            && array_intersect(
               ['content-type', 'content-length', 'content-encoding', 'content-language', 'x-api-key'],
               array_keys($Landed303['headers'])
            ) === []
            && ($Landed303['headers']['accept'] ?? null) === 'application/json',
         description: 'A cross-origin 303 becomes a bodiless GET with the allowlist only: '
            . json_encode($Landed303['headers'])
      );

      yield assert(
         assertion: isset($Bare['headers']['accept']) === false
            && isset($Bare['headers']['x-trace-id']) === false
            && $Bare['body'] === 'secret=body'
            && ($Bare['headers']['content-type'] ?? null) === 'text/plain',
         description: 'An empty allowlist carries no caller header — only the replayed body and its fields: '
            . json_encode($Bare['headers'])
      );

      yield assert(
         assertion: isset($Home['headers']['authorization']) === false
            && isset($Home['headers']['x-api-key']) === false
            && ($Home['headers']['accept'] ?? null) === 'application/json',
         description: 'Coming back to the first origin does not bring the dropped headers back'
      );
   }
);
