<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Chunked;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Regression — the zero chunk is not complete until its trailer section ends.
 *
 * The valid probe splits the final trailer CRLF from the bytes already held by
 * Decoder_Chunked. Completion must report only those two bytes from the current
 * call as consumed, leaving the following request untouched for the transport
 * pipeline. The malformed probe places a request line where a trailer field is
 * required and must be rejected rather than treated as a second request.
 */

$probe = [
   'error' => '',
   'firstState' => '',
   'firstConsumed' => -1,
   'secondState' => '',
   'secondConsumed' => -1,
   'body' => '',
   'malformedState' => '',
   'malformedRejection' => '',
   'whitespaceState' => '',
   'whitespaceRejection' => '',
   'splitFailure' => '',
   'pipelineError' => '',
   'pipelineResponse' => '',
];

return new Specification(
   description: 'Chunked terminal trailers must be complete and use a raw current-read cursor',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex = 0) use (&$probe): string {
      $WPI = Bootgly\WPI;
      $OldRequest = $WPI->Request;

      try {
         $Request = new Request;
         $Request->Body->waiting = true;
         $WPI->Request = $Request;

         $Package = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };

         $Decoder = new Decoder_Chunked;
         $Decoder->init();
         $Package->Decoder = $Decoder;

         $first = "5\r\nhello\r\n0\r\nX-Trailer: ok\r\n";
         $FirstState = $Decoder->decode($Package, $first, strlen($first));
         $probe['firstState'] = $FirstState->name;
         $probe['firstConsumed'] = $Package->consumed;

         if ($FirstState === States::Incomplete) {
            $second = "\r\nGET /h2-trailer-next HTTP/1.1\r\nHost: localhost\r\n\r\n";
            $SecondState = $Decoder->decode($Package, $second, strlen($second));
            $probe['secondState'] = $SecondState->name;
            $probe['secondConsumed'] = $Package->consumed;
            $probe['body'] = $Request->Body->raw;
         }

         $MalformedRequest = new Request;
         $MalformedRequest->Body->waiting = true;
         $WPI->Request = $MalformedRequest;

         $MalformedPackage = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };
         $MalformedDecoder = new Decoder_Chunked;
         $MalformedDecoder->init();
         $MalformedPackage->Decoder = $MalformedDecoder;

         $malformed = "0\r\nGET /h2-terminal-smuggled HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
         $MalformedState = $MalformedDecoder->decode(
            $MalformedPackage,
            $malformed,
            strlen($malformed),
         );
         $probe['malformedState'] = $MalformedState->name;
         $probe['malformedRejection'] = $MalformedPackage->rejection;

         $WhitespaceRequest = new Request;
         $WhitespaceRequest->Body->waiting = true;
         $WPI->Request = $WhitespaceRequest;

         $WhitespacePackage = new class extends Packages {
            public string $rejection = '';

            public function reject (string $raw): void
            {
               $this->rejected = true;
               $this->rejection = $raw;
            }
         };
         $WhitespaceDecoder = new Decoder_Chunked;
         $WhitespaceDecoder->init();
         $WhitespacePackage->Decoder = $WhitespaceDecoder;

         $whitespace = " 1\r\nA\r\n0\r\n\r\n";
         $WhitespaceState = $WhitespaceDecoder->decode(
            $WhitespacePackage,
            $whitespace,
            strlen($whitespace),
         );
         $probe['whitespaceState'] = $WhitespaceState->name;
         $probe['whitespaceRejection'] = $WhitespacePackage->rejection;

         // @ Exercise every split position before the complete terminal
         //   section. The second call must consume exactly its remaining raw
         //   framing prefix and leave the following request untouched.
         $framed = "3\r\nabc\r\n2\r\nde\r\n0\r\nX-Trailer: ok\r\n\r\n";
         $next = "GET /h2-split-next HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $terminalLength = strlen($framed);

         for ($split = 1; $split < $terminalLength; $split++) {
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

            $prefix = substr($framed, 0, $split);
            $suffix = substr($framed, $split) . $next;
            $PrefixState = $SplitDecoder->decode(
               $SplitPackage,
               $prefix,
               strlen($prefix),
            );
            if ($PrefixState !== States::Incomplete) {
               $probe['splitFailure'] =
                  "split={$split}, first={$PrefixState->name}, expected=Incomplete";
               break;
            }

            $SuffixState = $SplitDecoder->decode(
               $SplitPackage,
               $suffix,
               strlen($suffix),
            );
            $expected = $terminalLength - $split;

            if (
               $SuffixState !== States::Complete
               || $SplitPackage->consumed !== $expected
               || $SplitRequest->Body->raw !== 'abcde'
            ) {
               $probe['splitFailure'] = "split={$split}, "
                  . "first={$PrefixState->name}, second={$SuffixState->name}, "
                  . "consumed={$SplitPackage->consumed}, expected={$expected}, "
                  . 'body=' . json_encode($SplitRequest->Body->raw);
               break;
            }
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $WPI->Request = $OldRequest;
      }

      $head = "POST /h2-trailer-original HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Transfer-Encoding: chunked\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "\r\n";
      $chunked = "5;mode=test\r\nhello\r\n"
         . "0\r\n"
         . "X-Trailer: ok\r\n"
         . "\r\n";
      $next = "GET /h2-trailer-next HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
      $wire = $head . $chunked . $next;

      $socket = @stream_socket_client(
         "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
      );
      if (! is_resource($socket)) {
         $probe['pipelineError'] =
            "Could not connect to {$hostPort}: {$errorNumber} {$errorMessage}";
      }
      else {
         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);

         if (@fwrite($socket, $wire) !== strlen($wire)) {
            $probe['pipelineError'] = 'Could not write the complete same-read pipeline.';
         }

         $pipelineResponse = '';
         while ($probe['pipelineError'] === '') {
            $chunk = @fread($socket, 65535);
            if ($chunk === false || $chunk === '') {
               if (@feof($socket)) {
                  break;
               }

               $metadata = stream_get_meta_data($socket);
               if (($metadata['timed_out'] ?? false) === true) {
                  break;
               }
               continue;
            }

            $pipelineResponse .= $chunk;
            if (str_contains($pipelineResponse, 'H2-TRAILER-NEXT')) {
               break;
            }
         }

         $probe['pipelineResponse'] = $pipelineResponse;
         @fclose($socket);
      }

      return "GET /h2-trailer-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/h2-trailer-original', function (Request $Request, Response $Response) {
         $body = $Request->Body->raw === 'hello'
            ? 'H2-TRAILER-ORIGINAL'
            : 'H2-TRAILER-BODY-MISMATCH';
         return $Response(code: 200, body: $body);
      }, POST);

      yield $Router->route('/h2-trailer-next', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'H2-TRAILER-NEXT');
      }, GET);

      yield $Router->route('/h2-trailer-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);
   },

   test: function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'Harness request did not reach /h2-trailer-harness.';
      }

      if ($probe['error'] !== '') {
         return $probe['error'];
      }

      if ($probe['firstState'] !== States::Incomplete->name) {
         return 'The decoder completed before receiving the final trailer CRLF.';
      }

      if ($probe['firstConsumed'] !== strlen("5\r\nhello\r\n0\r\nX-Trailer: ok\r\n")) {
         return 'The decoder did not absorb every byte from the incomplete first call.';
      }

      if ($probe['secondState'] !== States::Complete->name) {
         return 'The decoder did not complete after the trailer terminator arrived.';
      }

      if ($probe['secondConsumed'] !== 2) {
         return 'Completion did not report the two raw terminal bytes from the current call.';
      }

      if ($probe['body'] !== 'hello') {
         return 'The decoded chunk payload did not remain exactly "hello".';
      }

      if ($probe['malformedState'] !== States::Rejected->name) {
         return 'A request line in place of a trailer field was not rejected.';
      }

      if (! str_contains($probe['malformedRejection'], '400 Bad Request')) {
         return 'Malformed terminal framing did not emit HTTP 400.';
      }

      if ($probe['whitespaceState'] !== States::Rejected->name) {
         return 'Whitespace around the chunk-size was not rejected.';
      }

      if (! str_contains($probe['whitespaceRejection'], '400 Bad Request')) {
         return 'Whitespace around the chunk-size did not emit HTTP 400.';
      }

      if ($probe['splitFailure'] !== '') {
         return 'Raw cursor failed across a chunked split: ' . $probe['splitFailure'];
      }

      if ($probe['pipelineError'] !== '') {
         return $probe['pipelineError'];
      }

      if (! str_contains($probe['pipelineResponse'], 'H2-TRAILER-ORIGINAL')) {
         return 'The same-read chunked POST did not receive its exact decoded body.';
      }

      if (! str_contains($probe['pipelineResponse'], 'H2-TRAILER-NEXT')) {
         return 'The request after same-read chunked trailers was not pipelined.';
      }

      return true;
   }
);
