<?php

namespace Bootgly\API\Security\Tests\JWTRemoteRedirectDowngrade;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use const STREAM_SOCK_STREAM;
use function assert;
use function fclose;
use function fgets;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function preg_match;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function str_contains;
use function stream_context_create;
use function stream_get_contents;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;


$supported = function_exists('pcntl_fork')
   && function_exists('pcntl_waitpid')
   && function_exists('openssl_pkey_get_details')
   && function_exists('openssl_pkey_get_private')
   && function_exists('openssl_pkey_get_public')
   && function_exists('proc_open')
   && function_exists('stream_socket_pair')
   && function_exists('stream_socket_server');


return new Test(
   description: 'JWT: a verified HTTPS JWKS endpoint cannot redirect trust to plaintext HTTP',
   skip: $supported === false,
   test: function () {
      /** @var array{
       *    first: array{private:string,public:string,jwk:array<string,string>,body:string},
       *    second: array{private:string,public:string,jwk:array<string,string>,body:string}
       * } $fixtures
       */
      $fixtures = require __DIR__ . '/fixtures/jwt_rs256.php';
      $first = $fixtures['first'];
      $second = $fixtures['second'];
      $certificate = __DIR__ . '/fixtures/jwt_loopback_tls.pem';
      $TLSContext = stream_context_create([
         'ssl' => [
            'local_cert' => $certificate,
            'verify_peer' => false,
            'allow_self_signed' => true,
         ],
      ]);
      $TLSListener = @stream_socket_server(
         'tls://127.0.0.1:0',
         $TLSErrorCode,
         $TLSError,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         $TLSContext
      );
      $HTTPListener = @stream_socket_server('tcp://127.0.0.1:0', $HTTPErrorCode, $HTTPError);

      if (is_resource($TLSListener) === false || is_resource($HTTPListener) === false) {
         is_resource($TLSListener) && fclose($TLSListener);
         is_resource($HTTPListener) && fclose($HTTPListener);
         throw new RuntimeException(
            "JWT-13 loopback listeners failed: TLS {$TLSErrorCode} {$TLSError}; "
            . "HTTP {$HTTPErrorCode} {$HTTPError}"
         );
      }

      $TLSAddress = (string) stream_socket_get_name($TLSListener, false);
      $HTTPAddress = (string) stream_socket_get_name($HTTPListener, false);
      $TLSSeparator = strrpos($TLSAddress, ':');
      $HTTPSeparator = strrpos($HTTPAddress, ':');
      $TLSPort = $TLSSeparator === false ? 0 : (int) substr($TLSAddress, $TLSSeparator + 1);
      $HTTPPort = $HTTPSeparator === false ? 0 : (int) substr($HTTPAddress, $HTTPSeparator + 1);
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

      if ($TLSPort < 1 || $HTTPPort < 1 || $Pair === false) {
         fclose($TLSListener);
         fclose($HTTPListener);
         throw new RuntimeException('JWT-13 loopback fixture could not allocate ports or control channel.');
      }

      [$Control, $ChildControl] = $Pair;
      stream_set_timeout($Control, 10);
      $PID = pcntl_fork();

      if ($PID === -1) {
         fclose($Control);
         fclose($ChildControl);
         fclose($TLSListener);
         fclose($HTTPListener);
         throw new RuntimeException('JWT-13 loopback fixture could not fork.');
      }

      if ($PID === 0) {
         fclose($Control);
         $TLSPaths = [];
         $HTTPPaths = [];
         $fixtureError = '';
         $Read = static function ($Socket): string {
            $head = '';

            while (str_contains($head, "\r\n\r\n") === false && strlen($head) < 65536) {
               $chunk = @fread($Socket, 8192);
               if (is_string($chunk) === false || $chunk === '') {
                  break;
               }

               $head .= $chunk;
            }

            return $head;
         };
         $Write = static function ($Socket, string $bytes): bool {
            while ($bytes !== '') {
               $written = @fwrite($Socket, $bytes);
               if (is_int($written) === false || $written < 1) {
                  return false;
               }

               $bytes = substr($bytes, $written);
            }

            return true;
         };

         for ($request = 0; $request < 4; $request++) {
            $Client = @stream_socket_accept($TLSListener, 3.0);
            if (is_resource($Client) === false) {
               $fixtureError = "accepted {$request} of 4 expected HTTPS requests";
               break;
            }

            stream_set_timeout($Client, 2);
            $head = $Read($Client);
            $path = '';
            if (preg_match('/^GET\s+([^\s]+)\s+HTTP\/\d(?:\.\d)?/i', $head, $matches) === 1) {
               $path = $matches[1];
            }
            $TLSPaths[] = $path;

            if ($path === '/direct' || $path === '/secure-final') {
               $response = "HTTP/1.1 200 OK\r\n"
                  . "Cache-Control: no-store\r\n"
                  . "Content-Type: application/json\r\n"
                  . 'Content-Length: ' . strlen($first['body']) . "\r\n"
                  . "Connection: close\r\n\r\n"
                  . $first['body'];
            }
            elseif ($path === '/secure-redirect') {
               $response = "HTTP/1.1 302 Found\r\n"
                  . "Location: https://127.0.0.1:{$TLSPort}/secure-final\r\n"
                  . "Content-Length: 0\r\n"
                  . "Connection: close\r\n\r\n";
            }
            elseif ($path === '/downgrade') {
               $response = "HTTP/1.1 302 Found\r\n"
                  . "Location: http://127.0.0.1:{$HTTPPort}/attacker\r\n"
                  . "Content-Length: 0\r\n"
                  . "Connection: close\r\n\r\n";
            }
            else {
               $response = "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
            }

            if ($Write($Client, $response) === false) {
               $fixtureError = "failed to write HTTPS response for {$path}";
            }
            fclose($Client);
         }

         // @ A secure client never reaches this listener. The timeout keeps
         //   the fixed path deterministic while still recording the vulnerable
         //   plaintext request when automatic redirects are enabled.
         $Attacker = @stream_socket_accept($HTTPListener, 2.0);
         if (is_resource($Attacker)) {
            stream_set_timeout($Attacker, 2);
            $head = $Read($Attacker);
            $path = '';
            if (preg_match('/^GET\s+([^\s]+)\s+HTTP\/\d(?:\.\d)?/i', $head, $matches) === 1) {
               $path = $matches[1];
            }
            $HTTPPaths[] = $path;
            $response = "HTTP/1.1 200 OK\r\n"
               . "Cache-Control: no-store\r\n"
               . "Content-Type: application/json\r\n"
               . 'Content-Length: ' . strlen($second['body']) . "\r\n"
               . "Connection: close\r\n\r\n"
               . $second['body'];

            if ($Write($Attacker, $response) === false) {
               $fixtureError = 'failed to write the plaintext attacker response';
            }
            fclose($Attacker);
         }

         $report = json_encode([
            'tls_paths' => $TLSPaths,
            'http_paths' => $HTTPPaths,
            'error' => $fixtureError,
         ]);
         @fwrite($ChildControl, (is_string($report) ? $report : '{}') . "\n");
         fclose($ChildControl);
         fclose($TLSListener);
         fclose($HTTPListener);
         exit($fixtureError === '' ? 0 : 1);
      }

      fclose($ChildControl);
      fclose($TLSListener);
      fclose($HTTPListener);
      $clientCode = <<<'PHP'
$root = (string) getenv('BOOTGLY_JWT13_ROOT');
$fixture = (string) getenv('BOOTGLY_JWT13_KEYS');
require $root . 'autoboot.php';
$fixtures = require $fixture;
$first = $fixtures['first'];
$second = $fixtures['second'];

try {
   $Direct = new \Bootgly\API\Security\JWT\Remote(
      (string) getenv('BOOTGLY_JWT13_DIRECT')
   );
   $Direct->timeout = 2;
   $DirectKeys = $Direct->fetch();

   $Secure = new \Bootgly\API\Security\JWT\Remote(
      (string) getenv('BOOTGLY_JWT13_SECURE_REDIRECT')
   );
   $Secure->timeout = 2;
   $SecureKeys = $Secure->fetch();

   $Downgrade = new \Bootgly\API\Security\JWT\Remote(
      (string) getenv('BOOTGLY_JWT13_DOWNGRADE')
   );
   $Downgrade->timeout = 2;
   $DowngradeKeys = $Downgrade->fetch();

   echo json_encode([
      'direct_kind' => get_debug_type($DirectKeys),
      'direct_status' => $Direct->status,
      'direct_legitimate' => $DirectKeys instanceof \Bootgly\API\Security\JWT\KeySet
         && $DirectKeys->get($first['jwk']['kid']) !== null,
      'secure_kind' => get_debug_type($SecureKeys),
      'secure_status' => $Secure->status,
      'secure_legitimate' => $SecureKeys instanceof \Bootgly\API\Security\JWT\KeySet
         && $SecureKeys->get($first['jwk']['kid']) !== null,
      'downgrade_kind' => $DowngradeKeys instanceof \Bootgly\API\Security\JWT\Failures
         ? $DowngradeKeys->name
         : get_debug_type($DowngradeKeys),
      'downgrade_status' => $Downgrade->status,
      'downgrade_attacker' => $DowngradeKeys instanceof \Bootgly\API\Security\JWT\KeySet
         && $DowngradeKeys->get($second['jwk']['kid']) !== null,
   ], JSON_THROW_ON_ERROR);
}
catch (Throwable $Exception) {
   fwrite(STDERR, $Exception::class . ': ' . $Exception->getMessage());
   exit(2);
}
PHP;
      $environment = getenv();
      unset(
         $environment['AI_AGENT'],
         $environment['BOOTGLY_AGENT_STDOUT_REDIRECTED'],
         $environment['CODEX_THREAD_ID']
      );
      $environment['BOOTGLY_JWT13_ROOT'] = BOOTGLY_ROOT_DIR;
      $environment['BOOTGLY_JWT13_KEYS'] = __DIR__ . '/fixtures/jwt_rs256.php';
      $environment['BOOTGLY_JWT13_DIRECT'] = "https://127.0.0.1:{$TLSPort}/direct";
      $environment['BOOTGLY_JWT13_SECURE_REDIRECT'] = "https://127.0.0.1:{$TLSPort}/secure-redirect";
      $environment['BOOTGLY_JWT13_DOWNGRADE'] = "https://127.0.0.1:{$TLSPort}/downgrade";
      $descriptors = [
         1 => ['pipe', 'w'],
         2 => ['pipe', 'w'],
      ];
      $Process = null;
      $Pipes = [];
      $clientOutput = '';
      $clientError = '';
      $clientExit = -1;
      $clientResult = [];
      $exception = '';
      $serverReport = [
         'tls_paths' => [],
         'http_paths' => [],
         'error' => 'fixture report was not received',
      ];
      $waited = -1;
      $childStatus = -1;

      try {
         $Process = proc_open(
            [
               PHP_BINARY,
               '-d',
               "openssl.cafile={$certificate}",
               '-r',
               $clientCode,
            ],
            $descriptors,
            $Pipes,
            BOOTGLY_ROOT_DIR,
            $environment
         );
         if (is_resource($Process) === false) {
            throw new RuntimeException('JWT-13 trusted client process could not start.');
         }

         $clientOutput = (string) stream_get_contents($Pipes[1]);
         $clientError = (string) stream_get_contents($Pipes[2]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         $Pipes = [];
         $clientExit = proc_close($Process);
         $Process = null;
         $decoded = json_decode($clientOutput, true);
         if (is_array($decoded)) {
            $clientResult = $decoded;
         }
      }
      catch (Throwable $Exception) {
         $exception = $Exception::class . ': ' . $Exception->getMessage();
      }
      finally {
         foreach ($Pipes as $Pipe) {
            is_resource($Pipe) && fclose($Pipe);
         }
         if (is_resource($Process)) {
            proc_terminate($Process);
            $clientExit = proc_close($Process);
         }

         $line = @fgets($Control);
         if (is_string($line)) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
               $serverReport = $decoded;
            }
         }
         fclose($Control);
         $waited = pcntl_waitpid($PID, $childStatus);
      }

      $childClean = $waited === $PID
         && pcntl_wifexited($childStatus)
         && pcntl_wexitstatus($childStatus) === 0;
      $fixtureClean = $exception === ''
         && $clientExit === 0
         && $childClean
         && $clientError === ''
         && ($serverReport['error'] ?? null) === ''
         && ($serverReport['tls_paths'] ?? null) === [
            '/direct',
            '/secure-redirect',
            '/secure-final',
            '/downgrade',
         ];
      $directSecure = ($clientResult['direct_kind'] ?? null) === 'Bootgly\\API\\Security\\JWT\\KeySet'
         && ($clientResult['direct_status'] ?? null) === 200
         && ($clientResult['direct_legitimate'] ?? null) === true;
      $redirectSecure = ($clientResult['secure_kind'] ?? null) === 'Bootgly\\API\\Security\\JWT\\KeySet'
         && ($clientResult['secure_status'] ?? null) === 200
         && ($clientResult['secure_legitimate'] ?? null) === true;
      $downgradeSecure = ($clientResult['downgrade_attacker'] ?? null) === false
         && ($serverReport['http_paths'] ?? null) === [];

      yield assert(
         assertion: $fixtureClean,
         description: 'The JWT-13 trusted TLS fixture completed and reaped both processes'
            . ($exception === '' ? '' : " ({$exception})")
            . ($clientError === '' ? '' : " ({$clientError})")
      );

      yield assert(
         assertion: $directSecure,
         description: 'The directly fetched HTTPS control trusts the dedicated loopback CA and legitimate key'
      );

      yield assert(
         assertion: $redirectSecure,
         description: 'A verified HTTPS endpoint can follow a verified HTTPS redirect and resolve the legitimate key'
      );

      yield assert(
         assertion: $downgradeSecure,
         description: 'JWT-13 CONFIRMED: insecure=false must neither follow HTTPS to plaintext nor trust the attacker JWKS; evidence='
            . json_encode([
               'client' => $clientResult,
               'server' => $serverReport,
            ])
      );
   }
);
