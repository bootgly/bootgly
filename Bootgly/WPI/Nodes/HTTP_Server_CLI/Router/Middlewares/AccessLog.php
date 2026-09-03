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
use function preg_replace_callback;
use function round;
use function spl_object_id;
use function sprintf;
use function strlen;
use function strpos;
use function substr;
use Closure;
use Throwable;

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
 * Register it ONCE per channel, GLOBALLY and OUTERMOST — first in
 * `$Router->intercept(...)` / index 0 of `SAPI::$Middlewares`. Route-cache
 * replays and the warm-router fast path run inside the global onion, so a
 * global instance logs them too (with the reset Response's status and no
 * body bytes — the wire came from the cache). A route-level instance logs
 * only its routes: never the 404 catch-all, never an answer a global
 * middleware short-circuited (401/403/429, a CORS preflight), and never a
 * deferral begun outside its chain. Neither logs the health probe, the ACME
 * responder or a request the decoder rejected before routing.
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
 * ! The target is client-controlled and the message is rendered through
 *   `Template\Escaped`, where every directive starts with `@`. Bytes outside
 *   printable ASCII and every `@` enter the message `%XX`-encoded (`%40` is
 *   how `@` is written in a URI anyway), the target is capped at `LIMIT`
 *   characters, and the query never rides in the line by default —
 *   credentials and tokens travel in it. The context carries the raw
 *   values: the JSON encoder renders no directive.
 *
 * A `Formatter` closure replaces the default line: it receives the context
 * array plus `target` (the neutralized URI) and `method` (neutralized) and
 * returns the message — build it from `target`, never from `URI`, and leave
 * the trailing newline out. Whatever it returns is rendered by
 * `Template\Escaped`.
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
   /** The per-instance key the entry is parked under on the Request's bag. */
   private string $key;


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
      $this->key = self::class . '#' . spl_object_id($this);
   }

   /**
    * @param Request $Request
    * @param Response $Response
    */
   public function process (object $Request, object $Response, Closure $next): object
   {
      // ! Opened before the onion runs and parked on the per-request bag: the
      //   snapshot a deferral captures copies the bag, so the sealing pass
      //   finds this very object
      $Entry = $this->open($Request);
      $Request->{$this->key} = $Entry;

      try {
         $Result = $next($Request, $Response);
      }
      catch (Throwable $Throwable) {
         $this->record($Entry, $Request, $Response);
         // ? A throw that passed this middleware reaches only the routing
         //   Catcher (500) — unless a deferral completed inline and its wire
         //   is already out: the lifecycle then settled with the real status
         $Exchange = Exchange::fetch($Request) ?? Exchange::fetch($Response);
         if ($Exchange !== null && $Exchange->check()) {
            $this->observe($Entry, $Exchange);
         }
         else {
            $Entry->code = 500;
            $Entry->bytes = null;
            $Entry->throwable = $Throwable::class;
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
         $this->observe($Entry, $Exchange);
      }

      // :
      return $Result;
   }

   public function seal (Request $Request, Response $Response): void
   {
      $Entry = $Request->{$this->key} ?? null;
      // ? Not opened by this instance — a deferral begun outside its chain
      if ($Entry instanceof Entry === false) {
         return;
      }

      // @ Record only: the lifecycle writes the line once it settles, with the
      //   status it settles on — a later pass over a boundary's answer simply
      //   overwrites what is recorded here
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
    * Let the lifecycle settle the line.
    */
   private function observe (Entry $Entry, Exchange $Exchange): void
   {
      // ! The closure captures the entry alone — never the Request or the
      //   Response, which the next message on the connection reuses (and a
      //   retained Response would pin the snapshot and its body)
      $Exchange->observe(function (Exchange $Exchange, null|int $code) use ($Entry): void {
         // ? No status: the transport or the scheduler cancelled the generation
         if ($code === null) {
            $Entry->cancelled = true;
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

      // ! Raw values — the context is JSON-encoded, never rendered
      $context = [
         'method' => $Entry->method,
         'URI' => $Entry->URI,
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
      if ($Entry->throwable !== null) {
         $context['throwable'] = $Entry->throwable;
      }

      // ! The message drives the terminal through Template\Escaped: the
      //   client-controlled parts enter it neutralized
      $entry = $context;
      $entry['method'] = $this->clean($Entry->method);
      $entry['target'] = $this->clean($Entry->URI);

      $message = $this->Formatter === null
         ? $this->render($Entry, $entry['method'], $entry['target'], $ms)
         : ($this->Formatter)($entry);

      // @ Best effort: a line that cannot be written never fails the request
      //   it describes, nor the teardown that settled it
      try {
         $this->Logger->log(...[$level => "{$message}@.;", 'context' => $context]);
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
    * Neutralize client-controlled text for the rendered message.
    *
    * Bytes outside printable ASCII and every `@` — the byte every Output
    * directive opens with, or closes with — leave as `%XX`; the result is
    * capped so a line never turns into a paragraph.
    */
   private function clean (string $text): string
   {
      $cleaned = preg_replace_callback(
         '/[^\x21-\x7E]|@/',
         static fn (array $matches): string => sprintf('%%%02X', ord($matches[0])),
         $text
      ) ?? '';

      // ?: Capped
      if (strlen($cleaned) > self::LIMIT) {
         return substr($cleaned, 0, self::LIMIT - 1) . '…';
      }

      // :
      return $cleaned;
   }
}
