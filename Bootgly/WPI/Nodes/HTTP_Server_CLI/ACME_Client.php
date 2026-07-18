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


use const DNS_A;
use const DNS_AAAA;
use const FILTER_FLAG_GLOBAL_RANGE;
use const FILTER_VALIDATE_IP;
use const SOCK_STREAM;
use function array_unique;
use function array_values;
use function ctype_digit;
use function dns_get_record;
use function filter_var;
use function function_exists;
use function gethostbynamel;
use function hrtime;
use function implode;
use function in_array;
use function inet_ntop;
use function inet_pton;
use function intdiv;
use function is_array;
use function is_finite;
use function is_int;
use function is_string;
use function json_decode;
use function max;
use function microtime;
use function min;
use function ord;
use function parse_url;
use function preg_match;
use function socket_addrinfo_explain;
use function socket_addrinfo_lookup;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtotime;
use function substr;
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
    * Canonical HTTPS origins that ACME metadata may target. The configured
    * directory origin is always the first member; delegated origins require
    * explicit configuration and an exact effective-port match.
    * @var array<int,string>
    */
   public private(set) array $authorities;
   /**
    * Whether approved authorities may resolve to non-global unicast addresses.
    * Explicit trust expansion for private/test CAs only: `verify: false` does
    * not imply it. Each origin remains pinned to its first vetted address.
    */
   public private(set) bool $allowPrivate;
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
   /**
    * Exact numeric dial target pinned on first use of each approved origin.
    * @var array<string,string>
    */
   private array $addresses = [];
   /** Active order deadline; request() derives its socket timeouts from it. */
   private null|int $deadline = null;


   /** @param array<int,mixed> $authorities Runtime validation rejects non-strings. */
   public function __construct (
      Account $Account,
      string $directory,
      bool $verify = true,
      int $polls = 30,
      float $wait = 2.0,
      null|string $challenges = null,
      array $authorities = [],
      bool $allowPrivate = false
   )
   {
      $target = $this->parse($directory);
      if ($target === null) {
         throw new InvalidArgumentException(
            "ACME directory `{$directory}` must be an absolute https:// URL with a host."
         );
      }
      $origins = [$target['origin']];
      foreach ($authorities as $authority) {
         if (is_string($authority) === false) {
            throw new InvalidArgumentException(
               'Every delegated ACME authority must be an absolute https:// origin string.'
            );
         }
         $delegated = $this->parse($authority);
         if ($delegated === null || $delegated['path'] !== '/') {
            throw new InvalidArgumentException(
               "Delegated ACME authority `{$authority}` must be an absolute https:// origin without a path or query."
            );
         }
         $origins[] = $delegated['origin'];
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
      $this->authorities = array_values(array_unique($origins));
      $this->allowPrivate = $allowPrivate;
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
         // ! A persisted kid is untrusted legacy/CA state. Validate it even
         //   when unchanged contact means no network request would follow.
         $this->authorize($accountURL);
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
      // ! Never persist a foreign kid that could become a future update sink
      //   or be embedded into JWS requests sent to an approved authority.
      $this->authorize($URL);

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
         $this->authorize($orderURL);
         $this->authorize($finalize);
         foreach ($authorizations as $authorization) {
            if (is_string($authorization) === false) {
               throw new ProtocolException('ACME authorization URL is not a string.');
            }
            $this->authorize($authorization);
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
            // ! Reject a foreign challenge URL before publishing a token.
            $this->authorize($trigger);

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
         $this->authorize($certificate);

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
      // ! Directory JSON is CA-controlled. Validate every active advertised
      //   endpoint immediately so no later operation can partially act on it.
      $this->authorize($this->Directory->newAccount);
      $this->authorize($this->Directory->newNonce);
      $this->authorize($this->Directory->newOrder);

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
      // ! Fail before key materialization/nonce fetch/signing when the caller
      //   presents an out-of-policy endpoint.
      $this->authorize($URL);

      // ! Materialize the account key BEFORE reading the kid: generating a
      //   fresh key drops a stale persisted URL — reading the URL first
      //   would sign one doomed request with the obsolete kid
      $Key = $this->Account->Key;
      $kid = $this->Account->URL;
      if ($kid !== null) {
         // ! The persisted kid is untrusted state even when callers invoke
         //   order() directly without the normal register() preflight.
         $this->authorize($kid);
      }

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
    * Parse and canonicalize one HTTPS ACME URL without performing I/O.
    *
    * @return null|array{host:string,lookup:string,port:int,origin:string,authority:string,path:string}
    */
   private function parse (string $URL): null|array
   {
      if (
         $URL === '' || strlen($URL) > 8192
         || preg_match('/[\x00-\x20\x7f]/', $URL) === 1
         || str_contains($URL, '\\')
      ) {
         return null;
      }

      try {
         $parts = parse_url($URL);
      }
      catch (Throwable) {
         return null;
      }
      if (
         is_array($parts) === false
         || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
         || is_string($parts['host'] ?? null) === false
         || $parts['host'] === ''
         || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
      ) {
         return null;
      }

      $given = $parts['host'];
      $lookup = '';
      if (str_starts_with($given, '[')) {
         if (str_ends_with($given, ']') === false) {
            return null;
         }
         $host = $this->normalize(substr($given, 1, -1));
         if ($host === null || str_contains($host, ':') === false) {
            return null;
         }
         $lookup = $host;
      }
      else {
         $host = $this->normalize($given);
         if ($host === null) {
            $absolute = str_ends_with($given, '.');
            if (str_ends_with($given, '.')) {
               $given = substr($given, 0, -1);
               if ($given === '' || str_ends_with($given, '.')) {
                  return null;
               }
            }
            $host = strtolower($given);
            if (
               preg_match(
                  '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/',
                  $host
               ) !== 1
            ) {
               return null;
            }
            // A terminal dot is omitted from origin/Host/TLS identity but
            // retained for DNS lookup, preventing resolver search suffixes
            // from changing the configured absolute name.
            $lookup = $absolute ? "{$host}." : $host;
         }
         else {
            $lookup = $host;
         }
      }

      /** @var mixed $port parse_url() accepts zero despite its static stub. */
      $port = $parts['port'] ?? 443;
      if (is_int($port) === false || $port < 1 || $port > 65535) {
         return null;
      }
      $label = str_contains($host, ':') ? "[{$host}]" : $host;
      $authority = $port === 443 ? $label : "{$label}:{$port}";
      $path = $parts['path'] ?? '/';
      if ($path === '') {
         $path = '/';
      }
      if (isset($parts['query'])) {
         $path .= "?{$parts['query']}";
      }

      return [
         'host' => $host,
         'lookup' => $lookup,
         'port' => $port,
         'origin' => "https://{$authority}",
         'authority' => $authority,
         'path' => $path,
      ];
   }

   /** Canonicalize an IPv4/IPv6 literal, including IPv4-mapped IPv6. */
   private function normalize (string $IP): null|string
   {
      $packed = @inet_pton($IP);
      if ($packed === false) {
         return null;
      }
      if (
         strlen($packed) === 16
         && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
      ) {
         $packed = substr($packed, 12);
      }

      $normalized = inet_ntop($packed);

      return is_string($normalized) ? $normalized : null;
   }

   /**
    * Enforce exact configured-origin policy without resolving DNS.
    *
    * @return array{host:string,lookup:string,port:int,origin:string,authority:string,path:string}
    */
   private function authorize (string $URL): array
   {
      $target = $this->parse($URL);
      if ($target === null) {
         throw new ProtocolException(
            "ACME URL `{$URL}` is not an unambiguous absolute https:// URL."
         );
      }
      if (in_array($target['origin'], $this->authorities, true) === false) {
         throw new ProtocolException(
            "ACME URL `{$URL}` targets unapproved authority `{$target['origin']}`."
         );
      }

      return $target;
   }

   /** Resolve one approved host and return one exact vetted dial address. */
   private function resolve (string $host, null|string $origin = null): string
   {
      if ($origin !== null && isset($this->addresses[$origin])) {
         return $this->addresses[$origin];
      }

      $literal = $this->normalize($host);
      $addresses = $literal === null ? [] : [$literal];
      if ($literal === null) {
         $resolved = false;
         if (function_exists('socket_addrinfo_lookup')) {
            try {
               $AddressInfos = @socket_addrinfo_lookup($host, null, [
                  'ai_socktype' => SOCK_STREAM,
               ]);
            }
            catch (Throwable) {
               $AddressInfos = false;
            }
            foreach (is_array($AddressInfos) ? $AddressInfos : [] as $AddressInfo) {
               $explained = socket_addrinfo_explain($AddressInfo);
               $IP = $explained['ai_addr']['sin_addr']
                  ?? ($explained['ai_addr']['sin6_addr'] ?? null);
               if (is_string($IP)) {
                  $addresses[] = $IP;
               }
            }
            $resolved = true;
         }
         if ($resolved === false) {
            $IPv4 = @gethostbynamel($host);
            foreach (is_array($IPv4) ? $IPv4 : [] as $IP) {
               $addresses[] = $IP;
            }

            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            foreach (is_array($records) ? $records : [] as $record) {
               $IP = $record['ip'] ?? ($record['ipv6'] ?? null);
               if (is_string($IP)) {
                  $addresses[] = $IP;
               }
            }
         }
      }

      $normalized = [];
      foreach ($addresses as $IP) {
         $address = $this->normalize($IP);
         if ($address === null) {
            throw new ProtocolException(
               "ACME authority `{$host}` resolved to an invalid address."
            );
         }
         $normalized[] = $address;
      }
      $addresses = array_values(array_unique($normalized));
      if ($addresses === []) {
         throw new ConnectionException(
            "ACME authority `{$host}` could not be resolved."
         );
      }
      foreach ($addresses as $IP) {
         if ($this->permit($IP) === false) {
            throw new ProtocolException(
               "ACME authority `{$host}` resolved to prohibited address `{$IP}`."
            );
         }
      }

      $IP = $addresses[0];
      if ($origin !== null) {
         $this->addresses[$origin] = $IP;
      }

      return $IP;
   }

   /** Whether one canonical IP is an admitted unicast destination. */
   private function permit (string $IP): bool
   {
      $packed = @inet_pton($IP);
      if ($packed === false) {
         return false;
      }
      $first = ord($packed[0]);
      if (strlen($packed) === 4) {
         // 0/8 is not a remote destination; 224/4 is multicast and 240/4
         // is reserved/broadcast. They remain invalid even in test mode.
         if ($first === 0 || $first >= 224) {
            return false;
         }
      }
      else if ($IP === '::' || $first === 255) {
         // Unspecified and multicast IPv6 are never valid ACME peers.
         return false;
      }

      if ($this->allowPrivate === false) {
         if (
            strlen($packed) === 4
            && substr($packed, 0, 3) === "\xc0\x58\x63"
         ) {
            // Deprecated 6to4 relay anycast is special-use even though PHP's
            // GLOBAL_RANGE classifier currently admits 192.88.99/24.
            return false;
         }
         if (strlen($packed) === 16) {
            $wellKnown = inet_pton('64:ff9b::');
            $localUse = inet_pton('64:ff9b:1::');
            if (
               is_string($wellKnown) && substr($packed, 0, 12) === substr($wellKnown, 0, 12)
               || is_string($localUse) && substr($packed, 0, 6) === substr($localUse, 0, 6)
            ) {
               // NAT64 can translate an apparently global IPv6 destination
               // into an embedded private/loopback IPv4 address.
               return false;
            }
         }
      }

      if ($this->allowPrivate) {
         return true;
      }

      // @phpstan-ignore notIdentical.alwaysTrue (GLOBAL_RANGE is narrower than valid-IP)
      return filter_var($IP, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
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
      // ! One final central gate for every current/future ACME URL sink. DNS
      //   is resolved now and the transport dials that exact vetted literal;
      //   it never resolves the attacker-influenced hostname a second time.
      $target = $this->authorize($URL);
      $IP = $this->resolve($target['lookup'], $target['origin']);
      $dial = str_contains($IP, ':') ? "[{$IP}]" : $IP;

      // ! TLS toward the CA — verification only relaxed for test CAs
      $secure = $this->verify
         ? ['peer_name' => $target['host']]
         : [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'peer_name' => $target['host']
         ];

      // ! MODE_TEST = embedded/library mode: no Process state lock, no
      //   signal handling and no shutdown SIGINT broadcast — the client
      //   runs inside the certifier child beside a live server master
      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      // ! ACME speaks HTTP/1.1 by design: no ALPN h2 offer — directory
      //   endpoints and the local swap helpers are h1-only transports
      $Client->configure(
         host: $dial,
         port: $target['port'],
         workers: 0,
         secure: $secure,
         enableHTTP2: false
      );
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

      // ! The numeric dial target must not change HTTP authority or TLS/JWS
      //   identity. Host carries the original canonical authority; peer_name
      //   above preserves SNI/certificate verification; JWS keeps `$URL`.
      $headers = ['Host' => $target['authority']] + $headers;
      $Response = $Client->request($method, $target['path'], $headers, $body);
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
