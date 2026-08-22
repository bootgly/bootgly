<?php

namespace Bootgly\API\Security\Tests\JWTRemoteRedirectChain;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function assert;
use function fclose;
use function fgets;
use function fread;
use function function_exists;
use function fwrite;
use function get_debug_type;
use function is_array;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function preg_match;
use function str_contains;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\KeySet;
use Bootgly\API\Security\JWT\Remote;


$supported = function_exists('pcntl_fork')
   && function_exists('pcntl_waitpid')
   && function_exists('openssl_pkey_get_details')
   && function_exists('openssl_pkey_get_private')
   && function_exists('openssl_pkey_get_public')
   && function_exists('stream_socket_pair')
   && function_exists('stream_socket_server');


return new Test(
   description: 'JWT: native remote fetching uses only the final redirect response',
   skip: $supported === false,
   test: function () {
      /** @var array{
       *    first: array{private:string,public:string,jwk:array<string,string>,body:string},
       *    second: array{private:string,public:string,jwk:array<string,string>,body:string}
       * } $fixtures
       */
      $fixtures = require __DIR__ . '/fixtures/jwt_rs256.php';
      $body = $fixtures['first']['body'];
      $JWK = $fixtures['first']['jwk'];
      $keyID = $JWK['kid'];

      $Listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
      if (is_resource($Listener) === false) {
         throw new \RuntimeException("JWT-8 loopback listener failed: {$errorCode} {$error}");
      }

      $address = (string) stream_socket_get_name($Listener, false);
      $separator = strrpos($address, ':');
      $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

      if ($port < 1 || $Pair === false) {
         fclose($Listener);
         throw new \RuntimeException('JWT-8 loopback fixture could not allocate its control channel.');
      }

      [$Control, $ChildControl] = $Pair;
      stream_set_timeout($Control, 5);
      $PID = pcntl_fork();

      if ($PID === -1) {
         fclose($Control);
         fclose($ChildControl);
         fclose($Listener);
         throw new \RuntimeException('JWT-8 loopback fixture could not fork.');
      }

      if ($PID === 0) {
         fclose($Control);
         $paths = [];
         $fixtureError = '';
         $Write = static function ($Socket, string $bytes): bool {
            while ($bytes !== '') {
               $written = @fwrite($Socket, $bytes);
               if ($written === false || $written < 1) {
                  return false;
               }

               $bytes = substr($bytes, $written);
            }

            return true;
         };

         for ($request = 0; $request < 5; $request++) {
            $Client = @stream_socket_accept($Listener, 2.0);
            if (is_resource($Client) === false) {
               $fixtureError = "accepted {$request} of 5 expected requests";
               break;
            }

            stream_set_timeout($Client, 2);
            $head = '';

            while (str_contains($head, "\r\n\r\n") === false && strlen($head) < 65536) {
               $chunk = @fread($Client, 8192);
               if (is_string($chunk) === false || $chunk === '') {
                  break;
               }

               $head .= $chunk;
            }

            $path = '';
            if (preg_match('/^GET\s+([^\s]+)\s+HTTP\/\d(?:\.\d)?/i', $head, $matches) === 1) {
               $path = $matches[1];
            }
            $paths[] = $path;

            $responseBody = '';
            $status = '404 Not Found';
            $headers = [];

            if ($path === '/direct' || $path === '/final') {
               $status = '200 OK';
               $responseBody = $body;
               $headers[] = 'Cache-Control: public, max-age=1';
            }
            elseif ($path === '/redirect') {
               $status = '302 Found';
               $headers[] = "Location: http://127.0.0.1:{$port}/final";
               $headers[] = 'Cache-Control: no-store';
            }
            elseif ($path === '/reject') {
               $status = '302 Found';
               $headers[] = "Location: http://127.0.0.1:{$port}/unavailable";
               $headers[] = 'Cache-Control: public, max-age=31536000';
            }
            elseif ($path === '/unavailable') {
               $status = '503 Service Unavailable';
               $responseBody = $body;
               $headers[] = 'Cache-Control: public, max-age=1';
            }

            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($responseBody);
            $headers[] = 'Connection: close';
            $response = "HTTP/1.1 {$status}\r\n";

            foreach ($headers as $header) {
               $response .= "{$header}\r\n";
            }

            if ($Write($Client, "{$response}\r\n{$responseBody}") === false) {
               $fixtureError = "failed to write response for {$path}";
            }
            fclose($Client);
         }

         $report = json_encode([
            'paths' => $paths,
            'error' => $fixtureError,
         ]);
         $Write($ChildControl, (is_string($report) ? $report : '{}') . "\n");
         fclose($ChildControl);
         fclose($Listener);
         exit($fixtureError === '' ? 0 : 1);
      }

      fclose($ChildControl);
      fclose($Listener);
      $baseURI = "http://127.0.0.1:{$port}";
      $DirectKeys = null;
      $RedirectKeys = null;
      $Rejected = null;
      $directStatus = 0;
      $redirectStatus = 0;
      $redirectTTL = -1;
      $rejectedStatus = 0;
      $exception = '';
      $report = ['paths' => [], 'error' => 'fixture report was not received'];
      $waited = -1;
      $childStatus = -1;

      try {
         $Direct = new Remote("{$baseURI}/direct", ttl: 60, insecure: true);
         $Direct->timeout = 2;
         $DirectKeys = $Direct->fetch();
         $directStatus = $Direct->status;

         $Redirect = new Remote("{$baseURI}/redirect", ttl: 60, insecure: true);
         $Redirect->timeout = 2;
         $RedirectKeys = $Redirect->fetch();
         $redirectStatus = $Redirect->status;
         $redirectTTL = $Redirect->expires === 0
            ? 0
            : $Redirect->expires - $Redirect->fetched;

         $Reject = new Remote("{$baseURI}/reject", ttl: 60, insecure: true);
         $Reject->timeout = 2;
         $Rejected = $Reject->fetch();
         $rejectedStatus = $Reject->status;
      }
      catch (Throwable $Exception) {
         $exception = $Exception::class . ': ' . $Exception->getMessage();
      }
      finally {
         $line = @fgets($Control);
         if (is_string($line)) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
               $report = $decoded;
            }
         }

         fclose($Control);
         $waited = pcntl_waitpid($PID, $childStatus);
      }

      $childClean = $waited === $PID
         && pcntl_wifexited($childStatus)
         && pcntl_wexitstatus($childStatus) === 0;
      $redirectKind = $RedirectKeys instanceof Failures
         ? $RedirectKeys->name
         : get_debug_type($RedirectKeys);

      yield assert(
         assertion: $exception === ''
            && $childClean
            && ($report['error'] ?? null) === ''
            && ($report['paths'] ?? null) === [
               '/direct',
               '/redirect',
               '/final',
               '/reject',
               '/unavailable',
            ],
         description: 'The JWT-8 loopback fixture served and reaped exactly five ordered requests'
            . ($exception === '' ? '' : " ({$exception})")
      );

      yield assert(
         assertion: $DirectKeys instanceof KeySet
            && $DirectKeys->get($keyID) !== null
            && $directStatus === 200,
         description: 'The direct final-response control resolves the fixture signing key'
      );

      yield assert(
         assertion: $RedirectKeys instanceof KeySet
            && $RedirectKeys->get($keyID) !== null
            && $redirectStatus === 200,
         description: "JWT-8: native Remote must use the final redirect status and downloaded JWKS (actual {$redirectKind}, status {$redirectStatus})"
      );

      yield assert(
         assertion: $redirectTTL === 1,
         description: 'JWT-8: a redirect-hop no-store must not override the final response max-age'
      );

      yield assert(
         assertion: $Rejected === Failures::Status && $rejectedStatus === 503,
         description: 'A final non-success response remains rejected after an otherwise valid redirect'
      );
   }
);
