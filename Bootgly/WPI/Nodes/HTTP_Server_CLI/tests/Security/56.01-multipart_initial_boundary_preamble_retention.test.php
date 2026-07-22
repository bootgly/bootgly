<?php

use const Bootgly\WPI;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections as TCPConnections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC C4 — multipart initial-boundary scanning must not retain an
 * attacker-controlled preamble linearly while waiting for the first boundary.
 *
 * The attack follows Request::decode() through Frame multipart admission and
 * the installed per-connection Decoder_Downloading. Sixteen 64 KiB body reads
 * intentionally omit the advertised boundary; one MiB is sufficient to prove
 * the retention primitive without attempting worker exhaustion.
 *
 * Positive controls split a complete CRLF-delimited initial boundary line at
 * every internal byte position, proving that a bounded suffix implementation
 * can preserve cross-read detection. A live harmless request proves the native
 * harness remains operational after the in-process decoder probe.
 */
$probe = [
   'error' => '',
   'decoder_installed' => false,
   'install_state' => '',
   'boundary_bytes' => 0,
   'attack_bytes' => 1024 * 1024,
   'segment_bytes' => 64 * 1024,
   'attack_states' => [],
   'attack_reads' => 0,
   'attack_consumed' => 0,
   'retained_tail_bytes' => -1,
   'max_retained_tail_bytes' => -1,
   'memory_before_attack' => -1,
   'memory_after_attack' => -1,
   'retained_growth_bytes' => -1,
   'max_retained_growth_bytes' => -1,
   'body_downloaded' => -1,
   'attack_rejected' => false,
   'attack_rejection' => '',
   'memory_before_disconnect' => -1,
   'memory_after_disconnect' => -1,
   'released_on_disconnect_bytes' => -1,
   'cleanup_tail_bytes' => -1,
   'split_positions' => 0,
   'split_controls_passed' => 0,
   'split_failures' => [],
   'part_suffix_positions' => 4,
   'part_suffix_controls_passed' => 0,
   'part_suffix_failures' => [],
   'missing_boundary_state' => '',
   'missing_boundary_rejected' => false,
   'missing_boundary_rejection' => '',
   'missing_boundary_downloaded' => -1,
   'missing_boundary_consumed' => -1,
   'missing_closing_state' => '',
   'missing_closing_rejected' => false,
   'missing_closing_rejection' => '',
   'offset_zero_state' => '',
   'offset_zero_rejected' => false,
   'offset_zero_field' => null,
   'initial_feed_state' => '',
   'initial_feed_decoder' => false,
   'initial_feed_tail_bytes' => -1,
   'initial_feed_downloaded' => -1,
];
$Live = new class {
   public mixed $connection = null;
   public string $suffix = '';
   /** @var array<string,bool|int|string> */
   public array $snapshot = [];
   public string $inspectorWire = '';
   public string $attackWire = '';
   public string $error = '';
};
$Write = static function ($socket, string $bytes): bool {
   $offset = 0;
   $length = strlen($bytes);

   while ($offset < $length) {
      $written = fwrite($socket, substr($bytes, $offset));
      if ($written === false || $written === 0) {
         return false;
      }
      $offset += $written;
   }

   return true;
};
$Read = static function ($socket): string {
   $wire = '';
   $expected = null;

   while (true) {
      $chunk = fread($socket, 8192);
      if ($chunk === false || $chunk === '') {
         break;
      }
      $wire .= $chunk;

      $separator = strpos($wire, "\r\n\r\n");
      if ($separator !== false && $expected === null) {
         if (
            preg_match(
               '/\r\nContent-Length: (\d+)\r\n/i',
               substr($wire, 0, $separator + 2),
               $matches,
            ) === 1
         ) {
            $expected = $separator + 4 + (int) $matches[1];
         }
      }

      if ($expected !== null && strlen($wire) >= $expected) {
         break;
      }
   }

   return $wire;
};

return new Specification(
   description: 'Multipart initial-boundary search must keep preamble retention bounded',
   Separator: new Separator(line: true),

   request: function (string $hostPort, int $testIndex) use (
      &$probe,
      $Live,
      $Read,
      $Write,
   ): string {
      $socket = tmpfile();
      if (is_resource($socket) === false) {
         $probe['error'] = 'C4 fixture could not allocate its transport surrogate.';

         return "GET /c4-preamble-harness HTTP/1.1\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";
      }

      $WPI = WPI;
      $OldRequest = Server::$Request;
      $OldResponse = Server::$Response;
      $OldRouter = Server::$Router;
      $OldDecoder = Server::$Decoder;
      $OldWPIRequest = $WPI->Request;
      $OldWPIResponse = $WPI->Response;
      $OldWPIRouter = $WPI->Router;
      $Decoders = [];
      $Connections = [];
      $boundary = 'Bootgly-C4-Initial-Boundary';
      $wireBoundary = '--' . $boundary;

      try {
         Server::$Response = new Response;
         Server::$Router = new Router;
         Server::$Decoder = new Decoder_;
         $WPI->Response = Server::$Response;
         $WPI->Router = Server::$Router;

         $Build = static function (
            string $boundary,
            int $contentLength,
            string $initialBody = '',
         ) use (
            $socket,
            $WPI,
            &$Connections,
         ): array {
            /** @var Connection $Connection */
            $Connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
            $Connection->Socket = $socket;
            $Connection->timers = [];
            $Connection->handshakeTimer = 0;
            $Connection->handshaking = false;
            $Connection->writes = 0;
            $Connection->ip = '127.0.0.1';
            $Connection->port = 12345;
            $Connection->encrypted = false;
            $Connections[] = $Connection;

            $Package = new class($Connection) extends TCPPackages {
               public string $rejection = '';

               public function __construct (Connection $Connection)
               {
                  $this->Connection = $Connection;
                  $this->cache = true;
                  $this->changed = true;
                  $this->input = '';
                  $this->output = '';
                  $this->callbacks = [&$this->input];
                  $this->expired = false;
                  $this->downloading = [];
                  $this->uploading = [];
                  $this->closeAfterWrite = false;
               }

               public function reject (string $raw): void
               {
                  $this->rejected = true;
                  $this->rejection = $raw;
               }
            };

            $Request = new Request;
            Server::$Request = $Request;
            $WPI->Request = $Request;

            $raw = "POST /c4-preamble-probe HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
               . "Content-Length: {$contentLength}\r\n"
               . "\r\n"
               . $initialBody;
            $State = $Request->decode($Package, $raw, strlen($raw));

            return [$Request, $Package, $Package->Decoder, $State];
         };

         $boundaryBytes = strlen($wireBoundary);
         $probe['boundary_bytes'] = $boundaryBytes;
         $Tail = new ReflectionProperty(Decoder_Downloading::class, 'tailBuffer');
         $attackBytes = $probe['attack_bytes'];
         $segmentBytes = $probe['segment_bytes'];
         $declaredLength = $attackBytes + 4096;
         // ! Allocate the reusable transport chunk before the memory baseline
         //   so only decoder-owned retention contributes linearly.
         $segment = str_repeat('A', $segmentBytes);

         [$FeedRequest, , $FeedDecoder, $FeedState] = $Build(
            $boundary,
            $declaredLength,
            $segment,
         );
         $probe['initial_feed_state'] = $FeedState->name;
         $probe['initial_feed_decoder'] = $FeedDecoder instanceof Decoder_Downloading;
         $probe['initial_feed_downloaded'] = $FeedRequest->Body->downloaded;
         if ($FeedDecoder instanceof Decoder_Downloading) {
            $Decoders[] = $FeedDecoder;
            $feedTail = $Tail->getValue($FeedDecoder);
            $probe['initial_feed_tail_bytes'] = is_string($feedTail)
               ? strlen($feedTail)
               : -1;
            unset($feedTail);
         }

         [$AttackRequest, $AttackPackage, $AttackDecoder, $InstallState] =
            $Build($boundary, $declaredLength, '');
         $probe['install_state'] = $InstallState->name;
         $probe['decoder_installed'] = $AttackDecoder instanceof Decoder_Downloading;

         if ($AttackDecoder instanceof Decoder_Downloading) {
            $Decoders[] = $AttackDecoder;
            $segments = intdiv($attackBytes, $segmentBytes);
            gc_collect_cycles();
            $probe['memory_before_attack'] = memory_get_usage(false);

            for ($index = 0; $index < $segments; $index++) {
               $State = $AttackDecoder->decode($AttackPackage, $segment, $segmentBytes);
               $probe['attack_states'][] = $State->name;
               $probe['attack_reads']++;
               $probe['attack_consumed'] += $AttackPackage->consumed;

               $retained = $Tail->getValue($AttackDecoder);
               $retainedBytes = is_string($retained) ? strlen($retained) : -1;
               $probe['max_retained_tail_bytes'] = max(
                  $probe['max_retained_tail_bytes'],
                  $retainedBytes,
               );
               $probe['max_retained_growth_bytes'] = max(
                  $probe['max_retained_growth_bytes'],
                  memory_get_usage(false) - $probe['memory_before_attack'],
               );
               unset($retained);

               if ($State !== States::Incomplete) {
                  break;
               }
            }

            $probe['memory_after_attack'] = memory_get_usage(false);
            $probe['retained_growth_bytes'] =
               $probe['memory_after_attack'] - $probe['memory_before_attack'];
            $tail = $Tail->getValue($AttackDecoder);
            $probe['retained_tail_bytes'] = is_string($tail) ? strlen($tail) : -1;
            unset($tail);
            $probe['body_downloaded'] = $AttackRequest->Body->downloaded;
            $probe['attack_rejected'] = $AttackPackage->rejected;
            $probe['attack_rejection'] = $AttackPackage->rejection;

            $probe['memory_before_disconnect'] = memory_get_usage(false);
            $AttackDecoder->disconnect();
            gc_collect_cycles();
            $probe['memory_after_disconnect'] = memory_get_usage(false);
            $probe['released_on_disconnect_bytes'] =
               $probe['memory_before_disconnect'] - $probe['memory_after_disconnect'];
            $tail = $Tail->getValue($AttackDecoder);
            $probe['cleanup_tail_bytes'] = is_string($tail) ? strlen($tail) : -1;
            unset($tail);
         }

         // @ A declared multipart body that completes without any boundary is
         //   malformed. Bounded scanning must not turn it into an empty 200.
         [$MissingRequest, $MissingPackage, $MissingDecoder] =
            $Build($boundary, $segmentBytes, '');
         if ($MissingDecoder instanceof Decoder_Downloading) {
            $Decoders[] = $MissingDecoder;
            $MissingState = $MissingDecoder->decode(
               $MissingPackage,
               $segment,
               $segmentBytes,
            );
            $probe['missing_boundary_state'] = $MissingState->name;
            $probe['missing_boundary_rejected'] = $MissingPackage->rejected;
            $probe['missing_boundary_rejection'] = $MissingPackage->rejection;
            $probe['missing_boundary_downloaded'] = $MissingRequest->Body->downloaded;
            $probe['missing_boundary_consumed'] = $MissingPackage->consumed;
         }

         $missingClosingBody = $wireBoundary . "\r\n"
            . "Content-Disposition: form-data; name=\"control\"\r\n"
            . "\r\n"
            . "unfinished";
         [, $MissingClosingPackage, , $MissingClosingState] = $Build(
            $boundary,
            strlen($missingClosingBody),
            $missingClosingBody,
         );
         $probe['missing_closing_state'] = $MissingClosingState->name;
         $probe['missing_closing_rejected'] = $MissingClosingPackage->rejected;
         $probe['missing_closing_rejection'] = $MissingClosingPackage->rejection;

         // @ Ordinary offset-zero multipart remains accepted after the
         //   preamble scanner is bounded.
         $offsetZeroBody = $wireBoundary . "\r\n"
            . "Content-Disposition: form-data; name=\"control\"\r\n"
            . "\r\n"
            . "ok\r\n"
            . $wireBoundary . "--\r\n";
         [$OffsetRequest, $OffsetPackage, , $OffsetState] = $Build(
            $boundary,
            strlen($offsetZeroBody),
            $offsetZeroBody,
         );
         $probe['offset_zero_state'] = $OffsetState->name;
         $probe['offset_zero_rejected'] = $OffsetPackage->rejected;
         $probe['offset_zero_field'] = $OffsetRequest->fields['control'] ?? null;

         // @ Cross-read correctness control: accept a small legal preamble and
         //   split every byte inside the complete CRLF-delimited boundary line.
         $preamble = 'C4-safe-preamble';
         $delimiter = "\r\n" . $wireBoundary . "\r\n";
         $controlBody = $preamble
            . $delimiter
            . "Content-Disposition: form-data; name=\"control\"\r\n"
            . "\r\n"
            . "ok\r\n"
            . $wireBoundary . "--\r\n";
         $preambleBytes = strlen($preamble);
         $delimiterBytes = strlen($delimiter);
         $probe['split_positions'] = $delimiterBytes - 1;

         for ($split = 1; $split < $delimiterBytes; $split++) {
            $firstBytes = $preambleBytes + $split;
            $first = substr($controlBody, 0, $firstBytes);
            $second = substr($controlBody, $firstBytes);
            [$ControlRequest, $ControlPackage, $ControlDecoder, $ControlInstallState] =
               $Build($boundary, strlen($controlBody), $first);

            if ($ControlDecoder instanceof Decoder_Downloading === false) {
               $probe['split_failures'][] = [
                  'split' => $split,
                  'reason' => 'streaming decoder was not installed',
               ];
               continue;
            }

            $Decoders[] = $ControlDecoder;
            $SecondState = $ControlDecoder->decode(
               $ControlPackage,
               $second,
               strlen($second),
            );

            if (
               $ControlInstallState !== States::Complete
               || $SecondState !== States::Complete
               || $ControlPackage->rejected
               || $ControlRequest->Body->waiting
               || $ControlRequest->Body->downloaded !== strlen($controlBody)
               || ($ControlRequest->fields['control'] ?? null) !== 'ok'
            ) {
               $probe['split_failures'][] = [
                  'split' => $split,
                  'install' => $ControlInstallState->name,
                  'second' => $SecondState->name,
                  'rejected' => $ControlPackage->rejected,
                  'waiting' => $ControlRequest->Body->waiting,
                  'downloaded' => $ControlRequest->Body->downloaded,
                  'field' => $ControlRequest->fields['control'] ?? null,
               ];
               continue;
            }

            $probe['split_controls_passed']++;
         }

         // @ After a part body, the boundary token may be complete while its
         //   two-byte `--` or CRLF suffix is split into the next transport read.
         $partPrefix = $wireBoundary . "\r\n"
            . "Content-Disposition: form-data; name=\"control\"\r\n"
            . "\r\n"
            . 'one';
         $partMarker = "\r\n" . $wireBoundary;

         $terminalTail = "--\r\n";
         $terminalBody = $partPrefix . $partMarker . $terminalTail;
         for ($suffixBytes = 0; $suffixBytes < 2; $suffixBytes++) {
            [$SuffixRequest, $SuffixPackage, $SuffixDecoder] = $Build(
               $boundary,
               strlen($terminalBody),
               $partPrefix,
            );
            if ($SuffixDecoder instanceof Decoder_Downloading === false) {
               $probe['part_suffix_failures'][] = [
                  'kind' => 'terminal',
                  'suffix_bytes' => $suffixBytes,
                  'reason' => 'streaming decoder was not installed',
               ];
               continue;
            }

            $Decoders[] = $SuffixDecoder;
            $first = $partMarker . substr($terminalTail, 0, $suffixBytes);
            $second = substr($terminalTail, $suffixBytes);
            $FirstState = $SuffixDecoder->decode(
               $SuffixPackage,
               $first,
               strlen($first),
            );
            $SecondState = $SuffixDecoder->decode(
               $SuffixPackage,
               $second,
               strlen($second),
            );
            if (
               $FirstState !== States::Incomplete
               || $SecondState !== States::Complete
               || $SuffixPackage->rejected
               || ($SuffixRequest->fields['control'] ?? null) !== 'one'
            ) {
               $probe['part_suffix_failures'][] = [
                  'kind' => 'terminal',
                  'suffix_bytes' => $suffixBytes,
                  'first' => $FirstState->name,
                  'second' => $SecondState->name,
                  'rejected' => $SuffixPackage->rejected,
                  'field' => $SuffixRequest->fields['control'] ?? null,
               ];
               continue;
            }

            $probe['part_suffix_controls_passed']++;
         }

         $nextTail = "\r\n"
            . "Content-Disposition: form-data; name=\"second\"\r\n"
            . "\r\n"
            . "two\r\n"
            . $wireBoundary . "--\r\n";
         $nextBody = $partPrefix . $partMarker . $nextTail;
         for ($suffixBytes = 0; $suffixBytes < 2; $suffixBytes++) {
            [$SuffixRequest, $SuffixPackage, $SuffixDecoder] = $Build(
               $boundary,
               strlen($nextBody),
               $partPrefix,
            );
            if ($SuffixDecoder instanceof Decoder_Downloading === false) {
               $probe['part_suffix_failures'][] = [
                  'kind' => 'next-part',
                  'suffix_bytes' => $suffixBytes,
                  'reason' => 'streaming decoder was not installed',
               ];
               continue;
            }

            $Decoders[] = $SuffixDecoder;
            $first = $partMarker . substr($nextTail, 0, $suffixBytes);
            $second = substr($nextTail, $suffixBytes);
            $FirstState = $SuffixDecoder->decode(
               $SuffixPackage,
               $first,
               strlen($first),
            );
            $SecondState = $SuffixDecoder->decode(
               $SuffixPackage,
               $second,
               strlen($second),
            );
            if (
               $FirstState !== States::Incomplete
               || $SecondState !== States::Complete
               || $SuffixPackage->rejected
               || ($SuffixRequest->fields['control'] ?? null) !== 'one'
               || ($SuffixRequest->fields['second'] ?? null) !== 'two'
            ) {
               $probe['part_suffix_failures'][] = [
                  'kind' => 'next-part',
                  'suffix_bytes' => $suffixBytes,
                  'first' => $FirstState->name,
                  'second' => $SecondState->name,
                  'rejected' => $SuffixPackage->rejected,
                  'first_field' => $SuffixRequest->fields['control'] ?? null,
                  'second_field' => $SuffixRequest->fields['second'] ?? null,
               ];
               continue;
            }

            $probe['part_suffix_controls_passed']++;
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Decoders as $Decoder) {
            $Decoder->disconnect();
         }

         Server::$Request = $OldRequest;
         Server::$Response = $OldResponse;
         Server::$Router = $OldRouter;
         Server::$Decoder = $OldDecoder;
         $WPI->Request = $OldWPIRequest;
         $WPI->Response = $OldWPIResponse;
         $WPI->Router = $OldWPIRouter;

         @fclose($socket);
      }

      // ! Real network source-to-sink attack. Keep the body syntactically
      //   completable: after the worker snapshot, the test sends a valid first
      //   boundary, field and terminal boundary over this same connection.
      if ($probe['error'] === '') {
         $Live->suffix = "\r\n" . $wireBoundary . "\r\n"
            . "Content-Disposition: form-data; name=\"control\"\r\n"
            . "\r\n"
            . "ok\r\n"
            . $wireBoundary . "--\r\n";
         $contentLength = $probe['attack_bytes'] + strlen($Live->suffix);
         $Live->connection = stream_socket_client(
            "tcp://{$hostPort}",
            $errorCode,
            $errorMessage,
            timeout: 5,
         );

         if (is_resource($Live->connection) === false) {
            $Live->error = "could not open attack connection: {$errorCode} {$errorMessage}";
         }
         else {
            stream_set_blocking($Live->connection, true);
            stream_set_timeout($Live->connection, 5);
            $head = "POST /c4/preamble HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
               . "Content-Length: {$contentLength}\r\n"
               . "Connection: close\r\n"
               . "\r\n";
            $segment = str_repeat('A', $probe['segment_bytes']);

            if ($Write($Live->connection, $head) === false) {
               $Live->error = 'could not send the multipart attack head.';
            }
            else {
               for ($index = 0; $index < 16; $index++) {
                  if ($Write($Live->connection, $segment) === false) {
                     $Live->error = "could not send attack segment {$index}.";
                     break;
                  }
               }
            }

            // @ Poll through real indexed requests so the event loop can drain
            //   every queued attack segment before the attributed snapshot.
            if ($Live->error === '') {
               for ($attempt = 0; $attempt < 50; $attempt++) {
                  $inspector = stream_socket_client(
                     "tcp://{$hostPort}",
                     $inspectCode,
                     $inspectMessage,
                     timeout: 5,
                  );
                  if (is_resource($inspector) === false) {
                     $Live->error =
                        "could not open inspector: {$inspectCode} {$inspectMessage}";
                     break;
                  }

                  stream_set_blocking($inspector, true);
                  stream_set_timeout($inspector, 5);
                  $inspectRequest = "GET /c4/inspect HTTP/1.1\r\n"
                     . "Host: localhost\r\n"
                     . "X-Bootgly-Test: {$testIndex}\r\n"
                     . "Connection: close\r\n"
                     . "\r\n";
                  if ($Write($inspector, $inspectRequest) === false) {
                     fclose($inspector);
                     $Live->error = 'could not send inspector request.';
                     break;
                  }

                  $Live->inspectorWire = $Read($inspector);
                  fclose($inspector);
                  $separator = strpos($Live->inspectorWire, "\r\n\r\n");
                  $decoded = $separator === false
                     ? null
                     : json_decode(
                        substr($Live->inspectorWire, $separator + 4),
                        true,
                     );
                  if (is_array($decoded)) {
                     /** @var array<string,bool|int|string> $decoded */
                     $Live->snapshot = $decoded;
                  }

                  if (
                     ($Live->snapshot['found'] ?? false) === true
                     && ($Live->snapshot['downloaded'] ?? -1) >= $probe['attack_bytes']
                  ) {
                     break;
                  }

                  usleep(10000);
               }
            }
         }
      }

      return "GET /c4-preamble-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/c4/inspect', static function (
         Request $Request,
         Response $Response,
      ): Response {
         foreach (TCPConnections::$Connections as $Candidate) {
            $Decoder = $Candidate->Decoder;
            if (
               $Decoder instanceof Decoder_Downloading === false
               || $Decoder->Request->URI !== '/c4/preamble'
            ) {
               continue;
            }

            $TailStorage = new ReflectionProperty(
               Decoder_Downloading::class,
               'tailBuffer',
            );
            $StateStorage = new ReflectionProperty(
               Decoder_Downloading::class,
               'state',
            );
            $DownloadedStorage = new ReflectionProperty(
               Decoder_Downloading::class,
               'downloaded',
            );
            $BoundaryStorage = new ReflectionProperty(
               Decoder_Downloading::class,
               'boundary',
            );
            $ParsedStorage = new ReflectionProperty(
               Decoder_Downloading::class,
               'parsed',
            );
            $tail = $TailStorage->getValue($Decoder);
            $state = $StateStorage->getValue($Decoder);
            $downloaded = $DownloadedStorage->getValue($Decoder);
            $boundary = $BoundaryStorage->getValue($Decoder);
            $parsed = $ParsedStorage->getValue($Decoder);

            return $Response->JSON->send([
               'found' => true,
               'state' => is_int($state) ? $state : -1,
               'parsed' => is_bool($parsed) ? $parsed : true,
               'downloaded' => is_int($downloaded) ? $downloaded : -1,
               'body_downloaded' => $Decoder->Request->Body->downloaded,
               'retained_tail_bytes' => is_string($tail) ? strlen($tail) : -1,
               'boundary_bytes' => is_string($boundary) ? strlen($boundary) : -1,
            ]);
         }

         return $Response->JSON->send([
            'found' => false,
            'state' => -1,
            'parsed' => false,
            'downloaded' => -1,
            'body_downloaded' => -1,
            'retained_tail_bytes' => -1,
            'boundary_bytes' => -1,
         ]);
      }, GET);

      yield $Router->route('/c4/preamble', static function (
         Request $Request,
         Response $Response,
      ): Response {
         $control = $Request->fields['control'] ?? '';

         return $Response(
            code: 200,
            body: 'C4-LATE-BOUNDARY-OK:' . (is_string($control) ? $control : ''),
         );
      }, POST);

      yield $Router->route('/c4-preamble-harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(code: 200, body: 'C4-PREAMBLE-HARNESS-OK');
      }, GET);
   },

   // @phpstan-ignore return.unusedType
   test: function (string $response) use (
      &$probe,
      $Live,
      $Read,
      $Write,
   ): bool|string {
      if (! str_contains($response, 'C4-PREAMBLE-HARNESS-OK')) {
         return 'C4 fixture failed: the live harness control did not complete.';
      }

      if ($probe['error'] !== '') { // @phpstan-ignore notIdentical.alwaysFalse
         Vars::$labels = ['C4 multipart preamble fixture error'];
         dump(json_encode($probe));

         return 'C4 fixture failed: ' . $probe['error'];
      }

      if (
         $probe['decoder_installed'] !== true // @phpstan-ignore booleanOr.alwaysTrue, booleanOr.alwaysTrue, booleanOr.alwaysTrue, booleanOr.alwaysTrue, booleanOr.alwaysTrue, booleanOr.alwaysTrue, booleanOr.alwaysTrue, notIdentical.alwaysTrue
         || $probe['install_state'] !== States::Complete->name
         || $probe['attack_states'] === []
         || $probe['cleanup_tail_bytes'] !== 0
         || $probe['initial_feed_state'] !== States::Complete->name
         || $probe['initial_feed_decoder'] !== true
         || $probe['initial_feed_downloaded'] !== $probe['segment_bytes']
         || $probe['initial_feed_tail_bytes'] > $probe['boundary_bytes'] + 2
      ) {
         Vars::$labels = ['C4 multipart source-path controls'];
         dump(json_encode($probe));

         return 'C4 fixture failed: Request admission did not install, bound, and clean '
            . 'the real multipart streaming decoder.';
      }

      if ( // @phpstan-ignore deadCode.unreachable
         $probe['missing_boundary_state'] !== States::Rejected->name
         || $probe['missing_boundary_rejected'] !== true
         || str_contains($probe['missing_boundary_rejection'], '400 Bad Request') === false
         || $probe['missing_boundary_downloaded'] !== $probe['segment_bytes']
         || $probe['missing_boundary_consumed'] !== 0
      ) {
         Vars::$labels = ['C4 missing terminal-boundary control'];
         dump(json_encode([
            'state' => $probe['missing_boundary_state'],
            'rejected' => $probe['missing_boundary_rejected'],
            'rejection' => $probe['missing_boundary_rejection'],
            'downloaded' => $probe['missing_boundary_downloaded'],
            'consumed' => $probe['missing_boundary_consumed'],
         ]));

         return 'C4 fixture failed: a complete multipart body without a boundary '
            . 'was not rejected with 400 Bad Request.';
      }

      if (
         $probe['missing_closing_state'] !== States::Rejected->name
         || $probe['missing_closing_rejected'] !== true
         || str_contains($probe['missing_closing_rejection'], '400 Bad Request') === false
      ) {
         Vars::$labels = ['C4 missing closing-boundary control'];
         dump(json_encode([
            'state' => $probe['missing_closing_state'],
            'rejected' => $probe['missing_closing_rejected'],
            'rejection' => $probe['missing_closing_rejection'],
         ]));

         return 'C4 fixture failed: a multipart body without its closing boundary '
            . 'was not rejected with 400 Bad Request.';
      }

      if (
         $probe['offset_zero_state'] !== States::Complete->name
         || $probe['offset_zero_rejected'] !== false
         || $probe['offset_zero_field'] !== 'ok'
      ) {
         Vars::$labels = ['C4 offset-zero boundary control'];
         dump(json_encode([
            'state' => $probe['offset_zero_state'],
            'rejected' => $probe['offset_zero_rejected'],
            'field' => $probe['offset_zero_field'],
         ]));

         return 'C4 fixture failed: an ordinary offset-zero multipart boundary '
            . 'did not remain valid.';
      }

      if (
         $probe['split_failures'] !== []
         || $probe['split_controls_passed'] !== $probe['split_positions']
         || $probe['part_suffix_failures'] !== []
         || $probe['part_suffix_controls_passed'] !== $probe['part_suffix_positions']
      ) {
         Vars::$labels = ['C4 split-boundary controls'];
         dump(json_encode($probe));

         return 'C4 fixture failed: a valid boundary was not detected across every split.';
      }

      if ($Live->error !== '') {
         if (is_resource($Live->connection)) {
            fclose($Live->connection);
         }
         Vars::$labels = ['C4 live transport fixture error'];
         dump(json_encode([
            'error' => $Live->error,
            'snapshot' => $Live->snapshot,
            'inspector_wire' => $Live->inspectorWire,
         ]));

         return 'C4 fixture failed: ' . $Live->error;
      }
      if (is_resource($Live->connection) === false) {
         return 'C4 fixture failed: the live attack connection was unavailable.';
      }

      $lastAttackState = end($probe['attack_states']);
      $directRejected = $probe['attack_rejected'] === true
         && $lastAttackState === States::Rejected->name
         && (
            str_contains($probe['attack_rejection'], '400 Bad Request')
            || str_contains($probe['attack_rejection'], '413 Request Entity Too Large')
         );
      $found = ($Live->snapshot['found'] ?? false) === true;
      if ($found === false) {
         // @ A future explicit preamble cap may reject and remove the decoder
         //   before inspection. Only a concrete 400/413 response is secure;
         //   disappearance without it remains a fixture failure.
         $Live->attackWire = $Read($Live->connection);
         fclose($Live->connection);

         $liveRejected =
            str_contains($Live->attackWire, 'HTTP/1.1 400 Bad Request')
            || str_contains($Live->attackWire, 'HTTP/1.1 413 Request Entity Too Large');
         if ($liveRejected && $directRejected) {
            return true;
         }

         Vars::$labels = ['C4 live decoder attribution control'];
         dump(json_encode([
            'snapshot' => $Live->snapshot,
            'inspector_wire' => $Live->inspectorWire,
            'attack_wire' => $Live->attackWire,
            'direct' => $probe,
         ]));

         return 'C4 fixture failed: live and direct probes were not both explicitly rejected.';
      }

      $liveVulnerable =
         ($Live->snapshot['state'] ?? -1) === 0
         && ($Live->snapshot['parsed'] ?? true) === false
         && ($Live->snapshot['downloaded'] ?? -1) === $probe['attack_bytes']
         && ($Live->snapshot['body_downloaded'] ?? -1) === $probe['attack_bytes']
         && ($Live->snapshot['retained_tail_bytes'] ?? -1) === $probe['attack_bytes']
         && ($Live->snapshot['boundary_bytes'] ?? -1) === $probe['boundary_bytes'];
      $liveBounded =
         ($Live->snapshot['state'] ?? -1) === 0
         && ($Live->snapshot['parsed'] ?? true) === false
         && ($Live->snapshot['downloaded'] ?? -1) === $probe['attack_bytes']
         && ($Live->snapshot['body_downloaded'] ?? -1) === $probe['attack_bytes']
         && ($Live->snapshot['retained_tail_bytes'] ?? PHP_INT_MAX)
            <= $probe['boundary_bytes'] + 2;

      if ($liveVulnerable === false && $liveBounded === false) {
         fclose($Live->connection);
         Vars::$labels = ['C4 live multipart state control'];
         dump(json_encode($Live->snapshot));

         return 'C4 fixture failed: live multipart state was neither linearly retained nor bounded.';
      }

      // @ Complete the exact instrumented request with a valid late boundary.
      //   Confirmation requires the ordinary handler to receive its field.
      if ($Write($Live->connection, $Live->suffix) === false) {
         fclose($Live->connection);
         return 'C4 fixture failed: the valid late multipart suffix could not be sent.';
      }
      $Live->attackWire = $Read($Live->connection);
      fclose($Live->connection);
      if (
         str_contains($Live->attackWire, 'HTTP/1.1 200 OK') === false
         || str_contains($Live->attackWire, 'C4-LATE-BOUNDARY-OK:ok') === false
      ) {
         Vars::$labels = ['C4 late-boundary completion control'];
         dump(json_encode([
            'snapshot' => $Live->snapshot,
            'attack_wire' => $Live->attackWire,
         ]));

         return 'C4 fixture failed: the instrumented request did not complete as valid multipart.';
      }

      $allIncomplete = count(array_unique($probe['attack_states'])) === 1
         && $probe['attack_states'][0] === States::Incomplete->name;
      $directVulnerable =
         $probe['attack_rejected'] === false
         && $allIncomplete
         && $probe['attack_consumed'] === $probe['attack_bytes']
         && $probe['body_downloaded'] === $probe['attack_bytes']
         && $probe['retained_tail_bytes'] === $probe['attack_bytes']
         && $probe['max_retained_tail_bytes'] === $probe['attack_bytes']
         && $probe['retained_growth_bytes'] >= $probe['attack_bytes'] - 131072
         && $probe['released_on_disconnect_bytes'] >= $probe['attack_bytes'] - 131072;
      if ($liveVulnerable && $directVulnerable) {
         $evidence = [
            'live' => $Live->snapshot,
            'late_boundary_completed' => true,
            'direct' => [
               'state' => end($probe['attack_states']),
               'rejected' => $probe['attack_rejected'],
               'reads' => $probe['attack_reads'],
               'consumed' => $probe['attack_consumed'],
               'body_downloaded' => $probe['body_downloaded'],
               'retained_tail_bytes' => $probe['retained_tail_bytes'],
               'max_retained_tail_bytes' => $probe['max_retained_tail_bytes'],
               'retained_growth_bytes' => $probe['retained_growth_bytes'],
               'max_retained_growth_bytes' => $probe['max_retained_growth_bytes'],
               'released_on_disconnect_bytes' =>
                  $probe['released_on_disconnect_bytes'],
               'boundary_bytes' => $probe['boundary_bytes'],
               'retention_ratio' => round(
                  $probe['retained_tail_bytes'] / $probe['boundary_bytes'],
                  2,
               ),
               'segment_bytes' => $probe['segment_bytes'],
               'split_controls' => $probe['split_controls_passed'],
            ],
         ];
         Vars::$labels = ['C4 multipart initial-preamble retention evidence'];
         dump(json_encode($evidence));

         return 'CONFIRMED C4: multipart initial-boundary search retained the complete '
            . 'one-MiB attacker preamble on a live pre-routing connection, which '
            . 'then completed as valid multipart. Evidence: '
            . json_encode($evidence);
      }

      // @ Secure outcomes: reject at an explicit preamble cap, or continue
      //   scanning while retaining only the boundary-sized suffix.
      $directBounded = $probe['attack_rejected'] === false
         && $allIncomplete
         && $probe['attack_reads'] === intdiv(
            $probe['attack_bytes'],
            $probe['segment_bytes'],
         )
         && $probe['attack_consumed'] === $probe['attack_bytes']
         && $probe['body_downloaded'] === $probe['attack_bytes']
         && $probe['retained_tail_bytes'] <= $probe['boundary_bytes'] + 2
         && $probe['max_retained_tail_bytes'] <= $probe['boundary_bytes'] + 2
         && $probe['retained_growth_bytes'] <= 256 * 1024
         && $probe['max_retained_growth_bytes'] <= 256 * 1024;
      if (
         $liveBounded
         && ($directRejected || $directBounded)
      ) {
         return true;
      }

      Vars::$labels = ['C4 unexpected multipart preamble outcome'];
      dump(json_encode([
         'live' => $Live->snapshot,
         'direct' => $probe,
      ]));

      return 'C4 fixture produced neither confirmed linear retention nor a bounded/rejected outcome.';
   },
);
