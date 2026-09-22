<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use const FILTER_VALIDATE_IP;
use const SO_ERROR;
use const SOCKET_ECONNREFUSED;
use const SOCKET_ECONNRESET;
use const SOCKET_EHOSTUNREACH;
use const SOCKET_ENETUNREACH;
use const SOCKET_ETIMEDOUT;
use const SOL_SOCKET;
use const STREAM_CLIENT_ASYNC_CONNECT;
use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_TLS_CLIENT;
use function extension_loaded;
use function fclose;
use function filter_var;
use function implode;
use function is_file;
use function is_int;
use function is_readable;
use function is_resource;
use function preg_replace;
use function restore_error_handler;
use function set_error_handler;
use function socket_get_option;
use function socket_import_stream;
use function str_contains;
use function str_starts_with;
use function stream_context_create;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function strpos;
use function substr;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Events\Readiness;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection\ConnectionStates;
use Bootgly\ADI\Database\Driver;


/**
 * Database connection state holder.
 *
 * Protocol-specific clients attach non-blocking stream resources here and keep
 * transport state reusable by the per-worker pool.
 */
class Connection
{
   // ! The OpenSSL reason strings that mean the peer answered the TLS
   //   handshake with bytes that are not TLS records — a plaintext protocol
   //   talking back. They are the library's own text, which `setlocale()`
   //   never translates, and they are read from the library's section of
   //   the diagnostic only: the rest of it can quote text the PEER chose. A
   //   peer that closed instead reports no diagnostic at all (FIN) or a
   //   transport errno (RST) — see encrypt(). Everything else — an untrusted
   //   certificate, a peer name mismatch, a TLS alert, a CA store this
   //   process could not load — is a handshake the peer DID answer, or a
   //   fault of this process.
   private const array REFUSALS = [
      'wrong version number',
      'unknown protocol',
      'packet length too long',
      'http request',
      'https proxy request',
   ];
   // ! The two shapes PHP gives a failed handshake: a transport errno is
   //   `stream_socket_enable_crypto(): SSL: <strerror>` — the C library's
   //   text, translated under `setlocale()`, so it is matched by SHAPE and
   //   never by wording — while a library error is `SSL operation failed
   //   with code N. OpenSSL Error messages: ...` with the reasons after it.
   /**
    * PHP's own prefix of a transport errno raised during the handshake — `SSL: <strerror>`.
    *
    * Both prefixes are `php_error_docref()`'s: with `html_errors=1` AND a `docref_root`
    * PHP decorates the function name and neither matches — every shape then throws,
    * the safe direction (Bootgly sets neither; the CLI defaults `html_errors` off).
    * The library anchor cannot be planted through a certificate: the prefix plus a
    * reason exceeds the 64-byte CN cap.
    */
   private const string TRANSPORT = 'stream_socket_enable_crypto(): SSL: ';
   /** PHP's own prefix of a failure the library explained — its reasons follow LIBRARY. */
   private const string FAILED = 'stream_socket_enable_crypto(): SSL operation failed with code ';
   private const string LIBRARY = 'OpenSSL Error messages:';

   // * Config
   public Config $Config;

   // * Data
   /** @var resource|null */
   public private(set) mixed $socket = null;
   public private(set) bool $connected = false;
   public private(set) ConnectionStates $state = ConnectionStates::Idle;

   // * Metadata
   public private(set) null|Driver $Protocol = null;
   /**
    * What the peer did to the TLS handshake the last time encrypt() returned
    * `false` — the refusal a `prefer` driver downgrades on. Empty until then.
    */
   public private(set) string $refusal = '';
   /**
    * Why the dial failed the last time establish() returned `false` — the
    * endpoint and the cause, e.g. `127.0.0.1:3306 refused the connection
    * (ECONNREFUSED)`. Empty until then.
    */
   public private(set) string $failure = '';
   /**
    * The SSL context options the last connect() built from the config — what
    * the handshake presents and verifies. Empty under `disable`.
    *
    * @var array<string,bool|string>
    */
   public private(set) array $SSL = [];


   public function __construct (Config $Config)
   {
      // * Config
      $this->Config = $Config;
   }

   /**
    * Open a non-blocking TCP connection.
    */
   public function connect (float $deadline = 0.0): Readiness
   {
      $target = "tcp://{$this->Config->host}:{$this->Config->port}";
      $errorCode = 0;
      $error = '';
      $context = null;
      $this->SSL = [];

      if ($this->Config->secure['mode'] !== Config::SECURE_DISABLE) {
         // ! Config normalizes an empty `peer` to the host, so it is never empty here
         $peer = $this->Config->secure['peer'];
         $cafile = $this->Config->secure['cafile'];

         // ? A `cafile` that cannot be read is a configuration error, refused
         //   before the socket exists. OpenSSL would fail to load the store
         //   before any ClientHello and encrypt() would throw naming it; this
         //   names it earlier, without spending a connection on it.
         if ($cafile !== '' && (is_file($cafile) === false || is_readable($cafile) === false)) {
            throw new InvalidArgumentException("Database TLS cafile is not a readable file: {$cafile}.");
         }

         $SSL = [
            'verify_peer' => $this->Config->secure['verify'],
            'verify_peer_name' => $this->Config->secure['name'],
            'peer_name' => $peer,
            // ? SNI carries host names only — RFC 6066 §3 forbids IP literals
            'SNI_enabled' => filter_var($peer, FILTER_VALIDATE_IP) === false,
         ];
         // ? Only when SET. An empty `cafile` is not "the system store" — it is
         //   `ValueError: Path must not be empty` out of the handshake, thrown
         //   before any ClientHello, so the DEFAULT config never attempted TLS
         //   at all and `prefer` read that local error as a refusal. Absent,
         //   OpenSSL uses `openssl.cafile` / the system store.
         if ($cafile !== '') {
            $SSL['cafile'] = $cafile;
         }
         $context = stream_context_create(['ssl' => $SSL]);
         $this->SSL = $SSL;
      }
      $socket = @stream_socket_client(
         $target,
         $errorCode,
         $error,
         $this->Config->timeout,
         STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
         $context
      );

      if ($socket === false) {
         $message = $error !== '' ? $error : 'native stream returned false';

         throw new RuntimeException("Database connection failed: {$message}");
      }

      stream_set_blocking($socket, false);

      $this->socket = $socket;
      $this->connected = false;
      $this->state = ConnectionStates::Connecting;
      $this->refusal = '';
      $this->failure = '';

      return Readiness::write($socket, $deadline);
   }

   /**
    * Settle the non-blocking dial connect() started.
    *
    * Returns `true` once the socket has a peer, `null` while the dial is still
    * in flight, and `false` when it failed — refused, unreachable, reset or
    * timed out — with the endpoint and the cause recorded in `$failure`. The
    * cause is the socket's own errno when ext-sockets is loaded, and a
    * generic "refused, unreachable, reset or timed out" without it.
    *
    * @throws InvalidArgumentException when no socket is attached
    */
   public function establish (): null|bool
   {
      // ?
      if (is_resource($this->socket) === false) {
         throw new InvalidArgumentException('Connection socket must be attached before the dial is established.');
      }

      // ?: Nothing to settle — an attached stream arrives connected, and it
      //    need not be TCP: an unnamed peer (a socket pair) has no name to read
      if ($this->connected) {
         return true;
      }

      // ?: Not writable yet — the dial is still in flight. Callers are
      //    re-entered on more than write-readiness: Pool::wait() wakes every
      //    second whatever the socket's state, and a dial whose first SYN was
      //    dropped (a full accept queue, a lossy link) is still in flight then.
      //    A dial that completed — connected or failed — is writable at once.
      $read = [];
      $write = [$this->socket];
      $except = [];
      // ! A signal can interrupt even a zero-time probe (EINTR): `false` is a
      //   retry, never a failure, so the warning is not raised
      if (@stream_select($read, $write, $except, 0) !== 1) {
         return null;
      }

      // ?: A completed dial with a peer is established; one without has failed
      if (stream_socket_get_name($this->socket, true) !== false) {
         return true;
      }

      // ! The failed dial's errno — read only through ext-sockets, which is optional
      $errno = 0;
      if (extension_loaded('sockets')) {
         try {
            $Raw = socket_import_stream($this->socket);
            $option = $Raw === false ? false : socket_get_option($Raw, SOL_SOCKET, SO_ERROR);
            $errno = is_int($option) ? $option : 0;
         }
         catch (Throwable) {
            $errno = 0;
         }
      }

      $endpoint = "{$this->Config->host}:{$this->Config->port}";
      // ! Without an errno the constants below are never read: they exist only with ext-sockets
      $this->failure = $errno === 0
         ? "the connection to {$endpoint} failed: refused, unreachable, reset or timed out"
         : match ($errno) {
            SOCKET_ECONNREFUSED => "{$endpoint} refused the connection (ECONNREFUSED)",
            SOCKET_ECONNRESET => "{$endpoint} reset the connection (ECONNRESET)",
            SOCKET_EHOSTUNREACH => "{$endpoint} is unreachable: no route to host (EHOSTUNREACH)",
            SOCKET_ENETUNREACH => "{$endpoint} is unreachable: network is unreachable (ENETUNREACH)",
            SOCKET_ETIMEDOUT => "the connection to {$endpoint} timed out (ETIMEDOUT)",
            default => "the connection to {$endpoint} failed (errno {$errno})",
         };

      return false;
   }

   /**
    * Progress TLS encryption on the attached stream.
    *
    * Returns `true` once encrypted, `null` while the handshake still waits
    * on the peer, and `false` only when the peer refused TLS — it reset or
    * closed the connection during the handshake, or answered it with non-TLS
    * bytes — with the refusal recorded in `$refusal`, named by its shape:
    * a cut after a partial ServerHello and a cut on the ClientHello look the
    * same from here, and non-TLS bytes are known by OpenSSL's own reason
    * strings, read from the library's section of the diagnostic only — the
    * rest of it can quote text the peer chose, such as its certificate's
    * CN. Silence is not a refusal: a peer that has not answered
    * yet is `null` for as long as the caller waits. Every other failure
    * throws with the handshake diagnostic: an untrusted certificate, a peer
    * name mismatch, a TLS alert or a CA store this process could not load is
    * not a peer that lacks TLS, and a `prefer` driver must not downgrade to
    * plaintext on it.
    *
    * @throws RuntimeException when the handshake failed for any reason other than the peer refusing TLS
    */
   public function encrypt (): null|bool
   {
      // ?
      if (is_resource($this->socket) === false) {
         throw new InvalidArgumentException('Connection socket must be ready before TLS encryption.');
      }

      // ! The diagnostic decides what a failed handshake MEANS, so it is
      //   captured instead of silenced: `false` alone cannot tell a peer that
      //   reset the handshake from a certificate this process refused. Every
      //   message raised inside this one call is the handshake's own — PHP
      //   names the function in some (`stream_socket_enable_crypto(): SSL:
      //   ...`) and not in others (`no valid certs found cafile stream: ...`)
      //   — so every one is kept and none is forwarded, whatever
      //   `error_reporting()` masks: a handler that let the CA-store
      //   diagnostic through left this empty, and empty reads as the peer
      //   closing the connection — a refusal `prefer` downgraded on.
      $messages = [];
      set_error_handler(
         static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
         }
      );
      try {
         $encrypted = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
      }
      finally {
         restore_error_handler();
      }

      if ($encrypted === true) {
         $this->state = ConnectionStates::Encrypted;

         return true;
      }

      if ($encrypted === 0) {
         return null;
      }

      // ! What the peer presented is quoted verbatim by PHP — its CN — and
      //   ends up in operator-facing text: control bytes are not forwarded
      $diagnostic = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '?', implode('; ', $messages)) ?? implode('; ', $messages);

      // ?: No diagnostic is how PHP reports the peer closing the connection
      //    during the handshake (FIN) — on the ClientHello or after part of a
      //    ServerHello alike, so the record names the shape, not a phase.
      if ($messages === []) {
         $this->refusal = 'the peer closed the TLS handshake';

         return false;
      }

      // @@ Each message is classified by PHP's OWN prefix, never by text found
      //    anywhere in it: a name mismatch quotes the certificate's CN, and a
      //    CN carrying `OpenSSL Error messages:` or `(): SSL: ` — text the
      //    PEER chose — must not become the anchor of a refusal. The library
      //    section is read only out of a message PHP opened as a library
      //    failure; a transport errno only out of a message PHP opened as one.
      $transport = false;
      $reasons = '';
      foreach ($messages as $message) {
         if (str_starts_with($message, self::FAILED)) {
            $section = strpos($message, self::LIBRARY);
            $reasons .= $section === false ? '' : substr($message, $section);
         }
         else if (str_starts_with($message, self::TRANSPORT)) {
            $transport = true;
         }
      }

      // ?: A transport errno — the peer reset the connection during the
      //    handshake — with no library section anywhere. The shape is the
      //    test: the text is whatever the locale makes of it.
      if ($transport && $reasons === '') {
         $this->refusal = "the peer reset the TLS handshake ({$diagnostic})";

         return false;
      }

      // @@ A named plaintext-answer reason, read from the library's section alone
      foreach (self::REFUSALS as $refusal) {
         if (str_contains($reasons, $refusal)) {
            $this->refusal = "the peer answered the TLS handshake with non-TLS bytes ({$diagnostic})";

            return false;
         }
      }

      throw new RuntimeException($diagnostic);
   }

   /**
    * Attach a non-blocking stream resource to this connection.
    *
    * @param resource $socket
    */
   public function attach (mixed $socket): self
   {
      // ?
      if (is_resource($socket) === false) {
         throw new InvalidArgumentException('Connection socket must be a resource.');
      }

      // * Data
      $this->socket = $socket;
      $this->connected = true;
      $this->state = ConnectionStates::Ready;

      return $this;
   }

   /**
    * Transition the attached stream to a protocol state.
    */
   public function transition (ConnectionStates $state = ConnectionStates::Ready): self
   {
      // ?
      if (is_resource($this->socket) === false) {
         throw new InvalidArgumentException('Connection socket must be ready before state update.');
      }

      $this->connected = true;
      $this->state = $state;

      return $this;
   }

   /**
    * Bind a protocol instance to this connection.
    */
   public function bind (Driver $Protocol): self
   {
      $this->Protocol = $Protocol;

      return $this;
   }

   /**
    * Close the attached stream resource.
    */
   public function disconnect (): bool
   {
      if (is_resource($this->socket)) {
         fclose($this->socket);
      }

      $this->socket = null;
      $this->connected = false;
      $this->state = ConnectionStates::Idle;
      $this->Protocol = null;

      return true;
   }
}
