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


use const BOOTGLY_STORAGE_DIR;
use const FILTER_VALIDATE_EMAIL;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use function array_key_exists;
use function array_unique;
use function array_values;
use function bin2hex;
use function chmod;
use function explode;
use function fclose;
use function fflush;
use function file_get_contents;
use function filter_var;
use function flock;
use function fopen;
use function fstat;
use function fsync;
use function function_exists;
use function fwrite;
use function hash;
use function inet_pton;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function lstat;
use function mkdir;
use function parse_url;
use function preg_match;
use function random_bytes;
use function rename;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function substr_count;
use function time;
use function trim;
use function umask;
use function unlink;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Certificates;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\CertificateSnapshot;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ServerException;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Swaps;


/**
 * Auto-TLS configuration — the typed form of the HTTP Server `secure` config.
 *
 * Passing an AutoTLS instance to `HTTP_Server_CLI::configure(secure:)` makes
 * the server manage its own certificate: it boots on a temporary self-signed
 * certificate, obtains a real one from an ACME CA (Let's Encrypt by default)
 * in the background, hot-swaps it into the live listening sockets and renews
 * it automatically before expiry.
 *
 * Trust boundary: the configured runtime UID is trusted. The implementation
 * rejects link substitution, validates exact bytes and isolates active worker
 * credentials from later store replacement, but code already executing as the
 * same UID can also alter process memory or its private artifacts. Isolate
 * mutually untrusted applications under different UIDs/containers.
 *
 * Misconfiguration fails here, at construction — never later at the CA.
 */
class AutoTLS
{
   public const string DIRECTORY = 'https://acme-v02.api.letsencrypt.org/directory';
   public const string STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';

   public const int DEFAULT_THRESHOLD = 30;
   public const int DEFAULT_BITS = 2048;
   public const int DEFAULT_PORT = 80;

   // * Config
   /**
    * Certificate SAN domain set; `domains[0]` is the Common Name and names
    * the certificate storage directory. Wildcards are rejected — HTTP-01
    * cannot validate them (DNS-01 is deferred).
    * @var array<int,string>
    */
   public private(set) array $domains;
   /**
    * ACME account contact email.
    */
   public private(set) string $email;
   /**
    * Whether the Let's Encrypt staging environment is used — staged
    * certificates are stored apart and never satisfy production checks.
    */
   public private(set) bool $staging;
   /**
    * ACME directory URL. An explicit value wins over `$staging`.
    */
   public private(set) string $directory;
   /**
    * Storage base path (default: `BOOTGLY_STORAGE_DIR . 'security/tls/'`).
    */
   public private(set) string $path;
   /**
    * HTTP-01 token spool. Instances sharing one validation port must point at
    * the same directory; an explicit path enables safe cross-instance sharing.
    */
   public private(set) string $challenges;
   /**
    * Renew when fewer than this many days remain before expiry (1-89).
    */
   public private(set) int $threshold;
   /**
    * RSA key size for the account and certificate keys (>= 2048).
    */
   public private(set) int $bits;
   /**
    * RFC 8555 §7.3 `termsOfServiceAgreed`. Auto-TLS follows the
    * Caddy/Traefik model: configuring issuance IS the agreement, so this
    * defaults to true; passing `false` refuses construction — the CA
    * makes issuance impossible without it.
    */
   public private(set) bool $agreement;
   /**
    * HTTP-01 validation port the CA connects to (80 for real CAs;
    * configurable for test CAs — Pebble validates on 5002).
    */
   public private(set) int $port;
   /**
    * TLS peer verification when talking to the ACME directory
    * (false only for test CAs with a private root, e.g. Pebble).
    */
   public private(set) bool $verify;
   /**
    * Extra HTTPS origins explicitly trusted for CA-delegated ACME endpoints.
    * The directory origin is always included by the protocol client.
    * @var array<int,string>
    */
   public private(set) array $authorities;
   /** Explicit private/test-CA egress opt-in; origins still pin their first IP. */
   public private(set) bool $allowPrivate;
   /**
    * Extra SSL stream-context options merged into the server `$context`.
    * @var array<string,mixed>
    */
   public private(set) array $options;

   // * Metadata
   /**
    * Configuration identity — SHA-256 over the canonical (sorted) SAN set
    * plus the ACME directory URL. Persisted in the certificate manifest and
    * embedded in the store directory name: changing a SAN or switching CAs
    * can never silently reuse a certificate issued for another identity.
    */
   public private(set) string $identity;
   /**
    * Per-INSTANCE swap-rendezvous namespace — random at construction and
    * fork-inherited, so one running server (master + its workers/certifier)
    * shares it while unrelated servers on the same storage base can never
    * clobber each other's desired/applied attempts. Identity scoping is not
    * enough: two masters may legitimately serve the same SAN set.
    */
   public private(set) string $instance;
   /**
    * The ACME account — one per Certificate Authority SERVICE (the full
    * directory URL, not only its host): two ACME services under different
    * paths of the same authority never share a key or `kid`.
    */
   public private(set) Account $Account {
      get {
         if (isSet($this->Account) === false) {
            // ! Truncated readable host + directory-URL digest — the
            //   component stays far below NAME_MAX for any valid hostname
            $host = parse_url($this->directory)['host'] ?? 'unknown';
            $service = substr(hash('sha256', $this->directory), 0, 32);
            $authority = substr($host, 0, 40) . "-{$service}";

            $this->Account = new Account("{$this->path}account/{$authority}/", $this->bits);
         }

         return $this->Account;
      }
   }
   /**
    * The certificate store — one per configuration identity.
    */
   public private(set) Certificates $Certificates {
      get {
         if (isSet($this->Certificates) === false) {
            // ! Identity-named directory: a truncated readable label plus
            //   128 bits of the identity digest — distinct SAN sets or CAs
            //   never share a store (an 8-hex prefix admitted a practical
            //   chosen collision), and a 253-byte primary domain can never
            //   overflow the 255-byte filesystem component limit
            $suffix = substr($this->identity, 0, 32);
            $name = substr($this->domains[0], 0, 40)
               . ($this->staging ? '-staging' : '')
               . "-{$suffix}";

            $this->Certificates = new Certificates(
               "{$this->path}certificates/{$name}/",
               $this->identity
            );
         }

         return $this->Certificates;
      }
   }
   /**
    * Generation-aware swap rendezvous shared by THIS server's master and
    * workers (fork-inherited `$instance` namespace) — never by another
    * server on the same storage base.
    */
   public private(set) Swaps $Swaps {
      get {
         if (isSet($this->Swaps) === false) {
            $this->Swaps = new Swaps("{$this->path}swaps/", $this->instance);
         }

         return $this->Swaps;
      }
   }
   /**
    * SSL stream-context options for the server socket: the installed ACME
    * certificate when committed, else the self-signed bootstrap. Never
    * cached — the manifest changes under a live server (hot swap).
    * @var array<string,mixed>
    */
   public array $context {
      get {
         $context = [];

         $Snapshot = $this->snapshot();
         if ($Snapshot !== null) {
            $context = $Snapshot->secure();
         }

         // : User options merge in, but the managed credential keys are
         //   reserved (rejected at construction)
         return $this->options + $context;
      }
   }


   /**
    * @param array<int,mixed> $domains List of domain name strings — every
    *                                  entry is validated at construction.
    * @param array<string,mixed> $options
    * @param array<int,mixed> $authorities Runtime validation rejects non-strings.
    *
    * @throws InvalidArgumentException On any invalid configuration value.
    */
   public function __construct (
      array $domains,
      string $email,
      bool $staging = false,
      null|string $directory = null,
      null|string $path = null,
      null|string $challenges = null,
      int $threshold = self::DEFAULT_THRESHOLD,
      int $bits = self::DEFAULT_BITS,
      bool $agreement = true,
      int $port = self::DEFAULT_PORT,
      bool $verify = true,
      array $options = [],
      array $authorities = [],
      bool $allowPrivate = false
   )
   {
      // ? Validate the domain set — HTTP-01 constraints apply
      if ($domains === []) {
         throw new InvalidArgumentException(
            'Invalid AutoTLS `domains`: at least one domain is required.'
         );
      }
      $names = [];
      foreach ($domains as $domain) {
         if (is_string($domain) === false || $domain === '') {
            throw new InvalidArgumentException(
               'Invalid AutoTLS `domains`: every entry must be a non-empty string.'
            );
         }

         $name = strtolower($domain);

         if (str_contains($name, '*')) {
            throw new InvalidArgumentException(
               "Invalid AutoTLS domain `{$domain}`: wildcards require the DNS-01 challenge, which is not supported (HTTP-01 only)."
            );
         }
         if (
            preg_match(
               '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/',
               $name
            ) !== 1
         ) {
            throw new InvalidArgumentException(
               "Invalid AutoTLS domain `{$domain}`: expected a valid hostname."
            );
         }

         $names[] = $name;
      }
      // ! Canonicalize: duplicates collapse (order preserved — `domains[0]`
      //   stays the primary name)
      $names = array_values(array_unique($names));

      // ? Validate the operational values — fail at construction, not at the CA
      if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
         throw new InvalidArgumentException(
            "Invalid AutoTLS `email` `{$email}`: expected a valid contact address."
         );
      }
      if ($agreement === false) {
         throw new InvalidArgumentException(
            'Invalid AutoTLS `agreement`: the CA Terms of Service must be agreed to (RFC 8555 §7.3) — configuring Auto-TLS implies agreement (the Caddy model); pass `true` or drop the argument.'
         );
      }
      if ($threshold < 1 || $threshold > 89) {
         throw new InvalidArgumentException(
            "Invalid AutoTLS `threshold` `{$threshold}`: expected 1-89 days."
         );
      }
      if ($bits < 2048) {
         throw new InvalidArgumentException(
            "Invalid AutoTLS `bits` `{$bits}`: RSA keys must be at least 2048 bits."
         );
      }
      if ($port < 1 || $port > 65535) {
         throw new InvalidArgumentException(
            "Invalid AutoTLS `port` `{$port}`: expected 1-65535."
         );
      }
      if ($directory !== null && self::validate($directory) === false) {
         throw new InvalidArgumentException(
            "Invalid AutoTLS `directory` `{$directory}`: expected an unambiguous https:// URL with a valid host and port."
         );
      }
      $origins = [];
      foreach ($authorities as $authority) {
         if (is_string($authority) === false || self::validate($authority, true) === false) {
            $given = is_string($authority) ? $authority : '(non-string)';
            throw new InvalidArgumentException(
               "Invalid AutoTLS delegated authority `{$given}`: expected an https:// origin without path, query, credentials or fragment."
            );
         }
         $origins[] = $authority;
      }

      // ? Validate the storage path — the privileged boot writes into and
      //   hands off this tree; it must be a dedicated absolute directory,
      //   never `/`, a relative path or a traversal-equivalent one
      $base = rtrim($path ?? BOOTGLY_STORAGE_DIR . 'security/tls/', '/');
      if (
         $base === ''
         || str_starts_with($base, '/') === false
         || substr_count($base, '/') < 2
         || preg_match('/[\x00-\x1f\x7f]/', $base) === 1
         || str_contains("/{$base}/", '/../')
         || str_contains("/{$base}/", '/./')
      ) {
         $given = $path ?? '(default)';
         throw new InvalidArgumentException(
            "Invalid AutoTLS `path` `{$given}`: expected a dedicated absolute directory at least two levels deep, without `.`/`..` segments."
         );
      }

      $challengeBase = rtrim($challenges ?? "{$base}/challenges", '/');
      if (
         $challengeBase === ''
         || str_starts_with($challengeBase, '/') === false
         || substr_count($challengeBase, '/') < 2
         || preg_match('/[\x00-\x1f\x7f]/', $challengeBase) === 1
         || str_contains("/{$challengeBase}/", '/../')
         || str_contains("/{$challengeBase}/", '/./')
      ) {
         $given = $challenges ?? '(storage path + /challenges)';
         throw new InvalidArgumentException(
            "Invalid AutoTLS `challenges` `{$given}`: expected a dedicated absolute directory at least two levels deep, without `.`/`..` segments."
         );
      }

      // ? Reserve the managed credential keys — a partial override could
      //   pair an unrelated certificate with the managed private key, and a
      //   null-valued key would suppress the managed credential entirely
      //   (`array_key_exists`, never `isset`: null must also be rejected).
      //   `SNI_server_certs` is a second credential SELECTOR: PHP serves its
      //   pathname-based entries to matching SNI clients INSTEAD of the
      //   sealed managed leaf, bypassing every validation/ACK — reserved too.
      foreach (['SNI_server_certs', 'local_cert', 'local_pk', 'passphrase'] as $managed) {
         if (array_key_exists($managed, $options)) {
            throw new InvalidArgumentException(
               "Invalid AutoTLS `options`: `{$managed}` is managed by Auto-TLS and cannot be overridden."
            );
         }
      }

      // * Config
      $this->domains = $names;
      $this->email = $email;
      $this->staging = $staging;
      $this->directory = $directory ?? ($staging ? self::STAGING : self::DIRECTORY);
      $this->path = "{$base}/";
      $this->challenges = "{$challengeBase}/";
      $this->threshold = $threshold;
      $this->bits = $bits;
      $this->agreement = $agreement;
      $this->port = $port;
      $this->verify = $verify;
      $this->options = $options;
      $this->authorities = $origins;
      $this->allowPrivate = $allowPrivate;

      // * Metadata
      $sorted = $names;
      sort($sorted);
      $this->identity = hash('sha256', json_encode([$sorted, $this->directory], JSON_THROW_ON_ERROR));
      $this->instance = bin2hex(random_bytes(8));
   }

   /** Validate one unambiguous HTTPS URL or origin without performing I/O. */
   private static function validate (string $URL, bool $origin = false): bool
   {
      if (
         $URL === '' || strlen($URL) > 8192
         || preg_match('/[\x00-\x20\x7f]/', $URL) === 1
         || str_contains($URL, '\\')
      ) {
         return false;
      }

      try {
         $parts = parse_url($URL);
      }
      catch (Throwable) {
         return false;
      }
      if (
         is_array($parts) === false
         || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
         || is_string($parts['host'] ?? null) === false
         || $parts['host'] === ''
         || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
      ) {
         return false;
      }

      $host = $parts['host'];
      if (str_starts_with($host, '[')) {
         if (
            str_ends_with($host, ']') === false
            || str_contains(substr($host, 1, -1), ':') === false
            || @inet_pton(substr($host, 1, -1)) === false
         ) {
            return false;
         }
      }
      else {
         if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
         }
         if (
            $host === ''
            || (
               @inet_pton($host) === false
               && preg_match(
                  '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/i',
                  $host
               ) !== 1
            )
         ) {
            return false;
         }
      }

      /** @var mixed $port parse_url() accepts zero despite its static stub. */
      $port = $parts['port'] ?? 443;
      if (is_int($port) === false || $port < 1 || $port > 65535) {
         return false;
      }
      if ($origin) {
         $path = $parts['path'] ?? '';

         return ($path === '' || $path === '/') && isset($parts['query']) === false;
      }

      return true;
   }

   /**
    * Whether an installed (non-bootstrap) certificate exists, has not
    * expired AND covers this configuration's domain set — a matching pair
    * issued for other names (a swapped store) is never treated as healthy.
    */
   public function check (): bool
   {
      return $this->snapshot(allowBootstrap: false) !== null;
   }

   /**
    * Return one generation only after fullchain, hashes, pair, dates and SANs
    * were validated over the same manifest selection.
    */
   public function snapshot (
      null|string $generation = null,
      bool $allowBootstrap = true
   ): null|CertificateSnapshot
   {
      return $this->Certificates->snapshot(
         $this->domains,
         $generation,
         $allowBootstrap
      );
   }

   /**
    * Ensure a servable certificate exists: reuse the current one (installed
    * or bootstrap) while unexpired, else generate the self-signed bootstrap.
    * Reuse is revalidated on disk — an installed pair must still match and
    * a bootstrap must carry exactly this configuration's SAN set.
    */
   public function forge (): void
   {
      $Snapshot = $this->snapshot();
      if ($Snapshot !== null && $Snapshot->expires > time()) {
         return;
      }

      $this->Certificates->forge($this->domains, $this->bits);
   }

   /**
    * Threshold-, lock- and backoff-guarded issuance: register the account
    * when needed, order, validate and install. Returns true when a new
    * certificate was installed; false when nothing was due, another process
    * holds the renewal lock or a previous failure is still backing off.
    * Every failure after the lock is recorded for backoff and rethrown.
    *
    * @throws Throwable Typed ACME exceptions (`Exceptioning`) from the
    *                   protocol layer, plus local crypto/storage failures
    *                   (`RuntimeException`/`InvalidArgumentException`).
    */
   public function renew (): bool
   {
      // ! Single-flight — cross-process and cross-instance flock, under
      //   the same containment policy as the certificate store
      if ($this->contain("{$this->path}renew.lock") === false) {
         throw new RuntimeException(
            "AutoTLS storage path `{$this->path}` crosses a symlink — refusing to operate through it."
         );
      }
      if (
         is_dir($this->path) === false
         && mkdir($this->path, 0700, true) === false
         && is_dir($this->path) === false
      ) {
         throw new RuntimeException(
            "AutoTLS storage directory `{$this->path}` could not be created."
         );
      }
      // @phpstan-ignore identical.alwaysFalse (intentional post-mkdir containment recheck)
      if ($this->contain("{$this->path}renew.lock") === false) {
         throw new RuntimeException(
            "AutoTLS storage path `{$this->path}` became unsafe while preparing the renewal lock."
         );
      }
      $Lock = $this->open("{$this->path}renew.lock");
      if ($Lock === null) {
         throw new RuntimeException(
            "AutoTLS renewal lock at `{$this->path}renew.lock` could not be opened safely."
         );
      }
      if (flock($Lock, LOCK_EX | LOCK_NB) === false) {
         fclose($Lock);

         return false;
      }

      try {
         // ? Threshold — nothing due yet
         $days = $this->Certificates->inspect();
         if ($this->check() && $days !== null && $days > $this->threshold) {
            return false;
         }

         // ? Backoff — a previous failure is still cooling down
         $schedule = $this->recall();
         if (time() < $schedule['retry']) {
            return false;
         }

         // @ Order — the issuer writes to this configuration's exact spool;
         //   responders may have several paths chartered in the same process.
         $Client = new ACME_Client(
            $this->Account,
            $this->directory,
            $this->verify,
            challenges: $this->challenges,
            authorities: $this->authorities,
            allowPrivate: $this->allowPrivate
         );
         try {
            try {
               $Client->register($this->email, $this->agreement);
               $issued = $Client->order($this->domains, $this->bits);
            }
            catch (ServerException $Exception) {
               // ?! accountDoesNotExist — the persisted kid no longer names
               //   a usable account (deactivated / replaced CA state): drop
               //   it and retry once; newAccount is idempotent per key
               if (str_ends_with($Exception->type, ':accountDoesNotExist') === false) {
                  throw $Exception;
               }

               $this->Account->reset();
               $Client->register($this->email, $this->agreement);
               $issued = $Client->order($this->domains, $this->bits);
            }

            // ! Validation + install failures also enter the backoff — every
            //   failure after the lock is recorded
            $this->Certificates->install(
               $issued['certificate'],
               $issued['key'],
               $this->domains
            );
         }
         catch (Throwable $Throwable) {
            // ! A problem-document `Retry-After` (e.g. rateLimited) extends
            //   the persisted backoff — the CA's schedule is authoritative
            $after = $Throwable instanceof ServerException
               ? $Throwable->retryAfter
               : null;
            $this->record($schedule['attempts'] + 1, $Throwable->getMessage(), $after);

            throw $Throwable;
         }

         // @ A success resets the backoff
         if (is_file("{$this->Certificates->path}order.json")) {
            unlink("{$this->Certificates->path}order.json");
         }

         // :
         return true;
      }
      finally {
         flock($Lock, LOCK_UN);
         fclose($Lock);
      }
   }

   /** @return resource|null Open one regular private state file. */
   private function open (string $file): mixed
   {
      if ($this->contain($file) === false || is_link($file)) {
         return null;
      }

      $before = @lstat($file);
      $previousMask = umask(0077);
      try {
         $Handle = $before === false
            ? @fopen($file, 'x+b')
            : @fopen($file, 'c+b');
         // @phpstan-ignore identical.alwaysTrue (intentional final-link race recheck)
         if ($Handle === false && $before === false && is_link($file) === false) {
            $before = @lstat($file);
            $Handle = @fopen($file, 'c+b');
         }
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         return null;
      }

      $opened = fstat($Handle);
      $after = @lstat($file);
      if (is_array($opened) === false || is_array($after) === false) {
         fclose($Handle);
         return null;
      }
      $same = $opened['dev'] === $after['dev']
         && $opened['ino'] === $after['ino']
         && ((int) $opened['mode'] & 0170000) === 0100000;
      if (is_array($before)) {
         $same = $same
            && $before['dev'] === $opened['dev']
            && $before['ino'] === $opened['ino'];
      }
      if ($same === false || chmod($file, 0600) === false) {
         fclose($Handle);
         return null;
      }
      $secured = @lstat($file);
      $opened = fstat($Handle);
      if (
         is_array($secured) === false || is_array($opened) === false
         || $secured['dev'] !== $opened['dev']
         || $secured['ino'] !== $opened['ino']
         || ((int) $opened['mode'] & 0777) !== 0600
      ) {
         fclose($Handle);
         return null;
      }

      return $Handle;
   }

   /**
    * Whether a file under this configuration's storage base is link-free
    * and contained — the lock and backoff files honor the same boundary
    * as the certificate store and the account.
    */
   private function contain (string $file): bool
   {
      if (
         str_starts_with($file, $this->path) === false
         || str_contains("{$file}/", '/../')
         || str_contains("{$file}/", '/./')
      ) {
         return false;
      }

      $walk = '';
      foreach (explode('/', trim($file, '/')) as $segment) {
         if ($segment === '') {
            continue;
         }

         $walk .= "/{$segment}";
         if (is_link($walk)) {
            return false;
         }
      }

      // :
      return true;
   }

   /**
    * Read the backoff schedule — `attempts` and the `retry` timestamp.
    *
    * @return array{attempts:int, retry:int}
    */
   private function recall (): array
   {
      $file = "{$this->Certificates->path}order.json";
      if ($this->contain($file) === false || is_file($file) === false) {
         return ['attempts' => 0, 'retry' => 0];
      }

      $JSON = file_get_contents($file, false, null, 0, 65537);
      $decoded = is_string($JSON) && strlen($JSON) <= 65536
         ? json_decode($JSON, true)
         : null;
      if (is_array($decoded) === false) {
         return ['attempts' => 0, 'retry' => 0];
      }

      $attempts = $decoded['attempts'] ?? 0;
      $retry = $decoded['retry'] ?? 0;

      // :
      return [
         'attempts' => is_int($attempts) ? $attempts : 0,
         'retry' => is_int($retry) ? $retry : 0
      ];
   }

   /**
    * Record a failed attempt: 60s → 5m → 15m → 1h → 6h (capped) backoff.
    * A CA-directed `Retry-After` (e.g. `rateLimited`) extends the schedule
    * when it asks for MORE patience than the ladder — never less.
    */
   private function record (int $attempts, string $error, null|int $after = null): void
   {
      $delays = [60, 300, 900, 3600, 21600];
      $delay = $delays[$attempts - 1] ?? 21600;

      if ($after !== null && $after > $delay) {
         $delay = $after;
      }

      $file = "{$this->Certificates->path}order.json";
      if ($this->contain($file) === false) {
         return; // the boundary is violated — never write through it
      }

      if (is_dir($this->Certificates->path) === false) {
         mkdir($this->Certificates->path, 0700, true);
      }

      // ! The diagnostic is hostile input (a CA/proxy body can reach ~1 MiB
      //   and need not be UTF-8): cap it far below the 64 KiB recall() limit
      //   and substitute invalid sequences — persisting the BACKOFF must
      //   never fail because its diagnostic is unstorable
      $record = [
         'attempts' => $attempts,
         'retry' => time() + $delay,
         'error' => substr($error, 0, 2048)
      ];
      $JSON = json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE);
      if (is_string($JSON) === false) {
         $record['error'] = '(diagnostic dropped: unstorable bytes)';
         $JSON = json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE);
      }
      if (is_string($JSON) === false) {
         return;
      }
      $temporary = "{$file}." . bin2hex(random_bytes(8)) . '.tmp';
      $previousMask = umask(0077);
      try {
         $Handle = @fopen($temporary, 'x+b');
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         return;
      }
      $written = false;
      try {
         $length = strlen($JSON);
         $offset = 0;
         while ($offset < $length) {
            $bytes = fwrite($Handle, substr($JSON, $offset));
            if ($bytes === false || $bytes === 0) {
               break;
            }
            $offset += $bytes;
         }
         $written = $offset === $length
            && fflush($Handle)
            && (!function_exists('fsync') || fsync($Handle));
      }
      finally {
         fclose($Handle);
         if ($written === false) {
            @unlink($temporary);
         }
      }
      if ($written === false || rename($temporary, $file) === false) {
         @unlink($temporary);
      }
   }
}
