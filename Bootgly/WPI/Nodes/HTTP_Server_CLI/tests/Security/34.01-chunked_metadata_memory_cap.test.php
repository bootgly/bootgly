<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Chunked;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC M1 — chunk framing metadata must have an independent cap.
 *
 * The decoded body allowance is reduced to 16 bytes. A valid one-byte body is
 * the A/B control, and an oversized unfinished trailer confirms the existing
 * 16 KiB trailer mitigation. The attack then sends a 1 MiB chunk-size line
 * with an extension but no CRLF. A vulnerable decoder accepts the complete
 * transport read as Incomplete and retains all of it in its private buffer,
 * without reaching the decoded-body limit.
 */

$probe = [
   'error' => '',
   'bodyLimit' => 16,
   'metadataLimit' => 8 * 1024,
   'attackBytes' => 1024 * 1024,
   'attackReads' => 16,
   'controlState' => '',
   'controlBody' => '',
   'controlRejection' => '',
   'trailerState' => '',
   'trailerRejection' => '',
   'trailerRetainedBytes' => -1,
   'boundaryFirstState' => '',
   'boundaryFirstConsumed' => -1,
   'boundaryFirstRetainedBytes' => -1,
   'boundaryState' => '',
   'boundaryBody' => '',
   'boundaryRejection' => '',
   'splitFirstState' => '',
   'splitFirstRetainedBytes' => -1,
   'splitState' => '',
   'splitRejection' => '',
   'splitRetainedBytes' => -1,
   'splitBodyWaiting' => true,
   'terminatedState' => '',
   'terminatedRejection' => '',
   'terminatedRetainedBytes' => -1,
   'terminatedBodyWaiting' => true,
   'attackState' => '',
   'attackReadsCompleted' => 0,
   'attackConsumed' => -1,
   'attackRejection' => '',
   'attackRetainedBytes' => -1,
   'attackBodyWaiting' => false,
];

return new Specification(
   description: 'Chunk-size lines and extensions must have an independent memory cap',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $WPI = Bootgly\WPI;
      $OldRequest = $WPI->Request;
      $oldMaxBodySize = Request::$maxBodySize;

      try {
         Request::$maxBodySize = $probe['bodyLimit'];
         $Buffer = new ReflectionProperty(Decoder_Chunked::class, 'buffer');

         // # A/B control: a small valid extension and body must complete.
         $ControlRequest = new Request;
         $ControlRequest->Body->waiting = true;
         $WPI->Request = $ControlRequest;

         $ControlPackage = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };
         $ControlDecoder = new Decoder_Chunked;
         $ControlDecoder->init();
         $ControlPackage->Decoder = $ControlDecoder;

         $control = "1;mode=test\r\nA\r\n0\r\n\r\n";
         $ControlState = $ControlDecoder->decode(
            $ControlPackage,
            $control,
            strlen($control),
         );
         $probe['controlState'] = $ControlState->name;
         $probe['controlBody'] = $ControlRequest->Body->raw;
         $probe['controlRejection'] = $ControlPackage->rejection;

         // # Mitigation control: terminal trailers already have a 16 KiB cap.
         $TrailerRequest = new Request;
         $TrailerRequest->Body->waiting = true;
         $WPI->Request = $TrailerRequest;

         $TrailerPackage = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };
         $TrailerDecoder = new Decoder_Chunked;
         $TrailerDecoder->init();
         $TrailerPackage->Decoder = $TrailerDecoder;

         $trailer = "0\r\nX-Meta: " . str_repeat('T', 16 * 1024);
         $TrailerState = $TrailerDecoder->decode(
            $TrailerPackage,
            $trailer,
            strlen($trailer),
         );
         $retained = $Buffer->getValue($TrailerDecoder);
         $probe['trailerState'] = $TrailerState->name;
         $probe['trailerRejection'] = $TrailerPackage->rejection;
         $probe['trailerRetainedBytes'] = is_string($retained) ? strlen($retained) : -1;

         // # Exact boundary: 8 KiB is accepted even when CRLF is split.
         $BoundaryRequest = new Request;
         $BoundaryRequest->Body->waiting = true;
         $WPI->Request = $BoundaryRequest;

         $BoundaryPackage = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };
         $BoundaryDecoder = new Decoder_Chunked;
         $BoundaryDecoder->init();
         $BoundaryPackage->Decoder = $BoundaryDecoder;

         $boundary = '1;' . str_repeat('B', $probe['metadataLimit'] - 2);
         $BoundaryFirstState = $BoundaryDecoder->decode(
            $BoundaryPackage,
            $boundary,
            strlen($boundary),
         );
         $retained = $Buffer->getValue($BoundaryDecoder);
         $probe['boundaryFirstState'] = $BoundaryFirstState->name;
         $probe['boundaryFirstConsumed'] = $BoundaryPackage->consumed;
         $probe['boundaryFirstRetainedBytes'] = is_string($retained) ? strlen($retained) : -1;

         $BoundaryState = $BoundaryFirstState;
         if ($BoundaryFirstState === States::Incomplete) {
            $suffix = "\r\nA\r\n0\r\n\r\n";
            $BoundaryState = $BoundaryDecoder->decode(
               $BoundaryPackage,
               $suffix,
               strlen($suffix),
            );
         }
         $probe['boundaryState'] = $BoundaryState->name;
         $probe['boundaryBody'] = $BoundaryRequest->Body->raw;
         $probe['boundaryRejection'] = $BoundaryPackage->rejection;

         // # Split over-boundary: one more byte after an exact 8 KiB line rejects.
         $SplitRequest = new Request;
         $SplitRequest->Body->waiting = true;
         $WPI->Request = $SplitRequest;

         $SplitPackage = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };
         $SplitDecoder = new Decoder_Chunked;
         $SplitDecoder->init();
         $SplitPackage->Decoder = $SplitDecoder;

         $SplitFirstState = $SplitDecoder->decode(
            $SplitPackage,
            $boundary,
            strlen($boundary),
         );
         $retained = $Buffer->getValue($SplitDecoder);
         $probe['splitFirstState'] = $SplitFirstState->name;
         $probe['splitFirstRetainedBytes'] = is_string($retained) ? strlen($retained) : -1;

         $SplitState = $SplitDecoder->decode($SplitPackage, 'B', 1);
         $retained = $Buffer->getValue($SplitDecoder);
         $probe['splitState'] = $SplitState->name;
         $probe['splitRejection'] = $SplitPackage->rejection;
         $probe['splitRetainedBytes'] = is_string($retained) ? strlen($retained) : -1;
         $probe['splitBodyWaiting'] = $SplitRequest->Body->waiting;

         // # Terminated over-boundary line: delimiter offset must also reject.
         $TerminatedRequest = new Request;
         $TerminatedRequest->Body->waiting = true;
         $WPI->Request = $TerminatedRequest;

         $TerminatedPackage = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };
         $TerminatedDecoder = new Decoder_Chunked;
         $TerminatedDecoder->init();
         $TerminatedPackage->Decoder = $TerminatedDecoder;

         $terminated = '1;' . str_repeat('C', $probe['metadataLimit'] - 1)
            . "\r\nA\r\n0\r\n\r\n";
         $TerminatedState = $TerminatedDecoder->decode(
            $TerminatedPackage,
            $terminated,
            strlen($terminated),
         );
         $retained = $Buffer->getValue($TerminatedDecoder);
         $probe['terminatedState'] = $TerminatedState->name;
         $probe['terminatedRejection'] = $TerminatedPackage->rejection;
         $probe['terminatedRetainedBytes'] = is_string($retained) ? strlen($retained) : -1;
         $probe['terminatedBodyWaiting'] = $TerminatedRequest->Body->waiting;

         // # Attack: one 1 MiB unfinished size line/extension, no CRLF,
         //   split across the transport's normal 64 KiB read ceiling.
         $AttackRequest = new Request;
         $AttackRequest->Body->waiting = true;
         $WPI->Request = $AttackRequest;

         $AttackPackage = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };
         $AttackDecoder = new Decoder_Chunked;
         $AttackDecoder->init();
         $AttackPackage->Decoder = $AttackDecoder;

         $segmentBytes = (int) ($probe['attackBytes'] / $probe['attackReads']);
         $consumed = 0;
         $AttackState = States::Incomplete;

         for ($read = 0; $read < $probe['attackReads']; $read++) {
            $segment = $read === 0
               ? '1;' . str_repeat('A', $segmentBytes - 2)
               : str_repeat('A', $segmentBytes);

            $AttackState = $AttackDecoder->decode(
               $AttackPackage,
               $segment,
               strlen($segment),
            );
            $consumed += $AttackPackage->consumed;
            $probe['attackReadsCompleted']++;

            if ($AttackState !== States::Incomplete) {
               break;
            }
         }
         $retained = $Buffer->getValue($AttackDecoder);

         $probe['attackState'] = $AttackState->name;
         $probe['attackConsumed'] = $consumed;
         $probe['attackRejection'] = $AttackPackage->rejection;
         $probe['attackRetainedBytes'] = is_string($retained) ? strlen($retained) : -1;
         $probe['attackBodyWaiting'] = $AttackRequest->Body->waiting;
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         Request::$maxBodySize = $oldMaxBodySize;
         $WPI->Request = $OldRequest;
      }

      return "GET /m1-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/m1-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'M1-HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'M1-HARNESS-OK')) {
         return 'M1 harness did not receive its control response.';
      }
      if ($probe['error'] !== '') {
         return $probe['error'];
      }

      if (
         $probe['controlState'] !== States::Complete->name
         || $probe['controlBody'] !== 'A'
         || $probe['controlRejection'] !== ''
      ) {
         Vars::$labels = ['M1 valid chunked control'];
         dump(json_encode($probe));
         return 'The valid chunked A/B control did not complete with its exact body.';
      }

      if (
         $probe['trailerState'] !== States::Rejected->name
         || ! str_contains($probe['trailerRejection'], '431 Request Header Fields Too Large')
         || $probe['trailerRetainedBytes'] !== 0
      ) {
         Vars::$labels = ['M1 trailer-limit control'];
         dump(json_encode($probe));
         return 'The existing independent trailer cap was not observed.';
      }

      if (
         $probe['attackState'] === States::Incomplete->name
         && $probe['attackReadsCompleted'] === $probe['attackReads']
         && $probe['attackConsumed'] === $probe['attackBytes']
         && $probe['attackRejection'] === ''
         && $probe['attackRetainedBytes'] === $probe['attackBytes']
         && $probe['attackBodyWaiting'] === true
      ) {
         Vars::$labels = ['M1 chunk metadata retention evidence'];
         dump(json_encode($probe));
         return 'CONFIRMED M1: a 1 MiB unterminated chunk-size extension bypassed the '
            . '16-byte decoded-body cap and remained fully retained; evidence='
            . json_encode($probe);
      }

      if ($probe['attackState'] !== States::Rejected->name) {
         Vars::$labels = ['M1 unexpected chunk metadata outcome'];
         dump(json_encode($probe));
         return 'Oversized chunk framing metadata was neither rejected nor reproduced exactly.';
      }
      if (
         $probe['attackReadsCompleted'] !== 1
         || $probe['attackConsumed'] !== 0
         || ! str_contains($probe['attackRejection'], '431 Request Header Fields Too Large')
      ) {
         Vars::$labels = ['M1 early metadata rejection'];
         dump(json_encode($probe));
         return 'Oversized unterminated metadata was not rejected on its first transport read.';
      }
      if ($probe['attackRetainedBytes'] !== 0) {
         Vars::$labels = ['M1 chunk metadata retention evidence'];
         dump(json_encode($probe));
         return 'Rejected chunk framing metadata was not cleared.';
      }
      if ($probe['attackBodyWaiting'] !== false) {
         return 'Rejected chunk framing metadata left the request body waiting.';
      }

      if (
         $probe['splitFirstState'] !== States::Incomplete->name
         || $probe['splitFirstRetainedBytes'] !== $probe['metadataLimit']
         || $probe['splitState'] !== States::Rejected->name
         || ! str_contains($probe['splitRejection'], '431 Request Header Fields Too Large')
         || $probe['splitRetainedBytes'] !== 0
         || $probe['splitBodyWaiting'] !== false
      ) {
         Vars::$labels = ['M1 split over-boundary metadata'];
         dump(json_encode($probe));
         return 'Metadata crossing the 8 KiB boundary across reads was not rejected and cleared.';
      }

      if (
         $probe['boundaryFirstState'] !== States::Incomplete->name
         || $probe['boundaryFirstConsumed'] !== $probe['metadataLimit']
         || $probe['boundaryFirstRetainedBytes'] !== $probe['metadataLimit']
         || $probe['boundaryState'] !== States::Complete->name
         || $probe['boundaryBody'] !== 'A'
         || $probe['boundaryRejection'] !== ''
      ) {
         Vars::$labels = ['M1 exact metadata boundary'];
         dump(json_encode($probe));
         return 'The exact 8 KiB metadata boundary or its split CRLF was not accepted.';
      }

      if (
         $probe['terminatedState'] !== States::Rejected->name
         || ! str_contains($probe['terminatedRejection'], '431 Request Header Fields Too Large')
         || $probe['terminatedRetainedBytes'] !== 0
         || $probe['terminatedBodyWaiting'] !== false
      ) {
         Vars::$labels = ['M1 terminated over-boundary metadata'];
         dump(json_encode($probe));
         return 'A terminated chunk metadata line above 8 KiB was not rejected and cleared.';
      }

      return true;
   },
);
