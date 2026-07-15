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


use const JSON_THROW_ON_ERROR;
use const OPENSSL_KEYTYPE_RSA;
use function bin2hex;
use function chmod;
use function explode;
use function fclose;
use function fflush;
use function file_get_contents;
use function fopen;
use function fsync;
use function function_exists;
use function fwrite;
use function hash;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_string;
use function json_encode;
use function ksort;
use function mkdir;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_new;
use function parse_url;
use function preg_match;
use function random_bytes;
use function rename;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use function umask;
use function unlink;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

use Bootgly\API\Security\JWT\Key;
use Bootgly\API\Security\JWT\Segments;


/**
 * ACME account — the RSA account key pair and its registration identity.
 *
 * The private key is generated on first access and persisted at
 * `{path}key.pem` with 0600 permissions (plain PEM guarded by filesystem
 * permissions — the certbot/Vault precedent). The registered account URL
 * (the RFC 8555 `kid`) is persisted at `{path}url`.
 */
class Account
{
   // * Config
   /**
    * Account storage directory — one per Certificate Authority host.
    */
   public private(set) string $path;
   /**
    * RSA key size in bits (>= 2048).
    */
   public private(set) int $bits;

   // * Data
   /**
    * Registered account URL (RFC 8555 `kid`) — null until registered.
    */
   public private(set) null|string $URL;
   /** Last contact email successfully registered with the CA. */
   public private(set) null|string $contact;

   // * Metadata
   /**
    * Account key pair — loaded from `{path}key.pem`, generated and
    * persisted on first access.
    */
   public private(set) Key $Key {
      get {
         if (isSet($this->Key) === false) {
            $this->Key = $this->generate();
         }

         return $this->Key;
      }
   }
   /**
    * RFC 7517 public JWK — `{"kty":"RSA","n":<b64url>,"e":<b64url>}`.
    * @var array<string,string>
    */
   public private(set) array $JWK {
      get {
         if (isSet($this->JWK) === false) {
            $this->JWK = $this->export();
         }

         return $this->JWK;
      }
   }
   /**
    * RFC 7638 JWK thumbprint — the account half of every HTTP-01
    * key authorization (`{token}.{thumbprint}`).
    */
   public private(set) string $thumbprint {
      get {
         if (isSet($this->thumbprint) === false) {
            $this->thumbprint = self::digest($this->JWK);
         }

         return $this->thumbprint;
      }
   }


   public function __construct (string $path, int $bits = 2048)
   {
      // ? The containment walk resolves from the filesystem root — a
      //   relative path would make it check names the filesystem calls
      //   never touch (cwd-based), silently bypassing the boundary
      if (str_starts_with($path, '/') === false) {
         throw new InvalidArgumentException(
            "ACME account path `{$path}` must be absolute."
         );
      }
      if ($bits < 2048) {
         throw new InvalidArgumentException(
            "ACME account RSA key size `{$bits}` must be at least 2048 bits."
         );
      }

      // * Config
      $this->path = rtrim($path, '/') . '/';
      $this->bits = $bits;

      // * Data
      // ! The persisted kid is read under the SAME containment policy as
      //   every other account access — a symlinked account directory never
      //   sources (nor later deletes) state outside the tree
      $URL = $this->contain("{$this->path}url") && is_file("{$this->path}url")
         ? file_get_contents("{$this->path}url", false, null, 0, 8193)
         : false;
      $URL = is_string($URL) && strlen($URL) <= 8192 ? trim($URL) : '';
      $this->URL = $this->validate($URL)
         ? $URL
         : null;
      $contact = $this->contain("{$this->path}contact") && is_file("{$this->path}contact")
         ? file_get_contents("{$this->path}contact", false, null, 0, 8193)
         : false;
      $this->contact = is_string($contact) && strlen($contact) <= 8192 && trim($contact) !== ''
         ? trim($contact)
         : null;
   }

   /**
    * Compute the RFC 7638 thumbprint of an RSA public JWK: base64url of the
    * SHA-256 over the JSON of the required members only (`e`, `kty`, `n`),
    * ordered lexicographically, without whitespace.
    *
    * @param array<string,string> $JWK
    */
   public static function digest (array $JWK): string
   {
      // ! Required members only — optional members never join the digest
      $members = [
         'e' => $JWK['e'] ?? '',
         'kty' => $JWK['kty'] ?? '',
         'n' => $JWK['n'] ?? ''
      ];
      ksort($members);

      try {
         $JSON = json_encode($members, JSON_THROW_ON_ERROR);
      }
      catch (JsonException $Exception) {
         throw new RuntimeException(
            "ACME account thumbprint failed: {$Exception->getMessage()}"
         );
      }

      // :
      return new Segments()->pack(hash('sha256', $JSON, true));
   }

   /**
    * Whether a file path under the account directory is link-free — key
    * material is never read from nor written through a symlinked
    * component (same containment policy as the certificate store).
    */
   private function contain (string $file): bool
   {
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
    * Drop the registered account URL — the next `register()` re-registers
    * (RFC 8555 `newAccount` is idempotent per key). Used to self-recover
    * from `accountDoesNotExist` after a CA-side deactivation or a stale
    * `kid` left by replaced account state.
    */
   public function reset (): void
   {
      // ? Deletes honor the containment boundary too — a symlinked account
      //   directory must never aim the unlink outside the tree
      if ($this->contain("{$this->path}url") && is_file("{$this->path}url")) {
         unlink("{$this->path}url");
      }
      if ($this->contain("{$this->path}contact") && is_file("{$this->path}contact")) {
         unlink("{$this->path}contact");
      }

      $this->URL = null;
      $this->contact = null;
   }

   /**
    * Persist the registered account URL (the RFC 8555 `kid`).
    */
   public function save (string $URL): void
   {
      if ($this->validate($URL) === false) {
         throw new InvalidArgumentException(
            "ACME account URL `{$URL}` must be an absolute https:// URL without credentials or a fragment."
         );
      }
      if ($this->contain("{$this->path}url") === false) {
         throw new RuntimeException(
            "ACME account path `{$this->path}` crosses a symlink — refusing to persist the account URL."
         );
      }

      $this->persist("{$this->path}url", $URL);

      $this->URL = $URL;
   }

   /** Persist the contact acknowledged by the ACME account endpoint. */
   public function update (string $email): void
   {
      $file = "{$this->path}contact";
      $this->persist($file, $email);

      $this->contact = $email;
   }

   /**
    * Load the persisted account key or generate and persist a fresh one.
    */
   private function generate (): Key
   {
      $file = "{$this->path}key.pem";

      // ? Key material never crosses a symlinked component — read or write
      if ($this->contain($file) === false) {
         throw new RuntimeException(
            "ACME account path `{$this->path}` crosses a symlink — refusing to load or persist the account key."
         );
      }

      // ? Load the persisted key
      if (is_file($file)) {
         // @phpstan-ignore identical.alwaysFalse (intentional pre-read containment recheck)
         if (chmod($file, 0600) === false || $this->contain($file) === false) {
            throw new RuntimeException(
               "ACME account key at `{$file}` could not be restricted safely."
            );
         }
         $PEM = file_get_contents($file, false, null, 0, 65537);
         if (is_string($PEM) === false || strlen($PEM) > 65536) {
            throw new RuntimeException(
               "ACME account key at `{$file}` could not be read."
            );
         }

         // ?: Reuse only a semantically compatible RSA key. A parseable EC
         //   key or undersized RSA key is as unusable for RS256 as corrupt
         //   bytes and must enter the same recovery path.
         $Private = openssl_pkey_get_private($PEM);
         $details = $Private !== false
            ? openssl_pkey_get_details($Private)
            : false;
         if (
            is_array($details)
            && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && is_int($details['bits'] ?? null)
            && $details['bits'] >= $this->bits
         ) {
            return new Key($PEM, 'RS256');
         }

         if (rename($file, "{$file}.corrupt." . bin2hex(random_bytes(4))) === false) {
            throw new RuntimeException(
               "Incompatible ACME account key at `{$file}` could not be quarantined."
            );
         }
      }

      // ! Fresh RSA key pair
      $Generated = openssl_pkey_new([
         'private_key_bits' => $this->bits,
         'private_key_type' => OPENSSL_KEYTYPE_RSA
      ]);
      if ($Generated === false) {
         throw new RuntimeException('ACME account key generation failed.');
      }

      $PEM = '';
      if (openssl_pkey_export($Generated, $PEM) === false || is_string($PEM) === false) {
         throw new RuntimeException('ACME account key export failed.');
      }

      // @ Persist with 0600 before the key reaches its final name — the
      //   temporary name is unpredictable (no pre-planted symlink target)
      if (is_dir($this->path) === false && mkdir($this->path, 0700, true) === false) {
         throw new RuntimeException(
            "ACME account directory `{$this->path}` could not be created."
         );
      }
      $this->persist($file, $PEM);

      // ! A fresh key invalidates any persisted account URL — a stale `kid`
      //   paired with a new key can never sign a valid request; dropping it
      //   makes the next register() re-register and self-recover
      if ($this->contain("{$this->path}url") && is_file("{$this->path}url")) {
         unlink("{$this->path}url");
      }
      if ($this->contain("{$this->path}contact") && is_file("{$this->path}contact")) {
         unlink("{$this->path}contact");
      }
      $this->URL = null;
      $this->contact = null;

      // :
      return new Key($PEM, 'RS256');
   }

   /** Whether one persisted account endpoint is safe for future JWS use. */
   private function validate (string $URL): bool
   {
      if (
         $URL === '' || strlen($URL) > 8192
         || preg_match('/[\x00-\x20\x7f]/', $URL) === 1
      ) {
         return false;
      }
      $parts = parse_url($URL);

      return is_array($parts)
         && ($parts['scheme'] ?? null) === 'https'
         && is_string($parts['host'] ?? null)
         && $parts['host'] !== ''
         && isset($parts['user']) === false
         && isset($parts['pass']) === false
         && isset($parts['fragment']) === false;
   }

   /** Atomically replace one private account-state file with mode 0600. */
   private function persist (string $file, string $contents): void
   {
      if ($this->contain($file) === false) {
         throw new RuntimeException(
            "ACME account file `{$file}` crosses a symbolic link."
         );
      }
      if (is_dir($this->path) === false && mkdir($this->path, 0700, true) === false && is_dir($this->path) === false) {
         throw new RuntimeException(
            "ACME account directory `{$this->path}` could not be created."
         );
      }
      // @phpstan-ignore identical.alwaysFalse (intentional post-mkdir containment recheck)
      if ($this->contain($file) === false || chmod($this->path, 0700) === false) {
         throw new RuntimeException(
            "ACME account file `{$file}` became unsafe before persistence."
         );
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
         throw new RuntimeException(
            "ACME account temporary file for `{$file}` could not be created."
         );
      }

      $written = false;
      try {
         // The controlled creation mask sets 0600 before the first byte.
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
            @unlink($temporary);
         }
      }

      if ($written === false || rename($temporary, $file) === false) {
         @unlink($temporary);
         throw new RuntimeException(
            "ACME account file `{$file}` could not be persisted."
         );
      }
   }

   /**
    * Serialize the RFC 7517 public JWK from the account key pair.
    *
    * @return array<string,string>
    */
   private function export (): array
   {
      $Private = $this->Key->open();
      if ($Private === null) {
         throw new RuntimeException('ACME account key is not an RSA private key.');
      }

      $details = openssl_pkey_get_details($Private);
      $modulus = $details['rsa']['n'] ?? null;
      $exponent = $details['rsa']['e'] ?? null;
      if (is_string($modulus) === false || is_string($exponent) === false) {
         throw new RuntimeException('ACME account key details could not be derived.');
      }

      $Segments = new Segments();

      // :
      return [
         'kty' => 'RSA',
         'n' => $Segments->pack($modulus),
         'e' => $Segments->pack($exponent)
      ];
   }
}
