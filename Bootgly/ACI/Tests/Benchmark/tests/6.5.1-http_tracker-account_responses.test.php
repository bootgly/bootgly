<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\HTTP\Tracker;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should account for HTTP/1 writes and responses exactly',
   test: new Assertions(Case: function (): \Generator
   {
      $Capture = static function (
         array $requests,
         array $chunks,
         null|bool $peerEOF = null,
         array $limits = [],
      ): array {
         $Tracker = new Tracker(...$limits);
         $Tracker->queue($requests);
         $Tracker->accept(0);
         foreach ($chunks as $chunk) {
            $Tracker->feed($chunk);
         }
         if ($peerEOF !== null) {
            $Tracker->close($peerEOF);
         }

         return $Tracker->inspect();
      };

      // @ Fragment every status/header/body boundary, then every byte.
      $fixedWire = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nX-Test: yes\r\n\r\nhello";
      $fixedSplits = true;
      for ($split = 0, $size = strlen($fixedWire); $split <= $size; $split++) {
         $result = $Capture([1], [substr($fixedWire, 0, $split), substr($fixedWire, $split)]);
         $fixedSplits = $fixedSplits
            && $result['responses'] === 1
            && $result['statuses'] === [200 => 1]
            && $result['outstanding'] === 0
            && $result['accounting'] === true;
      }
      $result = $Capture([1], str_split($fixedWire));
      $fixedSplits = $fixedSplits && $result['responses'] === 1 && $result['failures'] === [];

      yield new Assertion(
         description: 'Status, headers, and fixed bodies survive every split point',
         fallback: 'Incremental Content-Length framing failed!'
      )
         ->expect($fixedSplits, Op::Identical, true)
         ->assert();

      // ! Persist the three false-count modes from the original harness:
      //   split status token, marker bytes inside a body, and incomplete CL.
      $Fragmented = new Tracker();
      $Fragmented->queue([1]);
      $Fragmented->accept(0);
      $fragmentFirst = $Fragmented->feed('HT');
      $fragmentSecond = $Fragmented->feed(
         "TP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
      );

      $bodyMarker = $Capture(
         [1, 1],
         [
            "HTTP/1.1 200 OK\r\nContent-Length: 7\r\n\r\nHTTP/1."
            . "HTTP/1.1 201 Created\r\nContent-Length: 0\r\n\r\n",
         ],
      );

      $Partial = new Tracker();
      $Partial->queue([1]);
      $Partial->accept(0);
      $partialCompletion = $Partial->feed(
         "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhe"
      );
      $Partial->close(true);
      $partial = $Partial->inspect();

      yield new Assertion(
         description: 'Original token-count false positives and negatives stay closed',
         fallback: 'A known substr_count response-accounting failure regressed!'
      )
         ->expect(
            [
               [$fragmentFirst, $fragmentSecond],
               [$bodyMarker['responses'], $bodyMarker['statuses']],
               [$partialCompletion, $partial['responses'], $partial['failures']],
            ],
            Op::Identical,
            [
               [0, 1],
               [2, [200 => 1, 201 => 1]],
               [0, 0, ['truncated_response' => 1]],
            ],
         )
         ->assert();

      // @ Request-method FIFO controls responses that never carry a body.
      $noBodyWire = "HTTP/1.1 200 OK\r\nContent-Length: 999\r\n\r\n"
         . "HTTP/1.1 204 No Content\r\nContent-Length: 10\r\n\r\n"
         . "HTTP/1.1 304 Not Modified\r\nTransfer-Encoding: chunked\r\n\r\n";
      $noBody = $Capture(
         [
            ['bytes' => 1, 'method' => 'HEAD'],
            ['bytes' => 1, 'method' => 'GET'],
            ['bytes' => 1, 'method' => 'GET'],
         ],
         [$noBodyWire],
      );
      $lowercaseMethod = $Capture(
         [['bytes' => 1, 'method' => 'head']],
         ["HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nx"],
      );
      $largeNoBody = $Capture(
         [
            ['bytes' => 1, 'method' => 'HEAD'],
            ['bytes' => 1, 'method' => 'GET'],
         ],
         [
            "HTTP/1.1 200 OK\r\nContent-Length: 999999999999999999999999\r\n"
            . "Transfer-Encoding: chunked\r\n\r\n"
            . "HTTP/1.1 304 Not Modified\r\nContent-Length: 999999999999999999999999\r\n"
            . "Transfer-Encoding: chunked\r\n\r\n",
         ],
         null,
         ['bodyLimit' => 4],
      );

      yield new Assertion(
         description: 'HEAD, 204, and 304 preserve the pipelined response tail',
         fallback: 'No-body response framing consumed a pipeline tail!'
      )
         ->expect(
            [
               $noBody['responses'],
               $noBody['statuses'],
               $noBody['outstanding'],
               $noBody['accounting'],
               [$lowercaseMethod['responses'], $lowercaseMethod['accounting']],
               [$largeNoBody['responses'], $largeNoBody['failures']],
            ],
            Op::Identical,
            [3, [200 => 1, 204 => 1, 304 => 1], 0, true, [1, true], [2, []]],
         )
         ->assert();

      $informational = $Capture(
         [1],
         [
            "HTTP/1.1 100 Continue\r\nX-Step: one\r\n\r\n"
            . "HTTP/1.1 103 Early Hints\r\nLink: </asset>\r\n\r\n"
            . "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
         ],
      );

      yield new Assertion(
         description: 'Informational heads do not consume the request FIFO',
         fallback: 'Informational response accounting is incorrect!'
      )
         ->expect(
            [$informational['responses'], $informational['informational'], $informational['statuses']],
            Op::Identical,
            [1, 2, [200 => 1]],
         )
         ->assert();

      // @ Identical decimal Content-Length values are accepted, conflicts are not.
      $duplicate = $Capture(
         [1, 1],
         [
            "HTTP/1.1 200 OK\r\nContent-Length: 05\r\nContent-Length: 5, 005\r\n\r\nhello"
            . "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
         ],
      );
      $conflict = $Capture(
         [1, 1],
         ["HTTP/1.1 200 OK\r\nContent-Length: 4\r\nContent-Length: 5\r\n\r\n"],
      );
      $ambiguous = $Capture(
         [1],
         ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Length: 0\r\n\r\n"],
      );

      yield new Assertion(
         description: 'Content-Length duplication is strict and TE plus CL is rejected',
         fallback: 'Ambiguous HTTP response length was accepted!'
      )
         ->expect(
            [
               $duplicate['responses'],
               $conflict['failures'],
               $ambiguous['failures'],
               $conflict['accounting'] && $ambiguous['accounting'],
            ],
            Op::Identical,
            [
               2,
               ['conflicting_content_length' => 1, 'pipeline_aborted' => 1],
               ['transfer_length_conflict' => 1],
               true,
            ],
         )
         ->assert();

      // @ Exercise every split inside chunk sizes, extensions, data CRLF, zero,
      //   trailers, and the following pipelined status line.
      $chunkWire = "HTTP/1.1 200 OK\r\nTransfer-Encoding: gzip; level=\"a,b\", chunked\r\n\r\n"
         . "4 ; foo = \"a;b\"\r\nWiki\r\n5;quoted=\"yes\"\r\npedia\r\n"
         . "0;done=yes\r\nX-Checksum: ok\r\n\r\n"
         . "HTTP/1.1 201 Created\r\nContent-Length: 0\r\n\r\n";
      $chunkSplits = true;
      for ($split = 0, $size = strlen($chunkWire); $split <= $size; $split++) {
         $result = $Capture([1, 1], [substr($chunkWire, 0, $split), substr($chunkWire, $split)]);
         $chunkSplits = $chunkSplits
            && $result['responses'] === 2
            && $result['statuses'] === [200 => 1, 201 => 1]
            && $result['failures'] === [];
      }
      $result = $Capture([1, 1], str_split($chunkWire));
      $chunkSplits = $chunkSplits && $result['responses'] === 2;

      yield new Assertion(
         description: 'Chunk extensions, data CRLF, trailers, and tails survive every split',
         fallback: 'Incremental chunked framing failed!'
      )
         ->expect($chunkSplits, Op::Identical, true)
         ->assert();

      $badChunk = $Capture(
         [1, 1],
         ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n1\r\naX\n"],
      );
      $truncatedChunk = $Capture(
         [1, 1],
         ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nab"],
         true,
      );

      yield new Assertion(
         description: 'Malformed and truncated chunks abort the remaining pipeline',
         fallback: 'A malformed or truncated chunk was treated as complete!'
      )
         ->expect(
            [$badChunk['failures'], $truncatedChunk['failures']],
            Op::Identical,
            [
               ['malformed_chunk' => 1, 'pipeline_aborted' => 1],
               ['truncated_response' => 1, 'pipeline_aborted' => 1],
            ],
         )
         ->assert();

      // @ Close-delimited bodies complete only on an observed peer EOF.
      $EOFHead = "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\nbody-until-eof";
      $beforeEOF = $Capture([1], [$EOFHead]);
      $atEOF = $Capture([1], [$EOFHead], true);
      $localClose = $Capture([1], [$EOFHead], false);
      $EOFTracker = new Tracker();
      $EOFTracker->queue([1]);
      $EOFTracker->accept(0);
      $feedCompletions = $EOFTracker->feed($EOFHead);
      $closeCompletions = $EOFTracker->close(true);

      yield new Assertion(
         description: 'EOF-delimited bodies require peer EOF',
         fallback: 'EOF-delimited response completion is not evidence based!'
      )
         ->expect(
            [
               [$beforeEOF['responses'], $beforeEOF['outstanding']],
               [$atEOF['responses'], $atEOF['failures'], $atEOF['terminal']],
               $localClose['failures'],
               [$feedCompletions, $closeCompletions],
            ],
            Op::Identical,
            [[0, 1], [1, [], true], ['connection_aborted' => 1], [0, 1]],
         )
         ->assert();

      $Closing = new Tracker();
      $Closing->queue([1]);
      $Closing->accept(0);
      $Closing->feed("HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
      $queueRejected = false;
      try {
         $Closing->queue([1]);
      }
      catch (\InvalidArgumentException) {
         $queueRejected = true;
      }

      $HTTP10 = new Tracker();
      $HTTP10->queue([1]);
      $HTTP10->accept(0);
      $HTTP10->feed("HTTP/1.0 200 OK\r\nContent-Length: 0\r\n\r\n");

      $HTTP10KeepAlive = new Tracker();
      $HTTP10KeepAlive->queue([1]);
      $HTTP10KeepAlive->accept(0);
      $HTTP10KeepAlive->feed(
         "HTTP/1.0 200 OK\r\nConnection: keep-alive\r\nContent-Length: 0\r\n\r\n"
      );

      $ClosingPipeline = new Tracker();
      $ClosingPipeline->queue([1, 1]);
      $ClosingPipeline->accept(0);
      $closingCompleted = $ClosingPipeline->feed(
         "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: 0\r\n\r\n"
         . "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
      );

      $EOFPipeline = new Tracker();
      $EOFPipeline->queue([1, 1]);
      $EOFPipeline->accept(0);
      $beforeCloseBody = $EOFPipeline->feed(
         "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
         . "HTTP/1.1 200 OK\r\n\r\npartial"
      );

      yield new Assertion(
         description: 'Connection persistence prevents unsafe replenishment without dropping tails',
         fallback: 'HTTP persistence semantics allowed a request on a closing connection!'
      )
         ->expect(
            [
               [$Closing->reusable, $queueRejected],
               [$HTTP10->reusable, $HTTP10KeepAlive->reusable],
               [
                  $closingCompleted,
                  $ClosingPipeline->inspect()['responses'],
                  $ClosingPipeline->reusable,
               ],
               [
                  $beforeCloseBody,
                  $EOFPipeline->inspect()['outstanding'],
                  $EOFPipeline->reusable,
               ],
            ],
            Op::Identical,
            [
               [false, true],
               [false, true],
               [2, 2, false],
               [1, 1, false],
            ],
         )
         ->assert();

      $upgrade = $Capture(
         [1, 1],
         ["HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\n\r\n"],
      );
      $tunnel = $Capture(
         [['bytes' => 1, 'method' => 'CONNECT']],
         ["HTTP/1.1 200 Connection Established\r\n\r\n"],
      );

      yield new Assertion(
         description: 'Protocol upgrades and successful CONNECT tunnels fail closed',
         fallback: 'Opaque upgraded bytes entered the HTTP/1 parser!'
      )
         ->expect(
            [$upgrade['failures'], $tunnel['failures']],
            Op::Identical,
            [
               ['unsupported_upgrade' => 1, 'pipeline_aborted' => 1],
               ['unsupported_tunnel' => 1],
            ],
         )
         ->assert();

      $bareLF = $Capture([1], ["HTTP/1.1 200 OK\nContent-Length: 0\r\n\r\n"]);
      $badHeader = $Capture([1], ["HTTP/1.1 200 OK\r\nBad Header: value\r\n\r\n"]);
      $forbiddenTrailer = $Capture(
         [1],
         [
            "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "0\r\nContent-Length: 0\r\n\r\n",
         ],
      );

      yield new Assertion(
         description: 'Malformed lines, fields, and framing trailers are rejected',
         fallback: 'Malformed HTTP field syntax was accepted!'
      )
         ->expect(
            [$bareLF['error'], $badHeader['error'], $forbiddenTrailer['error']],
            Op::Identical,
            ['malformed_line_ending', 'malformed_header', 'forbidden_trailer'],
         )
         ->assert();

      $longLine = $Capture(
         [1],
         ["HTTP/1.1 200 OK\r\nX-Long: " . str_repeat('a', 40) . "\r\n\r\n"],
         null,
         ['lineLimit' => 32, 'headerLimit' => 64],
      );
      $lengthOverflow = $Capture(
         [1],
         ["HTTP/1.1 200 OK\r\nContent-Length: " . str_repeat('9', 40) . "\r\n\r\n"],
      );
      $chunkOverflow = $Capture(
         [1],
         ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n" . str_repeat('f', 40) . "\r\n"],
      );
      $bodyLimit = $Capture(
         [1],
         ["HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\n"],
         null,
         ['bodyLimit' => 4],
      );

      yield new Assertion(
         description: 'Line, integer, chunk, and body limits fail closed',
         fallback: 'An HTTP parser limit or integer overflow was not detected!'
      )
         ->expect(
            [$longLine['error'], $lengthOverflow['error'], $chunkOverflow['error'], $bodyLimit['error']],
            Op::Identical,
            ['header_line_limit', 'length_overflow', 'chunk_overflow', 'body_limit'],
         )
         ->assert();

      // @ A repeated fixed response may reuse only the exact head previously
      //   accepted by the strict parser. Fragmentation and mismatch must retain
      //   the same observable framing behavior.
      $cacheHead = "HTTP/1.1 200 OK\r\nServer: Example\r\n"
         . "Date: Tue, 14 Jul 2026 20:00:00 GMT\r\n"
         . "Content-Type: text/plain\r\nContent-Length: 5\r\n\r\n";
      $cacheWire = $cacheHead . 'hello';
      $Cached = new Tracker();
      $Cached->queue(1);
      $Cached->accept(0);
      $seeded = $Cached->feed($cacheWire);

      $Cached->send();
      $atomic = $Cached->feed($cacheWire);
      $atomicSnapshot = $Cached->inspect();

      $Cached->send();
      $headPrefix = $Cached->feed(substr($cacheWire, 0, 37));
      $headTail = $Cached->feed(substr($cacheWire, 37));

      $Cached->queue(1);
      $Cached->accept(0);
      $bodyPrefix = $Cached->feed($cacheHead . 'he');
      $bodyTail = $Cached->feed('llo');

      $Cached->send(2);
      $cachedPipeline = $Cached->feed($cacheWire . $cacheWire);

      $Cached->queue(1);
      $Cached->accept(0);
      $staleHead = $Cached->feed(
         "HTTP/1.1 201 Created\r\nServer: Example\r\n"
         . "Date: Tue, 14 Jul 2026 20:00:01 GMT\r\n"
         . "Content-Type: text/plain\r\nContent-Length: 3\r\n\r\nnew"
      );
      $cached = $Cached->inspect();

      $MethodCache = new Tracker();
      $MethodCache->queue(1);
      $MethodCache->accept(0);
      $MethodCache->feed($cacheWire);
      $MethodCache->queue(['head' => ['bytes' => 1, 'method' => 'HEAD']]);
      $MethodCache->accept(0);
      $headMethod = $MethodCache->feed($cacheHead);

      $CacheMismatch = new Tracker();
      $CacheMismatch->queue(1);
      $CacheMismatch->accept(0);
      $CacheMismatch->feed($cacheWire);
      $CacheMismatch->queue(1);
      $CacheMismatch->accept(0);
      $CacheMismatch->feed(
         "HTTP/1.1 200 OK\r\nServer: Example\r\n"
         . "Date: Tue, 14 Jul 2026 20:00:00 GMT\r\n"
         . "Bad Header: value\r\nContent-Length: 0\r\n\r\n"
      );
      $cacheMismatch = $CacheMismatch->inspect();

      yield new Assertion(
         description: 'Validated fixed heads reuse exact bytes and fall back on every mismatch',
         fallback: 'The exact response-head cache changed strict framing behavior!'
      )
         ->expect(
            [
               [$seeded, $atomic, $headPrefix, $headTail, $bodyPrefix, $bodyTail, $cachedPipeline, $staleHead],
               [$atomicSnapshot['responses'], $atomicSnapshot['statuses'], $atomicSnapshot['accounting']],
               [$cached['responses'], $cached['statuses'], $cached['accounting']],
               [$headMethod, $MethodCache->inspect()['responses']],
               [$cacheMismatch['error'], $cacheMismatch['failures'], $cacheMismatch['accounting']],
            ],
            Op::Identical,
            [
               [1, 1, 0, 1, 0, 1, 2, 1],
               [2, [200 => 2], true],
               [7, [200 => 6, 201 => 1], true],
               [1, 2],
               ['malformed_header', ['malformed_header' => 1], true],
            ],
         )
         ->assert();

      // @ A partial socket write can cross one or more logical request bounds.
      $Tracker = new Tracker();
      $Tracker->queue([
         ['bytes' => 3, 'method' => 'GET'],
         ['bytes' => 2, 'method' => 'HEAD'],
         ['bytes' => 4, 'method' => 'GET'],
      ]);
      $initial = $Tracker->inspect();
      $Tracker->accept(6);
      $firstWrite = $Tracker->inspect();
      $Tracker->accept(3);
      $secondWrite = $Tracker->inspect();
      $completed = $Tracker->feed(
         "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
         . "HTTP/1.1 200 OK\r\nContent-Length: 99\r\n\r\n"
      );
      $Tracker->abort('socket write failed');
      $aborted = $Tracker->inspect();

      yield new Assertion(
         description: 'Partial writes cross exact request boundaries without guessing',
         fallback: 'Request write-boundary accounting is incorrect!'
      )
         ->expect(
            [
               [$initial['scheduled'], $initial['sent'], $initial['accounting']],
               [$firstWrite['sent'], $firstWrite['partial_writes'], $firstWrite['outstanding']],
               [$secondWrite['sent'], $secondWrite['partial_writes'], $secondWrite['outstanding']],
               [
                  $aborted['responses'],
                  $aborted['write_failures'],
                  $aborted['accounting'],
                  $aborted['terminal'],
                  $Tracker->check(),
                  $completed,
               ],
            ],
            Op::Identical,
            [
               [3, 0, true],
               [1, 1, 1],
               [2, 2, 2],
               [2, ['socket_write_failed' => 1], true, true, false, 2],
            ],
         )
         ->assert();

      $Peer = new Tracker();
      $Peer->queue([1, 2]);
      $Peer->accept(2);
      $Peer->close(true);
      $peerClosed = $Peer->inspect();

      $Invalid = new Tracker();
      $Invalid->queue([2]);
      $Invalid->accept(3);
      $invalidWrite = $Invalid->inspect();

      yield new Assertion(
         description: 'Terminal paths classify response and write failures independently',
         fallback: 'Terminal HTTP accounting left an unclassified request!'
      )
         ->expect(
            [
               [
                  $peerClosed['failures'],
                  $peerClosed['write_failures'],
                  $peerClosed['accounting'],
               ],
               [
                  $invalidWrite['sent'],
                  $invalidWrite['write_failures'],
                  $invalidWrite['accounting'],
               ],
            ],
            Op::Identical,
            [
               [['truncated_response' => 1], ['peer_closed_before_write' => 1], true],
               [0, ['invalid_write_boundary' => 1], true],
            ],
         )
         ->assert();

      $Ended = new Tracker();
      $Ended->queue([1, 1]);
      $Ended->accept(0);
      $Ended->censor('measurement ended');
      $ended = $Ended->inspect();

      $Unsolicited = new Tracker();
      $Unsolicited->queue([1]);
      $Unsolicited->accept(0);
      $Unsolicited->feed(
         "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
         . "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
      );
      $unsolicited = $Unsolicited->inspect();

      yield new Assertion(
         description: 'Measurement censoring closes the ledger and unsolicited responses invalidate it',
         fallback: 'A terminal accounting anomaly was hidden by balanced counters!'
      )
         ->expect(
            [
               [
                  $ended['failures'],
                  $ended['censors'],
                  $ended['write_censors'],
                  $ended['accounting'],
                  $ended['error'],
               ],
               [
                  $unsolicited['responses'],
                  $unsolicited['error'],
                  $unsolicited['accounting'],
               ],
            ],
            Op::Identical,
            [
               [[], ['measurement_ended' => 2], [], true, null],
               [1, 'unexpected_response', false],
            ],
         )
         ->assert();
   })
);
