<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use const BOOTGLY_PROJECT;
use const STR_PAD_LEFT;
use const ZLIB_ENCODING_DEFLATE;
use const ZLIB_ENCODING_GZIP;
use const ZLIB_ENCODING_RAW;
use function array_pop;
use function count;
use function defined;
use function explode;
use function gmdate;
use function gzcompress;
use function gzdeflate;
use function gzencode;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function preg_match;
use function str_pad;
use function str_replace;
use function strlen;
use Closure;
use Error;
use Fiber;
use InvalidArgumentException;
use SplObjectStorage;
use stdClass;
use Throwable;

use const Bootgly\WPI;
use Bootgly\ABI\Data\__String\Path;
use Bootgly\ABI\Data\Language;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Modules\HTTP;
use Bootgly\WPI\Modules\HTTP\Server;
use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_HTTP2;
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

   // * Config
   // ...

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
   private string $hints;
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

      // * Config
      // ...

      // * Data
      $this->files = [];

      $this->source = null;
      $this->type = null;
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
      $this->Header = clone $this->Header;
      $this->Body = clone $this->Body;

      if ($this->Request !== null) {
         $this->Request = clone $this->Request;
      }

      // # Deferred
      $this->Fibers = new SplObjectStorage;

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
   public function reset (Packages $Package, Request $Request): void
   {
      $this->Package = $Package;

      $this->Request = $Request;

      // * Data
      // # Content
      $this->source = null;
      $this->type = null;
      $this->cache = 0;
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
      $this->Resources->reset();

      if ($this->code !== 200) {
         $this->code(200);
      }
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
         $Resource->bind($this->Package, $this->Request);
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
      // ! Always block dangerous schemes — emitted Location with
      //   `javascript:` / `data:` / `vbscript:` / `file:` is executed by
      //   email clients and WebView hybrids even if browsers ignore it.
      if (preg_match('#^\s*(?:javascript|data|vbscript|file)\s*:#i', $URI) === 1) {
         $URI = '/';
      }

      // ! Block external redirects by default (open-redirect prevention)
      if ($allowExternal === false) {
         // @ Reject:
         //   - empty / not-leading-`/` targets (`\\evil.com`, `evil.com`)
         //   - protocol-relative `//evil.com`
         //   - backslash-smuggled `/\evil.com` (UA normalises `\` → `/`)
         //   - any control byte (`\x00-\x1F`, `\x7F`) or backslash anywhere
         //     (defeats proxy-trimmed `/\t//evil.com` variants)
         if (
            $URI === ''
            || $URI[0] !== '/'
            || (isset($URI[1]) && ($URI[1] === '/' || $URI[1] === '\\'))
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $URI) === 1
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

      if ($File->readable === false) {
         $this->code( 403);
         return $this;
      }

      // @
      $size = $File->size;
      if (! is_int($size)) {
         $this->code( 500);
         return $this;
      }

      // @ Prepare HTTP headers
      $this->Header->prepare([
         'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', $File->modified),
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
      $Range = WPI->Request->Header->get('Range');

      if (is_string($Range) && $Range !== '') {
         // @ Parse Client range requests
         $ranges = WPI->Request->range($size, $Range);

         switch ($ranges) {
            case -2: // Malformed Range header string
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

               /** @var array<int, array{start:int,end:int|null}> $ranges */
               foreach ($ranges as $range) {
                  $start = $range['start'];
                  $end = $range['end'];

                  $offset = $start;
                  $length = 0;
                  if ($end > $start) {
                     $length += ($end - $start);
                  }
                  $length += 1;

                  $parts[] = [
                     'offset' => $offset,
                     'length' => $length
                  ];
               }
         }
      }
      else {
         // @ Set User offset / length
         $ranges[] = [
            'start' => $offset,
            'end' => $length
         ];
         $parts[] = [
            'offset' => $offset,
            'length' => $length ?? $size - $offset
         ];
      }

      // ! Header
      $rangesCount = count($ranges);
      // @ Set Content Length Header
      if ($rangesCount === 1) {
         $this->Header->set('Content-Length', (string) $parts[0]['length']);
      }
      // @ Set HTTP range requests Headers
      $pads = [];
      if (! empty($ranges) && ($ranges[0]['end'] !== null || $ranges[0]['start'])) {
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

               if ($end > $size - 1) $end += 1;

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

            if ($end > $size - 1) $end += 1;

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
         'file' => $File->file, // @ Set file path to open handler

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

      if (
         $ttl <= 0
         || $Request === null
         || $Request->method !== 'GET'
         || $Request->protocol !== 'HTTP/1.1'
         || $this->code !== 200
         || $this->stream || $this->chunked || $this->encoded
      ) {
         return;
      }

      // ? Credentialed exchanges and cookie-setting responses never cache
      //   (request header fields are lowercase-normalized by the decoder)
      $fields = $Request->headers;

      if (isSet($fields['cookie']) || isSet($fields['authorization'])) {
         return;
      }

      if (isSet($this->Header->fields['Set-Cookie'])) {
         return;
      }

      // @
      // ! Interim 103 bytes ride at the front of the entry — a cache hit
      //   replays Early Hints exactly like the cold request (the entry's
      //   Date offset is unaffected: interim heads carry no Date field)
      if ($this->hints !== '') {
         $buffer = "{$this->hints}{$buffer}";
      }

      // ! Language-vary segment mirrors the encoders' fetch key — the active
      //   locale is the one that produced this body
      $vary = Language::$roots !== [] ? "\0" . Language::$locale : '';
      Cache::store("{$Request->method}\0{$Request->URI}{$vary}", $buffer, $ttl);
   }

   /**
    * Persistent deferred-job loop run by pooled Fibers.
    *
    * Executes one job, clears every request reference, parks itself back
    * into the pool and suspends with `Scheduler::DETACH` — the next defer()
    * resumes it with a fresh job instead of constructing a new Fiber.
    *
    * @param array{0:Closure,1:self,2:Packages} $job
    */
   private static function loop (array $job): void
   {
      // @@
      while (true) {
         [$work, $Response, $Package] = $job;

         // ! Drop the job container — only the locals hold the request now
         $job = null;
         $length = null;
         $buffer = null;

         try {
            // @ Execute user work (may call Fiber::suspend())
            $work($Response);

            // ? Guard: socket may have been closed while the Fiber was suspended
            if (is_resource($Package->Connection->Socket)) {
               // @ Encode and send response after work completes
               $buffer = $Response->encode($Package, $length);

               // ? Route response cache opt-in — store the built wire bytes
               if ($Response->cache !== 0) {
                  $Response->stash($buffer);
               }

               // @ Write response to socket
               $Package->writing($Package->Connection->Socket, length: $length, buffer: $buffer);
            }
         }
         catch (Throwable $Throwable) {
            // @ Environment-aware error response (also reports the throwable)
            $Errored = Catcher::respond($Response->Request, $Response, $Throwable);

            // ? Guard: socket may have been closed
            if (is_resource($Package->Connection->Socket)) {
               // @ Encode and send response after work fails
               $buffer = $Errored->encode($Package, $length);

               // @ Write response to socket
               $Package->writing($Package->Connection->Socket, length: $length, buffer: $buffer);
            }
         }
         finally {
            // @ Unregister Fiber from wait() guard
            $Self = Fiber::getCurrent();

            if ($Self !== null) {
               $Response->Fibers->detach($Self);
            }
         }

         // ! Clear request references before parking — a parked Fiber must
         //   not keep the previous Response/Package/buffer alive
         $work = $Response = $Package = $buffer = $length = null;

         $Self = Fiber::getCurrent();

         // ? Pool at capacity (or not inside a Fiber) — terminate instead
         if ($Self === null || count(self::$Pool) >= self::POOL_LIMIT) {
            return;
         }

         // @ Park and wait for the next job (the scheduler drops DETACH)
         self::$Pool[] = $Self;

         /** @var array{0:Closure,1:self,2:Packages} $job */
         $job = Fiber::suspend(Scheduler::DETACH);
      }
   }

   public function defer (Closure $work): self
   {
      // !
      $this->deferred = true;

      $Package = $this->Package;

      // ?
      if ($Package === null) {
         return $this;
      }

      $Response = clone $this;

      // @ Reuse a parked pool Fiber — constructing one costs ~8.5µs/request,
      //   resuming into the persistent job loop ~150ns
      $Fiber = array_pop(self::$Pool);

      if ($Fiber === null) {
         $Fiber = new Fiber(self::loop(...));

         // @ Register Fiber for wait() guard (must be set before start)
         $Response->Fibers->attach($Fiber);

         // @ Start Fiber with its first job
         $suspendedValue = $Fiber->start([$work, $Response, $Package]);
      }
      else {
         // @ Register Fiber for wait() guard (must be set before resume)
         $Response->Fibers->attach($Fiber);

         // @ Resume the parked job loop with the new job
         $suspendedValue = $Fiber->resume([$work, $Response, $Package]);
      }

      // @ Schedule suspended Fiber in event loop — forwards the suspended
      //   value for I/O-aware routing; DETACH (parked) and terminated
      //   Fibers are dropped by the scheduler
      if ($Fiber->isSuspended()) {
         TCP_Server_CLI::$Event->schedule($Fiber, $suspendedValue);
      }

      return $this;
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
      $current = Fiber::getCurrent();
      if ($current === null || !$this->Fibers->contains($current)) {
         return $this;
      }

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
            case 400: // Bad Request
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
