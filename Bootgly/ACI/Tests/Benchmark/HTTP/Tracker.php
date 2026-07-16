<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark\HTTP;


use const PHP_INT_MAX;
use function array_key_last;
use function array_keys;
use function array_shift;
use function array_sum;
use function count;
use function explode;
use function hexdec;
use function in_array;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function min;
use function ord;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function strlen;
use function strncmp;
use function strpos;
use function strtolower;
use function substr;
use function trim;

use InvalidArgumentException;
use LogicException;

use Bootgly\ACI\Tests\Benchmark\Latency\Histogram;


/**
 * Incremental HTTP/1 response and request-write accounting for one connection.
 *
 * Response bodies are counted and discarded. Only incomplete framing bytes are
 * retained between feed() calls.
 */
final class Tracker
{
   private const string STATUS = 'status';
   private const string HEADERS = 'headers';
   private const string FIXED = 'fixed';
   private const string CHUNK_SIZE = 'chunk-size';
   private const string CHUNK_DATA = 'chunk-data';
   private const string CHUNK_CRLF = 'chunk-crlf';
   private const string TRAILERS = 'trailers';
   private const string EOF = 'eof';

   private string $defaultMethod;
   private int $lineLimit;
   private int $headerLimit;
   private int $bodyLimit;
   private int $informationalLimit;

   /** @var list<array{bytes: int, offset: int, method: string}> */
   private array $writes = [];
   private int $writeFastBytes = 0;
   private string $writeFastMethod = '';
   private int $writeBytes = 0;

   /** @var list<string> */
   private array $methods = [];
   private int $defaultMethods = 0;

   // # Optional per-logical-request latency correlation
   // ? Pipeline 1 stays scalar. A compressed FIFO is materialized only when
   //   more than one distinct send timestamp is simultaneously outstanding.
   private null|Histogram $Histogram;
   private int $receivedNS = 0;
   private int $timestampFastNS = 0;
   private int $timestampFastCount = 0;

   /** @var list<array{timestamp: int, count: int}> */
   private array $timestamps = [];
   private int $timestampHead = 0;

   private string $buffer = '';
   private string $state = self::STATUS;
   private string $head = '';
   private int $headerBytes = 0;
   private int $trailerBytes = 0;
   private null|int $status = null;
   private null|int $HTTPVersion = null;
   private null|string $lengthText = null;

   /** @var list<string> */
   private array $codings = [];

   /** @var list<string> */
   private array $connections = [];

   private int $remaining = 0;
   private int $bodyBytes = 0;
   private int $interims = 0;

   // # Exact validated-head cache
   // ? A byte-identical response head has the same framing semantics. The
   //   generic parser seeds this cache only after validating the complete head;
   //   any mismatch falls back without consuming input.
   private string $cacheHead = '';
   private int $cacheHeadBytes = 0;
   private string $cacheMethod = '';
   private int $cacheStatus = 0;
   private int $cacheBodyBytes = 0;
   private bool $cacheReusable = true;
   private int $cacheResponses = 0;

   private int $scheduled = 0;
   private int $sent = 0;
   private int $responses = 0;
   private int $informational = 0;
   private int $partialWrites = 0;

   /** @var array<int, int> */
   private array $statuses = [];

   /** @var array<string, int> */
   private array $failures = [];

   /** @var array<string, int> */
   private array $censors = [];

   /** @var array<string, int> */
   private array $writeFailures = [];

   /** @var array<string, int> */
   private array $writeCensors = [];

   public protected(set) bool $terminal = false;
   public protected(set) bool $reusable = true;
   public protected(set) null|string $error = null;
   private bool $anomaly = false;


   public function __construct (
      string $method = 'GET',
      int $lineLimit = 8192,
      int $headerLimit = 65536,
      int $bodyLimit = 1073741824,
      int $informationalLimit = 16,
      null|Histogram $Histogram = null,
   )
   {
      if (
         preg_match("/\A[!#$%&'*+\\-.^_`|~0-9A-Za-z]+\z/D", $method) !== 1
         || $lineLimit < 16
         || $headerLimit < $lineLimit
         || $bodyLimit < 0
         || $informationalLimit < 1
      ) {
         throw new InvalidArgumentException('Invalid HTTP accounting limits or method.');
      }

      $this->defaultMethod = $method;
      $this->lineLimit = $lineLimit;
      $this->headerLimit = $headerLimit;
      $this->bodyLimit = $bodyLimit;
      $this->informationalLimit = $informationalLimit;
      $this->Histogram = $Histogram;
   }

   /**
    * @param int|array<array-key, mixed> $requests
    */
   public function queue (int|array $requests): void
   {
      if ($this->terminal === true || $this->reusable === false) {
         throw new InvalidArgumentException('A closed HTTP tracker cannot queue requests.');
      }

      if (is_int($requests)) {
         $this->append($requests, $this->defaultMethod);

         return;
      }

      foreach ($requests as $request) {
         if (is_int($request)) {
            $bytes = $request;
            $method = $this->defaultMethod;
         }
         elseif (is_array($request)) {
            $bytes = $request['bytes'] ?? null;
            $rawMethod = $request['method'] ?? $this->defaultMethod;
            $method = is_string($rawMethod) ? $rawMethod : '';
         }
         else {
            throw new InvalidArgumentException('Each request needs positive bytes and a valid method.');
         }

         $this->append($bytes, $method);
      }
   }

   /**
    * Record default-method requests whose complete bytes were already accepted
    * by one successful socket write.
    */
   public function send (int $count = 1, null|int $sentNS = null): void
   {
      if ($this->terminal === true || $this->reusable === false) {
         throw new InvalidArgumentException('A closed HTTP tracker cannot send requests.');
      }
      if (
         $count < 1
         || $count > PHP_INT_MAX - $this->scheduled
         || $count > PHP_INT_MAX - $this->sent
      ) {
         throw new InvalidArgumentException('Request accounting overflow or invalid send count.');
      }

      $this->scheduled += $count;
      $this->sent += $count;
      $this->defaultMethods += $count;
      $this->stamp($sentNS, $count);
      if ($this->buffer !== '') {
         $this->advance();
      }
   }

   /**
    * Accept the number of queued bytes left after a write attempt.
    */
   public function accept (int $remaining, null|int $sentNS = null): int
   {
      if ($this->terminal === true) {
         return 0;
      }

      if ($remaining < 0 || $remaining > $this->writeBytes) {
         $this->abort('invalid_write_boundary');

         return 0;
      }

      $completed = 0;

      // ? Pipeline 1 keeps its sole pending request in scalar fields. Partial
      //   progress shrinks the authoritative remainder; a second queued request
      //   materializes the generic FIFO in append().
      if ($this->writeFastBytes > 0 && $this->writes === []) {
         $accepted = $this->writeFastBytes - $remaining;
         if ($accepted === 0) {
            return 0;
         }

         if ($remaining > 0) {
            $this->partialWrites++;
            $this->writeFastBytes = $remaining;
            $this->writeBytes = $remaining;

            return 0;
         }

         $this->track($this->writeFastMethod);
         $this->stamp($sentNS, 1);
         $this->writeFastBytes = 0;
         $this->writeFastMethod = '';
         $this->writeBytes = 0;
         $this->sent++;
         $completed = 1;
         $this->advance();

         return $completed;
      }

      $accepted = $this->writeBytes - $remaining;
      if ($accepted === 0) {
         return 0;
      }

      if ($remaining > 0) {
         $this->partialWrites++;
      }

      // ? Pipeline 1 normally drains one queued request in one fwrite(). Avoid
      //   the generic FIFO loop and array_shift() on every response cycle.
      if ($remaining === 0 && count($this->writes) === 1) {
         $this->track($this->writes[0]['method']);
         $this->stamp($sentNS, 1);
         $this->writes = [];
         $this->writeBytes = 0;
         $this->sent++;
         $completed = 1;
         $this->advance();

         return $completed;
      }

      while ($accepted > 0 && $this->writes !== []) {
         $available = $this->writes[0]['bytes'] - $this->writes[0]['offset'];
         $consumed = min($available, $accepted);
         $this->writes[0]['offset'] += $consumed;
         $accepted -= $consumed;

         if ($this->writes[0]['offset'] === $this->writes[0]['bytes']) {
            $write = array_shift($this->writes);
            $this->track($write['method']);
            $this->stamp($sentNS, 1);
            $this->sent++;
            $completed++;
         }
      }

      $this->writeBytes = $remaining;
      $this->advance();

      return $completed;
   }

   /**
    * @return int Number of final responses completed by this feed.
    */
   public function feed (string $bytes, null|int $receivedNS = null): int
   {
      if ($this->terminal === true || $bytes === '') {
         return 0;
      }

      $before = $this->responses;
      $length = strlen($bytes);
      // ?! Hot path: receive() inlined — one method frame per read at ~1M
      //    reads/s is measurable load-generator overhead.
      if ($this->Histogram !== null) {
         $this->receivedNS = $receivedNS ?? 0;
      }

      // ? Pipeline 1 normally delivers one complete fixed-length response per
      //   read. Once its head has passed the strict parser, validate the same
      //   byte-identical head directly and avoid copying it into the generic
      //   incremental buffer. Any fragmentation, extra bytes, method change,
      //   or head mismatch falls through without consuming input.
      if (
         $this->buffer === ''
         && $this->state === self::STATUS
         && $this->methods === []
         && $this->defaultMethods > 0
         && $this->cacheHeadBytes > 0
         && $this->cacheMethod === $this->defaultMethod
         && $length === $this->cacheHeadBytes + $this->cacheBodyBytes
         && str_starts_with($bytes, $this->cacheHead)
      ) {
         if ($this->cacheReusable === false) {
            $this->reusable = false;
         }
         $this->observe();
         $this->defaultMethods--;
         $this->responses++;
         $this->cacheResponses++;
         $this->interims = 0;

         return 1;
      }

      if ($length > PHP_INT_MAX - strlen($this->buffer)) {
         $this->fail('buffer_overflow');

         return $this->responses - $before;
      }

      $this->buffer .= $bytes;
      $this->advance();

      return $this->responses - $before;
   }

   /**
    * @return int Number of EOF-delimited final responses completed by close.
    */
   public function close (bool $peerEOF, null|int $receivedNS = null): int
   {
      if ($this->terminal === true) {
         return 0;
      }

      $before = $this->responses;
      $this->receive($receivedNS);
      if (
         $peerEOF === true
         && $this->state === self::EOF
         && ($this->methods !== [] || $this->defaultMethods > 0)
      ) {
         $this->finish();
      }

      $writeReason = $peerEOF === true ? 'peer_closed_before_write' : 'connection_aborted';
      if ($this->methods !== [] || $this->defaultMethods > 0) {
         $this->settle($writeReason);
         $this->fail($peerEOF === true ? 'truncated_response' : 'connection_aborted');
      }
      else {
         $pending = $this->writes !== [] || $this->writeFastBytes > 0;
         $this->settle($writeReason);
         if ($pending === true) {
            $this->error ??= $writeReason;
         }
         $this->terminal = true;
         $this->reusable = false;
         $this->buffer = '';
      }

      return $this->responses - $before;
   }

   public function abort (string $reason): void
   {
      if ($this->terminal === true) {
         return;
      }

      $reason = $this->normalize($reason);
      $this->settle($reason);

      if ($this->methods !== [] || $this->defaultMethods > 0) {
         $this->discard($reason);
      }

      $this->error ??= $reason;
      $this->terminal = true;
      $this->reusable = false;
      $this->buffer = '';
   }

   /**
    * Close the ledger at the measurement boundary without reporting ordinary
    * in-flight work as a transport or server failure.
    */
   public function censor (string $reason = 'measurement_ended'): void
   {
      if ($this->terminal === true) {
         return;
      }

      $reason = $this->normalize($reason);
      $this->settle($reason, true);

      if ($this->methods !== [] || $this->defaultMethods > 0) {
         $this->discard($reason, true);
      }

      $this->terminal = true;
      $this->reusable = false;
      $this->buffer = '';
   }

   /**
    * Check whether this connection can still accept accounting events.
    */
   public function check (): bool
   {
      return $this->terminal === false;
   }

   /**
    * @return array{
    *    scheduled: int,
    *    sent: int,
    *    responses: int,
    *    informational: int,
    *    outstanding: int,
    *    statuses: array<int, int>,
    *    failures: array<string, int>,
    *    censors: array<string, int>,
    *    write_failures: array<string, int>,
    *    write_censors: array<string, int>,
    *    partial_writes: int,
    *    accounting: bool,
    *    terminal: bool,
    *    error: null|string
    * }
    */
   public function inspect (): array
   {
      $pending = count($this->writes) + ($this->writeFastBytes > 0 ? 1 : 0);
      $outstanding = count($this->methods) + $this->defaultMethods;
      $failed = array_sum($this->failures);
      $censored = array_sum($this->censors);
      $writeFailed = array_sum($this->writeFailures);
      $writeCensored = array_sum($this->writeCensors);
      $writeAccounted = $this->scheduled === $this->sent + $writeFailed + $writeCensored + $pending;
      $responseAccounted = $this->sent === $this->responses + $failed + $censored + $outstanding;
      $statuses = $this->statuses;
      if ($this->cacheResponses > 0) {
         $statuses[$this->cacheStatus] = ($statuses[$this->cacheStatus] ?? 0)
            + $this->cacheResponses;
      }

      return [
         'scheduled' => $this->scheduled,
         'sent' => $this->sent,
         'responses' => $this->responses,
         'informational' => $this->informational,
         'outstanding' => $outstanding,
         'statuses' => $statuses,
         'failures' => $this->failures,
         'censors' => $this->censors,
         'write_failures' => $this->writeFailures,
         'write_censors' => $this->writeCensors,
         'partial_writes' => $this->partialWrites,
         'accounting' => $writeAccounted && $responseAccounted && $this->anomaly === false,
         'terminal' => $this->terminal,
         'error' => $this->error,
      ];
   }

   private function append (mixed $bytes, string $method): void
   {
      if (
         is_int($bytes) === false
         || $bytes < 1
         || preg_match("/\A[!#$%&'*+\\-.^_`|~0-9A-Za-z]+\z/D", $method) !== 1
      ) {
         throw new InvalidArgumentException('Each request needs positive bytes and a valid method.');
      }

      if ($bytes > PHP_INT_MAX - $this->writeBytes || $this->scheduled === PHP_INT_MAX) {
         throw new InvalidArgumentException('Request write accounting overflow.');
      }

      if ($this->writeFastBytes > 0) {
         $this->writes[] = [
            'bytes' => $this->writeFastBytes,
            'offset' => 0,
            'method' => $this->writeFastMethod,
         ];
         $this->writeFastBytes = 0;
         $this->writeFastMethod = '';
      }

      if ($this->writes === [] && $this->writeBytes === 0) {
         $this->writeFastBytes = $bytes;
         $this->writeFastMethod = $method;
      }
      else {
         $this->writes[] = [
            'bytes' => $bytes,
            'offset' => 0,
            'method' => $method,
         ];
      }
      $this->writeBytes += $bytes;
      $this->scheduled++;
   }

   private function remember (string $method, int $bodyBytes): void
   {
      if ($this->status === null || $this->head === '') {
         return;
      }

      $headBytes = strlen($this->head);
      if ($bodyBytes < 0 || $bodyBytes > PHP_INT_MAX - $headBytes) {
         return;
      }

      if ($this->cacheResponses > 0) {
         $this->statuses[$this->cacheStatus] = ($this->statuses[$this->cacheStatus] ?? 0)
            + $this->cacheResponses;
         $this->cacheResponses = 0;
      }

      $this->cacheHead = $this->head;
      $this->cacheHeadBytes = $headBytes;
      $this->cacheMethod = $method;
      $this->cacheStatus = $this->status;
      $this->cacheBodyBytes = $bodyBytes;
      $this->cacheReusable = $this->reusable;
   }

   private function track (string $method): void
   {
      if ($method === $this->defaultMethod) {
         $this->defaultMethods++;

         return;
      }

      if ($this->defaultMethods > 0) {
         for ($index = 0; $index < $this->defaultMethods; $index++) {
            $this->methods[] = $this->defaultMethod;
         }
         $this->defaultMethods = 0;
      }
      $this->methods[] = $method;
   }

   private function advance (): void
   {
      while ($this->terminal === false) {
         if ($this->methods === [] && $this->defaultMethods === 0) {
            if ($this->buffer !== '') {
               $this->fail('unexpected_response');
            }

            return;
         }
         $method = $this->methods !== [] ? $this->methods[0] : $this->defaultMethod;

         // ? Reuse only an exact response head that this same connection has
         //   already parsed and validated for the current FIFO method. Prefix
         //   mismatch consumes nothing and falls through to the strict parser.
         if (
            $this->state === self::STATUS
            && $this->cacheHeadBytes > 0
            && $method === $this->cacheMethod
            && $this->buffer !== ''
         ) {
            $available = strlen($this->buffer);
            $compared = min($available, $this->cacheHeadBytes);
            if (strncmp($this->buffer, $this->cacheHead, $compared) === 0) {
               if ($available < $this->cacheHeadBytes) {
                  return;
               }

               $frameBytes = $this->cacheHeadBytes + $this->cacheBodyBytes;
               if ($this->cacheReusable === false) {
                  $this->reusable = false;
               }

               if ($available >= $frameBytes) {
                  $this->buffer = $available === $frameBytes
                     ? ''
                     : (string) substr($this->buffer, $frameBytes);
                  $this->complete($this->cacheStatus);

                  continue;
               }

               // @ The validated head is complete but its fixed body is split.
               //   Enter the existing body state with the exact remaining size.
               $this->buffer = (string) substr($this->buffer, $this->cacheHeadBytes);
               $this->status = $this->cacheStatus;
               $this->remaining = $this->cacheBodyBytes;
               $this->bodyBytes = 0;
               $this->state = self::FIXED;

               continue;
            }
         }

         if ($this->state === self::STATUS) {
            $line = $this->extract($this->lineLimit, 'status_line_limit');
            if ($line === null) {
               return;
            }

            if (
               preg_match(
                  '/\AHTTP\/1\.([01]) ([0-9]{3})(?: [\x20-\x7E\x80-\xFF]*)?\z/D',
                  $line,
                  $matches,
               ) !== 1
            ) {
               $this->fail('malformed_status');

               return;
            }

            $status = (int) $matches[2];
            if ($status < 100 || $status > 599) {
               $this->fail('malformed_status');

               return;
            }

            $this->head = $line . "\r\n";
            $this->status = $status;
            $this->HTTPVersion = (int) $matches[1];
            $this->headerBytes = strlen($line) + 2;
            if ($this->headerBytes > $this->headerLimit) {
               $this->fail('header_limit');

               return;
            }
            $this->state = self::HEADERS;

            continue;
         }

         if ($this->state === self::HEADERS) {
            $line = $this->extract($this->lineLimit, 'header_line_limit');
            if ($line === null) {
               return;
            }

            $lineBytes = strlen($line) + 2;
            if ($lineBytes > $this->headerLimit - $this->headerBytes) {
               $this->fail('header_limit');

               return;
            }
            $this->headerBytes += $lineBytes;
            $this->head .= $line . "\r\n";

            if ($line !== '') {
               if ($this->record($line, false) === false) {
                  return;
               }

               continue;
            }

            if ($this->status === null) {
               $this->fail('malformed_status');

               return;
            }

            if ($this->status >= 100 && $this->status < 200) {
               if ($this->status === 101) {
                  $this->fail('unsupported_upgrade');

                  return;
               }

               $this->informational++;
               $this->interims++;
               if ($this->interims > $this->informationalLimit) {
                  $this->fail('informational_limit');

                  return;
               }

               $this->reset();

               continue;
            }

            if ($method === 'CONNECT' && $this->status >= 200 && $this->status < 300) {
               $this->fail('unsupported_tunnel');

               return;
            }

            $this->retain();

            if ($method === 'HEAD' || $this->status === 204 || $this->status === 304) {
               $this->remember($method, 0);
               $this->finish();

               continue;
            }

            if ($this->lengthText !== null && $this->codings !== []) {
               $this->fail('transfer_length_conflict');

               return;
            }

            if ($this->codings !== []) {
               $chunked = array_keys($this->codings, 'chunked', true);
               $last = $this->codings[array_key_last($this->codings)];
               if (count($chunked) > 1 || ($chunked !== [] && $last !== 'chunked')) {
                  $this->fail('malformed_transfer_encoding');

                  return;
               }

               if ($last === 'chunked') {
                  $this->state = self::CHUNK_SIZE;
               }
               else {
                  $this->reusable = false;
                  $this->state = self::EOF;
               }

               continue;
            }

            if ($this->lengthText !== null) {
               $length = $this->measure($this->lengthText, 10);
               if ($length === null) {
                  return;
               }
               if ($length > $this->bodyLimit) {
                  $this->fail('body_limit');

                  return;
               }

               $this->remember($method, $length);
               $this->remaining = $length;
               if ($this->remaining === 0) {
                  $this->finish();
               }
               else {
                  $this->state = self::FIXED;
               }

               continue;
            }

            $this->reusable = false;
            $this->state = self::EOF;

            continue;
         }

         if ($this->state === self::FIXED) {
            if ($this->buffer === '') {
               return;
            }

            $consumed = min($this->remaining, strlen($this->buffer));
            $this->buffer = (string) substr($this->buffer, $consumed);
            $this->remaining -= $consumed;
            $this->bodyBytes += $consumed;

            if ($this->remaining === 0) {
               $this->finish();
            }

            continue;
         }

         if ($this->state === self::CHUNK_SIZE) {
            $line = $this->extract($this->lineLimit, 'chunk_line_limit');
            if ($line === null) {
               return;
            }

            if (
               preg_match(
                  '/\A([0-9A-Fa-f]+)(?:[ \t]*;[ \t]*[!#$%&\'*+\-.^_`|~0-9A-Za-z]+'
                  . '(?:[ \t]*=[ \t]*(?:[!#$%&\'*+\-.^_`|~0-9A-Za-z]+'
                  . '|"(?:[\x20-\x21\x23-\x5B\x5D-\x7E]|\\\\[\x20-\x7E])*"))?)*'
                  . '[ \t]*\z/D',
                  $line,
                  $matches,
               ) !== 1
            ) {
               $this->fail('malformed_chunk');

               return;
            }

            $size = $this->measure($matches[1]);
            if ($size === null) {
               return;
            }

            if ($size === 0) {
               $this->trailerBytes = 0;
               $this->state = self::TRAILERS;
            }
            else {
               if ($size > $this->bodyLimit - $this->bodyBytes) {
                  $this->fail('body_limit');

                  return;
               }

               $this->remaining = $size;
               $this->state = self::CHUNK_DATA;
            }

            continue;
         }

         if ($this->state === self::CHUNK_DATA) {
            if ($this->buffer === '') {
               return;
            }

            $consumed = min($this->remaining, strlen($this->buffer));
            $this->buffer = (string) substr($this->buffer, $consumed);
            $this->remaining -= $consumed;
            $this->bodyBytes += $consumed;

            if ($this->remaining === 0) {
               $this->state = self::CHUNK_CRLF;
            }

            continue;
         }

         if ($this->state === self::CHUNK_CRLF) {
            if (strlen($this->buffer) < 2) {
               if ($this->buffer !== '' && $this->buffer !== "\r") {
                  $this->fail('malformed_chunk');
               }

               return;
            }

            if (substr($this->buffer, 0, 2) !== "\r\n") {
               $this->fail('malformed_chunk');

               return;
            }

            $this->buffer = (string) substr($this->buffer, 2);
            $this->state = self::CHUNK_SIZE;

            continue;
         }

         if ($this->state === self::TRAILERS) {
            $line = $this->extract($this->lineLimit, 'trailer_line_limit');
            if ($line === null) {
               return;
            }

            $lineBytes = strlen($line) + 2;
            if ($lineBytes > $this->headerLimit - $this->trailerBytes) {
               $this->fail('trailer_limit');

               return;
            }
            $this->trailerBytes += $lineBytes;

            if ($line === '') {
               $this->finish();

               continue;
            }

            if ($this->record($line, true) === false) {
               return;
            }

            continue;
         }

         if ($this->state === self::EOF) {
            if ($this->buffer === '') {
               return;
            }

            $bytes = strlen($this->buffer);
            if ($bytes > $this->bodyLimit - $this->bodyBytes) {
               $this->fail('body_limit');

               return;
            }

            $this->bodyBytes += $bytes;
            $this->buffer = '';

            return;
         }

         $this->fail('invalid_parser_state');

         return;
      }
   }

   private function extract (int $limit, string $failure): null|string
   {
      $length = strlen($this->buffer);
      $newline = strpos($this->buffer, "\n");
      if ($newline !== false) {
         if ($newline === 0 || $this->buffer[$newline - 1] !== "\r") {
            $this->fail('malformed_line_ending');

            return null;
         }

         $end = $newline - 1;
         if (strpos(substr($this->buffer, 0, $end), "\r") !== false) {
            $this->fail('malformed_line_ending');

            return null;
         }

         if ($end > $limit) {
            $this->fail($failure);

            return null;
         }

         $line = substr($this->buffer, 0, $end);
         $this->buffer = (string) substr($this->buffer, $newline + 1);

         return $line;
      }

      $carriage = strpos($this->buffer, "\r");
      if ($carriage !== false && $carriage !== $length - 1) {
         $this->fail('malformed_line_ending');

         return null;
      }

      $lineBytes = $carriage === $length - 1 ? $length - 1 : $length;
      if ($lineBytes > $limit) {
         $this->fail($failure);
      }

      return null;
   }

   private function record (string $line, bool $trailer): bool
   {
      $colon = strpos($line, ':');
      if ($colon === false || $colon === 0) {
         $this->fail($trailer ? 'malformed_trailer' : 'malformed_header');

         return false;
      }

      $name = substr($line, 0, $colon);
      $value = substr($line, $colon + 1);
      if (
         preg_match("/\A[!#$%&'*+\\-.^_`|~0-9A-Za-z]+\z/D", $name) !== 1
         || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1
      ) {
         $this->fail($trailer ? 'malformed_trailer' : 'malformed_header');

         return false;
      }

      $name = strtolower($name);
      $value = trim($value, " \t");
      if ($trailer === true) {
         if (in_array($name, ['content-length', 'transfer-encoding', 'trailer'], true)) {
            $this->fail('forbidden_trailer');

            return false;
         }

         return true;
      }

      if ($name === 'content-length') {
         foreach (explode(',', $value) as $part) {
            $part = trim($part, " \t");
            if (preg_match('/\A[0-9]+\z/D', $part) !== 1) {
               $this->fail('malformed_content_length');

               return false;
            }

            $normalized = ltrim($part, '0');
            $normalized = $normalized === '' ? '0' : $normalized;
            if ($this->lengthText !== null && $normalized !== $this->lengthText) {
               $this->fail('conflicting_content_length');

               return false;
            }

            $this->lengthText = $normalized;
         }
      }
      elseif ($name === 'transfer-encoding') {
         $codings = $this->divide($value);
         if ($codings === null) {
            $this->fail('malformed_transfer_encoding');

            return false;
         }

         foreach ($codings as $coding) {
            if (
               preg_match(
                  '/\A([!#$%&\'*+\-.^_`|~0-9A-Za-z]+)'
                  . '((?:[ \t]*;[ \t]*[!#$%&\'*+\-.^_`|~0-9A-Za-z]+'
                  . '[ \t]*=[ \t]*(?:[!#$%&\'*+\-.^_`|~0-9A-Za-z]+'
                  . '|"(?:[\x20-\x21\x23-\x5B\x5D-\x7E]|\\\\[\x20-\x7E])*"))*)'
                  . '[ \t]*\z/D',
                  $coding,
                  $matches,
               ) !== 1
            ) {
               $this->fail('malformed_transfer_encoding');

               return false;
            }

            $coding = strtolower($matches[1]);
            if ($coding === 'chunked' && trim($matches[2], " \t") !== '') {
               $this->fail('malformed_transfer_encoding');

               return false;
            }
            $this->codings[] = $coding;
         }
      }
      elseif ($name === 'connection') {
         foreach (explode(',', $value) as $connection) {
            $connection = strtolower(trim($connection, " \t"));
            if (preg_match("/\A[!#$%&'*+\\-.^_`|~0-9A-Za-z]+\z/D", $connection) !== 1) {
               $this->fail('malformed_connection');

               return false;
            }
            $this->connections[] = $connection;
         }
      }

      return true;
   }

   /**
    * @return null|list<string>
    */
   private function divide (string $value): null|array
   {
      $parts = [];
      $start = 0;
      $quoted = false;
      $escaped = false;
      $length = strlen($value);

      for ($index = 0; $index < $length; $index++) {
         $byte = $value[$index];
         if ($escaped === true) {
            $escaped = false;

            continue;
         }
         if ($quoted === true && $byte === '\\') {
            $escaped = true;

            continue;
         }
         if ($byte === '"') {
            $quoted = !$quoted;

            continue;
         }
         if ($byte === ',' && $quoted === false) {
            $parts[] = trim(substr($value, $start, $index - $start), " \t");
            $start = $index + 1;
         }
      }

      if ($quoted === true || $escaped === true) {
         return null;
      }

      $parts[] = trim(substr($value, $start), " \t");

      return $parts;
   }

   private function measure (string $value, int $base = 16): null|int
   {
      $number = 0;
      $length = strlen($value);
      for ($index = 0; $index < $length; $index++) {
         $digit = $base === 16 ? (int) hexdec($value[$index]) : ord($value[$index]) - 48;
         if ($digit < 0 || $digit >= $base || $number > intdiv(PHP_INT_MAX - $digit, $base)) {
            $this->fail($base === 16 ? 'chunk_overflow' : 'length_overflow');

            return null;
         }
         $number = ($number * $base) + $digit;
      }

      return $number;
   }

   private function finish (): void
   {
      if (($this->methods === [] && $this->defaultMethods === 0) || $this->status === null) {
         $this->fail('invalid_parser_state');

         return;
      }

      $this->complete($this->status);
      $this->reset();
   }

   private function complete (int $status): void
   {
      $this->observe();
      if ($this->methods !== []) {
         if (count($this->methods) === 1) {
            $this->methods = [];
         }
         else {
            array_shift($this->methods);
         }
      }
      else {
         $this->defaultMethods--;
      }
      $this->responses++;
      $this->statuses[$status] = ($this->statuses[$status] ?? 0) + 1;
      $this->interims = 0;
   }

   private function reset (): void
   {
      $this->state = self::STATUS;
      $this->head = '';
      $this->headerBytes = 0;
      $this->trailerBytes = 0;
      $this->status = null;
      $this->HTTPVersion = null;
      $this->lengthText = null;
      $this->codings = [];
      $this->connections = [];
      $this->remaining = 0;
      $this->bodyBytes = 0;
   }

   private function fail (string $reason): void
   {
      if ($this->terminal === true) {
         return;
      }

      $this->error ??= $reason;
      if ($this->methods === [] && $this->defaultMethods === 0) {
         $this->anomaly = true;
      }
      $this->settle('pipeline_aborted');
      $this->reject($reason);
      $this->terminal = true;
      $this->reusable = false;
      $this->buffer = '';
   }

   private function reject (string $reason): void
   {
      if ($this->methods === [] && $this->defaultMethods === 0) {
         return;
      }

      if ($this->methods !== []) {
         if (count($this->methods) === 1) {
            $this->methods = [];
         }
         else {
            array_shift($this->methods);
         }
      }
      else {
         $this->defaultMethods--;
      }
      $this->failures[$reason] = ($this->failures[$reason] ?? 0) + 1;

      $remainder = count($this->methods) + $this->defaultMethods;
      if ($remainder > 0) {
         $this->failures['pipeline_aborted'] = ($this->failures['pipeline_aborted'] ?? 0)
            + $remainder;
         $this->methods = [];
         $this->defaultMethods = 0;
      }
      $this->purge();
   }

   private function discard (string $reason, bool $censored = false): void
   {
      $outstanding = count($this->methods) + $this->defaultMethods;
      if ($outstanding > 0) {
         if ($censored) {
            $this->censors[$reason] = ($this->censors[$reason] ?? 0) + $outstanding;
         }
         else {
            $this->failures[$reason] = ($this->failures[$reason] ?? 0) + $outstanding;
         }
         $this->methods = [];
         $this->defaultMethods = 0;
         $this->purge();
      }
   }

   private function retain (): void
   {
      if (
         in_array('close', $this->connections, true)
         || ($this->HTTPVersion === 0 && in_array('keep-alive', $this->connections, true) === false)
      ) {
         $this->reusable = false;
      }
   }

   private function settle (string $reason, bool $censored = false): void
   {
      $pending = count($this->writes) + ($this->writeFastBytes > 0 ? 1 : 0);
      if ($pending > 0) {
         if ($censored) {
            $this->writeCensors[$reason] = ($this->writeCensors[$reason] ?? 0) + $pending;
         }
         else {
            $this->writeFailures[$reason] = ($this->writeFailures[$reason] ?? 0) + $pending;
         }
         $this->writes = [];
         $this->writeFastBytes = 0;
         $this->writeFastMethod = '';
         $this->writeBytes = 0;
      }
   }

   /**
    * Retain the monotonic observation instant used by every final response
    * structurally completed while processing the current read callback.
    */
   private function receive (null|int $receivedNS): void
   {
      if ($this->Histogram === null) {
         return;
      }

      $this->receivedNS = $receivedNS ?? 0;
   }

   /**
    * Append one compressed segment to the logical-request send-time FIFO.
    */
   private function stamp (null|int $sentNS, int $count): void
   {
      if ($this->Histogram === null) {
         return;
      }
      if ($sentNS === null || $sentNS < 1) {
         throw new LogicException('Latency tracking requires a positive monotonic send timestamp.');
      }

      // ? Pipeline 1 consumes the scalar slot on every response and never
      //   materializes the generic FIFO. Avoid count() and redundant empty
      //   array assignments on that dominant path.
      if ($this->timestamps !== [] && $this->timestampHead >= count($this->timestamps)) {
         $this->timestamps = [];
         $this->timestampHead = 0;
      }

      if ($this->timestamps === []) {
         if ($this->timestampFastCount === 0) {
            $this->timestampFastNS = $sentNS;
            $this->timestampFastCount = $count;

            return;
         }
         if ($this->timestampFastNS === $sentNS) {
            $this->timestampFastCount += $count;

            return;
         }

         $this->timestamps[] = [
            'timestamp' => $this->timestampFastNS,
            'count' => $this->timestampFastCount,
         ];
         $this->timestampFastNS = 0;
         $this->timestampFastCount = 0;
      }

      $last = count($this->timestamps) - 1;
      if ($this->timestamps[$last]['timestamp'] === $sentNS) {
         $this->timestamps[$last]['count'] += $count;

         return;
      }

      $this->timestamps[] = ['timestamp' => $sentNS, 'count' => $count];
   }

   /**
    * Match one final response to exactly one fully-sent logical request.
    */
   private function observe (): void
   {
      if ($this->Histogram === null) {
         return;
      }
      if ($this->receivedNS < 1) {
         throw new LogicException('Latency tracking requires a positive monotonic response timestamp.');
      }

      if ($this->timestampFastCount > 0) {
         $sentNS = $this->timestampFastNS;
         $this->timestampFastCount--;
         if ($this->timestampFastCount === 0) {
            $this->timestampFastNS = 0;
         }
      }
      else {
         $segment = $this->timestamps[$this->timestampHead] ?? null;
         if ($segment === null || $segment['count'] < 1) {
            throw new LogicException('A final response has no matching monotonic request timestamp.');
         }

         $sentNS = $segment['timestamp'];
         $segment['count']--;
         if ($segment['count'] === 0) {
            $this->timestampHead++;
         }
         else {
            $this->timestamps[$this->timestampHead] = $segment;
         }
      }

      if ($this->receivedNS < $sentNS) {
         throw new LogicException('A response timestamp precedes its request timestamp.');
      }

      $this->Histogram->record($this->receivedNS - $sentNS);
   }

   /**
    * Release every send timestamp after terminal failure or censoring.
    */
   private function purge (): void
   {
      $this->timestampFastNS = 0;
      $this->timestampFastCount = 0;
      $this->timestamps = [];
      $this->timestampHead = 0;
   }

   private function normalize (string $reason): string
   {
      $reason = strtolower(trim($reason));
      $reason = (string) preg_replace('/[^a-z0-9]+/', '_', $reason);
      $reason = trim($reason, '_');

      return $reason === '' ? 'aborted' : substr($reason, 0, 64);
   }
}
