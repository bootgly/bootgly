<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should resolve Location through RFC 3986 and refuse every target that is not http(s)',
   test: function () {
      $A = 19904;
      $B = 19905;
      $log = (string) tempnam(sys_get_temp_dir(), 'hcli103');
      $serve = require __DIR__ . '/fixtures/origin.php';

      $locations = [
         // # Refused before the policy is asked
         '/gopher' => "gopher://127.0.0.1:{$B}/x",
         '/ftp' => "ftp://127.0.0.1:{$B}/x",
         '/userinfo' => "http://user:pass@127.0.0.1:{$B}/x",
         '/opaque' => 'https:foo',
         '/file' => 'file:///etc/passwd',
         '/javascript' => 'javascript:alert(1)',
         '/control' => "/a\tb",
         // # Resolved, then handed to the policy
         '/upper' => "HTTPS://127.0.0.1:{$B}/x",
         '/network' => "//127.0.0.1:{$B}/elsewhere",
         '/v6' => "http://[0:0::1]:{$B}/",
         '/localhost' => "http://LOCALHOST.:{$B}/",
         '/default' => 'https://Example.COM/x',
      ];
      $PID = $serve("127.0.0.1:{$A}", static function (array $request) use ($locations): string {
         $location = $locations[$request['target']] ?? null;

         return $location === null
            ? "HTTP/1.1 302 Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
            : "HTTP/1.1 307 Temporary Redirect\r\nLocation: {$location}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
      }, $log);
      usleep(200000);

      $calls = [];
      $request = static function (string $URI, int $maxRedirects = 10) use ($A, &$calls): array {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $A));
         $Client->timeout = 3;
         $Client->connectTimeout = 2;
         $Client->maxRedirects = $maxRedirects;
         // ! Records what it was asked and refuses: nothing is ever dialed
         $Client->Redirection = static function (string $host, int $port, bool $secure) use (&$calls, $URI): bool {
            $calls[$URI] = [$host, $port, $secure];

            return false;
         };
         $Response = $Client->request('GET', $URI);

         return [$Response->code, $Response->status];
      };

      try {
         $results = [];
         foreach (array_keys($locations) as $URI) {
            $results[$URI] = $request($URI);
         }
         $missing = $request('/missing');
         $disabled = $request('/network', maxRedirects: 0);
      }
      finally {
         posix_kill($PID, SIGTERM);
         pcntl_waitpid($PID, $status);
         @unlink($log);
      }

      $refused = ['/gopher', '/ftp', '/userinfo', '/opaque', '/file', '/javascript', '/control'];
      foreach ($refused as $URI) {
         yield assert(
            assertion: $results[$URI] === [0, 'Redirect Refused'] && isset($calls[$URI]) === false,
            description: "{$URI} ({$locations[$URI]}) is refused without asking the policy: "
               . json_encode([$results[$URI], $calls[$URI] ?? null])
         );
      }

      $expected = [
         // ! An upper-case scheme is still TLS — never downgraded to cleartext
         '/upper' => ['127.0.0.1', $B, true],
         // ! A network-path reference names its own authority (HCLI-20)
         '/network' => ['127.0.0.1', $B, false],
         // ! Hosts reach the policy lowercased, IPv6 canonical and bare, no trailing dot
         '/v6' => ['::1', $B, false],
         '/localhost' => ['localhost', $B, false],
         '/default' => ['example.com', 443, true],
      ];
      foreach ($expected as $URI => $call) {
         yield assert(
            assertion: ($calls[$URI] ?? null) === $call && $results[$URI] === [0, 'Redirect Refused'],
            description: "{$URI} ({$locations[$URI]}) reaches the policy as " . json_encode($calls[$URI] ?? null)
         );
      }

      yield assert(
         assertion: $missing[0] === 302 && isset($calls['/missing']) === false,
         description: 'A 3xx without Location stays the answer: ' . json_encode($missing)
      );

      yield assert(
         assertion: $disabled[0] === 307,
         description: 'maxRedirects = 0 returns the raw 3xx: ' . json_encode($disabled)
      );
   }
);
