<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Compression;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\ETag;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-22, the shipped victims: `ETag` and `Compression` work after `$next()`
 * by nature (hash/encode the final body) and were silently lost on every
 * deferred route. Implementing `Sealing`, both now run at settlement — and
 * in the unwind's order: Compression (innermost) seals first, ETag hashes
 * the bytes actually on the wire. The revalidation round-trip proves the
 * whole surface: 200 + gzip + ETag, then 304 on `If-None-Match`.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $first = [];
   /** @var array<string,mixed> */
   public array $replay = [];
   public string $etag = '';
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->first = Client::send(
            $Socket,
            Client::request('/deferred/seal/shipped', $testIndex, ['Accept-Encoding' => 'gzip']),
            4.0
         );
         if (preg_match('/ETag: (\S+)/', $Probe->first['head'] ?? '', $matches) === 1) {
            $Probe->etag = $matches[1];
         }
         $Probe->replay = Client::send(
            $Socket,
            Client::request('/deferred/seal/shipped', $testIndex, [
               'Accept-Encoding' => 'gzip',
               'If-None-Match' => $Probe->etag
            ]),
            4.0
         );
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      // ! ETag OUTSIDE Compression, as its own contract orders the chain
      yield $Router->route('/deferred/seal/shipped', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            $Response->JSON->send(['blob' => str_repeat('a', 2048)]);
         });
      }, GET, [new ETag, new Compression]);

      yield $Router->route('/deferred/ping', static function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      }, GET);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'etag' => $Probe->etag,
         'first' => $Probe->first['head'] ?? null,
         'replay' => [$Probe->replay['code'] ?? 0, $Probe->replay['head'] ?? null]
      ]);
      $body = (string) ($Probe->first['body'] ?? '');

      yield new Assertion(
         description: 'The deferred wire is compressed and carries a validator',
         fallback: "gzip/ETag missing on the deferred wire: {$evidence}"
      )
         ->expect(
            ($Probe->first['code'] ?? 0) === 200
            && str_contains($Probe->first['head'] ?? '', 'Content-Encoding: gzip')
            && $Probe->etag !== ''
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The body inflates back to the work\'s JSON',
         fallback: "gzip round-trip failed: {$evidence}"
      )
         ->expect(
            $body !== ''
            && str_contains((string) @gzdecode($body), '"blob":"aaa')
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'ETag identifies the bytes on the wire — Compression sealed first',
         fallback: "validator does not match the delivered representation: {$evidence}"
      )
         ->expect($Probe->etag)
         ->to->be('W/"' . hash('xxh3', $body) . '"')
         ->assert();

      yield new Assertion(
         description: 'Revalidation answers 304 with an empty body',
         fallback: "If-None-Match replay diverged: {$evidence}"
      )
         ->expect(
            ($Probe->replay['code'] ?? 0) === 304
            && ($Probe->replay['body'] ?? 'x') === ''
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The keep-alive connection still answers',
         fallback: "final ping failed: {$response}"
      )
         ->expect(str_starts_with($response, 'HTTP/1.1 200'))
         ->to->be(true)
         ->assert();
   })
);
