<?php

namespace Bootgly\API\Security\Tests\JWTRemoteRedirectReferences;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function assert;
use function fclose;
use function fgets;
use function fread;
use function function_exists;
use function fwrite;
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
use function str_contains;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use ReflectionMethod;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
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
   description: 'JWT: resolve every redirect Location as an RFC URI-reference',
   skip: $supported === false,
   test: function () {
      /** @var array{
       *    first: array{private:string,public:string,jwk:array<string,string>,body:string},
       *    second: array{private:string,public:string,jwk:array<string,string>,body:string}
       * } $fixtures
       */
      $fixtures = require __DIR__ . '/fixtures/jwt_rs256.php';
      $first = $fixtures['first'];
      $Listener = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
      if (is_resource($Listener) === false) {
         throw new \RuntimeException("JWT-14 loopback listener failed: {$errorCode} {$error}");
      }

      $address = (string) stream_socket_get_name($Listener, false);
      $separator = strrpos($address, ':');
      $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      if ($port < 1 || $Pair === false) {
         fclose($Listener);
         throw new \RuntimeException('JWT-14 loopback fixture could not allocate its control channel.');
      }

      [$Control, $ChildControl] = $Pair;
      stream_set_timeout($Control, 5);
      $PID = pcntl_fork();

      if ($PID === -1) {
         fclose($Control);
         fclose($ChildControl);
         fclose($Listener);
         throw new \RuntimeException('JWT-14 loopback fixture could not fork.');
      }

      if ($PID === 0) {
         fclose($Control);
         $paths = [];
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

         for ($request = 0; $request < 2; $request++) {
            $Client = @stream_socket_accept($Listener, 2.0);
            if (is_resource($Client) === false) {
               $fixtureError = "accepted {$request} of 2 expected requests";
               break;
            }

            stream_set_timeout($Client, 2);
            $head = $Read($Client);
            $path = '';
            if (preg_match('/^GET\s+([^\s]+)\s+HTTP\/\d(?:\.\d)?/i', $head, $matches) === 1) {
               $path = $matches[1];
            }
            $paths[] = $path;

            if ($request === 0) {
               $response = "HTTP/1.1 302 Found\r\n"
                  . "Location: /a//b\r\n"
                  . "Content-Length: 0\r\n"
                  . "Connection: close\r\n\r\n";
            }
            else {
               $response = "HTTP/1.1 200 OK\r\n"
                  . "Cache-Control: no-store\r\n"
                  . "Content-Type: application/json\r\n"
                  . 'Content-Length: ' . strlen($first['body']) . "\r\n"
                  . "Connection: close\r\n\r\n"
                  . $first['body'];
            }

            if ($Write($Client, $response) === false) {
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
      $Keys = null;
      $fetchError = '';
      $report = ['paths' => [], 'error' => 'fixture report was not received'];
      $waited = -1;
      $childStatus = -1;

      try {
         $Remote = new Remote(
            "http://127.0.0.1:{$port}/start",
            ttl: 0,
            insecure: true
         );
         $Remote->timeout = 2;
         $Remote->redirects = 1;
         $Keys = $Remote->fetch();
      }
      catch (Throwable $Exception) {
         $fetchError = $Exception::class . ': ' . $Exception->getMessage();
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

      // @ The live path above proves what reached the wire. This direct matrix
      //   isolates every URI-reference branch so a future edit cannot repair
      //   the headline path while regressing another RFC form.
      $base = 'https://a/b/c/d;p?q';
      $Resolver = new Remote($base, static fn (): string => $first['body']);
      $Follow = new ReflectionMethod(Remote::class, 'follow');
      $Locate = new ReflectionMethod(Remote::class, 'locate');
      $references = [
         'https://g/a/../keys' => 'https://g/keys',
         '//g/a/../keys' => 'https://g/keys',
         '/a//b' => 'https://a/a//b',
         'x//y' => 'https://a/b/c/x//y',
         '../g' => 'https://a/b/g',
         '?y' => 'https://a/b/c/d;p?y',
         '#s' => 'https://a/b/c/d;p?q',
         'g#s' => 'https://a/b/c/g',
         'g:h' => 'g:h',
         './g' => 'https://a/b/c/g',
         '/g' => 'https://a/g',
         '../../../g' => 'https://a/g',
         'g/../h' => 'https://a/b/c/h',
         ';p' => 'https://a/b/c/;p',
      ];
      $mismatches = [];

      foreach ($references as $location => $expected) {
         $actual = $Follow->invoke($Resolver, $base, $location);
         if ($actual !== $expected) {
            $mismatches[$location] = [
               'expected' => $expected,
               'actual' => $actual,
            ];
         }
      }

      $emptyLocation = $Locate->invoke($Resolver, [
         'HTTP/1.1 302 Found',
         'Location:',
      ]);
      $emptyReference = $Follow->invoke($Resolver, $base, '');
      $emptyPassed = $emptyLocation === '' && $emptyReference === $base;

      $childClean = $waited === $PID
         && pcntl_wifexited($childStatus)
         && pcntl_wexitstatus($childStatus) === 0;
      $fixtureClean = $fetchError === ''
         && $childClean
         && ($report['error'] ?? null) === ''
         && is_array($report['paths'] ?? null)
         && ($report['paths'][0] ?? null) === '/start'
         && isset($report['paths'][1]);
      $livePassed = $Keys instanceof KeySet
         && $Keys->get($first['jwk']['kid']) !== null
         && ($report['paths'][1] ?? null) === '/a//b';

      yield assert(
         assertion: $fixtureClean,
         description: 'The JWT-14 loopback fixture completed both requests and reaped its child'
            . ($fetchError === '' ? '' : " ({$fetchError})")
      );

      yield assert(
         assertion: $livePassed && $mismatches === [],
         description: 'JWT-14 CONFIRMED: native redirects must preserve and normalize every RFC URI-reference; evidence='
            . json_encode([
               'paths' => $report['paths'] ?? null,
               'mismatches' => $mismatches,
            ])
      );

      yield assert(
         assertion: $mismatches === [],
         description: 'Absolute, network-path, relative, query, fragment and RFC reference controls resolve exactly'
      );

      yield assert(
         assertion: $emptyPassed,
         description: 'JWT-15 CONFIRMED: an empty Location is an empty URI-reference to the current request URI; evidence='
            . json_encode([
               'located' => $emptyLocation,
               'resolved' => $emptyReference,
               'expected' => $base,
            ])
      );
   }
);
