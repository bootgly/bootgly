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


use function ctype_digit;
use function hrtime;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_finite;
use function is_string;
use function json_decode;
use function max;
use function microtime;
use function min;
use function parse_url;
use function preg_match;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function strtotime;
use function time;
use function usleep;
use InvalidArgumentException;
use Throwable;

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\CSR;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Directory;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ConnectionException;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\OrderException;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ProtocolException;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ServerException;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\JWS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Nonces;


/**
 * ACME v2 (RFC 8555) client — the Auto-TLS protocol orchestrator.
 *
 * Drives the certificate issuance flow against an ACME Certificate
 * Authority (Let's Encrypt by default): account registration, order
 * placement, HTTP-01 challenge publication, finalization with a CSR and
 * certificate chain download. All network I/O of the ACME subsystem lives
 * here; the helpers in `ACME_Client/` are pure protocol primitives.
 */
class ACME_Client
{
   /**
    * Total budget for one issuance (`order()` — placement, authorizations,
    * finalize and download), in seconds. The order-level budget is
    * `hrtime()`-based (monotonic); each transport phase inherits that same
    * hard deadline in addition to a compatibility wall-clock deadline.
    */
   public const int PATIENCE = 300;
   /** Maximum server-directed retry retained locally (one year). */
   public const int MAX_RETRY_AFTER = 31536000;

   // * Config
   /**
    * ACME directory URL.
    */
   public private(set) string $directory;
   /**
    * TLS peer verification toward the CA (false only for test CAs).
    */
   public private(set) bool $verify;
   /**
    * Maximum poll attempts per authorization/order.
    */
   public private(set) int $polls;
   /**
    * Default poll interval in seconds (the CA `Retry-After` wins).
    */
   public private(set) float $wait;
   /** Exact HTTP-01 spool used by this issuer. */
   public private(set) null|string $challenges;

   // * Data
   /**
    * The ACME account driving every signed request.
    */
   public private(set) Account $Account;

   // * Metadata
   private null|Directory $Directory = null;
   private Nonces $Nonces;
   private JWS $JWS;
   /** Active order deadline; request() derives its socket timeouts from it. */
   private null|int $deadline = null;


   public function __construct (
      Account $Account,
      string $directory,
      bool $verify = true,
      int $polls = 30,
      float $wait = 2.0,
      null|string $challenges = null
   )
   {
      $parts = parse_url($directory);
      if (
         is_array($parts) === false
         || ($parts['scheme'] ?? null) !== 'https'
         || is_string($parts['host'] ?? null) === false
         || $parts['host'] === ''
         || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
         || preg_match('/[\x00-\x20\x7f]/', $directory) === 1
      ) {
         throw new InvalidArgumentException(
            "ACME directory `{$directory}` must be an absolute https:// URL with a host."
         );
      }
      if ($polls < 1) {
         throw new InvalidArgumentException('ACME `polls` must be at least 1.');
      }
      if (is_finite($wait) === false || $wait <= 0) {
         throw new InvalidArgumentException('ACME `wait` must be a finite positive number.');
      }

      // * Config
      $this->directory = $directory;
      $this->verify = $verify;
      $this->polls = $polls;
      $this->wait = $wait;
      $this->challenges = $challenges;

      // * Data
      $this->Account = $Account;

      // * Metadata
      $this->Nonces = new Nonces();
      $this->JWS = new JWS($Account);
   }

   /**
    * Register the ACME account (RFC 8555 §7.3) and persist its URL (`kid`).
    *
    * `newAccount` is idempotent per key: a CA answering for an existing
    * account returns 200 with the same `Location`, so a lost local URL is
    * recovered by the same call that creates a fresh account.
    *
    * @throws Throwable Typed ACME exceptions (`Exceptioning`) plus local
    *                   crypto/serialization failures from the helpers.
    */
   public function register (string $email, bool $agreement): string
   {
      // ? Existing account: keep the CA contact synchronized instead of
      //   silently treating a changed configured email as already applied.
      $accountURL = $this->Account->URL;
      if ($accountURL !== null) {
         if ($this->Account->contact !== $email) {
            $updated = $this->post($accountURL, [
               'contact' => ["mailto:{$email}"]
            ]);
            if ($updated['code'] !== 200) {
               throw new ProtocolException(
                  "ACME account update answered HTTP {$updated['code']} — expected 200."
               );
            }
            $this->Account->update($email);
         }

         return $accountURL;
      }

      $Directory = $this->connect();

      $result = $this->expect(
         $this->post($Directory->newAccount, [
            'termsOfServiceAgreed' => $agreement,
            'contact' => ["mailto:{$email}"]
         ]),
         [200, 201],
         'newAccount'
      );

      $URL = $result['location'];
      if ($URL === null) {
         throw new ProtocolException(
            'ACME newAccount response is missing the account `Location`.'
         );
      }

      $this->Account->save($URL);
      $this->Account->update($email);

      // :
      return $URL;
   }

   /**
    * Run a full issuance: newOrder → authorizations → HTTP-01 publication →
    * challenge trigger → polls → finalize with a fresh CSR → certificate
    * chain download (RFC 8555 §7.4).
    *
    * @param array<int,string> $domains
    *
    * @return array{certificate:string, key:string} Fullchain PEM + key PEM.
    *
    * @throws Throwable Typed ACME exceptions (`Exceptioning`) plus local
    *                   crypto/serialization failures from the helpers.
    */
   public function order (array $domains, int $bits = 2048): array
   {
      // ! ONE monotonic deadline for the WHOLE issuance, started before
      //   the first network step — serial per-SAN authorization polling
      //   can never stack one budget per poll loop, and the non-polling
      //   steps (authorizations, finalize, download) are guarded too
      $deadline = hrtime(true) + self::PATIENCE * 1_000_000_000;
      $this->deadline = $deadline;
      $tokens = [];

      try {
         // Validate the complete CSR contract before placing an order. The
         // expensive key generation remains lazy until finalization.
         $CSR = new CSR($domains, $bits);
         $Directory = $this->connect();
         $this->expire($deadline);

         // @ newOrder
         $identifiers = [];
         foreach ($domains as $domain) {
            $identifiers[] = ['type' => 'dns', 'value' => $domain];
         }

         $placed = $this->expect(
            $this->post($Directory->newOrder, ['identifiers' => $identifiers]),
            [201],
            'newOrder'
         );

         $order = $placed['JSON'];
         $orderURL = $placed['location'];
         $finalize = is_array($order) ? ($order['finalize'] ?? null) : null;
         $authorizations = is_array($order) ? ($order['authorizations'] ?? null) : null;
         if ($orderURL === null || is_string($finalize) === false || is_array($authorizations) === false) {
            throw new ProtocolException(
               'ACME newOrder response is missing `Location`, `finalize` or `authorizations`.'
            );
         }

         // @@ Authorizations — publish and answer one HTTP-01 challenge each
         foreach ($authorizations as $authorization) {
            $this->expire($deadline);

            if (is_string($authorization) === false) {
               throw new ProtocolException('ACME authorization URL is not a string.');
            }

            $authz = $this->expect(
               $this->post($authorization, null),
               [200],
               'authorization'
            )['JSON'];
            if (is_array($authz) === false) {
               throw new ProtocolException('ACME authorization response is not JSON.');
            }

            // ? Already valid (reused authorization)
            if (($authz['status'] ?? null) === 'valid') {
               continue;
            }

            // ! The http-01 challenge
            $challenge = null;
            foreach (is_array($authz['challenges'] ?? null) ? $authz['challenges'] : [] as $candidate) {
               if (is_array($candidate) && ($candidate['type'] ?? null) === 'http-01') {
                  $challenge = $candidate;
                  break;
               }
            }
            $token = is_array($challenge) ? ($challenge['token'] ?? null) : null;
            $trigger = is_array($challenge) ? ($challenge['url'] ?? null) : null;
            if (is_string($token) === false || is_string($trigger) === false) {
               throw new OrderException(
                  'ACME authorization offers no usable http-01 challenge.'
               );
            }

            // @ Publish the key authorization for the HTTP-01 responders
            if (Challenges::save(
               $token,
               "{$token}.{$this->Account->thumbprint}",
               $this->challenges
            ) === false) {
               throw new OrderException(
                  'ACME challenge publication failed: the challenge directory is not configured.'
               );
            }
            $tokens[] = $token;

            // @ Trigger the validation and wait for `valid`
            $this->expect($this->post($trigger, []), [200], 'challenge trigger');
            $this->poll($authorization, ['valid'], ['pending', 'processing'], $deadline);
         }

         // @ Wait for the order to reach `ready` — a conforming asynchronous
         //   CA may still be processing the last authorization (RFC 8555 §7.4)
         $prepared = $this->poll($orderURL, ['ready', 'valid'], ['pending', 'processing'], $deadline);

         // ? A brand-new order settling `valid` BEFORE finalize means the CA
         //   issued for a CSR this client never sent — no local key could
         //   match the certificate, so downloading it would install a broken
         //   pair; surface the protocol violation instead
         if (($prepared['status'] ?? null) === 'valid') {
            throw new ProtocolException(
               "ACME order at `{$orderURL}` reached `valid` before finalization — no local key matches the issued certificate."
            );
         }

         // @ Finalize with a fresh CSR
         $this->expire($deadline);
         $this->expire($deadline);
         try {
            $this->expect(
               $this->post($finalize, ['csr' => $CSR->DER]),
               [200],
               'finalize'
            );
         }
         catch (ServerException $Exception) {
            // ?! orderNotReady — one transition race is recovered by
            //   re-polling to `ready` and finalizing once more (§7.4)
            if (str_ends_with($Exception->type, ':orderNotReady') === false) {
               throw $Exception;
            }

            $this->poll($orderURL, ['ready'], ['pending', 'processing'], $deadline);
            $this->expire($deadline);
            $this->expect(
               $this->post($finalize, ['csr' => $CSR->DER]),
               [200],
               'finalize retry'
            );
         }

         // @ Wait for the order to become valid
         $settled = $this->poll($orderURL, ['valid'], ['pending', 'ready', 'processing'], $deadline);

         $certificate = $settled['certificate'] ?? null;
         if (is_string($certificate) === false) {
            throw new ProtocolException(
               'ACME order settled without a `certificate` URL.'
            );
         }

         // @ Download the certificate chain
         $this->expire($deadline);
         $downloaded = $this->expect(
            $this->post(
               $certificate,
               null,
               ['Accept' => 'application/pem-certificate-chain']
            ),
            [200],
            'certificate download'
         );

         // :
         return [
            'certificate' => $downloaded['body'],
            'key' => $CSR->key
         ];
      }
      finally {
         // @ Published tokens never outlive the order
         foreach ($tokens as $token) {
            Challenges::drop($token, $this->challenges);
         }
         $this->deadline = null;
      }
   }

   /**
    * Fetch and validate the directory endpoint map (cached).
    */
   private function connect (): Directory
   {
      if ($this->Directory !== null) {
         return $this->Directory;
      }

      $Response = $this->request('GET', $this->directory);

      // ? RFC 8555 §7.1.1 — the directory is a 200 `application/json`
      //   object; anything else (proxy page, error body) is never parsed
      //   into an endpoint map. A problem document keeps its type/detail
      //   and `Retry-After` instead of degrading to a generic error.
      if ($Response->code !== 200) {
         $this->raise(
            $Response,
            "ACME directory at `{$this->directory}` answered HTTP {$Response->code} — expected 200."
         );
      }
      $media = strtolower((string) $Response->Header->get('content-type'));
      if (str_starts_with($media, 'application/json') === false) {
         throw new ProtocolException(
            "ACME directory at `{$this->directory}` answered `{$media}` — expected `application/json`."
         );
      }

      $endpoints = json_decode($Response->body, true);
      if (is_array($endpoints) === false) {
         throw new ProtocolException(
            "ACME directory at `{$this->directory}` did not return a JSON object."
         );
      }

      /** @var array<string,mixed> $endpoints */
      $this->Directory = new Directory($endpoints);

      // :
      return $this->Directory;
   }

   /**
    * Fetch a fresh replay nonce via HEAD on `newNonce` (RFC 8555 §7.2).
    */
   private function fetch (): string
   {
      $Directory = $this->connect();

      // ! request() harvests the Replay-Nonce into the pool
      $Response = $this->request('HEAD', $Directory->newNonce);

      // ? RFC 8555 §7.2 — HEAD on newNonce answers 200 (some CAs 204). A
      //   problem document keeps its type/detail and `Retry-After`.
      if ($Response->code !== 200 && $Response->code !== 204) {
         $this->raise(
            $Response,
            "ACME newNonce at `{$Directory->newNonce}` answered HTTP {$Response->code} — expected 200/204."
         );
      }

      $nonce = $this->Nonces->take();
      if ($nonce === null) {
         throw new ProtocolException(
            'ACME newNonce response is missing the `Replay-Nonce` header.'
         );
      }

      // :
      return $nonce;
   }

   /**
    * Signed POST (or POST-as-GET when `$payload` is null) with nonce
    * management: pool consumption, harvest and the single transparent
    * `badNonce` retry (RFC 8555 §6.5).
    *
    * @param array<string,mixed>|null $payload
    * @param array<string,string> $headers
    *
    * @return array{code:int, location:null|string, body:string, JSON:null|array<string,mixed>, retryAfter:null|int}
    */
   private function post (string $URL, null|array $payload, array $headers = []): array
   {
      // ! Materialize the account key BEFORE reading the kid: generating a
      //   fresh key drops a stale persisted URL — reading the URL first
      //   would sign one doomed request with the obsolete kid
      $Key = $this->Account->Key;

      $nonce = $this->Nonces->take() ?? $this->fetch();

      $retried = false;
      while (true) {
         $signed = $this->JWS->sign($URL, $nonce, $payload, $this->Account->URL);

         $Response = $this->request(
            'POST',
            $URL,
            ['Content-Type' => 'application/jose+json'] + $headers,
            $signed
         );

         $code = $Response->code;
         $body = $Response->body;
         $decoded = json_decode($body, true);
         /** @var array<string,mixed>|null $JSON */
         $JSON = is_array($decoded) ? $decoded : null;

         // ? Unexpected redirect — never followed (JWS `url` integrity)
         if ($code >= 300 && $code < 400) {
            throw new ProtocolException(
               "ACME endpoint `{$URL}` answered an unexpected redirect (HTTP {$code})."
            );
         }

         // ? Problem document (RFC 8555 §6.7)
         if ($code >= 400) {
            $type = is_string($JSON['type'] ?? null) ? $JSON['type'] : 'about:blank';
            $detail = is_string($JSON['detail'] ?? null) ? $JSON['detail'] : $body;

            // ?! badNonce — retry exactly once, ONLY with the nonce this
            //   error response itself carried (RFC 8555 §6.5). Every pooled
            //   nonce predates the failure and is suspect: the pool is
            //   cleared first, so a missing/invalid response nonce falls
            //   back to a fresh fetch — never to an older pooled one
            if (str_ends_with($type, ':badNonce') && $retried === false) {
               $retried = true;
               $this->Nonces->clear();
               $fresh = $Response->Header->get('replay-nonce');
               $nonce = is_string($fresh) && preg_match('/^[A-Za-z0-9_\-]+$/', $fresh) === 1
                  ? $fresh
                  : $this->fetch();
               continue;
            }

            throw new ServerException($type, $detail, $code, $this->delay($Response));
         }

         // :
         return [
            'code' => $code,
            'location' => $Response->Header->get('location'),
            'body' => $body,
            'JSON' => $JSON,
            'retryAfter' => $this->delay($Response)
         ];
      }
   }

   /**
    * POST-as-GET poll until the resource `status` reaches a goal state.
    *
    * @param array<int,string> $goals Accepting states.
    * @param array<int,string> $pending States worth another poll.
    * @param null|int $deadline Absolute `hrtime` deadline shared by the
    *                           WHOLE issuance — serial authorization polls
    *                           draw from one budget, never one each.
    *
    * @return array<string,mixed> The settled resource.
    */
   private function poll (string $URL, array $goals, array $pending, null|int $deadline = null): array
   {
      // ! Attempt budget AND a total monotonic wall-clock deadline, with
      //   each wait capped to the time remaining — a huge (hostile/buggy)
      //   `Retry-After` can never pin the certifier and block every future
      //   renewal attempt
      $budget = $this->polls;
      $deadline ??= hrtime(true) + self::PATIENCE * 1_000_000_000;
      while ($budget-- > 0 && hrtime(true) < $deadline) {
         $result = $this->expect($this->post($URL, null), [200], 'resource poll');

         $resource = $result['JSON'];
         if ($resource === null) {
            throw new ProtocolException("ACME resource at `{$URL}` is not JSON.");
         }

         $status = $resource['status'] ?? null;

         // ?: Settled
         if (in_array($status, $goals, true)) {
            return $resource;
         }

         // ? Failed — surface the challenge error detail when present
         if (in_array($status, $pending, true) === false) {
            $detail = '';
            foreach (is_array($resource['challenges'] ?? null) ? $resource['challenges'] : [] as $challenge) {
               $error = is_array($challenge) ? ($challenge['error'] ?? null) : null;
               if (is_array($error) && is_string($error['detail'] ?? null)) {
                  $detail = ": {$error['detail']}";
                  break;
               }
            }

            $state = is_string($status) ? $status : 'unknown';
            throw new OrderException(
               "ACME resource at `{$URL}` settled as `{$state}`{$detail}"
            );
         }

         // @ Wait — the CA Retry-After wins over the default interval;
         //   BOTH branches are capped at the time remaining in the deadline
         //   (the Retry-After branch additionally at 30s)
         $remaining = intdiv((int) ($deadline - hrtime(true)), 1000);
         if ($remaining <= 0) {
            $this->expire((int) $deadline);
         }
         $delay = $result['retryAfter'];
         $wanted = $delay !== null && $delay > 0
            ? min($delay, 30) * 1_000_000
            : $this->wait * 1_000_000;
         $this->pause((int) min($wanted, $remaining), (int) $deadline);
      }

      throw new OrderException(
         "ACME resource at `{$URL}` did not settle within {$this->polls} polls / " . self::PATIENCE . 's.'
      );
   }

   /**
    * Run one HTTP request against an absolute URL and harvest its nonce.
    *
    * @param array<string,string> $headers
    */
   private function request (
      string $method,
      string $URL,
      array $headers = [],
      mixed $body = null
   ): Response
   {
      // ? HTTPS only — RFC 8555 §6.1 requires TLS for every ACME endpoint,
      //   including each URL the directory advertises
      $parts = parse_url($URL);
      $scheme = $parts['scheme'] ?? '';
      $host = $parts['host'] ?? '';
      if (
         $host === '' || $scheme !== 'https'
         || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
         || preg_match('/[\x00-\x20\x7f]/', $URL) === 1
      ) {
         throw new ProtocolException("ACME URL `{$URL}` is not an https:// URL.");
      }

      $port = $parts['port'] ?? 443;
      $path = ($parts['path'] ?? '/')
         . (isSet($parts['query']) ? "?{$parts['query']}" : '');

      // ! TLS toward the CA — verification only relaxed for test CAs
      $secure = $this->verify
         ? []
         : [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
         ];

      // ! MODE_TEST = embedded/library mode: no Process state lock, no
      //   signal handling and no shutdown SIGINT broadcast — the client
      //   runs inside the certifier child beside a live server master
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      // ! ACME speaks HTTP/1.1 by design: no ALPN h2 offer — directory
      //   endpoints and the local swap helpers are h1-only transports
      $Client->configure(host: $host, port: $port, workers: 0, secure: $secure, enableHTTP2: false);
      // Fullchain responses are capped at 1 MiB by the certificate store;
      // reserve 64 KiB for status/headers and reject before accumulation.
      $Client->maxResponseBytes = 1114112;

      // ! A request started by order() receives only its remaining budget;
      //   neither connect nor response timeout may independently add 30s.
      if ($this->deadline !== null) {
         $this->expire($this->deadline);
         $remaining = ($this->deadline - hrtime(true)) / 1_000_000_000;
         $timeout = max(0.001, min(30.0, $remaining));
         $Client->connectTimeout = $timeout;
         $Client->timeout = $timeout;
         $Client->deadline = microtime(true) + $remaining;
         $Client->monotonicDeadline = $this->deadline;
      }

      // ! No transparent redirects — a 3xx would either convert the signed
      //   POST into a GET or leave a JWS whose protected `url` names the
      //   old target (RFC 8555 §6.4); unexpected 3xx surfaces as an error
      $Client->maxRedirects = 0;

      $Response = $Client->request($method, $path, $headers, $body);
      if ($this->deadline !== null) {
         $this->expire($this->deadline);
      }
      if ($Response instanceof Response === false || $Response->code === 0) {
         $status = $Response instanceof Response ? (string) $Response->status : 'no response';
         throw new ConnectionException(
            "ACME request `{$method} {$URL}` failed: {$status}."
         );
      }

      // @ Harvest the replay nonce from every response (RFC 8555 §6.5)
      $nonce = $Response->Header->get('replay-nonce');
      if ($nonce !== null && $nonce !== '') {
         $this->Nonces->store($nonce);
      }

      // :
      return $Response;
   }

   /**
    * Enforce the endpoint-specific successful status contract.
    *
    * @param array{code:int,location:null|string,body:string,JSON:null|array<string,mixed>,retryAfter:null|int} $result
    * @param array<int,int> $codes
    * @return array{code:int,location:null|string,body:string,JSON:null|array<string,mixed>,retryAfter:null|int}
    */
   private function expect (array $result, array $codes, string $operation): array
   {
      if (in_array($result['code'], $codes, true) === false) {
         throw new ProtocolException(
            "ACME {$operation} answered HTTP {$result['code']} — expected " . implode('/', $codes) . '.'
         );
      }

      return $result;
   }

   /**
    * Throw when the issuance deadline has passed — guards the non-polling
    * order steps (authorizations, finalize, download) between requests.
    */
   private function expire (int $deadline): void
   {
      if (hrtime(true) >= $deadline) {
         throw new OrderException(
            'ACME issuance exceeded its ' . self::PATIENCE . 's budget.'
         );
      }
   }

   /** Sleep through watchdog interruptions without crossing the order bound. */
   private function pause (int $microseconds, int $deadline): void
   {
      $until = (int) min($deadline, hrtime(true) + $microseconds * 1000);
      while (($remaining = intdiv((int) ($until - hrtime(true)), 1000)) > 0) {
         usleep($remaining);
      }
   }

   /**
    * Parse a response `Retry-After` into seconds from now.
    */
   /**
    * Raise the typed error for an unexpected UNSIGNED endpoint response
    * (directory/newNonce): an RFC 7807 problem document keeps its
    * `type`/`detail` and the response `Retry-After` as a `ServerException`
    * — exactly like signed POST errors — anything else stays a
    * `ProtocolException` with the caller's context message.
    */
   private function raise (Response $Response, string $message): never
   {
      $media = strtolower((string) $Response->Header->get('content-type'));
      if (str_starts_with($media, 'application/problem+json')) {
         $decoded = json_decode($Response->body, true);
         $type = is_array($decoded) && is_string($decoded['type'] ?? null)
            ? $decoded['type']
            : 'about:blank';
         $detail = is_array($decoded) && is_string($decoded['detail'] ?? null)
            ? $decoded['detail']
            : ($Response->body !== '' ? $Response->body : $message);

         throw new ServerException($type, $detail, $Response->code, $this->delay($Response));
      }

      throw new ProtocolException($message);
   }

   private function delay (Response $Response): null|int
   {
      $value = $Response->Header->get('retry-after');
      if ($value === null || $value === '') {
         return null;
      }

      if (ctype_digit($value)) {
         return min((int) $value, self::MAX_RETRY_AFTER);
      }

      $timestamp = strtotime($value);

      // ?:
      return $timestamp !== false
         ? min(max(0, $timestamp - time()), self::MAX_RETRY_AFTER)
         : null;
   }
}
