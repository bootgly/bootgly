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


use function array_unique;
use function array_values;
use function bin2hex;
use function date;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function preg_replace;
use function random_bytes;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strrchr;
use function strtolower;
use function substr;
use InvalidArgumentException;

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Mail\Message\Address;
use Bootgly\ACI\Mail\Message\Attachment;
use Bootgly\ACI\Mail\Message\Encoder;


/**
 * A MIME message builder (RFC 5322 + 2045-2047).
 *
 * Fill the public properties, `attach()` files, `embed()` inline images
 * (the returned `cid:` URI goes into the HTML body) and `render()` the
 * full raw message. `bcc` is envelope-only — it is never rendered.
 * The rendered output is always 7-bit safe: non-ASCII headers become
 * RFC 2047 encoded-words, non-ASCII text bodies quoted-printable and
 * binary parts wrapped base64.
 */
class Message
{
   /**
    * Structural headers with exactly one canonical source — rejected as
    * custom headers.
    */
   private const array RESERVED = [
      'bcc', 'cc', 'content-transfer-encoding', 'content-type', 'date',
      'from', 'message-id', 'mime-version', 'reply-to', 'subject', 'to'
   ];

   // * Config
   /**
    * Author — `a@b` or `Name <a@b>`.
    */
   public string $from = '';
   /**
    * Reply-To address ('' omits the header).
    */
   public string $reply = '';
   /**
    * @var array<int,string>|string
    */
   public array|string $to = [];
   /**
    * @var array<int,string>|string
    */
   public array|string $cc = [];
   /**
    * Envelope-only recipients — never rendered as a header.
    * @var array<int,string>|string
    */
   public array|string $bcc = [];
   public string $subject = '';
   /**
    * Plain-text body.
    */
   public string $text = '';
   /**
    * HTML body.
    */
   public string $html = '';
   /**
    * Message-ID without angle brackets ('' = generated at render()
    * and persisted).
    */
   public string $id = '';
   /**
    * RFC 2822 date ('' = `date('r')` at render() and persisted).
    */
   public string $date = '';
   /**
    * MIME boundary seed ('' = random at render() and persisted).
    */
   public string $boundary = '';
   /**
    * Extra headers (name => value); structural names are rejected at
    * render(). Non-ASCII values become RFC 2047 encoded-words.
    * @var array<string,string>
    */
   public array $headers = [];

   // * Data
   /**
    * @var array<int,Attachment>
    */
   public private(set) array $Attachments = [];
   /**
    * Inline (multipart/related) parts.
    * @var array<int,Attachment>
    */
   public private(set) array $Embeds = [];

   // * Metadata
   private Encoder $Encoder;
   /**
    * Envelope sender — the email of `from` (virtual, read-only).
    */
   public string $sender {
      get => new Address($this->from)->email;
   }
   /**
    * Envelope recipients — to, cc and bcc emails, deduplicated
    * (virtual, read-only).
    * @var array<int,string>
    */
   public array $recipients {
      get {
         $emails = [];
         foreach ([$this->to, $this->cc, $this->bcc] as $field) {
            foreach ($this->parse($field) as $Address) {
               $emails[] = $Address->email;
            }
         }

         return array_values(array_unique($emails));
      }
   }


   public function __construct ()
   {
      // * Metadata
      $this->Encoder = new Encoder();
   }

   /**
    * Add a regular attachment: a `File` (name/type detected) or raw bytes
    * (name required; type falls back to application/octet-stream).
    */
   public function attach (File|string $source, string $name = '', string $type = ''): self
   {
      $this->Attachments[] = new Attachment($source, $name, $type);

      // :
      return $this;
   }

   /**
    * Add an inline image (multipart/related) and return the `cid:` URI to
    * reference in the HTML body. Pass `$cid` for a stable Content-ID
    * (deterministic renders); '' generates one.
    */
   public function embed (File|string $source, string $name = '', string $type = '', string $cid = ''): string
   {
      // ! Stable Content-ID ('' generates one)
      if ($cid === '') {
         $cid = bin2hex(random_bytes(16));
      }

      $this->Embeds[] = new Attachment($source, $name, $type, Attachment::INLINE, $cid);

      // : The URI to reference in the HTML body
      return "cid:{$cid}";
   }

   /**
    * Render the full raw RFC 5322 message (CRLF line endings, 7-bit safe).
    * Unset `id`/`date`/`boundary` are generated once and persisted —
    * rendering is idempotent.
    */
   public function render (): string
   {
      // ? Guard
      if ($this->from === '') {
         throw new InvalidArgumentException('Mail message requires `from`.');
      }

      $Encoder = $this->Encoder;

      // ! Defaults — persisted, so render() is idempotent
      if ($this->date === '') {
         $this->date = date('r');
      }
      if ($this->boundary === '') {
         $this->boundary = bin2hex(random_bytes(16));
      }
      if ($this->id === '') {
         $domain = substr(strrchr($this->sender, '@') ?: '@', 1);
         $token = bin2hex(random_bytes(16));

         $this->id = "{$token}@{$domain}";
      }

      // ! Parse header addresses — bcc is envelope-only, never here
      $From = new Address($this->from);
      $Tos = $this->parse($this->to);
      $Ccs = $this->parse($this->cc);

      // @ Header block
      $headers = [];
      $headers[] = "Date: {$Encoder->check($this->date)}";
      $headers[] = $Encoder->fold("From: {$Encoder->format($From)}");
      if ($this->reply !== '') {
         $Reply = new Address($this->reply);
         $headers[] = $Encoder->fold("Reply-To: {$Encoder->format($Reply)}");
      }
      foreach (['To' => $Tos, 'Cc' => $Ccs] as $field => $Addresses) {
         // ? Skip empty address lists
         if ($Addresses === []) {
            continue;
         }

         $formatted = [];
         foreach ($Addresses as $Address) {
            $formatted[] = $Encoder->format($Address);
         }
         $list = implode(', ', $formatted);

         $headers[] = $Encoder->fold("{$field}: {$list}");
      }
      if ($this->subject !== '') {
         $subject = $Encoder->encode($Encoder->check($this->subject));
         $headers[] = $Encoder->fold("Subject: {$subject}");
      }
      $headers[] = "Message-ID: <{$Encoder->check($this->id)}>";

      // @ Custom headers
      foreach ($this->headers as $name => $value) {
         // ? Guard: header name grammar (printable ASCII minus `:`)
         if (preg_match('/^[\x21-\x39\x3B-\x7E]+$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid mail header name: `{$name}`.");
         }
         // ? Guard: structural headers have exactly one canonical source
         if (in_array(strtolower($name), self::RESERVED, true) === true) {
            throw new InvalidArgumentException("Reserved mail header: `{$name}`.");
         }

         $encoded = $Encoder->encode($Encoder->check($value));
         $headers[] = $Encoder->fold("{$name}: {$encoded}");
      }

      $headers[] = 'MIME-Version: 1.0';

      // @ Body tree
      [$content, $payload] = $this->body($this->boundary);
      foreach ($content as $header) {
         $headers[] = $header;
      }

      // :
      $head = implode("\r\n", $headers);

      return "{$head}\r\n\r\n{$payload}";
   }

   /**
    * @param array<int,string>|string $addresses
    * @return array<int,Address>
    */
   private function parse (array|string $addresses): array
   {
      // ! Normalize: a single string is one address; '' is none
      if (is_string($addresses) === true) {
         $addresses = $addresses === '' ? [] : [$addresses];
      }

      $Addresses = [];
      foreach ($addresses as $address) {
         $Addresses[] = new Address($address);
      }

      // :
      return $Addresses;
   }

   /**
    * Build the body tree with collapse:
    * `mixed { related { alternative { plain, html }, embeds… }, attachments… }`
    * — each level exists only when its parts do.
    *
    * @return array{0: array<int,string>, 1: string} content headers + payload
    */
   private function body (string $seed): array
   {
      // ! Leaves
      $plain = $this->write($this->text, 'plain');
      $html = $this->html !== '' ? $this->write($this->html, 'html') : null;

      // ! Level 3 — alternative (only when both bodies exist)
      $node = match (true) {
         $html !== null && $this->text !== '' => $this->join(
            'alternative',
            "=_{$seed}.3",
            [$this->flatten($plain), $this->flatten($html)]
         ),
         $html !== null => $html,
         default => $plain
      };

      // ! Level 2 — related (only when embeds exist)
      if ($this->Embeds !== []) {
         $parts = [$this->flatten($node)];
         foreach ($this->Embeds as $Embed) {
            $parts[] = $this->encode($Embed);
         }

         $node = $this->join('related', "=_{$seed}.2", $parts);
      }

      // ! Level 1 — mixed (only when attachments exist)
      if ($this->Attachments !== []) {
         $parts = [$this->flatten($node)];
         foreach ($this->Attachments as $Attachment) {
            $parts[] = $this->encode($Attachment);
         }

         $node = $this->join('mixed', "=_{$seed}.1", $parts);
      }

      // :
      return $node;
   }

   /**
    * Render an attachment/embed leaf into one full part string
    * (base64 payload, inline parts carry a Content-ID).
    */
   private function encode (Attachment $Attachment): string
   {
      $Encoder = $this->Encoder;

      // ! Header-safe file name: RFC 2047 when non-ASCII, then quoted
      $name = $Encoder->encode($Attachment->name);
      $name = str_replace(['\\', '"'], ['\\\\', '\\"'], $name);

      // @ Part headers
      $headers = [];
      $headers[] = "Content-Type: {$Attachment->type}; name=\"{$name}\"";
      $headers[] = 'Content-Transfer-Encoding: base64';
      if ($Attachment->disposition === Attachment::INLINE) {
         $headers[] = "Content-ID: <{$Attachment->cid}>";
      }
      $headers[] = "Content-Disposition: {$Attachment->disposition}; filename=\"{$name}\"";

      // : Full part (wrap() already ends with CRLF)
      $head = implode("\r\n", $headers);
      $payload = $Encoder->wrap($Attachment->contents);

      return "{$head}\r\n\r\n{$payload}";
   }

   /**
    * Render a text leaf: 7bit when pure ASCII with sane lines,
    * quoted-printable otherwise.
    *
    * @return array{0: array<int,string>, 1: string}
    */
   private function write (string $content, string $subtype): array
   {
      // ! Normalize EOLs to CRLF
      $content = preg_replace("/\r\n|\r|\n/", "\r\n", $content) ?? $content;

      // ! 7bit eligibility: ASCII-only, no NUL, no line over 998 chars
      $clean = preg_match('/[\x80-\xFF\x00]/', $content) !== 1;
      if ($clean === true) {
         foreach (explode("\r\n", $content) as $line) {
            if (strlen($line) > 998) {
               $clean = false;
               break;
            }
         }
      }

      // @ Encode
      $payload = $clean === true ? $content : $this->Encoder->quote($content);
      $encoding = $clean === true ? '7bit' : 'quoted-printable';

      // ! The payload must end on its own line
      if ($payload !== '' && str_ends_with($payload, "\r\n") === false) {
         $payload .= "\r\n";
      }

      // :
      return [
         [
            "Content-Type: text/{$subtype}; charset=UTF-8",
            "Content-Transfer-Encoding: {$encoding}"
         ],
         $payload
      ];
   }

   /**
    * Serialize a node (content headers + payload) into one part string.
    *
    * @param array{0: array<int,string>, 1: string} $node
    */
   private function flatten (array $node): string
   {
      [$headers, $payload] = $node;
      $head = implode("\r\n", $headers);

      // :
      return "{$head}\r\n\r\n{$payload}";
   }

   /**
    * Wrap parts into a multipart node.
    *
    * @param array<int,string> $parts
    * @return array{0: array<int,string>, 1: string}
    */
   private function join (string $subtype, string $boundary, array $parts): array
   {
      // @@ Assemble the multipart body
      $body = '';
      foreach ($parts as $part) {
         // ! Every part ends on its own line
         if ($part !== '' && str_ends_with($part, "\r\n") === false) {
            $part .= "\r\n";
         }

         $body .= "--{$boundary}\r\n{$part}";
      }
      $body .= "--{$boundary}--\r\n";

      // :
      return [
         ["Content-Type: multipart/{$subtype}; boundary=\"{$boundary}\""],
         $body
      ];
   }
}
