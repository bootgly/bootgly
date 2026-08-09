<?php

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * M4 PoC — a cached `103 Early Hints` block must not be adopted as the final
 * response head.
 *
 * The production attack leg uses no private-state mutation and invokes the
 * real Request decoder, route cache and Encoder_. Its explicit precondition is
 * a Middlewares subtype whose process() performs post-core lifecycle work
 * while the inherited stack (and therefore count) is empty. That is legal
 * polymorphism, but it makes Encoder_ classify the request as unmediated.
 *
 * An attacker-selected header then registers a late Handled listener after the
 * cache lookup. Encoder_ materializes the hit through adopt() before emitting
 * that event. On vulnerable code, adopt() splits at the first CRLFCRLF, imports
 * the interim Link field and makes the complete cached 200 response the body of
 * a new outer 200.
 *
 * Controls prove:
 * - an ordinary unmediated hit is a valid `103 + 200` replay;
 * - the same late-listener behavior in a canonical appended Middleware causes
 *   a cache miss and observes the current handler response;
 * - direct adoption handles a final-only wire correctly.
 */
$probe = [
   'error' => '',
   'ordinary' => [],
   'canonical' => [],
   'conditional' => [],
   'direct' => [],
];

return new Specification(
   description: 'Cache adoption must parse the final response after Early Hints',
   Separator: new Separator(line: true),

   request: static function () use (&$probe): string {
      $socket = tmpfile();
      if (! is_resource($socket)) {
         $probe['error'] = 'Could not allocate the production-encoder stream surrogate.';

         return "GET /m4-adoption-harness HTTP/1.1\r\nHost: localhost\r\n\r\n";
      }

      $OldRequest = Server::$Request;
      $OldResponse = Server::$Response;
      $OldRouter = Server::$Router;
      $OldDecoder = Server::$Decoder;
      $OldHandler = SAPI::$Handler ?? null;
      $OldMiddlewares = SAPI::$Middlewares ?? null;
      $OldEmitter = Emitter::$Instance;
      $oldEntries = Cache::$entries;
      $oldBytes = Cache::$bytes;
      $oldURIs = Cache::$URIs;
      $oldGeneration = Cache::$generation;
      $oldLocale = Language::$locale;

      $EncoderReflection = new ReflectionClass(Encoder_::class);
      $encoderProperties = [
         'wire',
         'Admitted',
         'adopted',
         'mediated',
         'guarded',
      ];
      $encoderState = [];
      foreach ($encoderProperties as $name) {
         $Property = $EncoderReflection->getProperty($name);
         $encoderState[$name] = $Property->getValue();
      }

      try {
         /** @var Connection $Connection */
         $Connection = (new ReflectionClass(Connection::class))->newInstanceWithoutConstructor();
         $Connection->Socket = $socket;
         $Connection->timers = [];
         $Connection->ip = '127.0.0.1';
         $Connection->port = 12345;
         $Connection->encrypted = false;
         $Connection->writes = 0;

         $Encode = static function (string $raw) use ($Connection): string {
            $Package = new class($Connection) extends TCPPackages {
               public function __construct (Connection $Connection)
               {
                  $this->Connection = $Connection;

                  $this->cache = true;
                  $this->changed = true;
                  $this->input = '';
                  $this->output = '';
                  $this->callbacks = [&$this->input];
                  $this->expired = false;
                  $this->consumed = 0;
                  $this->rejected = false;

                  $this->downloading = [];
                  $this->uploading = [];
                  $this->closeAfterWrite = false;
               }
            };

            Server::$Request = new Request;
            Server::$Decoder = new Decoder_;

            $size = strlen($raw);
            $State = Server::$Request->decode($Package, $raw, $size);
            if (
               $State !== States::Complete
               || $Package->consumed !== $size
               || $Package->rejected
            ) {
               throw new RuntimeException('Production Request::decode() rejected the PoC request.');
            }

            $length = null;

            return Encoder_::encode($Package, $length);
         };

         // Parse every leading informational block before treating a head as
         // final. Repeating adopt()'s first-separator split here would turn the
         // safe raw `103 + 200` control into a false positive.
         $Parse = static function (string $wire): array {
            $offset = 0;
            $codes = [];

            while (true) {
               $separator = strpos($wire, "\r\n\r\n", $offset);
               if ($separator === false) {
                  return [
                     'valid' => false,
                     'codes' => $codes,
                     'head' => '',
                     'body' => '',
                     'length_matches' => false,
                  ];
               }

               $head = substr($wire, $offset, $separator - $offset);
               if (preg_match('/^HTTP\/1\.1 ([0-9]{3})(?: |$)/', $head, $matches) !== 1) {
                  return [
                     'valid' => false,
                     'codes' => $codes,
                     'head' => $head,
                     'body' => '',
                     'length_matches' => false,
                  ];
               }

               $code = (int) $matches[1];
               $codes[] = $code;
               $offset = $separator + 4;

               if ($code >= 100 && $code < 200) {
                  continue;
               }

               $body = substr($wire, $offset);
               $contentLength = null;
               if (
                  preg_match(
                     '/^Content-Length:\s*([0-9]+)\r?$/mi',
                     $head,
                     $matches
                  ) === 1
               ) {
                  $contentLength = (int) $matches[1];
               }

               return [
                  'valid' => true,
                  'codes' => $codes,
                  'head' => $head,
                  'body' => $body,
                  'length_matches' => $contentLength === null
                     || $contentLength === strlen($body),
               ];
            }
         };
         $Header = static function (array $parsed, string $name): null|string {
            $quoted = preg_quote($name, '/');
            if (
               preg_match(
                  "/^{$quoted}:\\s*([^\\r\\n]+)\\r?$/mi",
                  (string) ($parsed['head'] ?? ''),
                  $matches
               ) !== 1
            ) {
               return null;
            }

            return $matches[1];
         };

         $hint = '</m4.css>; rel=preload; as=style';

         // ? Positive control: a normal cache hit skips the handler and returns
         //   one informational block followed by one final response.
         Cache::flush();
         Emitter::$Instance = new Emitter;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;
         $ordinaryHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$ordinaryHandlers, $hint): Generator {
            yield $Router->route('/m4-adoption-ordinary', static function (
               Request $Request,
               Response $Response
            ) use (&$ordinaryHandlers, $hint): Response {
               $ordinaryHandlers++;
               $Response->hint($hint);

               return $Response(body: "M4-ORDINARY:handler={$ordinaryHandlers}");
            }, GET, cache: ['TTL' => 60]);
         };

         $ordinaryCold = $Encode(
            "GET /m4-adoption-ordinary HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $ordinaryEntries = array_values(Cache::$entries);
         $ordinaryCached = $ordinaryEntries[0][0] ?? '';
         $ordinaryWarm = $Encode(
            "GET /m4-adoption-ordinary HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $probe['ordinary'] = [
            'handlers' => $ordinaryHandlers,
            'entries' => count(Cache::$entries),
            'cold' => $Parse($ordinaryCold),
            'warm' => $Parse($ordinaryWarm),
         ];

         // ? Canonical control: adding an ordinary Middleware after a clean
         //   prime makes the request mediated, flushes that entry and executes
         //   the current handler before its late listener observes the body.
         Cache::flush();
         $CanonicalEmitter = new Emitter;
         Emitter::$Instance = $CanonicalEmitter;
         SAPI::$Middlewares = new Middlewares;
         Server::$Response = new Response;
         Server::$Router = new Router;
         $canonicalHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$canonicalHandlers, $hint): Generator {
            yield $Router->route('/m4-adoption-canonical', static function (
               Request $Request,
               Response $Response
            ) use (&$canonicalHandlers, $hint): Response {
               $canonicalHandlers++;
               $Response->hint($hint);

               return $Response(body: "M4-CANONICAL:handler={$canonicalHandlers}");
            }, GET, cache: ['TTL' => 60]);
         };

         $canonicalPrime = $Encode(
            "GET /m4-adoption-canonical HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $Canonical = new class implements Middleware {
            public int $runs = 0;
            public int $registrations = 0;
            public int $events = 0;
            /** @var array<int,string> */
            public array $observed = [];

            public function process (
               object $Request,
               object $Response,
               Closure $Next
            ): object {
               $this->runs++;
               $Result = $Next($Request, $Response);

               /** @var Request $Request */
               if ($Request->Header->get('X-M4-Adopt') !== 'register') {
                  return $Result;
               }

               $this->registrations++;
               $Self = $this;
               Emitter::$Instance->listen(
                  RequestEvents::Handled,
                  static function (Emission $Emission) use ($Self): void {
                     /** @var Request $Request */
                     /** @var Response $Response */
                     [$Request, $Response] = $Emission->payload;
                     if ($Request->URI !== '/m4-adoption-canonical') {
                        return;
                     }

                     $Self->events++;
                     $Self->observed[] = $Response->Body->raw;
                     $Response->Body->raw .= ';M4-EVENT=current';
                     $Response->Header->set('X-M4-Event', (string) $Self->events);
                  }
               );

               return $Result;
            }
         };
         SAPI::$Middlewares = (new Middlewares)->append($Canonical);
         $canonicalAttack = $Encode(
            "GET /m4-adoption-canonical HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-M4-Adopt: register\r\n\r\n"
         );
         $canonicalParsed = $Parse($canonicalAttack);
         $probe['canonical'] = [
            'handlers' => $canonicalHandlers,
            'entries' => count(Cache::$entries),
            'middleware_count' => SAPI::$Middlewares->count,
            'runs' => $Canonical->runs,
            'registrations' => $Canonical->registrations,
            'events' => $Canonical->events,
            'observed' => $Canonical->observed,
            'prime' => $Parse($canonicalPrime),
            'attack' => $canonicalParsed,
            'event_header' => $Header($canonicalParsed, 'X-M4-Event'),
         ];

         // ! Conditional production attack: this subtype does real lifecycle
         //   work in process(), but its inherited stack remains empty. Encoder_
         //   therefore sees count=0, admits the cache hit, and only discovers
         //   the attacker-selected late listener at the response tail.
         Cache::flush();
         $ConditionalEmitter = new Emitter;
         Emitter::$Instance = $ConditionalEmitter;
         $Conditional = new class extends Middlewares {
            public int $runs = 0;
            public int $registrations = 0;
            public int $events = 0;
            /** @var array<int,string> */
            public array $observed = [];

            public function process (
               object $Request,
               object $Response,
               Closure $Next
            ): mixed {
               $this->runs++;
               $Result = parent::process($Request, $Response, $Next);

               /** @var Request $Request */
               if ($Request->Header->get('X-M4-Adopt') !== 'register') {
                  return $Result;
               }

               $this->registrations++;
               $Self = $this;
               Emitter::$Instance->listen(
                  RequestEvents::Handled,
                  static function (Emission $Emission) use ($Self): void {
                     /** @var Request $Request */
                     /** @var Response $Response */
                     [$Request, $Response] = $Emission->payload;
                     if ($Request->URI !== '/m4-adoption-conditional') {
                        return;
                     }

                     $Self->events++;
                     $Self->observed[] = $Response->Body->raw;
                     $Response->Body->raw .= ';M4-EVENT=current';
                     $Response->Header->set('X-M4-Event', (string) $Self->events);
                  }
               );

               return $Result;
            }
         };
         SAPI::$Middlewares = $Conditional;
         Server::$Response = new Response;
         Server::$Router = new Router;
         $conditionalHandlers = 0;
         SAPI::$Handler = static function (
            Request $Request,
            Response $Response,
            Router $Router
         ) use (&$conditionalHandlers, $hint): Generator {
            yield $Router->route('/m4-adoption-conditional', static function (
               Request $Request,
               Response $Response
            ) use (&$conditionalHandlers, $hint): Response {
               $conditionalHandlers++;
               $Response->hint($hint);
               $Response->Header->set('Content-Type', 'text/plain');

               return $Response(body: "M4-CONDITIONAL:handler={$conditionalHandlers}");
            }, GET, cache: ['TTL' => 60]);
         };

         $conditionalPrime = $Encode(
            "GET /m4-adoption-conditional HTTP/1.1\r\nHost: localhost\r\n\r\n"
         );
         $conditionalEntries = array_values(Cache::$entries);
         $conditionalCached = $conditionalEntries[0][0] ?? '';
         $conditionalAttack = $Encode(
            "GET /m4-adoption-conditional HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "X-M4-Adopt: register\r\n\r\n"
         );
         $conditionalParsed = $Parse($conditionalAttack);
         $cachedParsed = $Parse($conditionalCached);
         $probe['conditional'] = [
            'handlers' => $conditionalHandlers,
            'entries_after_prime' => count($conditionalEntries),
            'entries_after_attack' => count(Cache::$entries),
            'middleware_count' => $Conditional->count,
            'runs' => $Conditional->runs,
            'registrations' => $Conditional->registrations,
            'events' => $Conditional->events,
            'observed' => $Conditional->observed,
            'prime' => $Parse($conditionalPrime),
            'cached' => $cachedParsed,
            'attack' => $conditionalParsed,
            'cached_content_type' => $Header($cachedParsed, 'Content-Type'),
            'link' => $Header($conditionalParsed, 'Link'),
            'content_type' => $Header($conditionalParsed, 'Content-Type'),
            'event_header' => $Header($conditionalParsed, 'X-M4-Event'),
         ];

         // ? Direct corroboration with the exact authentic ordinary cache wire.
         //   A structural lifecycle fix may prevent the conditional subtype
         //   from priming at all; the unmediated control remains an authentic
         //   hinted entry in either remediation shape. The
         //   final-only suffix is the positive parser control.
         $firstSeparator = strpos($ordinaryCached, "\r\n\r\n");
         if ($firstSeparator === false) {
            throw new RuntimeException('The authentic hinted cache wire has no head separator.');
         }
         $finalWire = substr($ordinaryCached, $firstSeparator + 4);

         $DirectControl = new Response;
         Encoder_::adopt($DirectControl, $finalWire);
         $DirectAttack = new Response;
         Encoder_::adopt($DirectAttack, $ordinaryCached);
         $extraHint = "HTTP/1.1 103 Early Hints\r\n"
            . "Link: </m4-extra.js>; rel=preload; as=script\r\n\r\n";
         $DirectMultiple = new Response;
         Encoder_::adopt($DirectMultiple, $extraHint . $ordinaryCached);
         $DirectMissing = new Response;
         $DirectMissing(body: 'M4-MISSING-SENTINEL');
         Encoder_::adopt($DirectMissing, $extraHint);
         $DirectMismatch = new Response;
         $DirectMismatch(body: 'M4-LENGTH-SENTINEL');
         Encoder_::adopt($DirectMismatch, $ordinaryCached . 'X');
         $invalidNameWire = str_replace(
            "\r\nContent-Length:",
            "\r\nBad Name: rejected\r\nContent-Length:",
            $finalWire
         );
         $DirectInvalid = new Response;
         $DirectInvalid(body: 'M4-INVALID-SENTINEL');
         Encoder_::adopt($DirectInvalid, $invalidNameWire);
         $transferWire = str_replace(
            "\r\nContent-Length:",
            "\r\nTransfer-Encoding: chunked\r\nContent-Length:",
            $finalWire
         );
         $DirectTransfer = new Response;
         $DirectTransfer(body: 'M4-TRANSFER-SENTINEL');
         Encoder_::adopt($DirectTransfer, $transferWire);
         $probe['direct'] = [
            'control_body' => $DirectControl->Body->raw,
            'control_content_type' => $DirectControl->Header->get('Content-Type'),
            'attack_body' => $DirectAttack->Body->raw,
            'attack_link' => $DirectAttack->Header->get('Link'),
            'attack_content_type' => $DirectAttack->Header->get('Content-Type'),
            'multiple_body' => $DirectMultiple->Body->raw,
            'multiple_link' => $DirectMultiple->Header->get('Link'),
            'multiple_content_type' => $DirectMultiple->Header->get('Content-Type'),
            'missing_body' => $DirectMissing->Body->raw,
            'missing_link' => $DirectMissing->Header->get('Link'),
            'mismatch_body' => $DirectMismatch->Body->raw,
            'mismatch_link' => $DirectMismatch->Header->get('Link'),
            'invalid_body' => $DirectInvalid->Body->raw,
            'invalid_header' => $DirectInvalid->Header->get('Bad Name'),
            'transfer_body' => $DirectTransfer->Body->raw,
            'transfer_header' => $DirectTransfer->Header->get('Transfer-Encoding'),
         ];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($encoderState as $name => $value) {
            $Property = $EncoderReflection->getProperty($name);
            $Property->setValue(null, $value);
         }

         Cache::$entries = $oldEntries;
         Cache::$bytes = $oldBytes;
         Cache::$URIs = $oldURIs;
         Cache::$generation = $oldGeneration;
         Language::$locale = $oldLocale;
         Emitter::$Instance = $OldEmitter;
         Server::$Request = $OldRequest;
         Server::$Response = $OldResponse;
         Server::$Router = $OldRouter;
         Server::$Decoder = $OldDecoder;

         if ($OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }

         @fclose($socket);
      }

      return "GET /m4-adoption-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield $Router->route('/m4-adoption-harness', static function (
         Request $Request,
         Response $Response
      ): Response {
         return $Response(body: 'M4-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['M4 production probe'];
         dump(json_encode($probe));

         return 'M4 fixture failed before validation: ' . $probe['error'];
      }

      if (! str_contains($response, 'M4-HARNESS-OK')) {
         Vars::$labels = ['M4 harness response', 'M4 production probe'];
         dump(json_encode($response), json_encode($probe));

         return 'M4 fixture failed: the registered native harness route did not execute.';
      }

      $ordinary = $probe['ordinary'];
      if (
         ($ordinary['handlers'] ?? null) !== 1
         || ($ordinary['entries'] ?? null) !== 1
         || ($ordinary['cold']['valid'] ?? false) !== true
         || ($ordinary['cold']['codes'] ?? null) !== [200]
         || ($ordinary['cold']['body'] ?? null) !== 'M4-ORDINARY:handler=1'
         || ($ordinary['cold']['length_matches'] ?? false) !== true
         || ($ordinary['warm']['valid'] ?? false) !== true
         || ($ordinary['warm']['codes'] ?? null) !== [103, 200]
         || ($ordinary['warm']['body'] ?? null) !== 'M4-ORDINARY:handler=1'
         || ($ordinary['warm']['length_matches'] ?? false) !== true
      ) {
         Vars::$labels = ['M4 ordinary replay control'];
         dump(json_encode($ordinary));

         return 'M4 fixture failed: ordinary hinted route-cache replay was not proven.';
      }

      $canonical = $probe['canonical'];
      if (
         ($canonical['handlers'] ?? null) !== 2
         || ($canonical['entries'] ?? null) !== 0
         || ($canonical['middleware_count'] ?? null) !== 1
         || ($canonical['runs'] ?? null) !== 1
         || ($canonical['registrations'] ?? null) !== 1
         || ($canonical['events'] ?? null) !== 1
         || ($canonical['observed'] ?? null) !== ['M4-CANONICAL:handler=2']
         || ($canonical['attack']['valid'] ?? false) !== true
         || ($canonical['attack']['codes'] ?? null) !== [200]
         || ($canonical['attack']['body'] ?? null)
            !== 'M4-CANONICAL:handler=2;M4-EVENT=current'
         || ($canonical['attack']['length_matches'] ?? false) !== true
         || ($canonical['event_header'] ?? null) !== '1'
      ) {
         Vars::$labels = ['M4 canonical lifecycle control'];
         dump(json_encode($canonical));

         return 'M4 fixture failed: canonical middleware did not fail closed around replay.';
      }

      $direct = $probe['direct'];
      if (
         ($direct['control_body'] ?? null) !== 'M4-ORDINARY:handler=1'
         || ($direct['control_content_type'] ?? null) !== 'text/html; charset=UTF-8'
      ) {
         Vars::$labels = ['M4 direct final-only control'];
         dump(json_encode($direct));

         return 'M4 fixture failed: final-only adoption control was not valid.';
      }

      $conditional = $probe['conditional'];
      $productionSignature = (
         ($conditional['handlers'] ?? null) === 1
         && ($conditional['entries_after_prime'] ?? null) === 1
         && ($conditional['entries_after_attack'] ?? null) === 0
         && ($conditional['middleware_count'] ?? null) === 0
         && ($conditional['runs'] ?? null) === 2
         && ($conditional['registrations'] ?? null) === 1
         && ($conditional['events'] ?? null) === 1
         && isset($conditional['observed'][0])
         && str_starts_with($conditional['observed'][0], 'HTTP/1.1 200 OK')
         && ($conditional['cached']['codes'] ?? null) === [103, 200]
         && ($conditional['cached']['body'] ?? null) === 'M4-CONDITIONAL:handler=1'
         && ($conditional['cached_content_type'] ?? null) === 'text/plain'
         && ($conditional['attack']['codes'] ?? null) === [200]
         && isset($conditional['attack']['body'])
         && str_starts_with($conditional['attack']['body'], 'HTTP/1.1 200 OK')
         && str_ends_with($conditional['attack']['body'], ';M4-EVENT=current')
         && ($conditional['attack']['length_matches'] ?? false) === true
         && ($conditional['link'] ?? null) === '</m4.css>; rel=preload; as=style'
         && ($conditional['content_type'] ?? null) === 'text/html; charset=UTF-8'
         && ($conditional['event_header'] ?? null) === '1'
      );
      $directSignature = (
         isset($direct['attack_body'])
         && str_starts_with($direct['attack_body'], 'HTTP/1.1 200 OK')
         && ($direct['attack_link'] ?? null) === '</m4.css>; rel=preload; as=style'
         && ($direct['attack_content_type'] ?? null) === ''
      );

      if ($productionSignature && $directSignature) {
         Vars::$labels = [
            'M4 conditional production source-to-sink',
            'M4 direct parser corroboration',
            'M4 canonical protected control',
         ];
         dump(
            json_encode($conditional),
            json_encode($direct),
            json_encode($canonical)
         );

         return 'CONFIRMED M4: a count-invisible middleware subtype made the production '
            . 'encoder adopt a leading 103 as final, nesting cached 200 wire in the body.';
      }

      // ! The selected remediation must close BOTH roots: correct final-head
      //   parsing and the count-invisible lifecycle classification. Parser-only
      //   handling would make this event leg look correct while a subtype that
      //   mutates Body directly after $Next could still lose current output.
      $secureLifecycle = (
         ($conditional['handlers'] ?? null) === 2
         && ($conditional['entries_after_prime'] ?? null) === 0
         && ($conditional['entries_after_attack'] ?? null) === 0
         && ($conditional['middleware_count'] ?? null) === 0
         && ($conditional['runs'] ?? null) === 2
         && ($conditional['registrations'] ?? null) === 1
         && ($conditional['events'] ?? null) === 1
         && ($conditional['observed'] ?? null) === ['M4-CONDITIONAL:handler=2']
         && ($conditional['prime']['codes'] ?? null) === [200]
         && ($conditional['prime']['body'] ?? null) === 'M4-CONDITIONAL:handler=1'
         && ($conditional['attack']['codes'] ?? null) === [200]
         && ($conditional['attack']['body'] ?? null)
            === 'M4-CONDITIONAL:handler=2;M4-EVENT=current'
         && ($conditional['attack']['length_matches'] ?? false) === true
         && ($conditional['link'] ?? null) === null
         && ($conditional['content_type'] ?? null) === 'text/plain'
         && ($conditional['event_header'] ?? null) === '1'
      );
      $secureDirect = (
         ($direct['attack_body'] ?? null) === 'M4-ORDINARY:handler=1'
         && ($direct['attack_link'] ?? null) === ''
         && ($direct['attack_content_type'] ?? null) === 'text/html; charset=UTF-8'
         && ($direct['multiple_body'] ?? null) === 'M4-ORDINARY:handler=1'
         && ($direct['multiple_link'] ?? null) === ''
         && ($direct['multiple_content_type'] ?? null) === 'text/html; charset=UTF-8'
         && ($direct['missing_body'] ?? null) === 'M4-MISSING-SENTINEL'
         && ($direct['missing_link'] ?? null) === ''
         && ($direct['mismatch_body'] ?? null) === 'M4-LENGTH-SENTINEL'
         && ($direct['mismatch_link'] ?? null) === ''
         && ($direct['invalid_body'] ?? null) === 'M4-INVALID-SENTINEL'
         && ($direct['invalid_header'] ?? null) === ''
         && ($direct['transfer_body'] ?? null) === 'M4-TRANSFER-SENTINEL'
         && ($direct['transfer_header'] ?? null) === ''
      );

      if ($secureLifecycle && $secureDirect) {
         return true;
      }

      Vars::$labels = [
         'M4 conditional production result',
         'M4 direct parser result',
      ];
      dump(json_encode($conditional), json_encode($direct));

      return 'M4 result was ambiguous: controls passed, but neither the vulnerable '
         . 'nor the secure final-response adoption oracle matched. Evidence: '
         . json_encode([
            'conditional' => $conditional,
            'direct' => $direct,
         ]);
   }
);
