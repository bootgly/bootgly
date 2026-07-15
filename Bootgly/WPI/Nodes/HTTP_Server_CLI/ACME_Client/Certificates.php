<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;


use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const OPENSSL_KEYTYPE_RSA;
use const SCANDIR_SORT_DESCENDING;
use function array_diff;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function basename;
use function bin2hex;
use function chmod;
use function count;
use function dirname;
use function explode;
use function fclose;
use function fflush;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function fsync;
use function function_exists;
use function fwrite;
use function glob;
use function hash;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_readable;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_check_private_key;
use function openssl_x509_export;
use function openssl_x509_parse;
use function openssl_x509_read;
use function openssl_x509_verify;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function random_bytes;
use function rename;
use function rmdir;
use function rtrim;
use function scandir;
use function sort;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function trim;
use function umask;
use function unlink;
use InvalidArgumentException;
use JsonException;
use OpenSSLCertificateSigningRequest;
use RuntimeException;
use Throwable;


/**
 * ACME certificate store — bootstrap, inspection and versioned installs.
 *
 * Issued certificates land in per-issuance versioned directories; the
 * `current.json` manifest is the atomic commit point (temp + rename), so a
 * live worker can never read a half-written PEM: paths only change when the
 * manifest rename lands.
 */
class Certificates
{
   public const string MANIFEST = 'current.json';
   public const string BOOTSTRAP = 'bootstrap.pem';
   public const int KEEP = 2;
   private const int MAX_MANIFEST_BYTES = 65536;
   private const int MAX_CERTIFICATE_BYTES = 1048576;
   private const int MAX_KEY_BYTES = 65536;

   // * Config
   /**
    * Certificate storage directory — one per configuration identity.
    */
   public private(set) string $path;
   /**
    * Expected configuration identity — a manifest carrying a different one
    * (changed SAN set, switched CA) is never trusted. Empty disables the
    * guard (identity-less stores, e.g. direct construction in tests).
    */
   public private(set) string $identity;

   // * Metadata
   /**
    * `local_cert` candidate — the manifest certificate when committed, the
    * bootstrap when forged, null when neither exists. Never cached: the
    * manifest is re-read at every access (it changes under a live server).
    */
   public null|string $certificate {
      get => $this->read()['certificate'];
   }
   /**
    * `local_pk` candidate — the manifest key when committed; null for the
    * bootstrap (a combined cert+key PEM needs no separate key).
    */
   public null|string $key {
      get => $this->read()['key'];
   }


   public function __construct (string $path, string $identity = '')
   {
      // ? The containment walk resolves from the filesystem root — a
      //   relative path would make it check names the filesystem calls
      //   never touch (cwd-based), silently bypassing the boundary
      if (str_starts_with($path, '/') === false) {
         throw new InvalidArgumentException(
            "ACME certificate store path `{$path}` must be absolute."
         );
      }

      // * Config
      $this->path = rtrim($path, '/') . '/';
      $this->identity = $identity;
   }

   /**
    * Read the paths from one fully validated generation.
    *
    * @return array{certificate:null|string, key:null|string}
    */
   public function read (): array
   {
      $Snapshot = $this->snapshot();

      return [
         'certificate' => $Snapshot?->certificate,
         'key' => $Snapshot?->key
      ];
   }

   /**
    * Whether an installed (non-bootstrap) certificate exists, has not
    * expired and its pair still matches on disk — manifest metadata alone
    * is never trusted for reuse.
    */
   public function check (): bool
   {
      return $this->snapshot(allowBootstrap: false) !== null;
   }

   /**
    * Days until the current certificate expires — null when none exists.
    * Derived from the certificate ON DISK, never from the manifest: a
    * tampered future `expires` must not suppress threshold renewal.
    */
   public function inspect (): null|int
   {
      $expires = $this->snapshot()?->expires;

      return $expires !== null ? intdiv($expires - time(), 86400) : null;
   }

   /**
    * Resolve and validate one exact generation in one pass.
    *
    * @param array<int,string> $domains Expected configured DNS names.
    */
   public function snapshot (
      array $domains = [],
      null|string $generation = null,
      bool $allowBootstrap = true
   ): null|CertificateSnapshot
   {
      $manifest = $this->trust();
      if ($manifest !== null) {
         $recorded = $manifest['generation'] ?? null;
         if ($generation === null || $recorded === $generation) {
            $Snapshot = $this->resolve($manifest, $domains);
            if ($Snapshot !== null && ($allowBootstrap || $Snapshot->bootstrap === false)) {
               return $Snapshot;
            }
         }
      }

      // An exact requested generation never silently falls back to another
      // credential. Bootstrap fallback is only for ordinary startup recovery.
      if ($generation !== null || $allowBootstrap === false) {
         return null;
      }

      $file = "{$this->path}" . self::BOOTSTRAP;
      if ($this->contain($file) === false || is_readable($file) === false) {
         return null;
      }
      $PEM = file_get_contents($file, false, null, 0, self::MAX_CERTIFICATE_BYTES + 1);
      if (is_string($PEM) === false || strlen($PEM) > self::MAX_CERTIFICATE_BYTES) {
         return null;
      }
      $digest = hash('sha256', $PEM);

      return $this->verify(
         generation: substr($digest, 0, 32),
         certificate: $file,
         key: null,
         certificatePEM: $PEM,
         keyPEM: $PEM,
         certificateHash: $digest,
         keyHash: null,
         bootstrap: true,
         domains: $domains
      );
   }

   /**
    * @param array<string,mixed> $manifest
    * @param array<int,string> $domains
    */
   private function resolve (array $manifest, array $domains): null|CertificateSnapshot
   {
      $generation = $manifest['generation'] ?? null;
      $certificate = $manifest['certificate'] ?? null;
      $key = $manifest['key'] ?? null;
      $bootstrap = $manifest['selfsigned'] ?? null;
      $certificateHash = $manifest['certificateHash'] ?? null;
      $keyHash = $manifest['keyHash'] ?? null;
      if (
         is_string($generation) === false
         || preg_match('/^[a-f0-9]{32}$/', $generation) !== 1
         || is_string($certificate) === false
         || is_bool($bootstrap) === false
         || is_string($certificateHash) === false
         || preg_match('/^[a-f0-9]{64}$/', $certificateHash) !== 1
         || $this->contain($certificate) === false
         || is_readable($certificate) === false
      ) {
         return null;
      }

      $certificatePEM = file_get_contents(
         $certificate,
         false,
         null,
         0,
         self::MAX_CERTIFICATE_BYTES + 1
      );
      if (
         is_string($certificatePEM) === false
         || strlen($certificatePEM) > self::MAX_CERTIFICATE_BYTES
         || hash('sha256', $certificatePEM) !== $certificateHash
      ) {
         return null;
      }

      if ($bootstrap) {
         if ($key !== null || $keyHash !== null) {
            return null;
         }
         $keyPEM = $certificatePEM;
      }
      else {
         if (
            is_string($key) === false
            || is_string($keyHash) === false
            || preg_match('/^[a-f0-9]{64}$/', $keyHash) !== 1
            || $this->contain($key) === false
            || is_readable($key) === false
         ) {
            return null;
         }
         $keyPEM = file_get_contents($key, false, null, 0, self::MAX_KEY_BYTES + 1);
         if (
            is_string($keyPEM) === false
            || strlen($keyPEM) > self::MAX_KEY_BYTES
            || hash('sha256', $keyPEM) !== $keyHash
         ) {
            return null;
         }
      }

      return $this->verify(
         $generation,
         $certificate,
         is_string($key) ? $key : null,
         $certificatePEM,
         $keyPEM,
         $certificateHash,
         is_string($keyHash) ? $keyHash : null,
         $bootstrap,
         $domains
      );
   }

   /** @param array<int,string> $domains */
   private function verify (
      string $generation,
      string $certificate,
      null|string $key,
      string $certificatePEM,
      string $keyPEM,
      string $certificateHash,
      null|string $keyHash,
      bool $bootstrap,
      array $domains
   ): null|CertificateSnapshot
   {
      $blocks = $this->split($certificatePEM, $bootstrap);
      if ($blocks === []) {
         return null;
      }
      foreach ($blocks as $position => $block) {
         if (openssl_x509_read($block) === false) {
            return null;
         }
         $validity = openssl_x509_parse($block);
         $from = is_array($validity) ? ($validity['validFrom_time_t'] ?? null) : null;
         $to = is_array($validity) ? ($validity['validTo_time_t'] ?? null) : null;
         if (is_int($from) === false || $from > time() || is_int($to) === false || $to <= time()) {
            return null;
         }
         if (
            isset($blocks[$position + 1])
            && openssl_x509_verify($block, $blocks[$position + 1]) !== 1
         ) {
            return null;
         }
      }

      $leaf = $blocks[0];
      if (openssl_x509_check_private_key($leaf, $keyPEM) === false) {
         return null;
      }
      $parsed = openssl_x509_parse($leaf);
      $from = is_array($parsed) ? ($parsed['validFrom_time_t'] ?? null) : null;
      $to = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;
      if (is_int($from) === false || $from > time() || is_int($to) === false || $to <= time()) {
         return null;
      }

      $SAN = strtolower((string) ($parsed['extensions']['subjectAltName'] ?? ''));
      $covered = [];
      foreach (explode(',', $SAN) as $entry) {
         $entry = trim($entry);
         if (str_starts_with($entry, 'dns:')) {
            $covered[] = substr($entry, 4);
         }
      }
      $covered = array_values(array_unique($covered));
      sort($covered);
      $expected = array_values(array_unique(array_map('strtolower', $domains)));
      sort($expected);
      if (
         $expected !== []
         && ($bootstrap ? $covered !== $expected : array_diff($expected, $covered) !== [])
      ) {
         return null;
      }

      return new CertificateSnapshot(
         $generation,
         $certificate,
         $key,
         $certificateHash,
         $keyHash,
         $from,
         $to,
         $bootstrap,
         $covered
      );
   }

   /**
    * Generate the temporary self-signed bootstrap certificate (combined
    * cert+key PEM) and commit the manifest pointing at it.
    *
    * @param array<int,string> $domains
    */
   public function forge (array $domains, int $bits = 2048): void
   {
      if ($domains === []) {
         throw new RuntimeException('ACME bootstrap requires at least one domain.');
      }
      if ($bits < 2048) {
         throw new RuntimeException('ACME bootstrap RSA keys must be at least 2048 bits.');
      }
      // ? This public helper validates independently of the AutoTLS facade —
      //   the domains are interpolated into an OpenSSL configuration
      //   (the exact facade grammar: total length, label sizes and edges)
      foreach ($domains as $domain) {
         if (
            preg_match(
               '/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?$/i',
               $domain
            ) !== 1
         ) {
            throw new RuntimeException(
               "ACME bootstrap domain `{$domain}` is not a valid hostname."
            );
         }
      }

      // ! Self-signed pair with the full SAN set (temp openssl.cnf); the CN
      //   is set only when it fits OpenSSL's 64-byte limit — SAN is the
      //   identity modern clients read
      $names = [];
      foreach ($domains as $domain) {
         $names[] = "DNS:{$domain}";
      }
      $SAN = implode(',', $names);

      $configuration = tempnam(sys_get_temp_dir(), 'bootgly-acme-bootstrap-');
      if ($configuration === false) {
         throw new RuntimeException('ACME bootstrap temporary configuration could not be created.');
      }

      try {
         $written = file_put_contents(
            $configuration,
            <<<INI
            [req]
            distinguished_name = req_distinguished_name
            req_extensions = v3_req
            x509_extensions = v3_req
            [req_distinguished_name]
            [v3_req]
            subjectAltName = {$SAN}
            INI
         );
         if ($written === false) {
            throw new RuntimeException('ACME bootstrap temporary configuration could not be written.');
         }

         $subject = strlen($domains[0]) <= 64
            ? ['commonName' => $domains[0]]
            : [];
         $arguments = [
            'digest_alg' => 'sha256',
            'config' => $configuration,
            'req_extensions' => 'v3_req',
            'x509_extensions' => 'v3_req'
         ];

         $Key = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA
         ]);
         $Request = $Key !== false
            ? openssl_csr_new($subject, $Key, $arguments)
            : false;
         $X509 = $Request instanceof OpenSSLCertificateSigningRequest
            ? openssl_csr_sign($Request, null, $Key, 30, $arguments)
            : false;

         $certificate = '';
         $private = '';
         if (
            $Key === false || $X509 === false
            || openssl_x509_export($X509, $certificate) === false
            || openssl_pkey_export($Key, $private) === false
         ) {
            throw new RuntimeException('ACME bootstrap certificate generation failed.');
         }
      }
      finally {
         unlink($configuration);
      }

      // @ Persist the combined PEM with restricted permissions — the
      //   temporary name is unpredictable, so a pre-planted symlink cannot
      //   redirect a privileged write (the final rename replaces any link)
      $this->prepare($this->path);

      $file = "{$this->path}" . self::BOOTSTRAP;
      $temporary = "{$file}." . bin2hex(random_bytes(8)) . '.tmp';
      $combined = "{$certificate}{$private}";
      try {
         $this->write($temporary, $combined, 0600);
      }
      catch (Throwable) {
         @unlink($temporary);
         throw new RuntimeException(
            "ACME bootstrap certificate could not be persisted at `{$file}`."
         );
      }
      if (rename($temporary, $file) === false) {
         @unlink($temporary);
         throw new RuntimeException(
            "ACME bootstrap certificate could not be committed at `{$file}`."
         );
      }

      $parsed = openssl_x509_parse($certificate);
      $expires = is_array($parsed) && is_int($parsed['validTo_time_t'] ?? null)
         ? $parsed['validTo_time_t']
         : time() + (30 * 86400);

      $this->commit([
         'generation' => bin2hex(random_bytes(16)),
         'certificate' => $file,
         'key' => null,
         'certificateHash' => hash('sha256', $combined),
         'keyHash' => null,
         'issued' => time(),
         'expires' => $expires,
         'selfsigned' => true
      ]);
   }

   /**
    * Install an issued certificate: validate the complete pair, write the
    * versioned directory, commit the manifest atomically and prune old
    * versions. Nothing invalid ever reaches the commit point.
    *
    * @param array<int,string> $domains Expected SAN set — every entry must
    *                                   be covered by the leaf (empty skips
    *                                   the coverage check).
    */
   public function install (string $fullchain, string $key, array $domains = []): void
   {
      if (strlen($fullchain) > self::MAX_CERTIFICATE_BYTES || strlen($key) > self::MAX_KEY_BYTES) {
         throw new RuntimeException('ACME install rejected an oversized certificate chain or private key.');
      }
      // ! Leaf/chain split — the first PEM block is the leaf. EVERY block
      //   must parse as a certificate; the stored fullchain is rebuilt from
      //   the parsed blocks (normalized), so trailing garbage or a corrupt
      //   intermediate can never be persisted
      $blocks = $this->split($fullchain);
      if ($blocks === []) {
         throw new RuntimeException('ACME install received an empty certificate chain.');
      }
      $normalized = [];
      foreach ($blocks as $block) {
         $X509 = openssl_x509_read($block);
         $exported = '';
         if ($X509 === false || openssl_x509_export($X509, $exported) === false) {
            throw new RuntimeException(
               'ACME install received an unparseable certificate chain block.'
            );
         }
         $validity = openssl_x509_parse($X509);
         $from = is_array($validity) ? ($validity['validFrom_time_t'] ?? null) : null;
         $to = is_array($validity) ? ($validity['validTo_time_t'] ?? null) : null;
         if (is_int($from) === false || $from > time() || is_int($to) === false || $to <= time()) {
            throw new RuntimeException(
               'ACME install rejected: a certificate in the chain is outside its validity window.'
            );
         }
         $normalized[] = $exported;
      }
      $leaf = $normalized[0];
      $chain = implode('', array_slice($normalized, 1));
      $fullchain = implode('', $normalized);

      // ? Every adjacent pair must actually LINK — block N signed by block
      //   N+1 (RFC 8555 orders the chain leaf-first; the trust root may be
      //   omitted, so the final block itself is not verified upward). A
      //   parseable but unrelated certificate never rides along.
      $blocks = count($normalized);
      for ($position = 0; $position < $blocks - 1; $position++) {
         if (openssl_x509_verify($normalized[$position], $normalized[$position + 1]) !== 1) {
            throw new RuntimeException(
               'ACME install rejected: the certificate chain does not link.'
            );
         }
      }

      $parsed = openssl_x509_parse($leaf);
      if (is_array($parsed) === false || is_int($parsed['validTo_time_t'] ?? null) === false) {
         throw new RuntimeException('ACME install received an unparseable leaf certificate.');
      }

      // ? The leaf must match the private key — a mismatched pair would
      //   break every new TLS handshake after the swap
      if (openssl_x509_check_private_key($leaf, $key) === false) {
         throw new RuntimeException(
            'ACME install rejected: the certificate does not match the private key.'
         );
      }

      // ? The leaf must be inside its validity window right now
      $from = $parsed['validFrom_time_t'] ?? null;
      if (is_int($from) === false || $from > time() || $parsed['validTo_time_t'] <= time()) {
         throw new RuntimeException(
            'ACME install rejected: the certificate is outside its validity window.'
         );
      }

      // ? The leaf must cover every ordered domain (SAN, case-insensitive)
      if ($domains !== []) {
         $SAN = strtolower((string) ($parsed['extensions']['subjectAltName'] ?? ''));
         $covered = [];
         foreach (explode(',', $SAN) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'dns:')) {
               $covered[] = substr($entry, 4);
            }
         }
         foreach ($domains as $domain) {
            if (in_array(strtolower($domain), $covered, true) === false) {
               throw new RuntimeException(
                  "ACME install rejected: the certificate does not cover `{$domain}`."
               );
            }
         }
      }

      // @ Versioned directory — a swap never races a file being rewritten.
      //   The name is claimed EXCLUSIVELY via mkdir (atomic): even two
      //   concurrent low-level callers can never share a generation, with
      //   or without the higher-level renewal lock
      $this->prepare($this->path);

      $issued = time();
      $attempt = 0;
      do {
         $version = $attempt === 0 ? (string) $issued : "{$issued}-{$attempt}";
         $directory = "{$this->path}{$version}/";
         $attempt++;

         $claimed = @mkdir($directory, 0700);
      } while ($claimed === false && $attempt < 1000);

      if ($claimed === false) {
         throw new RuntimeException(
            "ACME certificate generation directory could not be claimed under `{$this->path}`."
         );
      }

      try {
         $this->write("{$directory}fullchain.pem", $fullchain, 0644);
         $this->write("{$directory}certificate.pem", $leaf, 0644);
         $this->write("{$directory}chain.pem", $chain, 0644);
         $this->write("{$directory}key.pem", $key, 0600);
      }
      catch (Throwable) {
         foreach (['fullchain.pem', 'certificate.pem', 'chain.pem', 'key.pem'] as $name) {
            @unlink("{$directory}{$name}");
         }
         @rmdir($directory);
         throw new RuntimeException(
            "ACME certificate could not be persisted at `{$directory}`."
         );
      }

      // @ Atomic commit point
      $this->commit([
         'generation' => bin2hex(random_bytes(16)),
         'certificate' => "{$directory}fullchain.pem",
         'key' => "{$directory}key.pem",
         'certificateHash' => hash('sha256', $fullchain),
         'keyHash' => hash('sha256', $key),
         'issued' => $issued,
         'expires' => $parsed['validTo_time_t'],
         'selfsigned' => false
      ]);

      // @ Pruning is best-effort maintenance AFTER the commit — a hostile
      //   or broken old version must never turn an installed certificate
      //   into a renewal failure
      try {
         $this->prune();
      }
      catch (Throwable) {
         // ! Ignored: the new generation is already committed
      }
   }

   /**
    * Whether a file path is contained in this store: it must live under the
    * store path, without traversal, and no component may be a symlink —
    * READS honor the same boundary as writes (an existing symlinked store
    * or a manifest pointing outside the tree is never followed).
    */
   private function contain (string $file): bool
   {
      // ? Prefix containment — manifest targets never leave the store
      if (
         str_starts_with($file, $this->path) === false
         || str_contains("{$file}/", '/../')
         || str_contains("{$file}/", '/./')
      ) {
         return false;
      }

      // ? Link-free — every component, from the filesystem root down
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
    * Read the manifest — null when absent or unreadable.
    *
    * @return array<string,mixed>|null
    */
   private function manifest (): null|array
   {
      $file = "{$this->path}" . self::MANIFEST;

      // ? A symlinked store (or manifest) is never read through
      if ($this->contain($file) === false) {
         return null;
      }

      if (is_file($file) === false) {
         return null;
      }

      $JSON = file_get_contents($file, false, null, 0, self::MAX_MANIFEST_BYTES + 1);
      if (is_string($JSON) === false || strlen($JSON) > self::MAX_MANIFEST_BYTES) {
         return null;
      }

      $decoded = json_decode($JSON, true);

      /** @var array<string,mixed>|null $decoded */
      $decoded = is_array($decoded) ? $decoded : null;

      // :
      return $decoded;
   }

   /**
    * Read the manifest and enforce the configuration identity — a manifest
    * written for another SAN set or CA is never trusted.
    *
    * @return array<string,mixed>|null
    */
   private function trust (): null|array
   {
      $manifest = $this->manifest();
      if ($manifest === null) {
         return null;
      }

      // ? Identity guard (empty expected identity disables it)
      if ($this->identity !== '') {
         $recorded = $manifest['identity'] ?? null;
         if (is_string($recorded) === false || $recorded !== $this->identity) {
            return null;
         }
      }

      // :
      return $manifest;
   }

   /**
    * Commit the manifest atomically (unpredictable temp + rename) carrying
    * the configuration identity.
    *
    * @param array<string,mixed> $manifest
    */
   private function commit (array $manifest): void
   {
      $this->prepare($this->path);

      $manifest['identity'] = $this->identity;

      $file = "{$this->path}" . self::MANIFEST;
      $temporary = "{$file}." . bin2hex(random_bytes(8)) . '.tmp';

      try {
         $JSON = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
      }
      catch (JsonException $Exception) {
         throw new RuntimeException(
            "ACME manifest serialization failed: {$Exception->getMessage()}"
         );
      }

      try {
         $this->write($temporary, $JSON, 0600);
      }
      catch (Throwable) {
         @unlink($temporary);
         throw new RuntimeException(
            "ACME manifest could not be committed at `{$file}`."
         );
      }
      if (rename($temporary, $file) === false) {
         @unlink($temporary);
         throw new RuntimeException(
            "ACME manifest could not be committed at `{$file}`."
         );
      }
   }

   /**
    * Split a PEM bundle into individual certificate blocks.
    *
    * @return array<int,string>
    */
   private function split (string $bundle, bool $combined = false): array
   {
      $pattern = '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----\s*/s';
      $count = preg_match_all($pattern, $bundle, $matches);
      if ($count === false || $count === 0) {
         return [];
      }
      $remainder = preg_replace($pattern, '', $bundle);
      if (is_string($remainder) === false) {
         return [];
      }
      $remainder = trim($remainder);
      if (
         $remainder !== ''
         && ($combined === false || preg_match(
            '/\A-----BEGIN ((?:RSA |EC |ENCRYPTED )?PRIVATE KEY)-----\r?\n(?:[A-Za-z0-9+\/=]+\r?\n)+-----END \1-----\z/D',
            $remainder
         ) !== 1)
      ) {
         return [];
      }

      $blocks = [];
      foreach ($matches[0] as $block) {
         $blocks[] = trim($block) . "\n";
      }

      // :
      return $blocks;
   }

   /**
    * Remove versioned directories beyond the newest KEEP. The generation
    * the current manifest references is always protected — a backward
    * clock or a stale future-dated version can never delete the
    * just-committed credentials.
    */
   private function prune (): void
   {
      $entries = scandir($this->path, SCANDIR_SORT_DESCENDING);
      if ($entries === false) {
         return;
      }

      // ! The manifest target is untouchable regardless of its sort order
      $manifest = $this->manifest();
      $certificate = $manifest['certificate'] ?? null;
      $current = is_string($certificate)
         ? basename(dirname($certificate))
         : null;

      $versions = [];
      foreach ($entries as $entry) {
         // ? A symlinked "version" is never followed — is_dir() would
         //   resolve it and glob/unlink would escape the store
         if (
            preg_match('/^\d+(-\d+)?$/', $entry) === 1
            && is_link("{$this->path}{$entry}") === false
            && is_dir("{$this->path}{$entry}")
         ) {
            $versions[] = $entry;
         }
      }

      if (count($versions) <= self::KEEP) {
         return;
      }

      foreach (array_slice($versions, self::KEEP) as $version) {
         if ($version === $current) {
            continue;
         }

         $directory = "{$this->path}{$version}/";
         foreach (glob("{$directory}*") ?: [] as $file) {
            if (is_link($file) === false && is_dir($file)) {
               continue; // unexpected nested directory — leave it alone
            }
            unlink($file);
         }
         rmdir($directory);
      }
   }

   /**
    * Ensure a storage directory exists with restricted permissions — and
    * refuse to operate through ANY symlinked path component: a privileged
    * boot must never create or write where a runtime-user-planted link
    * points (containment; name-based, so it re-runs before every write —
    * a TOCTOU window remains, but no pre-planted link survives it).
    */
   private function prepare (string $directory): void
   {
      $walk = '';
      foreach (explode('/', trim($directory, '/')) as $segment) {
         if ($segment === '') {
            continue;
         }

         $walk .= "/{$segment}";
         if (is_link($walk)) {
            throw new RuntimeException(
               "ACME storage path `{$directory}` crosses a symlink at `{$walk}` — refusing to operate through it."
            );
         }
      }

      if (is_dir($directory) === false && mkdir($directory, 0700, true) === false) {
         throw new RuntimeException(
            "ACME storage directory `{$directory}` could not be created."
         );
      }
      if ($this->contain($directory) === false || chmod($directory, 0700) === false) {
         throw new RuntimeException(
            "ACME storage directory `{$directory}` is not a private link-free directory."
         );
      }
   }

   /** Create one new regular file with its mode set before any bytes. */
   private function write (string $file, string $contents, int $mode): void
   {
      if ($this->contain($file) === false) {
         throw new RuntimeException("ACME storage file `{$file}` is outside the safe boundary.");
      }
      $previousMask = umask($mode === 0600 ? 0077 : 0022);
      try {
         $Handle = @fopen($file, 'x+b');
      }
      finally {
         umask($previousMask);
      }
      if ($Handle === false) {
         throw new RuntimeException("ACME storage file `{$file}` could not be created exclusively.");
      }

      $written = false;
      try {
         $length = strlen($contents);
         $offset = 0;
         while ($offset < $length) {
            $bytes = fwrite($Handle, substr($contents, $offset));
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
            @unlink($file);
         }
      }

      if ($written === false) {
         throw new RuntimeException("ACME storage file `{$file}` could not be written completely.");
      }
   }
}
