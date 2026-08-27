<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use const BOOTGLY_PROJECT;
use const PHP_INT_MAX;
use const STR_PAD_LEFT;
use const ZLIB_ENCODING_DEFLATE;
use const ZLIB_ENCODING_GZIP;
use const ZLIB_ENCODING_RAW;
use function array_key_exists;
use function array_pop;
use function clearstatcache;
use function count;
use function defined;
use function explode;
use function fclose;
use function fopen;
use function fstat;
use function gmdate;
use function gzcompress;
use function gzdeflate;
use function gzencode;
use function hrtime;
use function is_array;
use function is_resource;
use function is_string;
use function preg_match;
use function preg_match_all;
use function realpath;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strncasecmp;
use function strncmp;
use function strpos;
use function strtolower;
use function substr;
use function trim;
use Closure;
use Error;
use Fiber;
use InvalidArgumentException;
use LogicException;
use ReflectionFunction;
use SplObjectStorage;
use stdClass;
use Throwable;
use WeakReference;

use const Bootgly\WPI;
use Bootgly\ABI\Code\__String\Path;
use Bootgly\ABI\Data\Language;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Events\Cancelling;
use Bootgly\ACI\Events\Contextualizing;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI\Endpoints\Servers\Ownership;
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Challenge;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Exchange;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Body;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource as ResponseResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database as DatabaseResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\JSON as JSONResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\JSONP as JSONPResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Negotiation as NegotiationResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Plaintext as PlaintextResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Pre as PreResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE as SSEResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\View as ViewResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\XML as XMLResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Route;


/**
 * * Config
 * @property-read DatabaseResource $Database
 * @property-read JSONResource $JSON
 * @property-read JSONPResource $JSONP
 * @property-read NegotiationResource $Negotiation
 * @property-read PlaintextResource $Plaintext
 * @property-read PreResource $Pre
 * @property-read SSEResource $SSE
 * @property-read ViewResource $View
 * @property-read XMLResource $XML
 */
class Response extends Server\Response
{
   use Raw;


   private null|Packages $Package;
   private null|Request $Request;
   private null|Router $Router;
   private null|Route $Route;
   private null|Exchange $Exchange;
   private null|Cancellation $Cancellation;
   /**
    * Immediate clone source. A clone can promote the source response to a
    * lifecycle without putting any registry lookup on requests that never
    * clone, defer or mount SSE.
    */
   private self $Owner;
   private bool $capturing;
   /** @var array<int,array{Request:Request,Response:self,Router:Router,Route:Route}> */
   private array $Contexts;

   // * Config
   /**
    * Default budget, in seconds, a deferred response may stay parked on the
    * reactor before a `Timeout` is delivered at its suspension point.
    * `0` = unbounded. A per-call `defer()` timeout takes precedence.
    */
   public static int|float $deferredTimeout = 0;

   // * Data
   // # Content
   public string|null $source;
   public string|null $type;
   /**
    * Route response cache TTL (seconds) — stamped per request by the Router
    * dispatcher wrapper when the matched route opted in via `cache:`;
    * consumed by the encoder / defer() to store the built wire bytes.
    */
   public int $cache = 0;
   /**
    * Whether this response may become worker-shared route-cache wire.
    *
    * A Received listener, global middleware or a Handled listener makes this
    * false before routing.
    * Deferred clones retain that decision, preventing their later stash()
    * from bypassing a lifecycle boundary the live encoder cannot serialize
    * into reusable raw wire.
   */
   public private(set) bool $cacheable = true;
   /**
    * Cache invalidation generation captured when this Response was bound.
    *
    * Clones retain it, so work deferred across Cache::flush() cannot repopulate
    * entries invalidated by a response-lifecycle transition.
    */
   private int $cacheGeneration;
   private object $Scope;
   // Whether $Scope was handed to a resource this request (see attach()).
   // Lets reset() skip the per-request stdClass realloc on routes that
   // never touch scoped resources (static routes: one less alloc/request).
   private bool $scoped;

   // * Metadata
   // # State (sets)
   public bool $chunked;
   public bool $encoded;
   // @ Interim 103 bytes emitted by hint() this request (HTTP/1.1 only) —
   //   prepended to the route-cache entry so warm hits replay Early Hints
   public private(set) string $hints;
   // # Type (set)
   #public bool $dynamic;
   #public bool $static;
   public bool $stream;
   /** @var array<int,array<string,mixed>> */
   protected array $files;
   // # Status (sets ...)
   public bool $initied = false;
   public bool $prepared;
   public bool $processed;
   public bool $sent;
   // # Deferred
   public bool $deferred;
   /** @var SplObjectStorage<Fiber<mixed,mixed,mixed,mixed>,true> */
   private SplObjectStorage $Fibers;
   /**
    * Parked worker Fibers, shared per worker process.
    *
    * A full Fiber lifecycle (construct + start + destroy) costs ~8.5µs on the
    * reference machine, while resuming a parked one costs ~150ns — pooled
    * Fibers loop over deferred jobs instead of being constructed per request.
    *
    * @var array<int,Fiber<mixed,mixed,mixed,mixed>>
    */
   private static array $Pool = [];
   /**
    * Pool ceiling — beyond it a finishing Fiber terminates instead of parking.
    */
   private const int POOL_LIMIT = 256;
   /** Lazy: only a deferral that outlived its budget ever logs. */
   private static null|Logger $Logger = null;

   // / HTTP
   public Header $Header;
   public Body $Body;
   // / Resources
   public Resources $Resources;

   /**
    * Construct a new Response instance.
    *
    * @param int $code The status code of the response.
    * @param array<string>|null $headers The headers of the response.
    * @param string $body The body of the response.
    */
   public function __construct (int $code = 200, null|array $headers = null, string $body = '')
   {
      $this->Package = null;

      $this->Request = null;
      $this->Router = null;
      $this->Route = null;
      $this->Exchange = null;
      $this->Cancellation = null;
      $this->Owner = $this;
      $this->capturing = false;
      $this->Contexts = [];

      // * Config
      // ...

      // * Data
      $this->files = [];

      $this->source = null;
      $this->type = null;
      $this->cacheGeneration = Cache::$generation;
      $this->Scope = new stdClass;
      $this->scoped = false;

      // * Metadata
      // # Status
      $this->initied = false;
      $this->prepared = true;
      $this->processed = true;
      $this->sent = false;
      // # Deferred
      $this->deferred = false;
      $this->Fibers = new SplObjectStorage;
      // # State
      $this->chunked = false;
      $this->encoded = false;
      $this->hints = '';
      // # Type
      #$this->dynamic = false;
      #$this->static = false;
      $this->stream = false;

      // / HTTP
      $this->Header = new Header;
      $this->Body = new Body;
      // / Resources
      $this->Resources = new Resources(
         fn (ResponseResource $Resource): ResponseResource => $this->attach($Resource),
         $this
      );

      // @
      if ($code !== 200) {
         $this->code($code);
      }

      if ($headers !== null) {
         $this->Header->prepare($headers);
      }

      if ($body !== '') {
         $this->Body->raw = $body;
      }
   }
   public function __clone ()
   {
      $SourceResponse = $this->Owner;
      $SourceRequest = $this->Request;
      $Cancellation = $this->Cancellation;
      $capturing = $this->capturing;
      $Exchange = $SourceResponse->promote();

      $this->Owner = $this;
      $this->Exchange = $Exchange;

      $this->Header = clone $this->Header;
      $this->Body = clone $this->Body;

      if ($capturing) {
         if ($this->Request !== null) {
            $this->Request = $this->Request->capture();
         }
         $this->capturing = false;
         // ! defer() assigns a fresh generation only after the clone and all
         //   resource forks succeed. Inheriting the parent's token here would
         //   let linking the child cancel its still-running parent.
         $this->Cancellation = null;
      }
      else if ($SourceRequest !== null) {
         $this->Request = clone $SourceRequest;
         $SharedExchange = Exchange::share($SourceRequest, $this->Request);
         if ($SharedExchange !== null) {
            $Exchange = $SharedExchange;
            $this->Exchange = $SharedExchange;
         }
         // ! Request::__clone() scrubs protocol stream state for cache
         //   templates. A selected Response clone remains on the same stream.
         $this->Request->stream = $SourceRequest->stream;
      }

      // @ Ordinary application clones share an active scheduled generation.
      if ($capturing === false && $Cancellation !== null) {
         Cancellation::link($this, $Cancellation);
      }

      // ! Preserve the admitted exchange as a weak Response snapshot. Its
      //   terminal tombstone lets resources mounted lazily on a retained clone
      //   reject writes after the keep-alive connection moved to a new request.
      Exchange::track($this, $Exchange);

      // # Deferred
      $this->Fibers = new SplObjectStorage;
      $this->Contexts = [];

      // / Resources
      $this->Resources = $this->Resources->fork(
         fn (ResponseResource $Resource): ResponseResource => $this->attach($Resource),
         $this
      );
   }
   /**
    * Get the specified property, mounted resource or content format.
    *
    * @param string $name The name of the property, mounted resource or content format to get.
    *
    * @return bool|string|int|array<mixed>|ResponseResource The value of the property or resource.
    */
   public function __get (string $name): bool|string|int|array|ResponseResource
   {
      // @ Construct Resource on demand — the single magic surface left:
      //   resource names are registry-defined (built-ins + user-defined),
      //   so they cannot become declared properties. Everything static
      //   ($code, ...) is a real property with asymmetric visibility.
      $Resource = $this->Resources->fetch($name);

      if ($Resource !== null) {
         return $Resource;
      }

      throw new InvalidArgumentException("Unknown response property or resource: {$name}");
   }

   /**
    * Prepare the response for sending.
    *
    * @param int $code The status code of the response.
    * @param array<string> $headers The headers of the response.
    * @param string $body The body of the response.
    *
    * @return self The Response instance, for chaining 
    */
   public function __invoke (int $code = 200, array $headers = [], string $body = ''): self
   {
      if ($code !== $this->code) {
         $this->code($code);
      }
      if ($headers !== []) {
         $this->Header->prepare($headers);
      }
      $this->Body->raw = $body;

      return $this;
   }

   /**
    * Mount one response resource and bind it to this response scheduler.
    *
    * @template T of ResponseResource
    * @param T $Resource
    * @return T
    */
   public function mount (ResponseResource $Resource, null|string $name = null): ResponseResource
   {
      $parts = explode('\\', $Resource::class);
      $name ??= (string) array_pop($parts);

      return $this->Resources->set($name, $Resource);
   }

   /**
    * Reset the response to its initial state.
    *
    * @param Packages $Package
    * @param Request $Request
    *
    * @return void
    */
   public function reset (
      Packages $Package,
      Request $Request,
   ): void
   {
      $this->Package = $Package;

      $this->Request = $Request;
      // ? No lifecycle lookup on the ordinary synchronous path. A pre-reset
      //   observer/clone has already bound the current Exchange through
      //   guard()/promote(); only a Response that still carries an older
      //   lifecycle needs the registry comparison and tombstone reset.
      $Exchange = $this->Exchange;
      if ($Exchange !== null && Exchange::fetch($Request) !== $Exchange) {
         $this->Exchange = null;
         Exchange::track($this, null);
      }
      $this->Cancellation = null;
      $this->Owner = $this;

      // * Data
      // # Content
      $this->source = null;
      $this->type = null;
      $this->cache = 0;
      $this->cacheable = true;
      $this->cacheGeneration = Cache::$generation;
      // ? Realloc Scope only when the previous request actually scoped a
      //   resource into it — non-scoped routes skip the per-request alloc.
      if ($this->scoped) {
         $this->Scope = new stdClass;
         $this->scoped = false;
      }
      $this->content = '';
      $this->files = [];

      // * Metadata
      // # State (sets)
      $this->chunked = false;
      $this->encoded = false;
      $this->hints = '';
      // # Type (set)
      $this->stream = false;
      // # Status (sets ...)
      $this->initied = false;
      $this->prepared = true;
      $this->processed = true;
      $this->sent = false;
      // # Deferred
      $this->deferred = false;
      // NOTE: $this->Fibers is intentionally left untouched. Deferred work runs on a
      // private clone (see defer()/__clone) that owns its own Fibers guard; the shared
      // singleton's Fibers is never populated, so there is nothing to reset here.
      $this->Header->clean();
      $this->Body->raw = '';
      // ? The registry publishes whether it has anything to drop. Reading the
      //   flag here skips the call frame itself on routes that mount no
      //   resource; a subtype always reports true, so its override still runs.
      $Resources = $this->Resources;
      if ($Resources->resettable) {
         $Resources->reset();
      }

      if ($this->code !== 200) {
         $this->code(200);
      }
   }

   /**
    * Make this response ineligible for worker-shared route-cache storage.
    *
    * The state is one-way for the current request and survives cloning, which
    * protects deferred completion after the synchronous encoder has returned.
    */
   public function guard (): void
   {
      // @ Received runs before reset() against the persistent Response. The
      //   encoder binds the current transport/request generation explicitly so
      //   an HTTP/2 listener cannot clone the previous stream or Cancellation.
      //   Ordinary cache guards have no context and perform no lifecycle read.
      $Context = Exchange::capture($this);
      if ($Context !== null) {
         $this->Package = $Context['Package'];
         $this->Request = $Context['Request'];
         $this->Exchange = $Context['Exchange'];
         $this->Cancellation = null;
         $this->Owner = $this;
         Exchange::track($this, $Context['Exchange']);
      }

      $this->cacheable = false;
   }

   /**
    * Promote this response to a correlated lifecycle on first escape.
    */
   private function promote (): null|Exchange
   {
      // ! The escape marker belongs to the source even when an observed
      //   Exchange already exists. A clone may defer while the persistent
      //   source itself remains synchronous; the next Request must still
      //   detach this generation's reusable alias.
      $this->cacheable = false;

      $Exchange = $this->Exchange;
      if ($Exchange !== null) {
         return $Exchange;
      }

      $Request = $this->Request;
      if ($Request === null) {
         return null;
      }

      $Exchange = Exchange::fetch($Request);
      if ($Exchange === null) {
         $Exchange = new Exchange;
         Exchange::admit($Request, $Exchange);
      }

      $this->Exchange = $Exchange;
      Exchange::track($this, $Exchange);

      return $Exchange;
   }

   /**
    * Bind a deferred exchange to both transport and HTTP/2 stream teardown.
    */
   private function retain (Cancellation $Cancellation): void
   {
      $Package = $this->Package;
      $Connection = $Package?->Connection;
      if ($Package === null || $Connection === null) {
         return;
      }

      if ($Cancellation->observe(static function (
         Cancellation $Cancellation,
      ) use ($Connection): void {
         Ownership::detach($Connection, $Cancellation);
      }) === false) {
         return;
      }
      Ownership::attach($Connection, $Cancellation);

      $Request = $this->Request;
      if ($Request === null || $Request->stream === 0) {
         return;
      }

      $H2 = $Package->decoded;
      if ($H2 instanceof Decoder_HTTP2 === false) {
         return;
      }

      $Stream = $H2->Streams[$Request->stream] ?? null;
      if ($Stream === null) {
         return;
      }

      if ($Cancellation->observe(static function (
         Cancellation $Cancellation,
      ) use ($Stream): void {
         Ownership::detach($Stream, $Cancellation);
      }) === false) {
         return;
      }
      Ownership::attach($Stream, $Cancellation);
   }

   /**
    * Attach one response resource to this response lifecycle.
    */
   private function attach (ResponseResource $Resource): ResponseResource
   {
      if ($Resource instanceof DatabaseResource) {
         $Resource->bind($this);
         $Resource->scope($this->Scope);
         $this->scoped = true;
      }

      if ($Resource instanceof SSEResource) {
         // @ Mounting SSE is the first possible out-of-band escape. Promote
         //   lazily here so ordinary responses never pay lifecycle setup.
         $this->promote();
         // ! Pass the owner: a carried instance must follow the response it is
         //   being attached to, or it keeps observing the generation of the one
         //   that mounted it.
         $Resource->bind($this->Package, $this->Request, $this);
      }

      if ($Resource instanceof Scheduling) {
         $Resource->schedule(fn (mixed $value = null): self => $this->wait($value));
      }

      return $Resource;
   }

   // # Authentication
   /**
      * Build an HTTP authentication challenge response.
      *
      * Sets status `401 Unauthorized` and emits the correct `WWW-Authenticate`
      * header for supported response authentication descriptors. Bearer/JWT
      * challenges are owned by router authentication guards.
    *
    * @param Authentication $Method The authentication method to use.
    *
    * @return self The Response instance, for chaining
    */
   public function authenticate (Authentication $Method): self
   {
      $this->code( 401);

      if ($Method instanceof Authentication\Basic) {
         $this->Header->set(
            'WWW-Authenticate',
            "Basic realm=\"{$Method->realm}\""
         );
      }

      return $this;
   }
   /**
    * Appends the provided data to the body of the response.
    *
    * @param mixed $body The data that should be appended to the response body.
    *
    * @return self The Response instance, for chaining
    */
   public function append ($body): self
   {
      $this->initied = true;

      $current = is_string($this->content) ? $this->content : '';
      $this->content = $current . $this->Body->stringify($body) . "\n";

      return $this;
   }

   /**
    * Compresses the response body using the specified method.
    *
    * @param string $raw The raw response content.
    * @param string $method The compression method to use (gzip, deflate, or compress).
    * @param int $level The level of compression.
    * @param int|null $encoding The optional encoding type.
    *
    * @return string|false The compressed content or false on failure.
    */
   public function compress (string $raw, string $method = 'gzip', int $level = 9, null|int $encoding = null): string|false
   {
      $encoded = false;
      $deflated = false;
      $compressed = false;

      try {
         switch ($method) {
            case 'gzip':
               $encoding ??= ZLIB_ENCODING_GZIP;
               $encoded = @gzencode($raw, $level, $encoding);
               break;
            case 'deflate':
               $encoding ??= ZLIB_ENCODING_RAW;
               $deflated = @gzdeflate($raw, $level, $encoding);
               break;
            case 'compress':
               $encoding ??= ZLIB_ENCODING_DEFLATE;
               $compressed = @gzcompress($raw, $level, $encoding);
               break;
         }
      }
      catch (Throwable) {
         // ...
      }

      if ($encoded) {
         $this->encoded = true;
         $this->Header->set('Content-Encoding', 'gzip');
         return $encoded;
      }
      else if ($deflated) {
         $this->encoded = true;
         $this->Header->set('Content-Encoding', 'deflate');
         return $deflated;
      }
      else if ($compressed) {
         $this->encoded = true;
         $this->Header->set('Content-Encoding', 'gzip');
         return $compressed;
      }

      return false;
   }

   /**
    * Send an interim `103 Early Hints` response (RFC 8297) carrying `Link`
    * header values, so clients preload assets while the final response is
    * still being produced. Repeatable — each call emits one interim
    * response directly on the transport; the final response is unaffected.
    *
    * @param string|array<string> $links One or more `Link` header values
    *                                    (e.g. `'</app.css>; rel=preload; as=style'`).
    *
    * @return self The Response instance, for chaining
    */
   public function hint (string|array $links = []): self
   {
      // ? No transport bound (detached contexts) or final response already sent
      $Package = $this->Package;
      $Request = $this->Request;
      if ($Package === null || $Request === null || $this->sent) {
         return $this;
      }
      // ? Interim responses are HTTP/1.1+ (RFC 8297)
      if ($Request->protocol === 'HTTP/1.0') {
         return $this;
      }

      // ! Link header lines (CRLF-stripped — response-splitting guard)
      $lines = '';
      foreach ((array) $links as $link) {
         $link = str_replace(["\r", "\n"], '', (string) $link);

         if ($link === '') {
            continue;
         }

         // ? Same forbidden-octet gate the final response applies. Stripping
         //   CR/LF alone still let NUL, vertical tab and the rest of the C0
         //   range onto the interim wire.
         if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $link) === 1) {
            continue;
         }

         $lines .= "Link: {$link}\r\n";
      }

      // ? Nothing to hint — a Link-less 103 is useless wire chatter
      if ($lines === '') {
         return $this;
      }

      // # HTTP/2 — interim HEADERS frame(s) without END_STREAM (§8.1)
      if ($Request->stream !== 0) {
         $H2 = $Package->decoded;

         // ? Stream already reset — the hint has no destination
         if (
            $H2 instanceof Decoder_HTTP2 === false
            || isSet($H2->Streams[$Request->stream]) === false
         ) {
            return $this;
         }

         // ! Flush pending control frames in the same write — the outbox
         //   piggybacks on the next response write, which would defeat "early"
         $block = Encoder_HTTP2::compress(103, $lines, null);
         $buffer = $H2->outbox
            . Encoder_HTTP2::pack($Request->stream, $block, $H2->Remote->frame);
         $H2->outbox = '';

         $Package->writing($Package->Connection->Socket, strlen($buffer), $buffer);

         return $this;
      }

      // # HTTP/1.1 — literal interim head, written before the final response
      //   bytes (never routed through code()/the wire cache)
      $head = "HTTP/1.1 103 Early Hints\r\n{$lines}\r\n";
      $Package->writing($Package->Connection->Socket, strlen($head), $head);

      // ! Remember the interim bytes — stash() prepends them to the route
      //   cache entry, so warm hits replay the exact cold-request wire
      $this->hints .= $head;

      return $this;
   }

   /**
    * Redirects to a new URI. Default return is 307 for GET (Temporary Redirect) and 303 (See Other) for POST.
    *
    * @param string $URI The new URI to redirect to.
    * @param ?int $code The HTTP status code to use for the redirection.
    *
    * @return self The Response instance, for chaining.
    *
    * ⚠️  SECURITY: Open Redirect risk.
    * Never pass user-supplied input directly to this method without validation.
    * If the redirect target may be controlled by the user (e.g., a ?next= parameter),
    * validate that the URI is relative or matches an explicitly allowed host before
    * calling redirect(). Example:
    *
    *   $next = $Request->queries['next'] ?? '/';
    *   if (!str_starts_with($next, '/')) {
    *       $next = '/'; // reject external URLs
    *   }
    *   $Response->redirect($next);
    */
   public function redirect (string $URI, int|null $code = null, bool $allowExternal = false): self
   {
      // ! Reject before any redirect classification or header normalization.
      //   Header::set() removes CR/LF and permits HTAB, so sanitizing there can
      //   join a split executable scheme after this method has already checked
      //   it. Backslash is also rejected in both modes because URL consumers
      //   can reinterpret it as a path separator.
      if (preg_match('/[\x00-\x1F\x7F\\\\]/', $URI) === 1) {
         $URI = '/';
      }

      // ! Always block executable/local schemes. Modern browsers can refuse
      //   these redirects, but embedded and non-browser consumers vary; the
      //   Response contract must never emit them as Location.
      if (preg_match('#^\s*(?:javascript|data|vbscript|file)\s*:#i', $URI) === 1) {
         $URI = '/';
      }

      // ! Block external redirects by default (open-redirect prevention)
      if ($allowExternal === false) {
         // @ Reject:
         //   - empty / not-leading-`/` targets (`\\evil.com`, `evil.com`)
         //   - protocol-relative `//evil.com`
         //   The common guard above already rejects every control byte and
         //   backslash in both internal and external modes.
         if (
            $URI === ''
            || $URI[0] !== '/'
            || (isset($URI[1]) && $URI[1] === '/')
         ) {
            $URI = '/';
         }
      }

      // !?
      switch ($code) {
         case 300: // Multiple Choices
         case 301: // Moved Permanently
         case 302: // Found (or Moved Temporarily)
         case 303: // See Other
         case 307: // Temporary Redirect
         case 308: // Permanent Redirect

            break;
         default:
            $code = null;
      }

      // # Set default code
      if ($code === null) {
         $code = match (WPI->Request->method) {
            'POST' => 303, // See Other
            default => 307 // Temporary Redirect
         };
      }

      // @
      $this->code( $code);
      $this->Header->set('Location', $URI);
      $this->end();

      return $this;
   }
   /**
    * Set the HTTP Server Response code.
    *
    * @param int $code 
    *
    * @return self The Response instance, for chaining 
    */
   public function code (int $code): self
   {
      // ? Informational statuses (1xx) are interim by definition (RFC 9110
      //   §15.2) — they can never terminate an exchange as the final
      //   status: HTTP/1.1 would serialize a lone 1xx head with
      //   Content-Length/body and HTTP/2 would END_STREAM it; clients keep
      //   waiting for the missing final response either way. 101 is not
      //   even interim-then-final: it hands the connection to another
      //   protocol, which is owned by dedicated servers (e.g. the WS
      //   stack), never by this response API.
      if ($code >= 100 && $code < 200) {
         throw new InvalidArgumentException(
            "Informational status {$code} cannot be a final response status — use hint() for 103 Early Hints; protocol switches (101) belong to a dedicated protocol server."
         );
      }

      $message = HTTP::RESPONSE_STATUS[$code] ?? null;

      // ? Fail loud on every other unsupported value — one stable
      //   exception for below-100 garbage and unmapped codes alike,
      //   instead of a silent fallback or an undefined-key error
      if ($message === null) {
         throw new InvalidArgumentException(
            "Invalid HTTP response status code: {$code}."
         );
      }

      // * Data
      // @ status
      $this->code = $code;

      // * Metadata
      // @ status
      $this->message = $message;
      $this->status = "$code $message";
      $this->response = parent::PROTOCOL . ' ' . $this->status;

      return $this;
   }
   /**
    * Send the response
    *
    * @param mixed|null $body The body of the response.
    * @param mixed ...$options Additional options for the response
    *
    * @return Response The Response instance, for chaining
    */
   public function send (mixed $body = null, mixed ...$options): self
   {
      // ?
      if ($this->sent === true) {
         return $this;
      }

      // @ Output
      if ($body === null) {
         $body = $this->Body->raw !== ''
            ? $this->Body->raw
            : $this->content;
      }

      $this->Body->raw = $this->Body->stringify($body);

      $this->sent = true;

      return $this;
   }
   /**
    * Start a file upload from the Server to the Client
    *
    * Neither protocol keeps the descriptor open while the response is parked,
    * so the transport reopens this pathname per write quantum and validates
    * every reopen against the identity captured here. Two consequences the
    * caller owns:
    *
    * - a file modified while it is being streamed aborts the response — there
    *   is no way to tell an append from a rewrite through `stat` alone, so
    *   snapshot anything under concurrent modification before serving it;
    * - the served tree must live on a filesystem with stable `st_dev`/`st_ino`.
    *   Mounts that regenerate inodes are indistinguishable from a swap.
    *
    * @param string $file The project-relative file path to upload.
    * @param int $offset The data offset.
    * @param int|null $length The length of the data to upload.
    * @param bool $close Close the connection after sending.
    *
    * @return Response The Response instance, for chaining
    */
   public function upload (string $file, int $offset = 0, null|int $length = null, bool $close = true): self
   {
      // ?!
      if ( !defined('BOOTGLY_PROJECT') ) {
         throw new Error('HTTP_Server_CLI must be started through a Project. BOOTGLY_PROJECT is not defined.');
      }

      $File = new File(BOOTGLY_PROJECT->path . Path::normalize($file), base: BOOTGLY_PROJECT->path);

      // ! Reading `readable` forces pathify(), which is what runs guard()'s
      //   realpath() jail check. Both it and is_file() answer from PHP's stat
      //   and realpath caches (realpath_cache_ttl defaults to 120s), so a
      //   long-lived worker would keep approving a name that has since become
      //   a symlink. Drop the cached answer first.
      clearstatcache(true, $File->file);

      if ($File->readable === false) {
         $this->code( 403);
         return $this;
      }

      // ! guard() computed the resolved path and threw it away — pathify()
      //   stores the symbolic one. Recover it and use it from here on: a
      //   resolved path has no symlink component left for a later flip to
      //   redirect, so the transport reopens the name the jail approved
      //   rather than a name that merely pointed there once.
      $path = realpath($File->file);
      if ($path === false) {
         $this->code(403);
         return $this;
      }

      // ! Capture filesystem identity and metadata from one opened handler.
      //   Neither protocol parks the descriptor, so every later reopen —
      //   `Packages::uploading()` for HTTP/1, `Encoder_HTTP2::queue()` and
      //   `Decoder_HTTP2::open()` for HTTP/2 — rejects pathname replacement
      //   by comparing against this stat before reading a byte.
      $Handler = @fopen($path, 'rb');
      if ($Handler === false) {
         $this->code(403);
         return $this;
      }
      try {
         $state = @fstat($Handler);
      }
      finally {
         @fclose($Handler);
      }
      if (
         $state === false
         || ($state['mode'] & 0170000) !== 0100000
      ) {
         $this->code( 500);
         return $this;
      }
      $size = $state['size'];
      $identity = [
         'device' => $state['dev'],
         'inode' => $state['ino'],
         'mode' => $state['mode'],
         'size' => $size,
         'modified' => $state['mtime'],
         'changed' => $state['ctime'],
      ];

      // @ Prepare HTTP headers
      $this->Header->prepare([
         'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', $state['mtime']),
         // Cache
         'Cache-Control' => 'no-cache, must-revalidate',
         'Expires' => '0',
      ]);

      // @ Return null Response if client Purpose === prefetch
      if (WPI->Request->Header->get('Purpose') === 'prefetch') {
         $this->code(204);
         $this->Header->set('Cache-Control', 'no-store');
         $this->Header->set('Expires', '0');
         return $this;
      }

      $ranges = [];
      $parts = [];
      // ! A client that sent a Range field is answered with 206 whatever it
      //   selected, including every byte. The server-initiated branch below
      //   decides this for itself, because there `upload($file)` with no
      //   window at all is the common case and it is a whole representation.
      $partial = true;
      $Range = WPI->Request->Header->get('Range');

      if (is_string($Range) && $Range !== '') {
         // @ Parse Client range requests
         $ranges = WPI->Request->range($size, $Range, combine: true);

         switch ($ranges) {
            case -2: // Malformed Range header string (no `=`) — rejected
               //   with a real 400: RFC 9110 §14.2 allows ignore or reject,
               //   and 416 would presuppose a parseable ranges-specifier.
               $this->end(400);
               return $this;
            case -1:
               $this->end(416, (string) $size);
               return $this;
            default:
               if (! is_array($ranges)) {
                  return $this;
               }

               /** @var mixed $type */
               $type = array_pop($ranges);
               // @ Check Range type
               if (! is_string($type) || $type !== 'bytes') {
                  $this->end(416, (string) $size);
                  return $this;
               }

               $selected = 0;
               /** @var array<int, array{start:int,end:int}> $ranges */
               foreach ($ranges as $range) {
                  $start = $range['start'];
                  $end = $range['end'];

                  $offset = $start;
                  $length = $end - $start + 1;
                  if ($length > $size - $selected) {
                     $this->end(416, (string) $size);
                     return $this;
                  }
                  $selected += $length;

                  $parts[] = [
                     'offset' => $offset,
                     'length' => $length
                  ];
               }
         }
      }
      else {
         // @ Set User offset / length
         $bytes = $length ?? $size - $offset;

         // ? A server-chosen window is a programming input, not a client
         //   request — there is no Range field to answer 416 to. One the
         //   representation cannot satisfy is refused here, at the source,
         //   instead of being advertised in a Content-Length the transport
         //   has to abandon mid-message once the head is already committed.
         //   A zero-length window of a non-empty representation is refused
         //   too: it has no last byte, so no Content-Range can describe it
         //   in a 206. Only the whole empty representation stays a 200.
         if (
            $offset < 0
            || $offset > $size
            || $bytes < 0
            || $bytes > $size - $offset
            || ($bytes === 0 && $size > 0)
         ) {
            $this->code(500);
            return $this;
         }

         // ! `end` is an OFFSET here, exactly as on the client-Range path
         //   above: the last byte sent, not a count. Storing the length in
         //   this slot is what produced `bytes 10-5/62` for a five-byte
         //   window at offset 10.
         $ranges[] = [
            'start' => $offset,
            'end' => $offset + $bytes - 1
         ];
         $parts[] = [
            'offset' => $offset,
            'length' => $bytes
         ];

         // ? Only a window narrower than the representation is partial
         //   content. A client Range field, by contrast, is always answered
         //   with 206 — even when it selects every byte.
         $partial = $offset !== 0 || $bytes !== $size;
      }

      // ! Header
      $rangesCount = count($ranges);
      // @ Set Content Length Header
      if ($rangesCount === 1) {
         $this->Header->set('Content-Length', (string) $parts[0]['length']);
      }
      // @ Set HTTP range requests Headers
      $pads = [];
      if (! empty($ranges) && $partial) {
         // @ Set Response status
         $this->code(206); // 206 Partial Content

         if ($rangesCount > 1) { // @ HTTP Multipart ranges
            $boundary = str_pad(
               string: (string) ++WPI->Request::$multiparts,
               length: 20,
               pad_string: '0',
               pad_type: STR_PAD_LEFT
            );

            $this->Header->set('Content-Type', 'multipart/byteranges; boundary=' . $boundary);

            $length = 0;
            foreach ($ranges as $index => $range) {
               $start = $range['start'];
               $end = $range['end'];

               $prepend = <<<HTTP_RAW
               \r\n--$boundary
               Content-Type: application/octet-stream
               Content-Range: bytes {$start}-{$end}/{$size}\r\n\r\n
               HTTP_RAW;

               $append = null;
               if ($index === $rangesCount - 1) {
                  $append = <<<HTTP_RAW
                  \r\n--$boundary--\r\n
                  HTTP_RAW;
               }

               $length += $parts[$index]['length'];
               $length += strlen($prepend);
               $length += strlen($append ?? '');

               $pads[] = [
                  'prepend' => $prepend,
                  'append' => $append
               ];
            }

            $this->Header->set('Content-Length', (string) $length);
         }
         else { // @ HTTP Single part ranges
            $start = $ranges[0]['start'];
            $end = $ranges[0]['end'];

            $this->Header->set('Content-Range', "bytes {$start}-{$end}/{$size}");
         }
      }
      else {
         $this->Header->set('Accept-Ranges', 'bytes');
      }
      // @ Set Content-Disposition Header
      if ($rangesCount === 1) {
         $this->Header->set('Content-Type', 'application/octet-stream');
         $this->Header->set('Content-Disposition', 'attachment; filename="'.$File->basename.'"');
      }
      // @ Build Response Header
      #$this->Header->build();

      // @ Prepare upstream
      $this->stream = true;
      // @ Prepare writing
      $this->files[] = [
         'file' => $path, // @ Set resolved file path to open handler
         'identity' => $identity,

         'parts' => $parts,
         'pads' => $pads,

         'close' => $close
      ];

      $this->sent = true;

      return $this;
   }
   // # Deferred
   /**
    * Defer the response to be completed asynchronously via Fiber.
    *
    * @param Closure(self):void $work The async work to execute inside a Fiber.
    * 
    * @return Response The Response instance, for chaining
    */
   /**
    * Store this response's built wire bytes in the route response cache.
    *
    * Consumes the per-request `cache` TTL stamp (set by the Router dispatcher
    * wrapper) and stores only when the exchange is safely cacheable: GET over
    * HTTP/1.1, status 200, plain identity body, no credentials on the request
    * and no cookies on the response.
    */
   public function stash (string $buffer): void
   {
      // ! Consume the per-request TTL stamp
      $ttl = $this->cache;
      $this->cache = 0;

      // ?
      $Request = $this->Request;

      // ? `$this->stream` below is NOT what keeps a file response out of the
      //   cache — it is always false here, because `Raw::encode()` clears it
      //   before returning and every caller stashes afterwards. A streamed
      //   response is refused through `cacheable`, which `encode()` clears at
      //   the point it diverts the body to the transport. The read is kept as
      //   a cheap backstop for a future caller that stashes mid-encode.
      if (
         $ttl <= 0
         || $this->cacheable === false
         || $this->cacheGeneration !== Cache::$generation
         || $Request === null
         || $Request->method !== 'GET'
         || $Request->protocol !== 'HTTP/1.1'
         || $this->code !== 200
         || $this->stream || $this->chunked || $this->encoded
      ) {
         return;
      }

      // ? Reserved ACME HTTP-01 namespace never stores — mirrors the
      //   encoders' fetch guard: a cached entry must never shadow the
      //   built-in responder's token/404 semantics
      if (strncmp($Request->URI, Challenge::PREFIX, 28) === 0) {
         return;
      }

      // ? Credentialed exchanges and cookie-setting responses never cache
      //   (request header fields are lowercase-normalized by the decoder)
      $fields = $Request->headers;

      if (isSet($fields['cookie']) || isSet($fields['authorization'])) {
         return;
      }

      // ? TrustedProxy consumes these fields after the early cache lookup and
      //   may change application address/scheme according to a route-specific
      //   peer allowlist. The generic cache cannot reproduce that trust
      //   decision, so forwarded requests may read only their distinct key
      //   namespace (above) and never seed a reusable representation.
      if (
         isSet($fields['x-forwarded-proto'])
         || isSet($fields['x-forwarded-for'])
         || isSet($fields['x-real-ip'])
      ) {
         return;
      }

      // ? A Set-Cookie under ANY casing and from ANY serialization source
      //   blocks storage. The canonical cookie API (Cookies::append → Session,
      //   Remember, applications) emits through Header::queue() — it never
      //   touches $fields — so an exact fields-only key check caches one
      //   client's session cookie and replays it to every other client.
      $Header = $this->Header;

      foreach ($Header->fields as $name => $_) {
         if (strcasecmp((string) $name, 'Set-Cookie') === 0) {
            return;
         }
      }
      foreach ($Header->prepared as $name => $_) {
         if (strcasecmp((string) $name, 'Set-Cookie') === 0) {
            return;
         }
      }
      foreach ($Header->queued as $line) {
         if (strncasecmp($line, 'set-cookie:', 11) === 0) {
            return;
         }
      }
      // ? Worker-persistent presets serialize too — but a preset masked by
      //   remove() is NOT serialized for this response, so it must not
      //   block storage
      $masked = $Header->masked;
      foreach ($Header->preset as $name => $_) {
         if (
            strcasecmp((string) $name, 'Set-Cookie') === 0
            && isSet($masked[strtolower((string) $name)]) === false
         ) {
            return;
         }
      }

      // ? Inspect the final serialized header block so every insertion path
      //   (fields, prepared, queued and unmasked presets) is covered. The
      //   lookup path has no response metadata, therefore this L1 cache can
      //   safely represent only automatic Accept-Language variance. Any other
      //   Vary dimension — or Vary: * — must fail closed instead of replaying
      //   one representation to a different request variant.
      $varyLanguage = false;
      $Header->build();

      // ? A bare CR/LF inside a serialized value can be interpreted as a
      //   second field by tolerant downstream recipients while remaining
      //   invisible to a CRLF line scan. Such malformed wire is never safe to
      //   cache, regardless of which Header insertion source produced it.
      if (preg_match('/\r(?!\n)|(?<!\r)\n/', $Header->raw) === 1) {
         return;
      }

      foreach (explode("\r\n", $Header->raw) as $line) {
         // ? Response cache directives are policy, not a hint (audit
         //   2026-07-27 M5). A handler that discovers its response is private
         //   and says so must not have a route-level TTL store it anyway.
         if (strncasecmp($line, 'Cache-Control:', 14) === 0) {
            foreach (explode(',', substr($line, 14)) as $directive) {
               $directive = strtolower(trim($directive));

               // ? This cache stores RAW WIRE, so it can neither revalidate nor
               //   selectively drop the fields a qualified form names. Every
               //   directive it cannot honour exactly must fail closed:
               //   `no-cache` demands validation before reuse, and
               //   `private="Set-Cookie"` demands removing one named field.
               if (
                  $directive === 'no-store'
                  || $directive === 'private'
                  || $directive === 'no-cache'
                  || str_starts_with($directive, 'private=')
                  || str_starts_with($directive, 'no-cache=')
               ) {
                  return;
               }
            }

            continue;
         }

         if (strncasecmp($line, 'Vary:', 5) !== 0) {
            continue;
         }

         foreach (explode(',', substr($line, 5)) as $token) {
            $token = trim($token);
            if (
               $token === ''
               || strcasecmp($token, 'Accept-Language') !== 0
               || Language::$roots === []
            ) {
               return;
            }

            $varyLanguage = true;
         }
      }

      // ? Last gate, and the only one that inspects the artifact instead of
      //   the state that produced it: what is stored must be a complete
      //   message on its own terms. Every reason a body might be missing is
      //   refused above — streamed, chunked, encoded, HEAD — and each of those
      //   was a bug before it was a guard. Checking the bytes closes the class
      //   rather than the instance, because an entry whose advertised length
      //   disagrees with what follows it desynchronizes every connection that
      //   replays it, and the next producer-side omission gets caught here
      //   instead of shipping.
      //
      //   Runs before the interim 103 bytes are prepended, so the separator
      //   found here is this response's own.
      $separator = strpos($buffer, "\r\n\r\n");

      if ($separator === false) {
         return;
      }

      // ? Exactly one Content-Length, and it must equal the bytes behind it.
      //   Two fields are a smuggling shape that must never become shared wire,
      //   whatever the values say.
      //
      // !! The trailing CRLF is a LOOKAHEAD on purpose. Consuming it would
      //   swallow the delimiter the next field needs, and adjacent duplicates
      //   would then report as one — which is exactly how this check first
      //   shipped, and what the duplicate leg of 96.02 caught.
      $framing = [];
      $fields = preg_match_all(
         '/\r\nContent-Length:[ \t]*([0-9]+)[ \t]*(?=\r\n)/i',
         substr($buffer, 0, $separator + 2),
         $framing
      );

      if (
         $fields !== 1
         || (int) $framing[1][0] !== strlen($buffer) - $separator - 4
      ) {
         return;
      }

      // @
      // ! Interim 103 bytes ride at the front of the entry — a cache hit
      //   replays Early Hints exactly like the cold request (the entry's
      //   Date offset is unaffected: interim heads carry no Date field)
      if ($this->hints !== '') {
         $buffer = "{$this->hints}{$buffer}";
      }

      // ! Stable composition mirrors both encoders' fetch path and keeps
      //   the scheduling Request's exact variance available to deferred work.
      Cache::store(Cache::compose($Request, $varyLanguage), $buffer, $ttl, $Request->URI);
   }

   /**
    * Log a deferral terminated by its budget.
    */
   private static function warn (self $Response, Timeout $Timeout): void
   {
      // ! The request target is attacker-controlled. It never rides in the
      //   message (its `@` bytes would be read as Output directives) and
      //   never carries its query (credentials ride there — see Catcher).
      $Request = $Response->Request;
      $context = [];
      if ($Request !== null) {
         $URI = $Request->URI;
         $query = strpos($URI, '?');

         $context['method'] = $Request->method;
         $context['URI'] = $query === false
            ? $URI
            : substr($URI, 0, $query);
      }

      self::$Logger ??= new Logger(channel: self::class);
      self::$Logger->log(
         warning: "Deferred response timed out after {$Timeout->timeout}s — answered 503.@.;",
         context: $context
      );
   }

   /**
    * Persistent deferred-job loop run by pooled Fibers.
    *
    * Executes one job, clears every request reference, parks itself back
    * into the pool and suspends with `Scheduler::DETACH` — the next defer()
    * resumes it with a fresh job instead of constructing a new Fiber.
    *
    * @param array{
    *    0:Closure,
    *    1:self,
    *    2:Packages,
    *    3:string,
    *    4:Cancellation,
    *    5:null|Exchange
    * } $job
    */
   private static function loop (array $job): void
   {
      // @@
      while (true) {
         [$work, $Response, $Package, $locale, $Cancellation, $Exchange] = $job;

         // ! Bind the scheduling request's locale to this Fiber — deferred
         //   work must not translate (or stash) under a locale negotiated by
         //   a request that interleaved while this one was suspended
         Language::bind($locale);

         // ! Drop the job container — only the locals hold the request now
         $job = null;
         $length = null;
         $buffer = '';
         $selected = false;

         try {
            // @ Execute user work (may call Fiber::suspend())
            $work($Response);

            // ? Nested defer/SSE/transport cancellation already selected or
            //   abandoned this generation. The outer job must not serialize a
            //   second response after that terminal handoff.
            if ($Cancellation->check() === false) {
               // ? Guard: socket may have closed while the Fiber was suspended.
               if (is_resource($Package->Connection->Socket)) {
                  $buffer = $Response->encode($Package, $length);

                  if ($Response->cache !== 0) {
                     $Response->stash($buffer);
                  }

               }

               // @ Commit only after serialization/cache preparation succeeds.
               //   If encode() throws, Catcher below can still select a 500
               //   whose wire and telemetry status agree. Close the scheduler
               //   generation before Exchange so its observer never cancels
               //   the Fiber that just completed normally.
               $Cancellation->finish();
               $Exchange?->finish($Response);
               $selected = true;

               if (is_resource($Package->Connection->Socket)) {
                  $Package->writing(
                     $Package->Connection->Socket,
                     length: $length,
                     buffer: $buffer,
                  );
               }
            }
         }
         catch (Throwable $Throwable) {
            // ! An out-of-band child/SSE may have completed and user code may
            //   then throw. Its terminal token wins; Catcher must not append a
            //   second 500 to the same persistent connection.
            if ($selected === false && $Cancellation->check() === false) {
               // ? A deferral past its budget is answered as unavailable, not
               //   as an application error: no report intake, no debug page
               $timedOut = $Throwable instanceof Timeout;
               if ($timedOut) {
                  self::warn($Response, $Throwable);
               }
               try {
                  $Errored = $timedOut
                     ? Catcher::respond($Response->Request, $Response, code: 503)
                     : Catcher::respond($Response->Request, $Response, $Throwable);
               }
               catch (Throwable) {
                  $Errored = new Response(code: $timedOut ? 503 : 500, body: '');
               }

               // ! Catcher returns a fresh Response. Bind the suspended job's
               //   exact transport/request generation before serialization;
               //   the ambient Server::$Request may already belong to an
               //   interleaved HTTP/2 stream. Passing this Cancellation into
               //   Encoder_HTTP2 also distinguishes normal stream completion
               //   from teardown before the 500 lifecycle is committed.
               $Errored->Package = $Package;
               $Errored->Request = $Response->Request;
               $Errored->Exchange = $Exchange;
               $Errored->Cancellation = $Cancellation;

               $encoded = is_resource($Package->Connection->Socket) === false;
               if ($encoded === false) {
                  try {
                     $buffer = $Errored->encode($Package, $length);
                     $encoded = true;
                  }
                  catch (Throwable) {
                     // A second serialization failure has no trustworthy wire
                     // status. Abandon this exchange and let transport cleanup
                     // continue without escaping the reactor or writing twice.
                  }
               }

               if ($encoded) {
                  $selected = true;
                  $Cancellation->finish();
                  $Exchange?->finish($Errored);

                  if (is_resource($Package->Connection->Socket)) {
                     try {
                        $Package->writing(
                           $Package->Connection->Socket,
                           length: $length,
                           buffer: $buffer,
                        );
                     }
                     catch (Throwable) {
                        // Terminal response was already selected. A retry or
                        // second error head could desynchronize keep-alive.
                     }
                  }
               }
               else {
                  $Cancellation->cancel();
                  $Exchange?->finish(null);
               }
            }
         }
         finally {
            // ! A path that neither selected nor handed off is abandonment.
            //   cancel() is idempotent and its committed bridge closes the
            //   Exchange without inventing a response status.
            $Cancellation->cancel();

            // @ Deferred multipart ownership lives on this private Response
            //   clone. Reclaim it after work/encoding on success, exception,
            //   or once resumed work observes that its socket was closed.
            // @ Ungated on purpose: `clean()` also returns the captured
            //   body's bytes to the worker ledger, and that reservation
            //   exists whether or not this request carried uploads. Its own
            //   first line is the no-uploads fast path.
            $Request = $Response->Request;
            $Request?->clean();

            // @ Drop this job's locale binding before the Fiber is pooled
            Language::unbind();

            // @ Unregister Fiber from wait() guard
            $Self = Fiber::getCurrent();

            if ($Self !== null) {
               $Response->Fibers->offsetUnset($Self);
            }
         }

         // ! Clear request references before parking — a parked Fiber must
         //   not keep the previous Response/Package/buffer alive
         $work = $Response = $Package = $buffer = $length = null;
         $Cancellation = $Exchange = null;

         $Self = Fiber::getCurrent();

         // ? Pool at capacity (or not inside a Fiber) — terminate instead
         if ($Self === null || count(self::$Pool) >= self::POOL_LIMIT) {
            return;
         }

         // @ Park and wait for the next job (the scheduler drops DETACH)
         self::$Pool[] = $Self;
         // ! Release the self-reference before parking — see wait(). This
         //   frame stays alive across every later job's suspension; holding
         //   the Fiber here would rebuild the self-cycle for every reused
         //   pooled Fiber.
         $Self = null;

         /** @var array{
          *    0:Closure,
          *    1:self,
          *    2:Packages,
          *    3:string,
          *    4:Cancellation,
          *    5:null|Exchange
          * } $job
          */
         $job = Fiber::suspend(Scheduler::DETACH);
      }
   }

   /**
    * Install this deferred response's admitted HTTP context for one Fiber
    * execution segment. The scheduler pairs every call with unbind().
    */
   private function bind (): void
   {
      $Request = $this->Request;
      $Router = $this->Router;
      $Route = $this->Route;

      if ($Request === null || $Router === null || $Route === null) {
         return;
      }

      $WPI = WPI;
      $this->Contexts[] = [
         'Request' => $WPI->Request,
         'Response' => $WPI->Response,
         'Router' => $WPI->Router,
         'Route' => $Router->Route,
      ];

      $WPI->Request = $Request;
      $WPI->Response = $this;
      $WPI->Router = $Router;
      $Router->Route = $Route;
   }

   /**
    * Restore the ambient HTTP context that preceded the current Fiber
    * execution segment.
    */
   private function unbind (): void
   {
      $Context = array_pop($this->Contexts);

      if ($Context === null) {
         return;
      }

      $Router = $this->Router;
      if ($Router !== null) {
         $Router->Route = $Context['Route'];
      }

      $WPI = WPI;
      $WPI->Router = $Context['Router'];
      $WPI->Response = $Context['Response'];
      $WPI->Request = $Context['Request'];
   }

   /**
    * Run response work on a pooled Fiber that may park on the worker reactor.
    *
    * @param Closure $work The deferred work, receiving this Response.
    * @param int|float $timeout Seconds the work may stay parked before a
    *    `Response\Timeout` is delivered at its suspension point; `0` uses
    *    `self::$deferredTimeout`, where `0` means unbounded.
    */
   public function defer (Closure $work, int|float $timeout = 0): self
   {
      $Package = $this->Package;

      // ?
      if ($Package === null) {
         $this->deferred = true;
         return $this;
      }

      // ! Validate the capability before cloning or executing user work. A
      //   late failure could otherwise orphan a suspended Fiber after its
      //   callback had already produced side effects.
      $Event = TCP_Server_CLI::$Event;
      if ($Event instanceof Contextualizing === false) {
         throw new LogicException(
            'HTTP deferred execution requires a context-aware scheduler.'
         );
      }

      $Request = $this->Request;
      $Exchange = $this->promote();
      if ($Exchange?->check()) {
         // ! A retained stale clone cannot emit onto a keep-alive transport
         //   after its admitted exchange already terminated.
         $this->deferred = true;

         return $this;
      }
      if (
         $Exchange?->inspect()
         && $Event instanceof Cancelling === false
      ) {
         // ! Fail before clone/capture and before upload ownership moves.
         throw new LogicException(
            'Observed HTTP deferred execution requires scheduler cancellation support.'
         );
      }
      if ($this->deferred) {
         return $this;
      }

      $Parent = $this->Cancellation;
      $Lease = $Exchange === null ? null : Cancellation::fetch($Exchange);
      $Current = Fiber::getCurrent();
      if (
         $Lease !== null
         && (
            $Lease !== $Parent
            || $Current === null
            || Cancellation::fetch($Current) !== $Lease
         )
      ) {
         // ! Another Response generation already selected asynchronous output
         //   for this request. The running Fiber's identity is insufficient:
         //   user work can retain a sibling clone created before selection and
         //   invoke it from that same Fiber. A legitimate nested handoff needs
         //   both the target Response and the currently running Fiber to carry
         //   the active generation; neither identity alone grants authority.
         $this->deferred = true;

         return $this;
      }

      $this->deferred = true;
      $Response = null;
      $DeferredRequest = null;
      $Cancellation = null;
      $Fiber = null;
      $uploads = false;
      $movedFiles = [];
      $claimed = false;
      $PreviousLease = null;
      $State = new class {
         public bool $terminal = false;
         public bool $cancelled = false;
      };

      try {
         $this->capturing = true;
         try {
            $Response = clone $this;
         }
         finally {
            $this->capturing = false;
         }
         // ! The source flag denotes handoff to this job. The private work
         //   clone starts clear so nested defer/SSE can signal a NEW handoff.
         $Response->deferred = false;
         $DeferredRequest = $Response->Request;
         // ! capture() copied upload paths while the live Request still owns
         //   them. Clear the clone before any further extension point can
         //   throw; ownership is moved atomically only immediately pre-start.
         if ($DeferredRequest !== null) {
            $DeferredRequest->files = [];
         }

         // ! Snapshot Router context and Route-bound work after capture.
         $WPI = WPI;
         $Router = $WPI->Router;
         $RouterRoute = $Router->Route;
         $Route = clone $RouterRoute;
         $Response->Router = $Router;
         $Response->Route = $Route;

         $Reflection = new ReflectionFunction($work);
         $BoundRoute = $Reflection->getClosureThis();
         if ($BoundRoute instanceof Route) {
            $WorkRoute = $BoundRoute === $RouterRoute
               ? $Route
               : clone $BoundRoute;
            $work = $work->bindTo($WorkRoute, $WorkRoute) ?? $work;
         }

         $Fiber = array_pop(self::$Pool);
         if ($Fiber === null) {
            $Fiber = new Fiber(self::loop(...));
            $fresh = true;
         }
         else {
            $fresh = false;
         }

         $Cancellation = Cancellation::open($Response);
         $Response->Cancellation = $Cancellation;
         Cancellation::link($Fiber, $Cancellation);
         if ($DeferredRequest !== null) {
            Cancellation::link($DeferredRequest, $Cancellation);
         }
         $Response->Fibers->offsetSet($Fiber, true);

         // ! Handled weakly for symmetry with Select::bind(): the guard seat
         //   on $Response->Fibers (above) is what owns the pooled Fiber for
         //   this generation, and this very observer drops it at settle. A
         //   null get() means the Fiber is already gone — and with it any
         //   storage seat, since SplObjectStorage holds strong keys — so
         //   there is nothing left to unregister.
         $Reference = WeakReference::create($Fiber);
         $Cancellation->observe(static function (
            Cancellation $Observed,
            bool $cancelled,
         ) use ($Reference, $Response, $State): void {
            $State->terminal = true;
            $State->cancelled = $cancelled;

            // ! Every terminal transition revokes this generation's wait()
            //   capability. In a nested handoff the parent finishes normally
            //   while still RUNNING; allowing it to suspend again afterward
            //   would abandon or cancel the selected child exchange.
            $Fiber = $Reference->get();
            if ($Fiber !== null && $Response->Fibers->offsetExists($Fiber)) {
               $Response->Fibers->offsetUnset($Fiber);
            }
            if ($cancelled === false) {
               return;
            }
            // ! Running work may still read its captured request. Its loop
            //   finally cleans after return; suspended/not-started work can be
            //   reclaimed immediately.
            if ($Fiber === null || $Fiber->isRunning() === false) {
               $Response->Request?->clean();
            }
         });

         // @ Transport/RST teardown owns the generation before user work.
         $Response->retain($Cancellation);
         if ($Cancellation->check()) {
            $Exchange?->finish(null);

            return $this;
         }

         // ! Publish the child as the request-global selection lease BEFORE
         //   user work starts. A sibling Response retained before defer() must
         //   not launch a competing job or SSE head from inside this Fiber.
         //   claim() does not terminalize the parent: a setup/scheduler failure
         //   can still restore it and flow into Catcher.
         if ($Exchange !== null) {
            $ObservedLease = Cancellation::fetch($Exchange);
            if ($ObservedLease !== null && $ObservedLease !== $Parent) {
               $Cancellation->cancel();

               return $this;
            }

            $PreviousLease = Cancellation::claim($Exchange, $Cancellation);
            $claimed = Cancellation::fetch($Exchange) === $Cancellation;
         }

         // @ Move completed uploads only after every preflight and ownership
         //   attachment succeeded, with no callback between the two writes.
         if ($Request !== null && $DeferredRequest !== null && $Request->hasFiles) {
            $movedFiles = $Request->files;
            $DeferredRequest->files = $movedFiles;
            $Request->files = [];
            $uploads = true;
         }

         $job = [
            $work,
            $Response,
            $Package,
            Language::resolve(),
            $Cancellation,
            $Exchange,
         ];
         $Response->bind();
         try {
            $suspendedValue = $fresh
               ? $Fiber->start($job)
               : $Fiber->resume($job);
         }
         finally {
            $Response->unbind();
         }

         if ($Fiber->isSuspended() && $suspendedValue !== Scheduler::DETACH) {
            if ($Cancellation->check()) { // @phpstan-ignore if.alwaysFalse
               $Exchange?->finish(null);

               return $this;
            }

            $Enter = static function () use ($Response): void {
               $Response->bind();
            };
            $Leave = static function () use (
               $Cancellation,
               $Fiber,
               $Response,
            ): void {
               $Response->unbind();
               // ! Cancellation can race a RUNNING segment that directly
               //   suspends. Deterministically reclaim it at the boundary.
               if (
                  $Cancellation->check() // @phpstan-ignore booleanAnd.leftAlwaysFalse
                  && $Fiber->isSuspended() // @phpstan-ignore booleanAnd.rightAlwaysTrue
               ) {
                  if ($Response->Fibers->offsetExists($Fiber)) {
                     $Response->Fibers->offsetUnset($Fiber);
                  }
                  $Response->Request?->clean();
               }
            };

            $Event->bind($Fiber, $Enter, $Leave);
            if ($Event->schedule($Fiber, $suspendedValue) === false) {
               // ? Select may have delivered a rejection through the Fiber,
               //   allowing Catcher to select a response before returning.
               if ($Cancellation->check() === false) { // @phpstan-ignore identical.alwaysTrue
                  throw new LogicException(
                     'HTTP deferred execution was rejected by the scheduler.'
                  );
               }
            }

            // @ Deadline — a parked deferral is bounded by the per-call budget
            //   or the server default. The Fiber is held weakly: a pooled
            //   Fiber must not be retained by a stale deadline, and the
            //   reactor re-checks identity before delivering.
            $budget = $timeout > 0 ? $timeout : self::$deferredTimeout;
            if ($budget > 0) {
               $Reference = WeakReference::create($Fiber);
               // ! A budget beyond the monotonic clock's remaining nanosecond
               //   headroom would wrap to a deadline in the past. Clamp in the
               //   integer domain: a float round-trip would overflow the sum
               //   and demote the deadline to the wall-clock timer bucket.
               $now = (int) hrtime(true);
               $headroom = PHP_INT_MAX - $now;
               $nanoseconds = $budget >= $headroom / 1_000_000_000
                  ? $headroom
                  : (int) ($budget * 1_000_000_000);
               $deadline = $now + $nanoseconds;
               $timer = $Event->defer($deadline, static function () use (
                  $Event,
                  $Reference,
                  $Cancellation,
                  $budget,
               ): void {
                  // ? Settled meanwhile — completion, handoff or teardown
                  if ($Cancellation->check()) { // @phpstan-ignore if.alwaysFalse
                     return;
                  }
                  $Parked = $Reference->get();
                  if ($Parked !== null) {
                     $Event->interrupt($Parked, new Timeout($budget));
                  }
               });
               // ! Every terminal transition disarms the deadline. The token
               //   settles only after the Fiber left its suspension point, so
               //   a firing deadline always finds the generation still active.
               $Cancellation->observe(static function () use ($Event, $timer): void {
                  $Event->cancel($timer);
               });
            }
         }

         // @ Commit the asynchronous selection only after inline completion
         //   or successful scheduler admission. Cancellation before commit is
         //   rollback; cancellation afterward closes the Exchange as no-status.
         $Cancellation->observe(static function (
            Cancellation $Observed,
            bool $cancelled,
         ) use ($Exchange): void {
            if ($cancelled) {
               $Exchange?->finish(null);
            }
         });
         if ($Exchange !== null) {
            $Exchange->observe(static function () use ($Cancellation): void {
               if ($Cancellation->check() === false) { // @phpstan-ignore identical.alwaysTrue
                  $Cancellation->cancel();
               }
            });
         }

         // ! Finish the running parent generation before publishing the child
         //   lease. Its loop sees a normal handoff and emits no stale wire.
         $Parent?->finish();
         $this->Cancellation = $Cancellation;

         return $this;
      }
      catch (Throwable $Throwable) {
         // ? A synchronously completed child/SSE already selected the wire.
         //   Orchestration errors after that boundary cannot append a 500.
         if (
            $State->terminal
            && $State->cancelled === false
            && ($Exchange?->check() ?? false)
         ) {
            $Parent?->finish();

            return $this;
         }

         $this->deferred = false;
         // ! Roll back sole upload ownership before cancellation observers can
         //   clean the rejected child. The caller/parent Request then resumes
         //   its normal lifecycle and remains responsible for final cleanup.
         if ($uploads && $Request !== null && $DeferredRequest !== null) {
            $DeferredRequest->files = [];
            $currentFiles = $Request->files;
            if ($currentFiles === []) {
               $Request->files = $movedFiles;
            }
            else {
               // ! A custom scheduler may re-enter application code during
               //   admission and add new uploads. Preserve both ownership
               //   maps instead of overwriting either branch. Distinct top-
               //   level keys retain their ordinary upload-record shape, and
               //   clean() recursively reclaims every tmp_name.
               $restoredFiles = $currentFiles;
               foreach ($movedFiles as $name => $file) {
                  $restoredName = $name;
                  while (array_key_exists($restoredName, $restoredFiles)) {
                     $restoredName = "deferred_{$restoredName}";
                  }
                  $restoredFiles[$restoredName] = $file;
               }
               $Request->files = $restoredFiles;
            }
            $uploads = false;
         }
         $Cancellation?->cancel();
         if (
            $claimed
            && $Exchange?->check() === false
            && $PreviousLease !== null
            && $PreviousLease->check() === false
            && Cancellation::fetch($Exchange) === null
         ) {
            Cancellation::claim($Exchange, $PreviousLease);
         }
         if ($DeferredRequest !== null) {
            if ($uploads === false) {
               // ! The live Request still owns these paths on pre-commit
               //   rollback; release only the captured body reservation.
               $DeferredRequest->files = [];
            }
            $DeferredRequest->clean();
         }
         if (
            $Fiber !== null
            && $Response !== null // @phpstan-ignore notIdentical.alwaysTrue
            && $Response->Fibers->offsetExists($Fiber)
         ) {
            $Response->Fibers->offsetUnset($Fiber);
         }

         throw $Throwable;
      }
   }
   /**
    * Wait for the deferred work to complete and the response to be sent.
    *
    * Yields control back to the event loop. The Fiber is resumed based on the value passed:
    * - `null` (default): tick-based — resumes on the next event loop iteration.
    * - `resource` (stream): read I/O-bound — resumes when `stream_select()` detects readability on the stream.
    * - `Readiness`: explicit read/write I/O-bound — resumes when `stream_select()` detects the requested readiness.
    *
    * @param Readiness|resource|null $value A readiness request, stream resource, or null for tick-based.
    *
    * @return Response The Response instance, for chaining.
    */
   public function wait (mixed $value = null): self
   {
      if ($value !== null && $value instanceof Readiness === false && is_resource($value) === false) {
         throw new InvalidArgumentException('HTTP response wait expects Readiness, resource or null.');
      }

      // ? Guard: only suspend from a Fiber created by defer()
      $Current = Fiber::getCurrent();
      if ($Current === null || $this->Fibers->offsetExists($Current) === false) {
         return $this;
      }
      // ! A compiled variable lives until its frame exits. Kept across the
      //   suspension it would make the parked stack reference its own Fiber
      //   — a self-cycle only the cycle collector can break — so an evicted
      //   generation could never be destroyed, and its finally never run, at
      //   the reactor's safe point. Release it before parking.
      unset($Current);

      // @ Suspend current Fiber until resumed by deferred work
      Fiber::suspend($value);

      return $this;
   }

   /**
    * Definitively terminates the HTTP Response.
    *
    * @param int|null $code The status code of the response.
    * @param string|null $context The context for additional information.
    *
    * @return Response Returns Response.
    */
   public function end (null|int $code = null, null|string $context = null): self
   {
      // ?
      if ($this->sent === true) {
         return $this;
      }

      // @
      if ($code) {
         // @ Preset
         switch ($code) {
            case 416: // Range Not Satisfiable
               $this->code(416);
               // Clean prepared headers / header fields already set
               $this->Header->clean();
               $this->Body->raw = ' '; // Needs body non-empty
               break;
            default:
               $this->code( $code);
         }

         // @ Contextualize
         switch ($code) {
            case 416: // Range Not Satisfiable
               if ($context) {
                  $this->Header->set('Content-Range', 'bytes */' . $context);
               }
               break;
         }
      }

      $this->sent = true;

      return $this;
   }
}
