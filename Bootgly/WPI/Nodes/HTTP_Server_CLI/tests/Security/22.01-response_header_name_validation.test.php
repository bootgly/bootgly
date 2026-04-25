<?php

use function json_encode;
use function preg_match;
use function str_contains;
use function strpos;
use function substr_count;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;
use Throwable;


/**
 * PoC — Response Header API sanitizes values better than names.
 *
 * `Header::set()` strips CR/LF from the value but NOT from the field
 * name; `prepare()` accepts an arbitrary `array<string,string>` and
 * neither validates names nor sanitizes values before the array is
 * concatenated into raw HTTP in `build()` as `"$name: $value"`. An
 * application that ever passes attacker-controlled data into a header
 * NAME (custom routing, A/B-test tags, locale codes from query
 * strings, etc.) opens a response-splitting primitive.
 *
 * The probe drives `Header` directly so no on-wire test harness can
 * launder the payload.
 */

$probe = [
   'error'        => '',
   'set'          => null,                      // set() must return false
   'setField'     => null,                      // injected name must NOT be present
   'queue'        => null,                      // queue() must return false
   'preparedRaw'  => '',                        // raw output after prepare()
   'splitInRaw'   => null,                      // injected line must not appear
];

return new Specification(
   description: 'Response header names must be RFC token-validated and CR/LF-stripped',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      try {
         // ---------- set() ----------
         $H1 = new Header;
         $injectedName = "X-Test\r\nInjected-Header: yes";
         $probe['set']      = $H1->set($injectedName, 'value');
         // After fix, the bad field must not be stored under either form.
         $probe['setField'] = $H1->get($injectedName) === ''
            && $H1->get('X-Test') === ''
            && $H1->get('Injected-Header') === '';

         // ---------- queue() ----------
         $H2 = new Header;
         $probe['queue'] = $H2->queue("Bad Name With Space", 'v');

         // ---------- prepare() ----------
         $H3 = new Header;
         $H3->prepare([
            "X-Smuggled\r\nLocation"   => 'https://evil.test/',
            "GoodHeader"                => "value-with\r\ncrlf-injected: 1",
            "Bad Name With Space"       => 'x',
         ]);
         $H3->build();
         $probe['preparedRaw'] = $H3->raw;

         // The raw must not contain the injected `Location:` line nor the
         // smuggled `crlf-injected:` line broken out of the value.
         $probe['splitInRaw'] = (
            ! preg_match('/^Location:/mi', $H3->raw)
            && ! preg_match('/^crlf-injected:/mi', $H3->raw)
            // No bare LF / CR injected by user payloads — every separator
            // must be a CRLF pair.
            && substr_count($H3->raw, "\n") === substr_count($H3->raw, "\r\n")
         );
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /response-header-name-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response) {
      return $Response(code: 200, body: 'HARNESS-OK');
   },

   test: function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return $probe['error'];
      }

      if ($probe['set'] !== false) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Header::set() accepted a field name containing CRLF (response-splitting primitive).';
      }

      if ($probe['setField'] !== true) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Header::set() persisted a field carrying CRLF in the name.';
      }

      if ($probe['queue'] !== false) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Header::queue() accepted a field name with whitespace (invalid RFC token).';
      }

      if ($probe['splitInRaw'] !== true) {
         Vars::$labels = ['Probe state', 'Built raw header'];
         dump(json_encode($probe), $probe['preparedRaw']);
         return 'Header::prepare() / build() emitted attacker-controlled bytes that produced a response-splitting payload.';
      }

      if (! str_contains($response, 'HARNESS-OK')) {
         Vars::$labels = ['Harness response'];
         dump(json_encode($response));
         return 'Harness request did not reach /response-header-name-harness.';
      }

      return true;
   }
);
