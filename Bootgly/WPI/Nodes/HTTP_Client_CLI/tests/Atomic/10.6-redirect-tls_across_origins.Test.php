<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Test(
   description: 'It should keep TLS trust per origin: no client identity and no loosened verification elsewhere',
   test: function () {
      $A1 = 19908; // 127.0.0.1 — asks for a client certificate
      $A2 = 19911; // 127.0.0.1 — does not
      $B1 = 19909; // 127.0.0.2 — asks for a client certificate
      $B2 = 19910; // 127.0.0.2 — does not
      $B3 = 19919; // 127.0.0.2 — trusted by the bundle, but named attacker.example
      $directory = sys_get_temp_dir() . '/hcli106-' . getmypid();
      @mkdir($directory, 0700, true);
      $files = [];

      // ! Self-signed certificates: each origin's name, a misnamed one, and the client's identity
      $certify = static function (string $CN) use ($directory, &$files): string {
         $Key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
         $CSR = openssl_csr_new(['commonName' => $CN], $Key, ['digest_alg' => 'sha256']);
         $Certificate = openssl_csr_sign($CSR, null, $Key, 1, ['digest_alg' => 'sha256']);
         openssl_x509_export($Certificate, $certificate);
         openssl_pkey_export($Key, $key);
         $file = "{$directory}/{$CN}.pem";
         file_put_contents($file, "{$certificate}{$key}");
         $files[] = $file;

         return $file;
      };
      $serve = require __DIR__ . '/fixtures/origin.php';
      $ask = ['verify_peer' => true, 'verify_peer_name' => false, 'allow_self_signed' => true, 'capture_peer_cert' => true];
      $logs = [];
      foreach (['A1', 'A2', 'B1', 'B2', 'B3'] as $name) {
         $logs[$name] = "{$directory}/{$name}.log";
         touch($logs[$name]);
         $files[] = $logs[$name];
      }
      $route = static fn (array $request): string => match ($request['target']) {
         '/to-b1' => "HTTP/1.1 307 Temporary Redirect\r\nLocation: https://127.0.0.2:{$B1}/landing\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n",
         '/to-b2' => "HTTP/1.1 302 Found\r\nLocation: https://127.0.0.2:{$B2}/landing\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n",
         '/to-b3' => "HTTP/1.1 302 Found\r\nLocation: https://127.0.0.2:{$B3}/landing\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n",
         '/self' => "HTTP/1.1 302 Found\r\nLocation: /landed\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
         default => "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK",
      };
      $request = static function (string $host, int $port, array $secure, string $URI): array {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: $host, port: $port, secure: $secure, enableHTTP2: false));
         $Client->timeout = 3;
         $Client->connectTimeout = 2;
         $Response = $Client->request('GET', $URI);

         return [$Response->code, $Response->status];
      };
      $read = static fn (string $log): array => array_map(
         static fn (string $line): array => (array) json_decode($line, true),
         file($log, FILE_IGNORE_NEW_LINES) ?: []
      );

      $PIDs = [];
      try {
         $A = $certify('127.0.0.1');
         $B = $certify('127.0.0.2');
         $Misnamed = $certify('attacker.example');
         $identity = $certify('m3-client');
         // ! The client trusts every origin through its own CA bundle
         $bundle = "{$directory}/bundle.pem";
         file_put_contents($bundle, file_get_contents($A) . file_get_contents($B) . file_get_contents($Misnamed));
         $files[] = $bundle;
         $fingerprint = openssl_x509_fingerprint((string) file_get_contents($A), 'sha256');

         $PIDs[] = $serve("127.0.0.1:{$A1}", $route, $logs['A1'], ['local_cert' => $A] + $ask);
         $PIDs[] = $serve("127.0.0.1:{$A2}", $route, $logs['A2'], ['local_cert' => $A, 'verify_peer' => false]);
         $PIDs[] = $serve("127.0.0.2:{$B1}", $route, $logs['B1'], ['local_cert' => $B] + $ask);
         $PIDs[] = $serve("127.0.0.2:{$B2}", $route, $logs['B2'], ['local_cert' => $B, 'verify_peer' => false]);
         $PIDs[] = $serve("127.0.0.2:{$B3}", $route, $logs['B3'], ['local_cert' => $Misnamed, 'verify_peer' => false]);
         usleep(300000);

         $trusted = ['cafile' => $bundle, 'local_cert' => $identity];
         $loosened = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];

         // @ (a) The identity reaches its own origin, never the next one
         $identityHop = $request('127.0.0.1', $A1, $trusted, '/to-b1');
         // @ (a') Control: B1 does see a certificate presented to it directly
         $direct = $request('127.0.0.2', $B1, $trusted, '/landing');
         // @ (b) A CA bundle is tightening — it still applies on the next origin
         $bundleHop = $request('127.0.0.1', $A1, $trusted, '/to-b2');
         // @ (c) Verification the caller turned off for one origin is back on for the next
         $loosenedHop = $request('127.0.0.1', $A2, $loosened, '/to-b2');
         // @ (d) Name verification turned off for one origin checks the next one's name
         $namelessHop = $request('127.0.0.1', $A2, ['cafile' => $bundle, 'verify_peer_name' => false], '/to-b3');
         // @ (e) A pinned fingerprint still applies — a hop to another certificate fails
         $pinnedHop = $request('127.0.0.1', $A2, ['cafile' => $bundle, 'peer_fingerprint' => ['sha256' => $fingerprint]], '/to-b2');
         // @ (f) The same origin keeps the leg's context
         $sameHop = $request('127.0.0.1', $A2, $loosened, '/self');

         $Received = array_map($read, $logs);
      }
      finally {
         foreach ($PIDs as $PID) {
            posix_kill($PID, SIGTERM);
            pcntl_waitpid($PID, $status);
         }
         foreach ($files as $file) {
            @unlink($file);
         }
         @rmdir($directory);
      }

      $certificates = static fn (array $Requests): array => array_map(
         static fn (array $request): mixed => $request['certificate'] ?? null,
         $Requests
      );
      $landings = static fn (array $Requests): int => count(array_filter(
         $Requests,
         static fn (array $request): bool => $request['target'] === '/landing'
      ));

      yield assert(
         assertion: ($certificates($Received['A1'])[0] ?? null) === 'm3-client',
         description: 'Control — the configured origin receives the client identity: '
            . json_encode($certificates($Received['A1']))
      );

      yield assert(
         assertion: in_array('m3-client', $certificates($Received['B1']), true) && $direct === [200, 'OK'],
         description: 'Control — the next origin can see a certificate presented to it directly: '
            . json_encode([$certificates($Received['B1']), $direct])
      );

      // ! B1 asks for a certificate the client no longer presents there: the
      //   handshake fails, and only the direct control ever reaches /landing
      yield assert(
         assertion: $identityHop[0] === 0 && $landings($Received['B1']) === 1,
         description: 'The client identity is never presented to the origin a redirect points at: '
            . json_encode([$identityHop, $certificates($Received['B1'])])
      );

      yield assert(
         assertion: $bundleHop === [200, 'OK'],
         description: 'The CA bundle still verifies the next origin: ' . json_encode($bundleHop)
      );

      yield assert(
         assertion: $loosenedHop[0] === 0 && $pinnedHop[0] === 0 && $landings($Received['B2']) === 1,
         description: 'Neither loosened verification nor a pin for another certificate lets a hop through: '
            . json_encode([$loosenedHop, $pinnedHop, $landings($Received['B2'])])
      );

      yield assert(
         assertion: $namelessHop[0] === 0 && $Received['B3'] === [],
         description: 'Name verification is back on for the next origin: ' . json_encode($namelessHop)
      );

      yield assert(
         assertion: $sameHop === [200, 'OK'],
         description: 'The same origin keeps the leg\'s TLS context: ' . json_encode($sameHop)
      );

      // @ One definition of origin: an http -> https upgrade of the same host is another origin
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: 80));
      $Resolve = new ReflectionMethod(HTTP_Client_CLI::class, 'resolve');
      $upgrade = $Resolve->invoke($Client, 'https://127.0.0.1/x', '/', 'GET', 'HTTP/1.1');

      yield assert(
         assertion: is_array($upgrade) && $upgrade['same'] === false && $upgrade['port'] === 443,
         description: 'An http -> https upgrade of the same host is cross-origin: ' . json_encode($upgrade)
      );
   }
);
