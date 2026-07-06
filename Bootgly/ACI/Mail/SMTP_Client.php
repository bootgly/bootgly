<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Mail;


use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_TLS_CLIENT;
use function array_shift;
use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function implode;
use function in_array;
use function is_resource;
use function is_string;
use function microtime;
use function openssl_error_string;
use function preg_match;
use function rtrim;
use function stream_context_create;
use function stream_get_meta_data;
use function stream_set_blocking;
use function stream_set_timeout;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;
use InvalidArgumentException;
use Throwable;

use Bootgly\ACI\Mail\Config;
use Bootgly\ACI\Mail\Exceptions\AuthenticationException;
use Bootgly\ACI\Mail\Exceptions\ConnectionException;
use Bootgly\ACI\Mail\Exceptions\CryptoException;
use Bootgly\ACI\Mail\Exceptions\PermanentException;
use Bootgly\ACI\Mail\Exceptions\ProtocolException;
use Bootgly\ACI\Mail\Exceptions\TransientException;
use Bootgly\ACI\Mail\Message;
use Bootgly\ACI\Mail\Receipt;
use Bootgly\ACI\Mail\Reply;
use Bootgly\ACI\Mail\SMTP_Client\Decoder;
use Bootgly\ACI\Mail\SMTP_Client\Encoder;
use Bootgly\ACI\Mail\SMTP_Client\Extensions;
use Bootgly\ACI\Mail\SMTP_Client\Mechanisms;


/**
 * Built-in SMTP client (RFC 5321) — blocking transport with per-phase
 * timeouts, implicit TLS (`tls`) or STARTTLS upgrade (`starttls`, RFC 3207)
 * and AUTH PLAIN / LOGIN / XOAUTH2 (RFC 4954).
 *
 * Used through the `Mail` facade; failures throw `Exceptioning` exceptions
 * (a 4xx reply is Transient/retryable, a 5xx reply is Permanent).
 */
class SMTP_Client
{
   // * Config
   public Config $Config;

   // * Data
   public private(set) bool $connected = false;
   public private(set) bool $encrypted = false;
   public private(set) bool $authenticated = false;

   // * Metadata
   /**
    * @var resource|null
    */
   private mixed $socket = null;
   private Decoder $Decoder;
   private Encoder $Encoder;
   private null|Extensions $Extensions = null;
   /**
    * Decoded replies not yet consumed by `read()`.
    * @var array<int,Reply>
    */
   private array $Replies = [];


   public function __construct (Config $Config)
   {
      // * Config
      $this->Config = $Config;

      // * Metadata
      $this->Decoder = new Decoder();
      $this->Encoder = new Encoder();
   }

   /**
    * Open the session: TCP connect, TLS (implicit or STARTTLS upgrade),
    * EHLO identification and AUTH — everything short of a mail transaction.
    * Useful as a boot-time credential/connectivity pre-flight.
    */
   public function connect (): bool
   {
      // ? Already connected
      if ($this->connected === true) {
         return true;
      }

      $Config = $this->Config;

      // ! SSL context — shared by implicit TLS and the STARTTLS upgrade
      $context = null;
      if ($Config->secure !== Config::SECURE_NONE) {
         $options = [
            'verify_peer' => $Config->verify,
            'verify_peer_name' => $Config->verify,
            'peer_name' => $Config->peer !== '' ? $Config->peer : $Config->host,
            'SNI_enabled' => true
         ];
         if ($Config->cafile !== '') {
            $options['cafile'] = $Config->cafile;
         }

         $context = stream_context_create(['ssl' => $options]);
      }

      // @ Connect (the `tls` scheme handshakes at the socket level)
      $scheme = $Config->secure === Config::SECURE_TLS ? 'tls' : 'tcp';
      $errno = 0;
      $error = '';
      $socket = @stream_socket_client(
         "{$scheme}://{$Config->host}:{$Config->port}",
         $errno,
         $error,
         $Config->timeout,
         STREAM_CLIENT_CONNECT,
         $context
      );

      // ? Connect failure — under implicit TLS this includes the handshake
      //   and certificate verification, so it maps to a crypto failure
      if ($socket === false) {
         $message = "SMTP connection to `{$Config->host}:{$Config->port}` failed: {$error}";

         if ($Config->secure === Config::SECURE_TLS) {
            throw new CryptoException($message, $errno ?? 0);
         }

         throw new ConnectionException($message, $errno ?? 0);
      }

      // ! Blocking socket with per-phase deadlines (see read())
      stream_set_blocking($socket, true);

      // * Data
      $this->connected = true;
      $this->encrypted = $Config->secure === Config::SECURE_TLS;
      // * Metadata
      $this->socket = $socket;
      $this->Decoder->reset();
      $this->Replies = [];

      try {
         // @ 220 greeting
         $this->greet();

         // @ EHLO identification (HELO fallback)
         $this->identify();

         // @ STARTTLS upgrade + fresh EHLO over TLS (RFC 3207)
         if ($Config->secure === Config::SECURE_STARTTLS) {
            $this->encrypt();
            $this->identify();
         }

         // @ AUTH (only when credentials are configured)
         $this->authenticate();
      }
      catch (Throwable $Throwable) {
         $this->disconnect();

         throw $Throwable;
      }

      // :
      return true;
   }

   /**
    * Send a mail (`MAIL FROM` / `RCPT TO` / `DATA`), lazily connecting when
    * needed. Takes either a `Message` (envelope and data derived from it —
    * bcc reaches the envelope only) or an explicit envelope plus a raw
    * RFC 5322 string. All-or-nothing: any refused recipient aborts the
    * transaction (RSET) before DATA.
    *
    * @param array<int,string>|string $recipients
    */
   public function send (string|Message $sender, array|string $recipients = [], string $data = ''): Receipt
   {
      $Config = $this->Config;

      // ? Message form — derive the envelope and the wire data from it
      if ($sender instanceof Message) {
         // ? A Message already carries its envelope — extras are ambiguous
         if (($recipients !== [] && $recipients !== '') || $data !== '') {
            throw new InvalidArgumentException(
               'SMTP send with a Message derives the envelope and data from it — pass no recipients/data.'
            );
         }

         $data = $sender->render();
         $recipients = $sender->recipients;
         $sender = $sender->sender;
      }

      // ! Normalize + validate the envelope
      $recipients = is_string($recipients) ? [$recipients] : $recipients;
      if ($recipients === []) {
         throw new InvalidArgumentException('SMTP send requires at least one recipient.');
      }
      $sender = $this->validate($sender, empty: true);
      $addresses = [];
      foreach ($recipients as $recipient) {
         $addresses[] = $this->validate($recipient);
      }

      // @ Lazy connect (no-op on a live session)
      $this->connect();

      // ! DATA wire payload (EOL-normalized + dot-stuffed)
      $stuffed = $this->Encoder->stuff($data);
      $size = strlen($stuffed);

      // ? Pre-flight: advertised SIZE limit (absent/0 = unlimited)
      $limit = (int) ($this->Extensions?->fetch('SIZE') ?? 0);
      if ($limit > 0 && $size > $limit) {
         throw new PermanentException(
            "SMTP message of {$size} bytes exceeds the server SIZE limit of {$limit} bytes.",
            552,
            '5.3.4'
         );
      }

      // ? Pre-flight: non-ASCII envelope addresses or header fields require
      //   SMTPUTF8 (RFC 6531) — refused locally, before MAIL
      $utf8 = false;
      foreach ([$sender, ...$addresses] as $address) {
         if (preg_match('/[\x80-\xFF]/', $address) === 1) {
            $utf8 = true;
            break;
         }
      }
      $position = strpos($stuffed, "\r\n\r\n");
      $headers = $position === false ? $stuffed : substr($stuffed, 0, $position);
      if ($utf8 === false && preg_match('/[\x80-\xFF]/', $headers) === 1) {
         $utf8 = true;
      }
      if ($utf8 === true && $this->Extensions?->check('SMTPUTF8') !== true) {
         throw new PermanentException(
            'SMTP message requires SMTPUTF8 (non-ASCII envelope or header) but the server does not advertise it.',
            553,
            '5.6.7'
         );
      }

      // ? Pre-flight: an 8-bit payload requires 8BITMIME — never sent
      //   silently to a 7-bit server (an SMTPUTF8 transaction already
      //   implies 8-bit support, RFC 6531)
      $eightbit = preg_match('/[\x80-\xFF]/', $stuffed) === 1;
      if (
         $eightbit === true && $utf8 === false
         && $this->Extensions?->check('8BITMIME') !== true
      ) {
         throw new PermanentException(
            'SMTP message contains 8-bit data but the server does not advertise 8BITMIME.',
            554,
            '5.6.1'
         );
      }

      // ! MAIL FROM parameters (only extensions the server advertised)
      $parameters = [];
      if ($this->Extensions?->check('SIZE') === true) {
         $parameters[] = "SIZE={$size}";
      }
      if (
         $eightbit === true
         && $this->Extensions?->check('8BITMIME') === true
      ) {
         $parameters[] = 'BODY=8BITMIME';
      }
      if ($utf8 === true) {
         $parameters[] = 'SMTPUTF8';
      }
      $extras = implode(' ', $parameters);
      $argument = $extras === '' ? "FROM:<{$sender}>" : "FROM:<{$sender}> {$extras}";

      try {
         // @ MAIL FROM
         $Reply = $this->exchange($this->Encoder->encode('MAIL', $argument), $Config->wait);
         $this->expect($Reply, [250]);

         // @@ RCPT TO — all-or-nothing
         foreach ($addresses as $address) {
            $Reply = $this->exchange($this->Encoder->encode('RCPT', "TO:<{$address}>"), $Config->wait);
            $this->expect($Reply, [250, 251]);
         }

         // @ DATA
         $Reply = $this->exchange($this->Encoder->encode('DATA'), $Config->wait);
         $this->expect($Reply, [354]);
      }
      catch (TransientException | PermanentException $Exception) {
         // ! Abort the open transaction so the session stays reusable
         $this->reset();

         throw $Exception;
      }

      // @ Payload in 16 KiB chunks + terminator (traced as a byte count)
      $offset = 0;
      while ($offset < $size) {
         $this->write(
            substr($stuffed, $offset, 16384),
            trace: $offset === 0 ? "[DATA {$size} bytes]" : ''
         );
         $offset += 16384;
      }
      $this->write(".\r\n");

      // @ Final acceptance (the server may take long — drain timeout)
      $Reply = $this->read($Config->drain);
      $this->expect($Reply, [250]);

      // : Acceptance evidence
      return new Receipt(
         code: $Reply->code,
         status: $Reply->status,
         reply: $Reply->text,
         recipients: $addresses,
         size: $size
      );
   }

   /**
    * Close the session: best-effort QUIT then socket teardown. Idempotent.
    */
   public function disconnect (): bool
   {
      // ? Nothing to close
      if ($this->connected === false) {
         return true;
      }

      // @ Best-effort QUIT — the server may already be gone
      try {
         $this->write($this->Encoder->encode('QUIT'));
      }
      catch (Throwable) {
         // ...The socket is broken; close() below tears it down anyway
      }

      $this->close();

      // :
      return true;
   }

   public function __destruct ()
   {
      // @ Best-effort teardown — a destructor must never throw
      try {
         $this->disconnect();
      }
      catch (Throwable) {
      }
   }

   // ---

   /**
    * Read and validate the 220 server greeting.
    */
   private function greet (): void
   {
      $Reply = $this->read($this->Config->wait);

      $this->expect($Reply, [220]);
   }

   /**
    * Identify via EHLO and learn the server capabilities; fall back to HELO
    * (no capabilities) when the server rejects EHLO as unknown.
    */
   private function identify (): void
   {
      $Config = $this->Config;

      $Reply = $this->exchange(
         $this->Encoder->encode('EHLO', $Config->domain), $Config->wait
      );

      // ? EHLO unsupported — HELO fallback (RFC 5321 §4.1.1.1)
      if ($Reply->code === 500 || $Reply->code === 502 || $Reply->code === 504) {
         $Reply = $this->exchange(
            $this->Encoder->encode('HELO', $Config->domain), $Config->wait
         );
         $this->expect($Reply, [250]);

         // * Metadata
         $this->Extensions = new Extensions();

         return;
      }

      $this->expect($Reply, [250]);

      // * Metadata
      $this->Extensions = new Extensions($Reply);
   }

   /**
    * Upgrade the live plaintext session to TLS via STARTTLS. Hard-fails when
    * the server does not advertise or refuses STARTTLS — never a silent
    * downgrade.
    */
   private function encrypt (): void
   {
      // ? STARTTLS must be advertised (also after a HELO fallback)
      if ($this->Extensions?->check('STARTTLS') !== true) {
         throw new CryptoException(
            'SMTP server does not advertise STARTTLS and the `starttls` mode forbids a plaintext session.'
         );
      }

      // @ STARTTLS
      $Reply = $this->exchange($this->Encoder->encode('STARTTLS'), $this->Config->wait);
      if ($Reply->code !== 220) {
         throw new CryptoException("SMTP server refused STARTTLS: {$Reply->text}", $Reply->code);
      }

      // ! Discard plaintext bytes buffered past the 220 — a reply injected
      //   before the handshake must never survive into the TLS session
      $this->Decoder->reset();
      $this->Replies = [];

      // @ TLS handshake on the live socket (context set at connect time)
      $socket = $this->socket;
      if (is_resource($socket) === false) {
         throw new ConnectionException('SMTP socket lost before the TLS upgrade.');
      }

      $negotiated = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
      if ($negotiated !== true) {
         $details = '';
         while (($error = openssl_error_string()) !== false) {
            $details = $details === '' ? $error : "{$details}; {$error}";
         }

         throw new CryptoException(
            $details === ''
               ? 'SMTP TLS negotiation failed.'
               : "SMTP TLS negotiation failed: {$details}"
         );
      }

      // * Data
      $this->encrypted = true;
      // * Metadata — capabilities must be re-learned over TLS
      $this->Extensions = null;
   }

   /**
    * Authenticate when credentials are configured: XOAUTH2 when a token is
    * set, otherwise PLAIN then LOGIN (whichever the server advertises).
    * Refuses to send credentials over an unencrypted session unless the
    * `insecure` config is explicitly true.
    */
   private function authenticate (): void
   {
      $Config = $this->Config;

      // ? No credentials configured — nothing to authenticate
      $oauth = $Config->token !== '';
      $basic = $Config->username !== '' && $Config->password !== '';
      if ($oauth === false && $basic === false) {
         return;
      }

      // ? Never send credentials over an unencrypted session without opt-in
      if ($this->encrypted === false && $Config->insecure === false) {
         throw new AuthenticationException(
            'SMTP credentials are configured but the session is not encrypted — set the `insecure` config to true to explicitly allow plaintext AUTH.'
         );
      }

      // ! Advertised mechanisms
      $advertised = strtoupper($this->Extensions?->fetch('AUTH') ?? '');
      $mechanisms = $advertised === '' ? [] : explode(' ', $advertised);

      $challenge = '';

      // @ XOAUTH2 (bearer token)
      if ($oauth === true) {
         // ? XOAUTH2 pairs the token with the username
         if ($Config->username === '') {
            throw new AuthenticationException('SMTP XOAUTH2 requires the `username` config.');
         }
         // ? The mechanism must be advertised
         if (in_array(Mechanisms::XOAuth2->value, $mechanisms, true) === false) {
            throw new AuthenticationException('SMTP server does not advertise the XOAUTH2 mechanism.');
         }

         $blob = Mechanisms::XOAuth2->encode($Config->username, $Config->token);
         $Reply = $this->exchange(
            $this->Encoder->encode('AUTH', "XOAUTH2 {$blob}"),
            $Config->wait,
            trace: 'AUTH XOAUTH2 ****'
         );

         // ? 334 challenge carries a base64 JSON error — answer with an
         //   empty line to elicit the final 535 (RFC 7628 flow)
         if ($Reply->code === 334) {
            $decoded = base64_decode($Reply->text, true);
            $challenge = $decoded === false ? $Reply->text : $decoded;

            $Reply = $this->exchange("\r\n", $Config->wait, trace: '');
         }
      }
      // @ PLAIN (initial response form)
      elseif (in_array(Mechanisms::Plain->value, $mechanisms, true) === true) {
         $blob = Mechanisms::Plain->encode($Config->username, $Config->password);
         $Reply = $this->exchange(
            $this->Encoder->encode('AUTH', "PLAIN {$blob}"),
            $Config->wait,
            trace: 'AUTH PLAIN ****'
         );

         // ? Some servers ignore the initial response and challenge anyway
         if ($Reply->code === 334) {
            $Reply = $this->exchange("{$blob}\r\n", $Config->wait, trace: '****');
         }
      }
      // @ LOGIN (334 challenge dance)
      elseif (in_array(Mechanisms::Login->value, $mechanisms, true) === true) {
         $Reply = $this->exchange($this->Encoder->encode('AUTH', 'LOGIN'), $Config->wait);

         if ($Reply->code === 334) {
            $username = Mechanisms::Login->encode($Config->username, '');
            $Reply = $this->exchange("{$username}\r\n", $Config->wait, trace: '****');
         }
         if ($Reply->code === 334) {
            $password = base64_encode($Config->password);
            $Reply = $this->exchange("{$password}\r\n", $Config->wait, trace: '****');
         }
      }
      else {
         throw new AuthenticationException(
            'SMTP server does not advertise a supported AUTH mechanism (PLAIN or LOGIN).'
         );
      }

      // ?: Authenticated
      if ($Reply->code === 235) {
         // * Data
         $this->authenticated = true;

         return;
      }

      // ? 4xx during AUTH is transient (e.g. 454) — retryable
      if ($Reply->code >= 400 && $Reply->code < 500) {
         throw new TransientException(
            "SMTP authentication temporarily rejected: {$Reply->text}",
            $Reply->code,
            $Reply->status
         );
      }

      $detail = $challenge === '' ? $Reply->text : "{$Reply->text} ({$challenge})";

      throw new AuthenticationException(
         "SMTP authentication failed ({$Reply->code}): {$detail}", $Reply->code
      );
   }

   /**
    * Validate an SMTP envelope address: no control bytes, spaces or angle
    * brackets (display names belong in message headers, not the envelope).
    * `$empty` allows the null reverse-path `<>` used by bounces.
    */
   private function validate (string $address, bool $empty = false): string
   {
      // ?: The null reverse-path `<>` (bounce messages)
      if ($address === '' && $empty === true) {
         return $address;
      }

      // ? Guard
      if ($address === '' || preg_match('/[\x00-\x20<>\x7F]/', $address) === 1) {
         throw new InvalidArgumentException("Invalid SMTP envelope address: `{$address}`.");
      }

      // :
      return $address;
   }

   /**
    * Abort the open mail transaction (best-effort RSET) so the session
    * stays reusable after a refused MAIL/RCPT/DATA.
    */
   private function reset (): void
   {
      try {
         $Reply = $this->exchange($this->Encoder->encode('RSET'), $this->Config->wait);
         $this->expect($Reply, [250]);
      }
      catch (Throwable) {
         // ...A broken session was already torn down by read()/write()
      }
   }

   // ---

   /**
    * Write a command and read its reply.
    */
   private function exchange (string $command, float $wait, null|string $trace = null): Reply
   {
      $this->write($command, $trace);

      // :
      return $this->read($wait);
   }

   /**
    * Write bytes with partial-write handling. `$trace` overrides what is
    * traced (credential redaction, DATA byte count); null traces the raw
    * bytes and '' suppresses the trace entirely.
    */
   private function write (string $bytes, null|string $trace = null): void
   {
      $socket = $this->socket;

      // ? Guard
      if ($this->connected === false || is_resource($socket) === false) {
         throw new ConnectionException('SMTP client is not connected.');
      }

      if ($trace !== '') {
         $this->trace('>', $trace ?? $bytes);
      }

      // @@ Drain the buffer — fwrite may write partially
      $length = strlen($bytes);
      $offset = 0;
      while ($offset < $length) {
         $written = @fwrite($socket, $offset === 0 ? $bytes : substr($bytes, $offset));

         if ($written === false || $written === 0) {
            $this->close();

            throw new ConnectionException('SMTP write failed: connection lost.');
         }

         $offset += $written;
      }
   }

   /**
    * Read one complete Reply, bounding the wait by an absolute deadline.
    */
   private function read (float $wait): Reply
   {
      $socket = $this->socket;

      // ? Guard
      if ($this->connected === false || is_resource($socket) === false) {
         throw new ConnectionException('SMTP client is not connected.');
      }

      // ! Absolute deadline for this reply
      $deadline = microtime(true) + $wait;

      // @@ Feed the decoder until a complete reply emerges
      while (true) {
         // ?: A previously decoded reply is pending
         if ($this->Replies !== []) {
            $Reply = array_shift($this->Replies);

            // # Trace (reconstructing the wire separators)
            $count = count($Reply->lines);
            foreach ($Reply->lines as $index => $line) {
               $separator = $index === $count - 1 ? ' ' : '-';
               $this->trace('<', "{$Reply->code}{$separator}{$line}");
            }

            return $Reply;
         }

         // ? Deadline reached
         $remaining = $deadline - microtime(true);
         if ($remaining <= 0) {
            $this->close();

            throw new ConnectionException("SMTP reply timed out after {$wait}s.");
         }

         // ! Bound the next blocking read by the remaining time (1ms floor)
         $seconds = (int) $remaining;
         $microseconds = (int) (($remaining - $seconds) * 1_000_000);
         if ($seconds === 0 && $microseconds < 1_000) {
            $microseconds = 1_000;
         }
         stream_set_timeout($socket, $seconds, $microseconds);

         $bytes = @fread($socket, 8192);

         // ? Failed/empty read: timeout, EOF or stream failure
         if ($bytes === false || $bytes === '') {
            $meta = stream_get_meta_data($socket);
            if ($meta['timed_out'] === true) {
               $this->close();

               throw new ConnectionException("SMTP reply timed out after {$wait}s.");
            }
            if ($bytes === false) {
               $this->close();

               throw new ConnectionException('SMTP read failed: connection lost.');
            }
            if (feof($socket) === true) {
               $this->close();

               throw new ConnectionException('SMTP connection closed by the server.');
            }

            continue;
         }

         // @ Decode — a grammar violation poisons the session
         try {
            foreach ($this->Decoder->decode($bytes) as $Reply) {
               $this->Replies[] = $Reply;
            }
         }
         catch (ProtocolException $Exception) {
            $this->close();

            throw $Exception;
         }
      }
   }

   /**
    * Validate a reply against the accepted codes: 4xx throws Transient
    * (retryable), 5xx throws Permanent, anything else unexpected throws
    * Protocol.
    *
    * @param array<int,int> $codes
    */
   private function expect (Reply $Reply, array $codes): Reply
   {
      // ?: Accepted
      if (in_array($Reply->code, $codes, true) === true) {
         return $Reply;
      }

      // ? 4xx — transient, retryable
      if ($Reply->code >= 400 && $Reply->code < 500) {
         throw new TransientException(
            "SMTP server replied {$Reply->code}: {$Reply->text}",
            $Reply->code,
            $Reply->status
         );
      }
      // ? 5xx — permanent
      if ($Reply->code >= 500 && $Reply->code < 600) {
         throw new PermanentException(
            "SMTP server replied {$Reply->code}: {$Reply->text}",
            $Reply->code,
            $Reply->status
         );
      }

      throw new ProtocolException(
         "Unexpected SMTP reply code `{$Reply->code}`: {$Reply->text}"
      );
   }

   /**
    * Emit a wire-trace line through the configured hook.
    */
   private function trace (string $direction, string $line): void
   {
      // ? No hook configured
      if ($this->Config->trace === null) {
         return;
      }

      ($this->Config->trace)($direction, rtrim($line, "\r\n"));
   }

   /**
    * Hard socket teardown and state reset (no QUIT).
    */
   private function close (): void
   {
      $socket = $this->socket;
      if (is_resource($socket) === true) {
         @fclose($socket);
      }

      // * Data
      $this->connected = false;
      $this->encrypted = false;
      $this->authenticated = false;
      // * Metadata
      $this->socket = null;
      $this->Extensions = null;
      $this->Replies = [];
      $this->Decoder->reset();
   }
}
