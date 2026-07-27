<?php

use Bootgly\ABI\Templates\Template\Escaped;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ServerException;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC — an ACME problem document must not reach the log renderer with
 * control bytes or Bootgly markup intact.
 *
 * A malicious or compromised configured CA returns a problem document whose
 * `type`/`detail` carries newlines, OSC/DCS terminal controls, or Bootgly format
 * directives. Those values land in the exception message, Auto-TLS logs that
 * message directly, and the Line formatter renders its markup while passing raw
 * terminal control bytes straight through — forging a log record boundary,
 * driving the operator's terminal, or faking severity formatting.
 *
 * The real exception is constructed and its message is pushed through the real
 * `Escaped::render()` the Line formatter uses.
 *
 * Controls: an ordinary detail must survive unchanged, and an e-mail address
 * must keep its `@` — the scrub targets the directive grammar, not text.
 */
$probe = ['error' => '', 'legs' => []];

return new Specification(
   description: 'ACME problem details must not inject controls or markup into logs',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      try {
         $Build = static function (string $type, string $detail): array {
            $Exception = new ServerException($type, $detail, 403);
            $message = $Exception->getMessage();

            return ['message' => $message, 'rendered' => Escaped::render($message)];
         };

         $probe['legs']['newline'] = $Build(
            'urn:ietf:params:acme:error:unauthorized',
            "denied\r\n2026-01-01 FAKE forged record"
         );
         $probe['legs']['terminal'] = $Build(
            'urn:ietf:params:acme:error:malformed',
            "title\x1b]0;pwned\x07 tail"
         );
         $probe['legs']['markup'] = $Build(
            '@\;urn@\;',
            'looks @\;important@\; here'
         );
         $probe['legs']['control_plain'] = $Build(
            'urn:ietf:params:acme:error:rateLimited',
            'too many certificates already issued'
         );
         $probe['legs']['control_email'] = $Build(
            'urn:ietf:params:acme:error:invalidContact',
            'contact admin@example.com to resolve'
         );
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /l1-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/l1-harness', static function (Request $Request, Response $Response) {
         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'L1 harness request did not reach /l1-harness.';
      }
      if ($probe['error'] !== '') {
         return 'L1 fixture error: ' . $probe['error'];
      }

      $legs = $probe['legs'];

      // ? Controls — ordinary diagnostics must survive intact, or the scrub is
      //   destroying the operator's information instead of protecting it.
      if (! str_contains($legs['control_plain']['message'] ?? '', 'too many certificates')) {
         return 'L1 control failed: an ordinary detail did not survive: '
            . json_encode($legs['control_plain'] ?? null);
      }
      if (! str_contains($legs['control_email']['message'] ?? '', 'admin@example.com')) {
         return 'L1 control failed: an e-mail address lost its `@`, so the scrub is hitting '
            . 'text rather than the directive grammar: ' . json_encode($legs['control_email'] ?? null);
      }

      $injected = [];
      foreach (['newline', 'terminal', 'markup'] as $leg) {
         $message = $legs[$leg]['message'] ?? '';
         $rendered = $legs[$leg]['rendered'] ?? '';

         if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $message) === 1) {
            $injected[] = "{$leg}: a control byte survived into the log message";
         }
         if (str_contains($rendered, "\x1b")) {
            $injected[] = "{$leg}: the rendered line carries an ESC sequence";
         }
      }

      if ($injected !== []) {
         return 'CONFIRMED L1: a CA-supplied problem document reached the log renderer with '
            . 'its payload intact — ' . implode('; ', $injected)
            . '. That forges log record boundaries and drives the operator terminal.';
      }

      return true;
   },
);
