<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M5 — query-decoded control bytes must not split an executable
 * redirect scheme before `Header::set()` serializes `Location`.
 *
 * The application explicitly allows external redirects and forwards a scalar
 * query parameter, which is the finding's required application precondition.
 * `parse_str()` decodes `%0A`, `%0D` and `%09` before redirect() applies its
 * dangerous-scheme guard. On vulnerable code, LF/CR prevent that guard from
 * seeing a contiguous scheme, then Header::set() removes them and emits a
 * literal `javascript:` / `vbscript:` Location. HTAB remains on the wire and
 * WHATWG URL parsing removes it before scheme parsing.
 *
 * Controls prove that:
 * - the real query decoder delivered the exact bytes to the handler;
 * - a legitimate external HTTPS redirect still traverses the same path; and
 * - the existing contiguous executable-scheme guard is active.
 */
$targets = [
   'safe_external' => 'https://control.example.test/safe',
   'contiguous_javascript' => 'javascript:alert(1)',
   'lf_javascript' => "java\nscript:alert(1)",
   'cr_vbscript' => "vbscr\ript:msgbox(1)",
   'tab_javascript' => "java\tscript:alert(1)",
];

return new Test(
   description: 'Redirects must reject control-byte-split executable schemes',
   Separator: new Separator(line: true),

   requests: [
      static function () use ($targets): string {
         return 'GET /m5/redirect?next='
            . rawurlencode($targets['safe_external'])
            . " HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function () use ($targets): string {
         return 'GET /m5/redirect?next='
            . rawurlencode($targets['contiguous_javascript'])
            . " HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function () use ($targets): string {
         return 'GET /m5/redirect?next='
            . rawurlencode($targets['lf_javascript'])
            . " HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function () use ($targets): string {
         return 'GET /m5/redirect?next='
            . rawurlencode($targets['cr_vbscript'])
            . " HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function () use ($targets): string {
         return 'GET /m5/redirect?next='
            . rawurlencode($targets['tab_javascript'])
            . " HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      static function () use ($targets): string {
         return "GET /m5/matrix HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m5/redirect', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $URI = $Request->query('next');

         // @ Byte-level witness that the source passed through Request's real
         //   percent-decoding path before redirect() received it.
         $Response->Header->set('X-M5-Decoded-Hex', bin2hex($URI));

         return $Response->redirect($URI, allowExternal: true);
      }, GET);

      yield $Router->route('/m5/matrix', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $octets = [...range(0x00, 0x1F), 0x7F, 0x5C];
         $schemes = ['javascript', 'data', 'vbscript', 'file'];
         $tested = 0;
         $failed = 0;
         $failures = [];

         $Inspect = static function (
            Response $Probe,
            string $mode,
            int $octet,
            null|string $scheme = null,
            null|int $position = null,
         ) use (&$tested, &$failed, &$failures): void {
            $tested++;
            $location = $Probe->Header->get('Location');

            if ($Probe->code === 307 && $Probe->sent && $location === '/') {
               return;
            }

            $failed++;
            if (count($failures) >= 12) {
               return;
            }

            $failures[] = [
               'mode' => $mode,
               'scheme' => $scheme,
               'position' => $position,
               'octet_hex' => sprintf('%02x', $octet),
               'code' => $Probe->code,
               'sent' => $Probe->sent,
               'location_hex' => bin2hex($location),
            ];
         };

         // ! Every forbidden byte at every internal position of every scheme.
         foreach ($schemes as $scheme) {
            $length = strlen($scheme);
            foreach (range(1, $length - 1) as $position) {
               foreach ($octets as $octet) {
                  $URI = substr($scheme, 0, $position)
                     . chr($octet)
                     . substr($scheme, $position)
                     . ':m5';
                  $Probe = new Response;
                  $Probe->redirect($URI, allowExternal: true);
                  $Inspect($Probe, 'scheme', $octet, $scheme, $position);
               }
            }
         }

         // ! The common boundary applies to ordinary internal and external
         //   targets too, not only strings resembling executable schemes.
         foreach ($octets as $octet) {
            $URI = '/safe' . chr($octet) . 'path';
            $Probe = new Response;
            $Probe->redirect($URI);
            $Inspect($Probe, 'internal', $octet);

            $URI = 'https://control.example.test/safe' . chr($octet) . 'path';
            $Probe = new Response;
            $Probe->redirect($URI, allowExternal: true);
            $Inspect($Probe, 'external', $octet);
         }

         $Internal = new Response;
         $Internal->redirect('/safe/path');
         $External = new Response;
         $External->redirect(
            'https://control.example.test/safe',
            allowExternal: true
         );

         return $Response->JSON->send([
            'tested' => $tested,
            'failures_total' => $failed,
            'failures_sample' => $failures,
            'controls' => [
               'internal' => [
                  'code' => $Internal->code,
                  'sent' => $Internal->sent,
                  'location' => $Internal->Header->get('Location'),
               ],
               'external' => [
                  'code' => $External->code,
                  'sent' => $External->sent,
                  'location' => $External->Header->get('Location'),
               ],
            ],
         ]);
      }, GET);
   },

   test: static function (array $responses) use ($targets): bool|string {
      if (count($responses) !== count($targets) + 1) {
         return 'M5 fixture failed: expected six responses, got '
            . count($responses) . '.';
      }

      $Header = static function (string $head, string $field): null|string {
         foreach (explode("\r\n", $head) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
               continue;
            }

            if (strcasecmp(substr($line, 0, $colon), $field) === 0) {
               return ltrim(substr($line, $colon + 1), " \t");
            }
         }

         return null;
      };

      $evidence = [];
      foreach (array_keys($targets) as $index => $leg) {
         $response = $responses[$index] ?? '';
         if (! is_string($response) || $response === '') {
            return "M5 fixture failed: {$leg} received no response.";
         }

         $separator = strpos($response, "\r\n\r\n");
         if ($separator === false) {
            return "M5 fixture failed: {$leg} received no complete response head.";
         }

         $head = substr($response, 0, $separator);
         if (! str_starts_with($head, 'HTTP/1.1 307 ')) {
            Vars::$labels = ['M5 non-redirect response'];
            dump(json_encode(['leg' => $leg, 'head' => $head]));

            return "M5 fixture failed: {$leg} did not receive HTTP 307.";
         }

         $decodedHex = $Header($head, 'X-M5-Decoded-Hex');
         $expectedHex = bin2hex($targets[$leg]);
         if ($decodedHex !== $expectedHex) {
            Vars::$labels = ['M5 query-decoding evidence'];
            dump(json_encode([
               'leg' => $leg,
               'expected_hex' => $expectedHex,
               'decoded_hex' => $decodedHex,
            ]));

            return "M5 fixture failed: {$leg} did not traverse the expected query-decoding path.";
         }

         $location = $Header($head, 'Location');
         if ($location === null) {
            return "M5 fixture failed: {$leg} emitted no Location header.";
         }

         $evidence[$leg] = [
            'input_hex' => $expectedHex,
            'location' => $location,
            'location_hex' => bin2hex($location),
         ];
      }

      // ? Positive external-redirect control: refusing every target cannot
      //   make the security oracle pass.
      if (
         $evidence['safe_external']['location']
         !== $targets['safe_external']
      ) {
         Vars::$labels = ['M5 safe external redirect control'];
         dump(json_encode($evidence, JSON_UNESCAPED_SLASHES));

         return 'M5 control failed: a legitimate HTTPS external redirect was not preserved.';
      }

      // ? Existing-guard control: a contiguous dangerous scheme is already
      //   required to fall back to `/` in both internal and external modes.
      if ($evidence['contiguous_javascript']['location'] !== '/') {
         Vars::$labels = ['M5 contiguous executable-scheme control'];
         dump(json_encode($evidence, JSON_UNESCAPED_SLASHES));

         return 'M5 control failed: contiguous javascript was not rejected.';
      }

      $bypasses = [];
      foreach (['lf_javascript', 'cr_vbscript', 'tab_javascript'] as $leg) {
         $location = $evidence[$leg]['location'];
         $normalized = str_replace(["\r", "\n", "\t"], '', $location);

         if (
            preg_match(
               '#^\s*(?:javascript|data|vbscript|file)\s*:#i',
               $normalized
            ) === 1
         ) {
            $bypasses[$leg] = $evidence[$leg] + [
               'normalized' => $normalized,
               'normalized_hex' => bin2hex($normalized),
            ];
         }
      }

      if ($bypasses !== []) {
         Vars::$labels = ['M5 executable-scheme redirect evidence'];
         dump(json_encode($bypasses, JSON_UNESCAPED_SLASHES));

         return 'CONFIRMED M5: query-decoded control-byte splitting bypassed '
            . 'executable-scheme rejection in the emitted Location header. Evidence: '
            . json_encode($bypasses, JSON_UNESCAPED_SLASHES);
      }

      foreach (['lf_javascript', 'cr_vbscript', 'tab_javascript'] as $leg) {
         if ($evidence[$leg]['location'] !== '/') {
            Vars::$labels = ['M5 unexpected redirect sanitization'];
            dump(json_encode($evidence, JSON_UNESCAPED_SLASHES));

            return "M5 secure outcome failed: {$leg} did not fall back to `/`.";
         }
      }

      $matrixResponse = $responses[count($targets)] ?? '';
      $separator = strpos($matrixResponse, "\r\n\r\n");
      if (
         $separator === false
         || ! str_starts_with($matrixResponse, 'HTTP/1.1 200 ')
      ) {
         return 'M5 matrix fixture failed: no complete HTTP 200 response.';
      }

      try {
         $matrix = json_decode(
            substr($matrixResponse, $separator + 4),
            true,
            flags: JSON_THROW_ON_ERROR
         );
      }
      catch (Throwable $Throwable) {
         return 'M5 matrix fixture failed: invalid JSON — '
            . $Throwable->getMessage();
      }

      $internal = $matrix['controls']['internal'] ?? [];
      $external = $matrix['controls']['external'] ?? [];
      $matrixPassed = ($matrix['tested'] ?? null) === 816
         && ($matrix['failures_total'] ?? null) === 0
         && ($matrix['failures_sample'] ?? null) === []
         && ($internal['code'] ?? null) === 307
         && ($internal['sent'] ?? null) === true
         && ($internal['location'] ?? null) === '/safe/path'
         && ($external['code'] ?? null) === 307
         && ($external['sent'] ?? null) === true
         && ($external['location'] ?? null)
            === 'https://control.example.test/safe';

      if (! $matrixPassed) {
         Vars::$labels = ['M5 common redirect boundary matrix'];
         dump(json_encode($matrix, JSON_UNESCAPED_SLASHES));

         return 'M5 remediation incomplete: common C0/DEL/backslash rejection '
            . 'failed its retained redirect matrix.';
      }

      return true;
   },
);
