<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-15: the rest of the snapshot contract `Response::$Request` advertises.
 *
 * `1.1` pins the fields; this pins the two carries no other spec reaches: the
 * completed uploads `defer()` moves into the snapshot, and the credentials the
 * exchange admitted — both the Basic pair parsed off the wire and the values a
 * middleware assigned by hand, which no header can re-derive once the
 * synchronous cycle scrubbed the live Request.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $wire = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $boundary = 'BG15DEFERRED';
         $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"alpha\"\r\n\r\n1\r\n"
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"upload\"; filename=\"deferred.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nDEFERRED\r\n"
            . "--{$boundary}--\r\n";
         $credentials = base64_encode('alice:s3cr3t');
         $request = "POST /deferred/credentials HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Authorization: Basic {$credentials}\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n{$body}";
         $Socket = Client::open($hostPort);
         $Probe->wire = Client::send($Socket, $request, 5.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'wire' => $Probe->wire]);
      $decoded = json_decode((string) ($Probe->wire['body'] ?? ''), true);
      $decoded = is_array($decoded) ? $decoded : [];

      yield new Assertion(
         description: 'The deferred exchange answered 200',
         fallback: "Unexpected status: {$evidence}"
      )
         ->expect($Probe->wire['code'] ?? 0)
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'The snapshot keeps the Basic credentials after the first wait()',
         fallback: "Credentials lost on the snapshot: {$evidence}"
      )
         ->expect([$decoded['username'] ?? null, $decoded['password'] ?? null])
         ->to->be(['alice', 's3cr3t'])
         ->assert();

      yield new Assertion(
         description: 'The snapshot keeps the credentials a middleware admitted by hand',
         fallback: "Admitted credentials lost on the snapshot: {$evidence}"
      )
         ->expect([$decoded['token'] ?? null, $decoded['headers'] ?? null])
         ->to->be(['INJECTED-TOKEN', ['x-fixture' => 'injected-header']])
         ->assert();

      yield new Assertion(
         description: 'The snapshot owns the completed uploads after the first wait()',
         fallback: "Uploads lost on the snapshot: {$evidence}"
      )
         ->expect([$decoded['files'] ?? null, $decoded['filename'] ?? null])
         ->to->be([1, 'deferred.txt'])
         ->assert();

      yield new Assertion(
         description: 'The moved upload is still on disk and readable by the work',
         fallback: "Upload custody lost on the snapshot: {$evidence}"
      )
         ->expect([$decoded['stored'] ?? null, $decoded['bytes'] ?? null])
         ->to->be([true, 'DEFERRED'])
         ->assert();
   })
);
