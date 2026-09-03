<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares;


use function hrtime;
use function ord;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function round;
use function sprintf;
use function strlen;
use function strpos;
use function substr;
use Closure;
use Throwable;
use WeakMap;

use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\AccessLog\Entry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Sealing;


/**
 * One log line per request, on its own channel.
 *
 * The line carries the method, the target, the protocol, the status, the body
 * bytes, the duration, the peer and the client address, the request id an
 * inner `RequestId` stamped, and whether the response was deferred or the
 * request cancelled. Severity follows the outcome — 5xx `error`, 4xx `warning`,
 * a cancelled request `notice`, everything else `info` — so
 * `bootgly logs --channel=HTTP.Server.CLI.access --level=warning` is already
 * a filter. Every record goes through `Logger::$Sinks` and the live tap
 * (`global: true`), so `bootgly logs -f --channel=HTTP.Server.CLI.access`
 * follows the traffic from any terminal.
 *
 * Register it ONCE per channel, GLOBALLY and OUTERMOST — the first middleware
 * of `SAPI::$Middlewares`:
 *
 * ```php
 * SAPI::$Middlewares->pipe(new AccessLog, new TrustedProxy(...), new RequestId);
 * ```
 *
 * That is total coverage traded against the route response cache: a global
 * pipeline with any middleware in it makes every response non-cacheable and
 * skips replay altogether, so `Router->route(..., cache:)` stops storing while
 * this middleware is installed globally (the warm-router fast path stays).
 * `$Router->intercept()` registers a ROUTE-SCOPED instance instead: it logs
 * only the routes declared after it, never the 404 catch-all, never an answer
 * a global middleware short-circuited (401/403/429, a CORS preflight), never a
 * deferral begun outside its chain — and, like any route middleware, it makes
 * those routes ineligible for the response cache too. Neither registration
 * logs the health probe, the ACME responder or a request the decoder rejected
 * before routing.
 *
 * ! The route onion is synchronous, and a deferred response answers through
 *   a private clone after the onion unwound — a post-`$next()` line would
 *   record the placeholder status and ~0 ms on every deferred route. Here
 *   the post-`$next()` half writes the line only for a synchronous outcome;
 *   a deferred generation is settled by its lifecycle (`Exchange`), which
 *   reports the real status once the answer is chosen — the work's, a
 *   boundary's or the Catcher's — and NO status when the client left with
 *   the response parked or the generation was abandoned: that request gets
 *   its line too, as `cancelled`. The sealing pass (`Sealing`) only records
 *   the bytes and the id the wire will carry; it never writes.
 *
 * ! Nothing is parked on the Request: the entry of a deferred generation is
 *   held against its own lifecycle token. The per-request attribute bag is
 *   the principal the route response cache partitions on, and it is composed
 *   twice — before the onion to look an entry up, after it to store one — so
 *   anything written there during the onion makes the two keys disagree and
 *   every stored entry unreachable.
 *
 * ! The target is client-controlled and the message is rendered through
 *   `Template\Escaped`, where every directive starts with `@`. Bytes outside
 *   printable ASCII and every `@` enter the message `%XX`-encoded (`%40` is
 *   how `@` is written in a URI anyway), the target is capped at `LIMIT`
 *   characters, and the query never rides in the line by default —
 *   credentials and tokens travel in it. The context carries the raw values,
 *   which the JSON encoder renders as data — except a target or a method that
 *   is not valid UTF-8, which the encoder would refuse (losing the whole
 *   record): both are then stored `%XX`-encoded, with `encoded: true` saying
 *   so. `encoded` describes those two fields; every other one is framework-
 *   generated, and the log formatters substitute what they cannot encode.
 *
 * A `Formatter` closure replaces the default line. It receives the neutralized
 * `target` and `method` alongside the outcome fields — never the raw target,
 * which no message may carry — and returns the message WITHOUT a trailing
 * newline: the log formatter terminates every record itself.
 */
class AccessLog implements Middleware, Sealing
{
   /** Length the neutralized target is capped at in the rendered message. */
   public const int LIMIT = 120;

   // * Config
   /** The log channel; the file sink writes `storage/logs/<channel>.log`. */
   public private(set) string $channel;
   /** The response header the request id is read back from; null = no id field. */
   public private(set) null|string $header;
   /** Whether the query string stays in the logged target. */
   public private(set) bool $query;
   /**
    * The message builder: `fn (array<string,mixed> $entry): string`.
    * Null renders the default line.
    */
   public private(set) null|Closure $Formatter;

   // * Data
   /** The channel's Logger — push a Handler on it to capture the records. */
   public private(set) Logger $Logger;

   // * Metadata
   /**
    * The entry of every deferred generation in flight, held against its
    * lifecycle token so the sealing pass finds it and a collected exchange
    * takes it away.
    *
    * @var WeakMap<Exchange,Entry>
    */
   private WeakMap $Entries;


   /**
    * @param string $channel The log channel (default: `HTTP.Server.CLI.access`).
    * @param null|string $header The response header carrying the request id (default: `X-Request-Id`); null logs no id.
    * @param bool $query Keep the query string in the logged target (default: false).
    * @param null|Closure $Formatter Build the message from the entry array; null renders the default line.
    */
   public function __construct (
      string $channel = 'HTTP.Server.CLI.access',
      null|string $header = 'X-Request-Id',
      bool $query = false,
      null|Closure $Formatter = null
   )
   {
      // * Config
      $this->channel = $channel;
      $this->header = $header;
      $this->query = $query;
      $this->Formatter = $Formatter;

      // * Data
      // ! `global: true` — without it the record reaches neither the sinks
      //   (the storage/logs file) nor the live tap, and dies in the worker
      $this->Logger = new Logger(channel: $channel, global: true);

      // * Metadata
      $this->Entries = new WeakMap;
   }

   /**
    * @param Request $Request
    * @param Response $Response
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      $Entry = $this->open($Request);

      try {
         $Result = $next($Request, $Response);
      }
      catch (Throwable $Throwable) {
         $this->record($Entry, $Request, $Response);
         $Entry->throwable = $Throwable::class;

         // ? A deferred generation owns the wire — settled already (a work
         //   that completed inline) or still parked while an outer middleware
         //   threw on its way out. Its lifecycle knows the real status; this
         //   throw only reaches the Catcher, whose answer is suppressed.
         $Exchange = Exchange::fetch($Request) ?? Exchange::fetch($Response);
         if ($Exchange !== null && ($Exchange->check() || $Response->deferred === true)) {
            $Entry->deferred = $Entry->deferred || $Response->deferred === true;
            $this->defer($Entry, $Exchange);
         }
         else {
            $Entry->code = 500;
            $Entry->bytes = null;
            $this->write($Entry);
         }

         throw $Throwable;
      }

      /** @var Response $Result */
      $this->record($Entry, $Request, $Result);

      // ?: Synchronous outcome — the status and the body are final
      if ($Result->deferred === false) {
         $this->write($Entry);

         return $Result;
      }

      // @ Deferred — the clone answers later; the lifecycle settles the line
      //   with the status the wire carries, or with none when the client left.
      //   The Response lookup covers a generation that completed inline: its
      //   Request aliases are gone, its snapshot survives.
      $Entry->deferred = true;
      $Exchange = Exchange::fetch($Request) ?? Exchange::fetch($Result);
      // ? No lifecycle to wait for (a double, a detached context): the line
      //   goes out now, with what is known
      if ($Exchange === null) {
         $this->write($Entry);
      }
      else {
         $this->defer($Entry, $Exchange);
      }

      // :
      return $Result;
   }

   /**
    * Record the wire a deferred generation is about to carry.
    *
    * Called by the sealing pass immediately before serialization, over the
    * Response chosen for the wire — the work's, a boundary's or the Catcher's.
    * It only completes the entry: the line itself is written when the
    * generation's lifecycle settles, with the status it settles on.
    *
    * @param Request $Request The generation's captured Request snapshot.
    * @param Response $Response The Response chosen for the wire.
    */
   public function seal (Request $Request, Response $Response): void
   {
      $Exchange = Exchange::fetch($Request);
      // ? Not a generation this instance opened
      if ($Exchange === null || isSet($this->Entries[$Exchange]) === false) {
         return;
      }

      $Entry = $this->Entries[$Exchange];
      $Entry->deferred = true;
      $this->record($Entry, $Request, $Response);
   }

   /**
    * Open the entry of a request entering the onion.
    *
    * @param Request $Request
    */
   private function open (object $Request): Entry
   {
      $Entry = new Entry;

      // ! Credentials and tokens travel in the query: it stays out unless asked for
      $URI = (string) $Request->URI;
      if ($this->query === false) {
         $query = strpos($URI, '?');
         if ($query !== false) {
            $URI = substr($URI, 0, $query);
         }
      }

      $Entry->method = (string) $Request->method;
      $Entry->URI = $URI;
      $Entry->protocol = (string) $Request->protocol;
      $Entry->peer = (string) $Request->peer;
      $Entry->address = (string) $Request->address;

      // :
      return $Entry;
   }

   /**
    * Record what a Response says about the request — typed loosely because
    * the synchronous unit tests hand doubles.
    *
    * @param Request $Request The live Request, or the snapshot at seal time.
    * @param Response $Response The Response as the onion left it, or the one chosen for the wire.
    */
   private function record (Entry $Entry, object $Request, object $Response): void
   {
      // @ The address an inner TrustedProxy resolved and the id an inner
      //   RequestId stamped are both pre-`$next()` writes — final once the
      //   onion unwound. A fresh error answer carries no id: the one already
      //   recorded stands.
      $Entry->address = (string) $Request->address;
      if ($this->header !== null) {
         $id = (string) $Response->Header->get($this->header);
         if ($id !== '') {
            $Entry->id = $id;
         }
      }

      $Entry->code = (int) $Response->code;
      $Entry->bytes = strlen((string) $Response->Body->raw);
   }

   /**
    * Hand one entry to the lifecycle that settles its request.
    */
   private function defer (Entry $Entry, Exchange $Exchange): void
   {
      // ! Held against the token, not the Request: the per-request attribute
      //   bag is what the route response cache partitions on, and the entry
      //   must reach the sealing pass through the captured snapshot, which
      //   shares this very exchange
      $this->Entries[$Exchange] = $Entry;

      // ! The closure captures the entry alone — never the Request or the
      //   Response, which the next message on the connection reuses (and a
      //   retained Response would pin the snapshot and its body)
      $Exchange->observe(function (Exchange $Exchange, null|int $code) use ($Entry): void {
         // ? No status: the transport or the scheduler cancelled the generation
         if ($code === null) {
            $Entry->cancelled = true;
            $Entry->bytes = null;
         }
         else {
            $Entry->code = $code;
         }

         $this->write($Entry);
      });
   }

   /**
    * Write the line — once.
    */
   private function write (Entry $Entry): void
   {
      // ? One line per request, whichever side settles it first
      if ($Entry->written) {
         return;
      }
      $Entry->written = true;

      $ms = round(((int) hrtime(true) - $Entry->started) / 1_000_000, 1);
      $code = $Entry->cancelled ? null : $Entry->code;
      $level = match (true) {
         $Entry->cancelled => 'notice',
         $code !== null && $code >= 500 => 'error',
         $code !== null && $code >= 400 => 'warning',
         default => 'info'
      };

      // @ Best effort: a line that cannot be built or written never fails the
      //   request it describes, nor the teardown that settled it
      try {
         // ! The raw values — as data, in the context. A target that is not
         //   valid UTF-8 would make the JSON encoder refuse the whole record,
         //   so it is stored encoded instead, and says so.
         $URI = $Entry->URI;
         $method = $Entry->method;
         // ! An empty pattern with /u fails on the first invalid byte — the
         //   UTF-8 test without a mbstring dependency (the WASM build has none)
         $encoded = preg_match('//u', $URI) !== 1 || preg_match('//u', $method) !== 1;
         $context = [
            'method' => $encoded ? $this->clean($method, false) : $method,
            'URI' => $encoded ? $this->clean($URI, false) : $URI,
            'protocol' => $Entry->protocol,
            'code' => $code,
            'ms' => $ms,
            'bytes' => $Entry->bytes,
            'peer' => $Entry->peer,
            'address' => $Entry->address,
            'id' => $Entry->id,
            'deferred' => $Entry->deferred,
            'cancelled' => $Entry->cancelled
         ];
         if ($encoded) {
            $context['encoded'] = true;
         }
         if ($Entry->throwable !== null) {
            $context['throwable'] = $Entry->throwable;
         }

         // ! The message drives the terminal through Template\Escaped: it is
         //   built from the neutralized halves only, and the Formatter is
         //   handed the same — the raw target belongs to the context alone
         $entry = $context;
         unset($entry['URI']);
         $entry['method'] = $this->clean($method);
         $entry['target'] = $this->clean($URI);

         try {
            $message = $this->Formatter === null
               ? $this->render($Entry, $entry['method'], $entry['target'], $ms)
               : ($this->Formatter)($entry);
         }
         catch (Throwable) {
            // ! A Formatter that fails costs its line's shape, nothing else
            $message = $this->render($Entry, $entry['method'], $entry['target'], $ms);
         }

         $this->Logger->log(...[$level => $message, 'context' => $context]);
      }
      catch (Throwable) {
         // ...
      }
   }

   /**
    * Render the default line.
    *
    * @param string $method The neutralized method.
    * @param string $target The neutralized target.
    */
   private function render (Entry $Entry, string $method, string $target, float $ms): string
   {
      // ?: No status to print: the client left, or the generation was abandoned
      if ($Entry->cancelled) {
         return "{$method} {$target} → cancelled after {$ms}ms";
      }

      // :
      return "{$method} {$target} → {$Entry->code} in {$ms}ms";
   }

   /**
    * Neutralize client-controlled text.
    *
    * Bytes outside printable ASCII and every `@` — the byte every Output
    * directive opens with, or closes with — leave as `%XX`, so no message
    * built from the result can drive the terminal and no encoder can refuse
    * it. What goes in a line is capped too: a target never turns a record
    * into a paragraph.
    *
    * @param bool $capped Whether to cap the result at `LIMIT` characters.
    */
   private function clean (string $text, bool $capped = true): string
   {
      $cleaned = preg_replace_callback(
         '/[^\x21-\x7E]|@/',
         static fn (array $matches): string => sprintf('%%%02X', ord($matches[0])),
         $text
      ) ?? '';

      // ?: Capped — never in the middle of an escape
      if ($capped && strlen($cleaned) > self::LIMIT) {
         $cut = substr($cleaned, 0, self::LIMIT - 1);

         return (preg_replace('/%[0-9A-F]?$/', '', $cut) ?? $cut) . '…';
      }

      // :
      return $cleaned;
   }
}
