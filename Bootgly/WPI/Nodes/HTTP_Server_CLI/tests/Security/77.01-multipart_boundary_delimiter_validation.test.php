<?php

use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security regression M3 — multipart boundary delimiter classification.
 *
 * A boundary token inside a part is a delimiter only when its suffix is a
 * valid next-part line or closing line. Bootgly's fail-closed policy rejects
 * malformed line-start candidates instead of recovering ambiguously.
 */

$probe = [
   'error' => '',
   'control' => [],
   'padded_control' => [],
   'terminal_controls' => [],
   'initial_attack' => [],
   'field_attack' => [],
   'file_attack' => [],
   'padding_stress' => [],
   'closing_padding_stress' => [],
   'closing_attacks' => [],
   'split_controls' => [],
   'split_attacks' => [],
   'anchor_control' => [],
   'anchor_decode' => [],
   'anchor_feed' => [],
   'feed_control' => [],
   'anchor_cuts' => [],
   'absolute_cuts' => [],
   'preamble_control' => [],
   'preamble_cuts' => [],
   'recovery_control' => [],
   'recovery_decode' => [],
   'recovery_feed' => [],
   'downloads_baseline' => null,
   'downloads_after' => null,
];

return new Specification(
   description: 'Multipart boundary tokens require a valid delimiter suffix',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $WPI = WPI;
      $OldRequest = $WPI->Request;
      $OldServerRequest = Server::$Request;

      $Run = static function (
         string $boundary,
         string $body,
         array $chunks = [],
         bool $feed = false
      ): array {
         $Request = new Request;
         $Request->Body->downloaded = 0;
         $Request->Body->length = strlen($body);
         $Request->Body->waiting = true;
         $Request->Body->streaming = true;

         $Package = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };

         $Decoder = new Decoder_Downloading;
         $Decoder->Request = $Request;
         $Decoder->init('--' . $boundary);
         $Package->Decoder = $Decoder;

         $states = [];
         $consumed = [];
         $tails = [];
         $charged = null;
         $fileContent = null;
         $Tail = new ReflectionProperty($Decoder, 'tailBuffer');
         $maxTail = 0;

         if ($chunks === []) {
            $chunks = [$body];
         }

         try {
            if ($feed) {
               $first = array_shift($chunks);
               if (! is_string($first)) {
                  throw new RuntimeException('The feed path requires an initial chunk.');
               }

               $Decoder->feed($first);
               $charged = $Decoder->charge();
               $states[] = 'feed';
               $consumed[] = strlen($first);
               $tail = (string) $Tail->getValue($Decoder);
               $tails[] = bin2hex($tail);
               $maxTail = strlen($tail);
            }

            foreach ($chunks as $chunk) {
               $State = $Decoder->decode($Package, $chunk, strlen($chunk));
               $states[] = $State->name;
               $consumed[] = $Package->consumed;
               $tail = (string) $Tail->getValue($Decoder);
               $tails[] = bin2hex($tail);
               $maxTail = max(
                  $maxTail,
                  strlen($tail)
               );

               if ($State !== States::Incomplete) {
                  break;
               }
            }

            $fields = $Request->fields;
            $file = $Request->download('upload');
            if (
               is_array($file)
               && is_string($file['tmp_name'] ?? null)
               && $file['tmp_name'] !== ''
               && is_file($file['tmp_name'])
            ) {
               $content = file_get_contents($file['tmp_name']);
               $fileContent = is_string($content) ? $content : null;
            }

            return [
               'states' => $states,
               'consumed' => $consumed,
               'tails' => $tails,
               'charged' => $charged,
               'rejected' => $Package->rejected,
               'fields' => $fields,
               'file_content' => $fileContent,
               'length' => strlen($body),
               'downloaded' => $Request->Body->downloaded,
               'waiting' => $Request->Body->waiting,
               'max_tail' => $maxTail,
            ];
         }
         finally {
            if ($Request->hasFiles) {
               $Request->clean();
            }
            $Decoder->disconnect();
         }
      };

      try {
         $probe['downloads_baseline'] = Downloads::peek();

         $boundary = 'Bootgly-M3-Delimiter';
         $wireBoundary = '--' . $boundary;
         $headerSafe = "Content-Disposition: form-data; name=\"safe\"\r\n\r\n";
         $headerAdmin = "Content-Disposition: form-data; name=\"admin\"\r\n\r\n";

         $controlBody = $wireBoundary . "\r\n"
            . $headerSafe
            . "SAFE\r\n"
            . $wireBoundary . "\r\n"
            . $headerAdmin
            . "false\r\n"
            . $wireBoundary . "--\r\n";
         $probe['control'] = $Run($boundary, $controlBody);

         // @ RFC 2046 requires receivers to accept SP/HTAB transport padding.
         $paddedBody = $wireBoundary . " \t\r\n"
            . $headerSafe
            . "SAFE\r\n"
            . $wireBoundary . "\t \r\n"
            . $headerAdmin
            . "false\r\n"
            . $wireBoundary . "-- \t\r\n";
         $probe['padded_control'] = $Run($boundary, $paddedBody);

         // @ Closing may coincide exactly with Content-Length or precede a
         //   CRLF-delimited epilogue.
         $bareCloseBody = $wireBoundary . "\r\n"
            . $headerSafe
            . "SAFE\r\n"
            . $wireBoundary . '--';
         $epilogueBody = $wireBoundary . "\r\n"
            . $headerSafe
            . "SAFE\r\n"
            . $wireBoundary . "-- \t\r\nIGNORED EPILOGUE";
         $probe['terminal_controls']['bare'] = $Run($boundary, $bareCloseBody);
         $probe['terminal_controls']['epilogue'] = $Run($boundary, $epilogueBody);

         // ! A close marker still requires valid padding/line termination.
         foreach (
            [
               'bytes' => '--XY',
               'padding' => "-- \tX",
               'cr' => "--\rX",
            ] as $name => $suffix
         ) {
            $body = $wireBoundary . "\r\n"
               . $headerSafe
               . "SAFE\r\n"
               . $wireBoundary . $suffix . "\r\n"
               . $wireBoundary . "--\r\n";
            $probe['closing_attacks'][$name] = $Run($boundary, $body);
         }

         // ! Invalid token in the multipart preamble must never open a part.
         $initialBody = $wireBoundary . 'XY'
            . $headerAdmin
            . "true\r\n"
            . $wireBoundary . "\r\n"
            . $headerSafe
            . "SAFE\r\n"
            . $wireBoundary . "--\r\n";
         $probe['initial_attack'] = $Run($boundary, $initialBody);

         // ! Invalid token inside a text field must fail closed before the
         //   apparent admin part can be committed.
         $fieldTail = $wireBoundary . 'XY'
            . $headerAdmin
            . "true";
         $fieldBody = $wireBoundary . "\r\n"
            . $headerSafe
            . 'SAFE' . "\r\n"
            . $fieldTail . "\r\n"
            . $wireBoundary . "--\r\n";
         $probe['field_attack'] = $Run($boundary, $fieldBody);

         // ! The same differential in a file body must reject before closing
         //   and handing off a truncated tempfile.
         $fileHeader = "Content-Disposition: form-data; name=\"upload\"; filename=\"m3.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n";
         $fileTail = $wireBoundary . 'XY'
            . $headerAdmin
            . "true";
         $fileBody = $wireBoundary . "\r\n"
            . $fileHeader
            . 'FILE' . "\r\n"
            . $fileTail . "\r\n"
            . $wireBoundary . "--\r\n";
         $probe['file_attack'] = $Run($boundary, $fileBody);

         // ! The same body must have identical semantics regardless of where
         //   transport fragmentation occurs. Truncating preamble context must
         //   never promote an inline token to absolute body offset zero.
         $anchorBoundary = 'Bootgly-M3-Anchor';
         $anchorWireBoundary = '--' . $anchorBoundary;
         $anchorBody = 'X' . $anchorWireBoundary . "\r\n"
            . $headerAdmin
            . "true\r\n"
            . $anchorWireBoundary . "--\r\n";
         $anchorCut = 1 + strlen($anchorWireBoundary) + 2;
         $anchorChunks = [
            substr($anchorBody, 0, $anchorCut),
            substr($anchorBody, $anchorCut),
         ];
         $probe['anchor_control'] = $Run($anchorBoundary, $anchorBody);
         $probe['anchor_decode'] = $Run(
            $anchorBoundary,
            $anchorBody,
            $anchorChunks
         );
         $probe['anchor_feed'] = $Run(
            $anchorBoundary,
            $anchorBody,
            $anchorChunks,
            true
         );
         $feedCut = strlen($wireBoundary) + 2;
         $probe['feed_control'] = $Run(
            $boundary,
            $controlBody,
            [
               substr($controlBody, 0, $feedCut),
               substr($controlBody, $feedCut),
            ],
            true
         );

         // @ Exercise every transport cut before/after the inline token and
         //   its CRLF. No segmentation may change the complete-body field map.
         $anchorEnd = 1 + strlen($anchorWireBoundary) + 2;
         for ($cut = 1; $cut <= $anchorEnd; $cut++) {
            $chunks = [
               substr($anchorBody, 0, $cut),
               substr($anchorBody, $cut),
            ];
            $probe['anchor_cuts'][$cut] = [
               'decode' => $Run($anchorBoundary, $anchorBody, $chunks),
               'feed' => $Run($anchorBoundary, $anchorBody, $chunks, true),
            ];
         }

         // @ A genuine absolute-start boundary must remain eligible while it
         //   and its immediate CRLF are fragmented at every byte.
         $absoluteEnd = strlen($wireBoundary) + 2;
         for ($cut = 1; $cut <= $absoluteEnd; $cut++) {
            $chunks = [
               substr($controlBody, 0, $cut),
               substr($controlBody, $cut),
            ];
            $probe['absolute_cuts'][$cut] = [
               'decode' => $Run($boundary, $controlBody, $chunks),
               'feed' => $Run($boundary, $controlBody, $chunks, true),
            ];
         }

         // @ A legal preamble boundary remains discoverable through its
         //   retained CRLF even after absolute-start provenance is revoked.
         $preamble = 'M3 PREAMBLE';
         $preambleBody = $preamble . "\r\n"
            . $wireBoundary . "\r\n"
            . $headerSafe
            . "SAFE\r\n"
            . $wireBoundary . "--\r\n";
         $probe['preamble_control'] = $Run($boundary, $preambleBody);
         $preambleStart = strlen($preamble);
         $preambleEnd = $preambleStart + 2 + strlen($wireBoundary) + 2;
         for ($cut = $preambleStart; $cut <= $preambleEnd; $cut++) {
            $chunks = [
               substr($preambleBody, 0, $cut),
               substr($preambleBody, $cut),
            ];
            $probe['preamble_cuts'][$cut] = [
               'decode' => $Run($boundary, $preambleBody, $chunks),
               'feed' => $Run($boundary, $preambleBody, $chunks, true),
            ];
         }

         // @ Refusing the promoted inline token must not hide the first later
         //   CRLF-anchored delimiter.
         $recoveryBody = 'X' . $wireBoundary . "\r\n"
            . $headerAdmin
            . "true\r\n"
            . $wireBoundary . "\r\n"
            . $headerSafe
            . "SAFE\r\n"
            . $wireBoundary . "--\r\n";
         $recoveryCut = 1 + strlen($wireBoundary) + 2;
         $recoveryChunks = [
            substr($recoveryBody, 0, $recoveryCut),
            substr($recoveryBody, $recoveryCut),
         ];
         $probe['recovery_control'] = $Run($boundary, $recoveryBody);
         $probe['recovery_decode'] = $Run(
            $boundary,
            $recoveryBody,
            $recoveryChunks
         );
         $probe['recovery_feed'] = $Run(
            $boundary,
            $recoveryBody,
            $recoveryChunks,
            true
         );

         // @ Fragment every suffix byte for immediate and padded next/close
         //   lines. Every first slice must wait; every second must complete.
         $nextMarker = "\r\n" . $wireBoundary . "\r\n";
         $nextAt = strpos($controlBody, $nextMarker);
         $closingAt = strrpos($controlBody, $wireBoundary . '--');
         $paddedNextMarker = "\r\n" . $wireBoundary . "\t \r\n";
         $paddedNextAt = strpos($paddedBody, $paddedNextMarker);
         $paddedClosingAt = strrpos($paddedBody, $wireBoundary . '--');
         if (
            $nextAt === false
            || $closingAt === false
            || $paddedNextAt === false
            || $paddedClosingAt === false
         ) {
            throw new RuntimeException('Could not locate valid control delimiters.');
         }

         $splitSpecs = [
            'next' => [$controlBody, $nextAt + 2 + strlen($wireBoundary), 2],
            'closing' => [$controlBody, $closingAt + strlen($wireBoundary), 2],
            'initial_padding' => [$paddedBody, strlen($wireBoundary), 4],
            'next_padding' => [
               $paddedBody,
               $paddedNextAt + 2 + strlen($wireBoundary),
               4,
            ],
            'closing_padding' => [
               $paddedBody,
               $paddedClosingAt + strlen($wireBoundary),
               6,
            ],
         ];
         foreach ($splitSpecs as $name => [$body, $suffixAt, $suffixLength]) {
            for ($index = 0; $index < $suffixLength; $index++) {
               $cut = $suffixAt + $index;
               $probe['split_controls'][$name . '_' . $index] = $Run(
                  $boundary,
                  $body,
                  [substr($body, 0, $cut), substr($body, $cut)]
               );
            }
         }

         // ! Invalid candidates fragmented exactly where the old decoder
         //   committed a field/file must wait once, then reject.
         $fieldCandidate = strpos($fieldBody, "\r\n" . $wireBoundary . 'XY');
         $fileCandidate = strpos($fileBody, "\r\n" . $wireBoundary . 'XY');
         $closingBody = $wireBoundary . "\r\n"
            . $headerSafe
            . "SAFE\r\n"
            . $wireBoundary . "--XY\r\n"
            . $wireBoundary . "--\r\n";
         $closingCandidate = strpos($closingBody, "\r\n" . $wireBoundary . '--XY');
         if (
            $fieldCandidate === false
            || $fileCandidate === false
            || $closingCandidate === false
         ) {
            throw new RuntimeException('Could not locate invalid attack delimiters.');
         }

         $attackSplits = [
            'initial' => [$initialBody, strlen($wireBoundary)],
            'field' => [
               $fieldBody,
               $fieldCandidate + 2 + strlen($wireBoundary),
            ],
            'file' => [
               $fileBody,
               $fileCandidate + 2 + strlen($wireBoundary),
            ],
            'closing_hyphen' => [
               $closingBody,
               $closingCandidate + 2 + strlen($wireBoundary) + 1,
            ],
            'closing_suffix' => [
               $closingBody,
               $closingCandidate + 2 + strlen($wireBoundary) + 2,
            ],
         ];
         foreach ($attackSplits as $name => [$body, $cut]) {
            $probe['split_attacks'][$name] = $Run(
               $boundary,
               $body,
               [substr($body, 0, $cut), substr($body, $cut)]
            );
         }

         // ! Unbounded RFC transport padding fragmented one byte at a time
         //   must remain linear: retain only a canonical delimiter prefix,
         //   never the attacker-growing padding run.
         $stressPadding = str_repeat(' ', 4096);
         $stressBody = $wireBoundary . $stressPadding . "\r\n"
            . $headerSafe
            . "SAFE\r\n"
            . $wireBoundary . "--\r\n";
         $stressChunks = [$wireBoundary];
         foreach (str_split($stressPadding) as $paddingByte) {
            $stressChunks[] = $paddingByte;
         }
         $stressChunks[] = substr(
            $stressBody,
            strlen($wireBoundary) + strlen($stressPadding)
         );
         $probe['padding_stress'] = $Run(
            $boundary,
            $stressBody,
            $stressChunks
         );

         $closingStressPrefix = $wireBoundary . "\r\n"
            . $headerSafe
            . "SAFE\r\n"
            . $wireBoundary . '--';
         $closingStressBody = $closingStressPrefix
            . $stressPadding . "\r\nIGNORED EPILOGUE";
         $closingStressChunks = [$closingStressPrefix];
         foreach (str_split($stressPadding) as $paddingByte) {
            $closingStressChunks[] = $paddingByte;
         }
         $closingStressChunks[] = "\r\nIGNORED EPILOGUE";
         $probe['closing_padding_stress'] = $Run(
            $boundary,
            $closingStressBody,
            $closingStressChunks
         );
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $probe['downloads_after'] = Downloads::peek();
         $WPI->Request = $OldRequest;
         Server::$Request = $OldServerRequest;
      }

      return "GET /m3-delimiter-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m3-delimiter-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'M3-DELIMITER-HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['M3 delimiter probe'];
         dump(json_encode($probe));
         return $probe['error'];
      }
      if (! str_contains($response, 'M3-DELIMITER-HARNESS-OK')) {
         Vars::$labels = ['M3 delimiter harness response'];
         dump(json_encode(['response' => $response]));
         return 'M3 delimiter harness did not receive its control response.';
      }

      $control = $probe['control'];
      if (
         ($control['states'] ?? []) !== [States::Complete->name]
         || ($control['rejected'] ?? true) !== false
         || ($control['fields'] ?? []) !== ['safe' => 'SAFE', 'admin' => 'false']
         || ($control['downloaded'] ?? -1) !== ($control['length'] ?? -2)
         || ($control['waiting'] ?? true) !== false
      ) {
         Vars::$labels = ['M3 valid-delimiter control'];
         dump(json_encode($control));
         return 'M3 fixture failed: a valid next-part or closing delimiter was not decoded.';
      }

      // ! Preserve the original finding-specific oracle before post-fix
      //   compatibility controls: vulnerable code must still report M3, not a
      //   later padded-delimiter fixture failure.
      $accepted = [];
      foreach (['initial_attack', 'field_attack', 'file_attack'] as $name) {
         $result = $probe[$name];
         if (($result['fields']['admin'] ?? null) === 'true') {
            $accepted[] = $name;
         }
      }

      if ($accepted !== []) {
         Vars::$labels = ['M3 arbitrary-suffix evidence'];
         dump(json_encode([
            'accepted_as_delimiter' => $accepted,
            'initial' => $probe['initial_attack'],
            'field' => $probe['field_attack'],
            'file' => $probe['file_attack'],
         ]));
         return 'CONFIRMED M3: multipart boundary tokens followed by arbitrary bytes '
            . 'created attacker-controlled parts in: ' . implode(', ', $accepted) . '.';
      }

      $anchorControl = $probe['anchor_control'];
      if (
         ($anchorControl['states'] ?? []) !== [States::Complete->name]
         || ($anchorControl['rejected'] ?? true) !== false
         || ($anchorControl['fields'] ?? []) !== []
         || ($anchorControl['downloaded'] ?? -1) !== ($anchorControl['length'] ?? -2)
         || ($anchorControl['waiting'] ?? true) !== false
      ) {
         Vars::$labels = ['M3 inline-anchor control'];
         dump(json_encode($anchorControl));
         return 'M3 fixture failed: the complete-body inline-boundary control '
            . 'did not preserve preamble semantics.';
      }

      $anchorDecode = $probe['anchor_decode'];
      $anchorFeed = $probe['anchor_feed'];
      if (
         ($anchorDecode['fields']['admin'] ?? null) === 'true'
         || ($anchorFeed['fields']['admin'] ?? null) === 'true'
      ) {
         Vars::$labels = ['M3 fragmented initial-anchor evidence'];
         dump(json_encode([
            'single' => $anchorControl,
            'decode_decode' => $anchorDecode,
            'feed_decode' => $anchorFeed,
         ]));
         return 'CONFIRMED M3: fragment retention promoted an inline multipart '
            . 'boundary to absolute body start and created admin=true.';
      }
      if (
         ($anchorDecode['fields'] ?? []) !== ($anchorControl['fields'] ?? [])
         || ($anchorFeed['fields'] ?? []) !== ($anchorControl['fields'] ?? [])
         || ($anchorDecode['states'] ?? [])
            !== [States::Incomplete->name, States::Complete->name]
         || ($anchorFeed['states'] ?? [])
            !== ['feed', States::Complete->name]
         || ($anchorDecode['rejected'] ?? true) !== false
         || ($anchorFeed['rejected'] ?? true) !== false
         || ($anchorDecode['downloaded'] ?? -1) !== ($anchorDecode['length'] ?? -2)
         || ($anchorFeed['downloaded'] ?? -1) !== ($anchorFeed['length'] ?? -2)
         || ($anchorDecode['waiting'] ?? true) !== false
         || ($anchorFeed['waiting'] ?? true) !== false
      ) {
         Vars::$labels = ['M3 fragmented initial-anchor compatibility'];
         dump(json_encode([
            'decode_decode' => $anchorDecode,
            'feed_decode' => $anchorFeed,
         ]));
         return 'M3 remediation incomplete: fragmented initial-boundary parsing '
            . 'did not preserve the complete-body result.';
      }

      $feedControl = $probe['feed_control'];
      if (
         ($feedControl['states'] ?? []) !== ['feed', States::Complete->name]
         || ($feedControl['charged'] ?? false) !== true
         || ($feedControl['rejected'] ?? true) !== false
         || ($feedControl['fields'] ?? [])
            !== ['safe' => 'SAFE', 'admin' => 'false']
         || ($feedControl['downloaded'] ?? -1) !== ($feedControl['length'] ?? -2)
         || ($feedControl['waiting'] ?? true) !== false
      ) {
         Vars::$labels = ['M3 valid initial-feed control'];
         dump(json_encode($feedControl));
         return 'M3 fixture failed: a valid boundary split through feed() '
            . 'did not complete with its exact fields.';
      }

      foreach ($probe['anchor_cuts'] as $cut => $paths) {
         foreach ($paths as $path => $result) {
            $states = $path === 'feed'
               ? ['feed', States::Complete->name]
               : [States::Incomplete->name, States::Complete->name];
            if (
               ($result['states'] ?? []) !== $states
               || ($path === 'feed' && ($result['charged'] ?? false) !== true)
               || ($result['rejected'] ?? true) !== false
               || ($result['fields'] ?? []) !== ($anchorControl['fields'] ?? [])
               || ($result['downloaded'] ?? -1) !== ($result['length'] ?? -2)
               || ($result['waiting'] ?? true) !== false
            ) {
               Vars::$labels = ['M3 fragmented inline-anchor sweep'];
               dump(json_encode([
                  'cut' => $cut,
                  'path' => $path,
                  'result' => $result,
               ]));
               return 'M3 remediation incomplete: an inline boundary changed '
                  . 'semantics at a transport cut.';
            }
         }
      }

      foreach ($probe['absolute_cuts'] as $cut => $paths) {
         foreach ($paths as $path => $result) {
            $states = $path === 'feed'
               ? ['feed', States::Complete->name]
               : [States::Incomplete->name, States::Complete->name];
            if (
               ($result['states'] ?? []) !== $states
               || ($path === 'feed' && ($result['charged'] ?? false) !== true)
               || ($result['rejected'] ?? true) !== false
               || ($result['fields'] ?? [])
                  !== ['safe' => 'SAFE', 'admin' => 'false']
               || ($result['downloaded'] ?? -1) !== ($result['length'] ?? -2)
               || ($result['waiting'] ?? true) !== false
            ) {
               Vars::$labels = ['M3 absolute initial-anchor sweep'];
               dump(json_encode([
                  'cut' => $cut,
                  'path' => $path,
                  'result' => $result,
               ]));
               return 'M3 remediation incomplete: a genuine body-start boundary '
                  . 'was lost at a transport cut.';
            }
         }
      }

      $preambleControl = $probe['preamble_control'];
      if (
         ($preambleControl['states'] ?? []) !== [States::Complete->name]
         || ($preambleControl['rejected'] ?? true) !== false
         || ($preambleControl['fields'] ?? []) !== ['safe' => 'SAFE']
         || ($preambleControl['waiting'] ?? true) !== false
      ) {
         Vars::$labels = ['M3 legal-preamble control'];
         dump(json_encode($preambleControl));
         return 'M3 fixture failed: a legal multipart preamble was not decoded.';
      }
      foreach ($probe['preamble_cuts'] as $cut => $paths) {
         foreach ($paths as $path => $result) {
            $states = $path === 'feed'
               ? ['feed', States::Complete->name]
               : [States::Incomplete->name, States::Complete->name];
            if (
               ($result['states'] ?? []) !== $states
               || ($path === 'feed' && ($result['charged'] ?? false) !== true)
               || ($result['rejected'] ?? true) !== false
               || ($result['fields'] ?? []) !== ['safe' => 'SAFE']
               || ($result['downloaded'] ?? -1) !== ($result['length'] ?? -2)
               || ($result['waiting'] ?? true) !== false
            ) {
               Vars::$labels = ['M3 fragmented legal-preamble sweep'];
               dump(json_encode([
                  'cut' => $cut,
                  'path' => $path,
                  'result' => $result,
               ]));
               return 'M3 remediation incomplete: a CRLF-anchored preamble '
                  . 'boundary was lost at a transport cut.';
            }
         }
      }

      $recoveryControl = $probe['recovery_control'];
      foreach (
         [
            'single' => $recoveryControl,
            'decode' => $probe['recovery_decode'],
            'feed' => $probe['recovery_feed'],
         ] as $path => $result
      ) {
         $states = match ($path) {
            'single' => [States::Complete->name],
            'feed' => ['feed', States::Complete->name],
            default => [States::Incomplete->name, States::Complete->name],
         };
         if (
            ($result['states'] ?? []) !== $states
            || ($path === 'feed' && ($result['charged'] ?? false) !== true)
            || ($result['rejected'] ?? true) !== false
            || ($result['fields'] ?? []) !== ['safe' => 'SAFE']
            || ($result['downloaded'] ?? -1) !== ($result['length'] ?? -2)
            || ($result['waiting'] ?? true) !== false
         ) {
            Vars::$labels = ['M3 later anchored-boundary recovery'];
            dump(json_encode(['path' => $path, 'result' => $result]));
            return 'M3 remediation incomplete: refusing an inline token hid '
               . 'the first later anchored boundary.';
         }
      }

      $padded = $probe['padded_control'];
      if (
         ($padded['states'] ?? []) !== [States::Complete->name]
         || ($padded['rejected'] ?? true) !== false
         || ($padded['fields'] ?? []) !== ['safe' => 'SAFE', 'admin' => 'false']
         || ($padded['downloaded'] ?? -1) !== ($padded['length'] ?? -2)
         || ($padded['waiting'] ?? true) !== false
      ) {
         Vars::$labels = ['M3 padded-delimiter control'];
         dump(json_encode($padded));
         return 'M3 remediation incomplete: RFC transport padding was not decoded.';
      }

      foreach ($probe['terminal_controls'] as $name => $result) {
         if (
            ($result['states'] ?? []) !== [States::Complete->name]
            || ($result['rejected'] ?? true) !== false
            || ($result['fields'] ?? []) !== ['safe' => 'SAFE']
            || ($result['downloaded'] ?? -1) !== ($result['length'] ?? -2)
            || ($result['waiting'] ?? true) !== false
         ) {
            Vars::$labels = ['M3 terminal-delimiter control'];
            dump(json_encode(['name' => $name, 'result' => $result]));
            return 'M3 fixture failed: a valid terminal delimiter or epilogue was not decoded.';
         }
      }

      foreach ($probe['split_controls'] as $name => $result) {
         if (
            ($result['states'] ?? []) !== [States::Incomplete->name, States::Complete->name]
            || ($result['rejected'] ?? true) !== false
            || ($result['fields'] ?? []) !== ['safe' => 'SAFE', 'admin' => 'false']
            || ($result['downloaded'] ?? -1) !== ($result['length'] ?? -2)
            || ($result['waiting'] ?? true) !== false
         ) {
            Vars::$labels = ['M3 fragmented valid-delimiter control'];
            dump(json_encode(['name' => $name, 'result' => $result]));
            return 'M3 fixture failed: a fragmented valid delimiter suffix was not decoded.';
         }
      }

      $attacks = [
         'initial' => $probe['initial_attack'],
         'field' => $probe['field_attack'],
         'file' => $probe['file_attack'],
      ] + $probe['closing_attacks'];
      foreach ($attacks as $name => $result) {
         if (
            ($result['states'] ?? []) !== [States::Rejected->name]
            || ($result['rejected'] ?? false) !== true
            || array_key_exists('admin', $result['fields'] ?? [])
            || ($result['file_content'] ?? null) !== null
            || ($result['waiting'] ?? true) !== false
         ) {
            Vars::$labels = ['M3 invalid-suffix rejection'];
            dump(json_encode(['name' => $name, 'result' => $result]));
            return 'M3 remediation incomplete: an invalid multipart delimiter '
               . 'did not fail closed before part hand-off: ' . $name . '.';
         }
      }

      foreach ($probe['split_attacks'] as $name => $result) {
         if (
            ($result['states'] ?? []) !== [States::Incomplete->name, States::Rejected->name]
            || ($result['rejected'] ?? false) !== true
            || array_key_exists('admin', $result['fields'] ?? [])
            || ($result['file_content'] ?? null) !== null
            || ($result['waiting'] ?? true) !== false
         ) {
            Vars::$labels = ['M3 fragmented invalid-suffix rejection'];
            dump(json_encode(['name' => $name, 'result' => $result]));
            return 'M3 remediation incomplete: a fragmented invalid delimiter '
               . 'committed state or did not fail closed: ' . $name . '.';
         }
      }

      $stress = $probe['padding_stress'];
      $stressStates = $stress['states'] ?? [];
      if (
         $stressStates === []
         || $stressStates[count($stressStates) - 1] !== States::Complete->name
         || ($stress['rejected'] ?? true) !== false
         || ($stress['fields'] ?? []) !== ['safe' => 'SAFE']
         || ($stress['downloaded'] ?? -1) !== ($stress['length'] ?? -2)
         || ($stress['max_tail'] ?? PHP_INT_MAX) > strlen('--Bootgly-M3-Delimiter') + 3
         || ($stress['waiting'] ?? true) !== false
      ) {
         Vars::$labels = ['M3 fragmented-padding linearity'];
         dump(json_encode($stress));
         return 'M3 remediation incomplete: fragmented transport padding '
            . 'was retained without a constant-size bound.';
      }

      $closingStress = $probe['closing_padding_stress'];
      $closingStressStates = $closingStress['states'] ?? [];
      if (
         $closingStressStates === []
         || $closingStressStates[count($closingStressStates) - 1] !== States::Complete->name
         || ($closingStress['rejected'] ?? true) !== false
         || ($closingStress['fields'] ?? []) !== ['safe' => 'SAFE']
         || ($closingStress['downloaded'] ?? -1) !== ($closingStress['length'] ?? -2)
         || ($closingStress['max_tail'] ?? PHP_INT_MAX)
            > strlen('--Bootgly-M3-Delimiter') + 6
         || ($closingStress['waiting'] ?? true) !== false
      ) {
         Vars::$labels = ['M3 fragmented-closing-padding linearity'];
         dump(json_encode($closingStress));
         return 'M3 remediation incomplete: fragmented closing transport '
            . 'padding was retained without a constant-size bound.';
      }

      if ($probe['downloads_after'] !== $probe['downloads_baseline']) {
         Vars::$labels = ['M3 invalid-file cleanup'];
         dump(json_encode([
            'baseline' => $probe['downloads_baseline'],
            'after' => $probe['downloads_after'],
         ]));
         return 'M3 remediation cleanup leaked rejected upload accounting.';
      }

      return true;
   },
);
