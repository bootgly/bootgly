<?php

use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Benchmark\Time\Series;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should align and merge strict monotonic one-second series',
   test: new Assertions(Case: function (): Generator
   {
      // ! Deliberately exceeds JavaScript's exact integer range; the exported
      //   absolute boundaries must remain decimal strings.
      $originNS = 9_100_000_000_000_000;
      $deadlineNS = $originNS + (3 * Series::BUCKET_NS);
      $Series = new Series($originNS, $deadlineNS);
      $Series->record($originNS, ['sent' => 2, 'bytes_read' => 20]);
      $Series->record($originNS + Series::BUCKET_NS - 1, ['responses' => 1]);
      $Series->record($originNS + Series::BUCKET_NS, [
         'responses' => 1,
         'failed' => 1,
      ]);
      $Series->record($deadlineNS - 1, [
         'censored' => 1,
         'write_failed' => 2,
         'write_censored' => 3,
      ]);
      $export = $Series->export();

      $Scalar = new Series($originNS, $deadlineNS);
      $Scalar->accumulate($originNS, sent: 2, bytesRead: 20);
      $Scalar->accumulate($originNS + Series::BUCKET_NS - 1, responses: 1);
      $Scalar->accumulate(
         $originNS + Series::BUCKET_NS,
         responses: 1,
         failed: 1,
      );
      $Scalar->accumulate(
         $deadlineNS - 1,
         censored: 1,
         writeFailed: 2,
         writeCensored: 3,
      );

      yield new Assertion(
         description: 'Scalar hot-path accumulation preserves the generic record contract',
         fallback: 'Allocation-free time-series accumulation changed the exported counters!'
      )
         ->expect($Scalar->export(), Op::Identical, $export)
         ->assert();

      yield new Assertion(
         description: 'Origin is inclusive, the last nanosecond is included, and seconds stay aligned',
         fallback: 'Half-open one-second bucket alignment is incorrect!'
      )
         ->expect(
            [
               [$export['origin_ns'], $export['deadline_ns'], $export['bucket_ns']],
               $export['buckets'],
               $export['totals'],
            ],
            Op::Identical,
            [
               [(string) $originNS, (string) $deadlineNS, 1_000_000_000],
               [
                  [
                     'second' => 0,
                     'sent' => 2,
                     'responses' => 1,
                     'failed' => 0,
                     'censored' => 0,
                     'write_failed' => 0,
                     'write_censored' => 0,
                     'bytes_read' => 20,
                  ],
                  [
                     'second' => 1,
                     'sent' => 0,
                     'responses' => 1,
                     'failed' => 1,
                     'censored' => 0,
                     'write_failed' => 0,
                     'write_censored' => 0,
                     'bytes_read' => 0,
                  ],
                  [
                     'second' => 2,
                     'sent' => 0,
                     'responses' => 0,
                     'failed' => 0,
                     'censored' => 1,
                     'write_failed' => 2,
                     'write_censored' => 3,
                     'bytes_read' => 0,
                  ],
               ],
               [
                  'sent' => 2,
                  'responses' => 2,
                  'failed' => 1,
                  'censored' => 1,
                  'write_failed' => 2,
                  'write_censored' => 3,
                  'bytes_read' => 20,
               ],
            ],
         )
         ->assert();

      $outside = [];
      foreach ([$originNS - 1, $deadlineNS] as $timestampNS) {
         try {
            $Series->record($timestampNS, ['responses' => 1]);
            $outside[] = false;
         }
         catch (InvalidArgumentException) {
            $outside[] = true;
         }
      }

      yield new Assertion(
         description: 'Timestamps before the origin and at the deadline are rejected',
         fallback: 'The half-open time-series boundary admitted an outside event!'
      )
         ->expect($outside, Op::Identical, [true, true])
         ->assert();

      $Imported = Series::import($export);
      $Incoming = new Series($originNS, $deadlineNS);
      $Incoming->record($originNS + Series::BUCKET_NS, [
         'sent' => 4,
         'responses' => 3,
         'censored' => 1,
         'bytes_read' => 40,
      ]);
      $Imported->merge($Incoming);
      $merged = $Imported->export();

      yield new Assertion(
         description: 'Strict import round-trips and compatible children merge elementwise',
         fallback: 'Time-series import or elementwise merge changed counters!'
      )
         ->expect(
            [
               Series::import($export)->export() === $export,
               $merged['buckets'][1],
               $merged['totals'],
            ],
            Op::Identical,
            [
               true,
               [
                  'second' => 1,
                  'sent' => 4,
                  'responses' => 4,
                  'failed' => 1,
                  'censored' => 1,
                  'write_failed' => 0,
                  'write_censored' => 0,
                  'bytes_read' => 40,
               ],
               [
                  'sent' => 6,
                  'responses' => 5,
                  'failed' => 1,
                  'censored' => 2,
                  'write_failed' => 2,
                  'write_censored' => 3,
                  'bytes_read' => 60,
               ],
            ],
         )
         ->assert();

      $invalid = [];
      $Reject = static function (array $document) use (&$invalid): void {
         try {
            Series::import($document);
            $invalid[] = false;
         }
         catch (InvalidArgumentException|OverflowException) {
            $invalid[] = true;
         }
      };

      $document = $export;
      $document['origin_ns'] = $originNS;
      $Reject($document);
      $document = $export;
      $document['unknown'] = true;
      $Reject($document);
      $document = $export;
      $document['metrics'] = array_reverse($document['metrics']);
      $Reject($document);
      $document = $export;
      $document['buckets'][1]['second'] = 2;
      $Reject($document);
      $document = $export;
      $document['buckets'][0]['responses'] = -1;
      $Reject($document);
      $document = $export;
      $document['totals']['responses']++;
      $Reject($document);
      $document = $export;
      $document['deadline_ns'] = (string) ($deadlineNS - 1);
      $Reject($document);
      $document = $export;
      $document['origin_ns'] = (string) PHP_INT_MAX . '0';
      $Reject($document);

      yield new Assertion(
         description: 'Import rejects imprecise, extended, inconsistent, and overflowing documents',
         fallback: 'Strict time-series import accepted a malformed document!'
      )
         ->expect($invalid, Op::Identical, array_fill(0, 8, true))
         ->assert();

      $mergeRejected = false;
      try {
         $Series->merge(new Series($originNS + 1, $deadlineNS + 1));
      }
      catch (InvalidArgumentException) {
         $mergeRejected = true;
      }

      yield new Assertion(
         description: 'Series with shifted absolute boundaries cannot merge',
         fallback: 'A time-series merge crossed incompatible measurement boundaries!'
      )
         ->expect($mergeRejected, Op::Identical, true)
         ->assert();

      $Overflowing = new Series($originNS, $originNS + Series::BUCKET_NS);
      $Overflowing->record($originNS, ['responses' => PHP_INT_MAX]);
      $recordOverflow = false;
      $mergeOverflow = false;
      try {
         $Overflowing->record($originNS, ['responses' => 1]);
      }
      catch (OverflowException) {
         $recordOverflow = true;
      }
      try {
         $Overflowing->merge($Overflowing);
      }
      catch (OverflowException) {
         $mergeOverflow = true;
      }

      $AtomicOverflow = new Series($originNS, $originNS + Series::BUCKET_NS);
      $AtomicOverflow->record($originNS, [
         'sent' => 1,
         'responses' => PHP_INT_MAX,
      ]);
      $beforeOverflow = $AtomicOverflow->export();
      $accumulateOverflow = false;
      try {
         $AtomicOverflow->accumulate($originNS, sent: 1, responses: 1);
      }
      catch (OverflowException) {
         $accumulateOverflow = true;
      }

      yield new Assertion(
         description: 'Record, scalar accumulation and merge reject integer overflow atomically',
         fallback: 'A time-series integer counter overflow was not rejected!'
      )
         ->expect(
            [
               $recordOverflow,
               $accumulateOverflow,
               $mergeOverflow,
               $Overflowing->export()['totals']['responses'],
               $AtomicOverflow->export() === $beforeOverflow,
            ],
            Op::Identical,
            [true, true, true, PHP_INT_MAX, true],
         )
         ->assert();

      $constructorRejected = [];
      foreach ([
         [$originNS, $originNS],
         [$originNS, $originNS + Series::BUCKET_NS - 1],
         [$originNS, $originNS + ((Series::MAXIMUM_BUCKETS + 1) * Series::BUCKET_NS)],
      ] as [$startNS, $endNS]) {
         try {
            new Series($startNS, $endNS);
            $constructorRejected[] = false;
         }
         catch (InvalidArgumentException) {
            $constructorRejected[] = true;
         }
      }

      yield new Assertion(
         description: 'Constructor rejects empty, fractional, and unbounded bucket windows',
         fallback: 'Invalid time-series window construction was accepted!'
      )
         ->expect($constructorRejected, Op::Identical, [true, true, true])
         ->assert();

      $atomic = $Series->export();
      try {
         $Series->record($originNS, ['sent' => 1, 'unknown' => 1]);
      }
      catch (InvalidArgumentException) {
         // Expected.
      }

      yield new Assertion(
         description: 'A rejected metric batch does not partially mutate the series',
         fallback: 'Time-series record validation was not atomic!'
      )
         ->expect($Series->export(), Op::Identical, $atomic)
         ->assert();

      $scalarRejected = [];
      foreach ([
         static fn () => $Scalar->accumulate($originNS),
         static fn () => $Scalar->accumulate($originNS, responses: -1),
         static fn () => $Scalar->accumulate($deadlineNS, responses: 1),
      ] as $Attempt) {
         try {
            $Attempt();
            $scalarRejected[] = false;
         }
         catch (InvalidArgumentException) {
            $scalarRejected[] = true;
         }
      }

      yield new Assertion(
         description: 'Scalar accumulation rejects empty, negative, and outside-window updates atomically',
         fallback: 'Allocation-free time-series validation accepted an invalid update!'
      )
         ->expect(
            [$scalarRejected, $Scalar->export()],
            Op::Identical,
            [[true, true, true], $export],
         )
         ->assert();
   })
);
