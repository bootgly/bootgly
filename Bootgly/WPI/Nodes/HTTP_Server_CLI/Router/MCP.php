<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


use const JSON_UNESCAPED_SLASHES;
use function array_is_list;
use function bin2hex;
use function constant;
use function count;
use function crc32;
use function defined;
use function explode;
use function hash;
use function hash_equals;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function min;
use function preg_match;
use function random_bytes;
use function rtrim;
use function serialize;
use function str_contains;
use function strlen;
use function strtolower;
use function time;
use function trim;
use Closure;
use InvalidArgumentException;
use RuntimeException;

use Bootgly\ABI\Resources\Cache;
use Bootgly\API\RPC;
use Bootgly\API\Security\Identity;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;


/**
 * Model Context Protocol over Streamable HTTP — the route handler that puts a
 * transport-independent `RPC\MCP` server on one endpoint:
 *
 * ```php
 * $Router->route('/mcp', new MCP($Server, origins: ['https://app.example.com']), [GET, POST, DELETE]);
 * ```
 *
 * - `POST` carries one JSON-RPC message (or batch). The answer is
 *   `application/json` or a one-event `text/event-stream`, whichever the
 *   client's `Accept` prefers; notifications alone get `202 Accepted`.
 * - `initialize` issues an `Mcp-Session-Id` (128 random bits, never
 *   client-chosen) bound to the authenticated principal; every later request
 *   must carry it: `400` when missing, `404` when unknown, ended, expired or
 *   opened by another principal (the client then initializes again).
 * - `MCP-Protocol-Version`, when sent, must equal the revision agreed at
 *   `initialize` (`400` otherwise).
 * - `GET` opens the listening stream (heartbeats only: the server core sends
 *   no server-initiated messages), bounded per session; while it is open the
 *   session does not expire. `DELETE` ends the session.
 * - `Origin`, when present, must be in the `origins` allowlist (`403`
 *   otherwise) — the DNS-rebinding defence. A missing `Origin` is accepted:
 *   browsers always send it on `POST`/`DELETE`, and `GET` needs a session.
 *
 * Sessions live in an ABI `Cache` — a 1 MiB shared-memory segment of their
 * own by default (about 3,500 sessions) — so a session opened on one worker is resumed on any other. A
 * session no request has resumed yet expires within a minute, so unused
 * `initialize` calls cannot hold the store for the whole TTL. Each call reaches
 * the application's closures with the request (`Request`) as their
 * `$context`, and runs as deferred work: a tool may await asynchronous I/O.
 */
final class MCP
{
   /** The JSON-RPC error code carried by transport refusals. */
   public const int TRANSPORT_ERROR = -32000;
   /** Seconds a session survives before its first resumed request. */
   private const int PROVISIONAL = 60;
   /** Seconds between two ticks of a listening stream. */
   private const int INTERVAL = 5;
   /** Seconds a listening stream's slot is held without a tick (three ticks). */
   private const int LEASE = 15;
   /** Seconds between two sweeps of a full session store, across workers. */
   private const int SWEEP = 5;

   // * Config
   /** The MCP server core this endpoint drives. */
   public private(set) RPC\MCP $Server;
   /**
    * The browser origins allowed to call (`scheme://host[:port]`, lowercase).
    *
    * @var array<int,string>
    */
   public private(set) array $origins;
   /**
    * Seconds a session survives without a request — an open listening stream
    * counts as one (sliding; each renewal grants at least 15 while `GET`
    * streams are enabled; a session never resumed lasts `min(TTL, 60)`).
    */
   public private(set) int $TTL;
   /** The largest request body accepted, in bytes. */
   public private(set) int $body;
   /** Listening streams one session may hold open at once; `0` answers `GET` with 405. */
   public private(set) int $streams;

   // * Data
   /** The session store (shared across workers). */
   protected Cache $Cache;

   // * Metadata
   /**
    * The allowlist as a set.
    *
    * @var array<string,true>
    */
   private array $allowed = [];
   /** The stamp of the last sweep of this session store, kept across workers. */
   protected Cache $Stamps;
   /** The second this worker last swept, when the stamp cannot be kept. */
   private int $swept = 0;
   /**
    * Seconds every renewal grants a session: the TTL — never less than a
    * stream's lease while `GET` streams are enabled, so no request ever
    * shortens what the ticks of an open stream rely on.
    */
   private int $lifetime;


   /**
    * @param RPC\MCP $Server The MCP server core.
    * @param array<int,string> $origins Browser origins allowed to call, as
    *        `scheme://host[:port]` — never derived from the request `Host`.
    * @param null|Cache $Cache The session store; by default a 1 MiB
    *        shared-memory segment of its own, derived from the project
    *        directory (`shared` driver, prefix `mcp:`) — never the one
    *        RateLimit or other caches use. Use a Redis-backed Cache when the
    *        workers span hosts.
    * @param int $TTL Seconds a session survives without a request (an open
    *        listening stream counts as one; each renewal grants at least 15
    *        while `GET` streams are enabled).
    * @param int $body The largest request body accepted, in bytes.
    * @param int $streams Listening streams one session may hold open; `0`
    *        disables the `GET` stream.
    *
    * @throws InvalidArgumentException An invalid origin or bound.
    */
   public function __construct (
      RPC\MCP $Server,
      array $origins = [],
      null|Cache $Cache = null,
      int $TTL = 3600,
      int $body = 1_048_576,
      int $streams = 1
   )
   {
      // ? Bounds
      if ($TTL < 1 || $body < 1 || $streams < 0) {
         throw new InvalidArgumentException('MCP: TTL and body must be positive and streams not negative.');
      }

      // @@ Origins: exact serialized origins only
      $normalized = [];
      foreach ($origins as $origin) {
         $origin = rtrim(strtolower(trim($origin)), '/');
         if (preg_match('#^[a-z][a-z0-9+.-]*://[a-z0-9.\-\[\]:]+$#D', $origin) !== 1) {
            throw new InvalidArgumentException('MCP: each origin must be `scheme://host[:port]`.');
         }
         $normalized[] = $origin;
         $this->allowed[$origin] = true;
      }

      // * Config
      $this->Server = $Server;
      $this->origins = $normalized;
      $this->TTL = $TTL;
      $this->body = $body;
      $this->streams = $streams;

      // * Data
      $scope = defined('BOOTGLY_WORKING_DIR')
         ? (string) constant('BOOTGLY_WORKING_DIR')
         : __DIR__;
      if ($Cache === null) {
         // ! A segment of its own: a store filled by sessions never fills
         //   the default segment other caches (RateLimit…) share. Small on
         //   purpose: sweeping a full segment holds its lock for a time that
         //   grows with the square of its size (about 0.1 s at 1 MiB).
         $segment = crc32("bootgly.mcp.sessions\0{$scope}") & 0x7fffffff;
         $Cache = new Cache([
            'driver' => 'shared',
            'segment' => $segment > 0 ? $segment : 1,
            'size' => 1_048_576,
            'prefix' => 'mcp:'
         ]);
      }
      $this->Cache = $Cache;

      // * Metadata
      $this->lifetime = $streams > 0 ? max($TTL, self::LEASE) : $TTL;
      // ! One key in a tiny segment of its own, per session store: it never
      //   fills, so a full store can still be throttled across every worker,
      //   and one flooded store never holds back another store's sweep
      $Config = $Cache->Driver->Config;
      $segment = crc32("bootgly.mcp.sweeps\0{$scope}\0{$Config->driver}\0{$Config->segment}\0{$Config->prefix}") & 0x7fffffff;
      $this->Stamps = new Cache([
         'driver' => 'shared',
         'segment' => $segment > 0 ? $segment : 1,
         'size' => 65_536,
         'prefix' => 'mcp:'
      ]);
   }

   /**
    * Answer one request to the MCP endpoint (`POST`, `GET` or `DELETE`).
    */
   public function __invoke (Request $Request, Response $Response): Response
   {
      // ? DNS rebinding: a present Origin must be allowlisted
      $origin = $Request->Header->get('Origin');
      if ($origin !== null && isSet($this->allowed[rtrim(strtolower(trim($origin)), '/')]) === false) {
         return $this->refuse($Response, 403, 'Forbidden: origin not allowed');
      }

      // :
      return match ($Request->method) {
         'POST' => $this->receive($Request, $Response),
         'GET' => $this->listen($Request, $Response),
         'DELETE' => $this->terminate($Request, $Response),
         default => $this->refuse($Response, 405, 'Method Not Allowed', [
            'Allow' => $this->streams > 0 ? 'GET, POST, DELETE' : 'POST, DELETE'
         ])
      };
   }

   /**
    * `POST`: one JSON-RPC message or batch.
    */
   private function receive (Request $Request, Response $Response): Response
   {
      // ? JSON only — a form post cannot reach the server core
      $type = strtolower(trim(explode(';', (string) $Request->Header->get('Content-Type'))[0]));
      if ($type !== 'application/json') {
         return $this->refuse($Response, 415, 'Unsupported Media Type: application/json is required');
      }
      // ? Accept: JSON or an event stream
      $mode = $this->negotiate($Request, true);
      if ($mode === '') {
         return $this->refuse($Response, 406, 'Not Acceptable: accept application/json or text/event-stream');
      }

      // !
      $payload = $Request->input;
      // ? Body bound
      if (strlen($payload) > $this->body) {
         return $this->refuse($Response, 413, 'Payload Too Large');
      }
      $initializing = self::detect($payload);
      $principal = $this->identify($Request);

      // ? Every request after `initialize` resumes its session
      if ($initializing === false && $this->resume($Request, $Response, $principal) === null) {
         return $Response;
      }

      // :
      return $Response->defer(function (Response $Response, Request $Request) use ($payload, $initializing, $principal, $mode): void {
         $answer = $this->Server->handle($payload, $Request);

         // @ A successful `initialize` opens a session
         if ($initializing && $answer !== null) {
            $decoded = json_decode($answer, associative: true);
            $result = is_array($decoded) ? ($decoded['result'] ?? null) : null;
            $revision = is_array($result) ? ($result['protocolVersion'] ?? null) : null;
            if (is_string($revision)) {
               $id = bin2hex(random_bytes(16));
               // ? A store that cannot hold the session refuses it loudly
               if ($this->store($id, ['principal' => $principal, 'revision' => $revision]) === false) {
                  $this->refuse($Response, 503, 'Service Unavailable: the session store cannot hold a new session');

                  return;
               }
               $Response->Header->set('Mcp-Session-Id', $id);
            }
         }

         // ?: Only notifications came in
         if ($answer === null) {
            $Response->code(202)->send('');

            return;
         }

         // ?: One event on a stream that ends with it
         if ($mode === 'text/event-stream') {
            $SSE = $Response->SSE;
            $SSE->heartbeat = 0;
            $SSE->open();
            $SSE->send($answer, event: 'message');
            $SSE->close();

            return;
         }

         // :
         $Response->Header->set('Content-Type', 'application/json');
         $Response->send($answer);
      });
   }

   /**
    * `GET`: the listening stream of a session.
    */
   private function listen (Request $Request, Response $Response): Response
   {
      // ? A JSON-only endpoint
      if ($this->streams === 0) {
         return $this->refuse($Response, 405, 'Method Not Allowed', ['Allow' => 'POST, DELETE']);
      }
      // ? Accept: an event stream
      if ($this->negotiate($Request, false) === '') {
         return $this->refuse($Response, 406, 'Not Acceptable: accept text/event-stream');
      }
      // ?
      $id = $this->resume($Request, $Response, $this->identify($Request));
      if ($id === null) {
         return $Response;
      }

      // ! This stream's slot: a lease in the session record that every tick
      //   renews, so a stream that ends without its `Close` (a killed worker,
      //   a stopped server, a peer gone silent) frees it within LEASE seconds.
      //   Kept in the session record, it never adds a key to the store.
      $token = bin2hex(random_bytes(8));
      $streams = $this->streams;
      $Claim = static function (array $slots) use ($token, $streams): null|array {
         // ? Streams per session, counted across workers
         if (count($slots) >= $streams) {
            return null;
         }
         $slots[$token] = time() + self::LEASE;

         // :
         return $slots;
      };
      try {
         $claimed = $this->update($id, $Claim);
      }
      catch (RuntimeException) {
         // ? A full store with nothing to sweep
         if ($this->sweep() === false) {
            return $this->refuse($Response, 503, 'Service Unavailable: the session store cannot hold a new stream');
         }

         // @ Swept: tried once more
         try {
            $claimed = $this->update($id, $Claim);
         }
         catch (RuntimeException) {
            return $this->refuse($Response, 503, 'Service Unavailable: the session store cannot hold a new stream');
         }
      }
      // ? The session ended meanwhile
      if ($claimed === null) {
         return $this->refuse($Response, 404, 'Not Found: session not found');
      }
      // ? Every slot is taken
      if ($claimed === false) {
         return $this->refuse($Response, 409, 'Conflict: too many open streams for this session');
      }

      // @ Heartbeats until the client leaves or the session ends
      $Release = function () use ($id, $token): void {
         try {
            $this->update($id, static function (array $slots) use ($token): null|array {
               // ? Already lapsed
               if (isSet($slots[$token]) === false) {
                  return null;
               }
               unset($slots[$token]);

               // :
               return $slots;
            });
         }
         catch (RuntimeException) {
            // ? A shrinking record always fits; the lease frees the slot anyway
         }
      };
      $SSE = $Response->SSE;
      $SSE->open(
         Tick: function (SSE $SSE) use ($id, $token): void {
            try {
               $kept = $this->update($id, static function (array $slots) use ($token): array {
                  // @ Renew the lease — and count itself again if a stall let it lapse
                  $slots[$token] = time() + self::LEASE;

                  // :
                  return $slots;
               });
            }
            catch (RuntimeException) {
               // ? A full store: try again at the next tick
               return;
            }
            // ? The session ended (DELETE) or expired
            if ($kept === null) {
               $SSE->close();
            }
         },
         interval: self::INTERVAL,
         Close: $Release
      );
      // ? The stream never opened (HTTP/1.0 is answered 505, a cancelled
      //   exchange gets nothing): no `Close` will free the slot
      if ($SSE->opened === false) {
         $Release();
      }

      // :
      return $Response;
   }

   /**
    * `DELETE`: the client ends its session.
    */
   private function terminate (Request $Request, Response $Response): Response
   {
      // ?
      $id = $this->resume($Request, $Response, $this->identify($Request));
      if ($id === null) {
         return $Response;
      }

      // @
      $this->Cache->delete("session:{$id}");

      // :
      return $Response->code(204)->send('');
   }

   /**
    * The session a request names, renewed — or null once the refusal was
    * written (`400` missing id or revision mismatch, `404` unknown session).
    */
   private function resume (Request $Request, Response $Response, string $principal): null|string
   {
      // !
      $id = $Request->Header->get('Mcp-Session-Id');

      // ?
      if ($id === null || $id === '') {
         $this->refuse($Response, 400, 'Bad Request: Mcp-Session-Id header is required');

         return null;
      }
      // ? Only an id this transport could have issued reaches the store
      $record = preg_match('/^[0-9a-f]{32}$/D', $id) === 1
         ? $this->Cache->fetch("session:{$id}")
         : null;
      // ? Unknown, ended, expired — or another principal's
      if (
         is_array($record) === false
         || is_string($record['principal'] ?? null) === false
         || hash_equals($record['principal'], $principal) === false
      ) {
         $this->refuse($Response, 404, 'Not Found: session not found');

         return null;
      }
      // ? The revision agreed at `initialize`
      $version = $Request->Header->get('MCP-Protocol-Version');
      if ($version !== null && $version !== ($record['revision'] ?? null)) {
         $this->refuse($Response, 400, 'Bad Request: unsupported MCP-Protocol-Version');

         return null;
      }

      // @ Sliding idle expiry
      $this->Cache->renew("session:{$id}", $this->lifetime);

      // :
      return $id;
   }

   /**
    * Change the open streams recorded in a session. `$Change` receives the
    * live slots (token => lease expiry, lapsed ones left out) and returns the
    * new set, or null to leave the record as it is. The record is swapped
    * only if no other worker changed it meanwhile (it is then read again, up
    * to three times), and the swap renews the session like a request does: a
    * stream opening, ticking or closing is activity of the session, and a
    * renewal by a request landing meanwhile is never undone.
    *
    * @param Closure(array<string,int>):(null|array<string,int>) $Change
    *
    * @return null|bool Null when the session is gone; whether the record changed.
    *
    * @throws RuntimeException The store cannot hold a grown record.
    */
   private function update (string $id, Closure $Change): null|bool
   {
      // !
      $key = "session:{$id}";

      // @@ Until the swap lands
      for ($attempt = 0; $attempt < 3; $attempt++) {
         $record = $this->Cache->fetch($key);
         // ? Ended or expired
         if (is_array($record) === false) {
            return null;
         }

         // ! The live slots
         $now = time();
         $slots = [];
         $stored = $record['streams'] ?? [];
         if (is_array($stored)) {
            // @@
            foreach ($stored as $token => $expiry) {
               if (is_int($expiry) && $expiry > $now) {
                  $slots[(string) $token] = $expiry;
               }
            }
         }

         // @
         $slots = $Change($slots);
         // ?: Nothing to change
         if ($slots === null) {
            return false;
         }
         $next = $record;
         $next['streams'] = $slots;
         // ?: Swapped
         if ($this->Cache->swap($key, $record, $next, $this->lifetime)) {
            return true;
         }
      }

      // : Changed by other workers every time
      return false;
   }

   /**
    * The answer form the client's `Accept` prefers: `application/json`
    * (only when `$JSON` — a `POST`), `text/event-stream`, or `''` when it
    * accepts neither. A `POST` over HTTP/1.0 cannot stream its answer, so it
    * is answered JSON or refused.
    */
   private function negotiate (Request $Request, bool $JSON): string
   {
      // !
      $streamable = $JSON === false || $Request->protocol !== 'HTTP/1.0';

      // ?: No Accept accepts anything
      if (trim((string) $Request->Header->get('Accept')) === '') {
         return $JSON ? 'application/json' : 'text/event-stream';
      }

      // ! Acceptable types by preference (`q=0` already left out)
      $types = $Request->types;

      // @@ In the client's order of preference
      foreach ($types as $type) {
         $type = strtolower($type);
         if ($JSON && in_array($type, ['application/json', 'application/*', '*/*'], true)) {
            return 'application/json';
         }
         if ($streamable && in_array($type, ['text/event-stream', 'text/*', '*/*'], true)) {
            return 'text/event-stream';
         }
      }

      // :
      return '';
   }

   /**
    * Store a new session with a provisional lifetime (its first resumed
    * request extends it to the TTL). A full store is swept of expired
    * sessions and tried once more.
    *
    * @param array{principal:string,revision:string} $record
    */
   private function store (string $id, array $record): bool
   {
      // !
      $key = "session:{$id}";
      $TTL = min($this->TTL, self::PROVISIONAL);

      try {
         // :
         return $this->Cache->create($key, $record, $TTL);
      }
      catch (RuntimeException) {
         // ? Nothing swept
         if ($this->sweep() === false) {
            return false;
         }

         try {
            // :
            return $this->Cache->create($key, $record, $TTL);
         }
         catch (RuntimeException) {
            return false;
         }
      }
   }

   /**
    * Sweep the expired sessions out of a full store — by one worker at most
    * once every SWEEP seconds, since a sweep holds the store's lock.
    *
    * @return bool Whether any session was swept (room to try again).
    */
   private function sweep (): bool
   {
      try {
         // ? Another worker swept within SWEEP seconds
         if ($this->stamp() === false) {
            return false;
         }

         // :
         return $this->Cache->purge() > 0;
      }
      catch (RuntimeException) {
         return false;
      }
   }

   /**
    * Take the turn to sweep the session store: true for one worker every
    * SWEEP seconds. A stamp that cannot be kept (its segment refused)
    * throttles this worker alone instead of never sweeping again.
    */
   private function stamp (): bool
   {
      try {
         // :
         return $this->Stamps->create('sweep', true, self::SWEEP);
      }
      catch (RuntimeException) {
         // ! This worker's own turn
         $now = time();
         // ?: Swept within SWEEP seconds
         if ($now - $this->swept < self::SWEEP) {
            return false;
         }
         $this->swept = $now;

         // :
         return true;
      }
   }

   /**
    * The fingerprint of the authenticated principal a session is bound to
    * (`Request->identity`: an `Identity` by its id, a scalar by its value,
    * anything else by its serialized form; none = anonymous).
    */
   private function identify (Request $Request): string
   {
      // !
      $identity = $Request->identity;

      $principal = match (true) {
         $identity === null => 'anonymous',
         $identity instanceof Identity => "identity:{$identity->id}",
         is_string($identity), is_int($identity) => "value:{$identity}",
         default => null
      };
      if ($principal === null) {
         $serialized = serialize($identity);
         $principal = "serialized:{$serialized}";
      }

      // :
      return hash('sha256', $principal);
   }

   /**
    * Write a transport refusal: the status and a JSON-RPC error without id.
    *
    * @param array<string,string> $headers
    */
   private function refuse (Response $Response, int $code, string $message, array $headers = []): Response
   {
      // @
      foreach ($headers as $name => $value) {
         $Response->Header->set($name, $value);
      }
      $Response->Header->set('Content-Type', 'application/json');

      // :
      return $Response->code($code)->send((string) json_encode([
         'jsonrpc' => '2.0',
         'id' => null,
         'error' => ['code' => self::TRANSPORT_ERROR, 'message' => $message]
      ], JSON_UNESCAPED_SLASHES));
   }

   /**
    * Whether a payload is one `initialize` request (a batch never is: the
    * server core refuses `initialize` inside one).
    */
   private static function detect (string $payload): bool
   {
      // ? Cheap screen before decoding
      if (str_contains($payload, 'initialize') === false && str_contains($payload, '\\u') === false) {
         return false;
      }

      // !
      $request = json_decode($payload, associative: true);

      // :
      return is_array($request)
         && array_is_list($request) === false
         && ($request['method'] ?? null) === 'initialize';
   }
}
