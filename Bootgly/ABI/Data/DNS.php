<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data;


use function array_is_list;
use function count;
use function inet_ntop;
use function inet_pton;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function ord;
use function pack;
use function strlen;
use function strspn;
use function substr;
use function unpack;

use Bootgly\ABI\Data\DNS\Faults;
use Bootgly\ABI\Data\DNS\Message;
use Bootgly\ABI\Data\DNS\Message\Question;
use Bootgly\ABI\Data\DNS\Message\Record;
use Bootgly\ABI\Data\DNS\Name;


/**
 * DNS message codec (RFC 1035 §4): a `Message` to bytes and bytes to a
 * `Message`.
 *
 * Pure byte transforms, no I/O and no state — the transport is the caller's
 * (a datagram as it is; over TCP, each message after a 2-byte length, RFC
 * 1035 §4.2.2). Record types A, AAAA, CNAME, NS, PTR, TXT, MX, SRV, CAA and
 * SOA are read into their fields; any other type keeps its raw RDATA (RFC
 * 3597). EDNS(0) is the message's `size` (RFC 6891).
 *
 * `decode()` reads bytes from the network, so it refuses what it cannot read
 * with a `Faults` case instead of throwing, and never builds part of a
 * message: compression pointers only backwards and never into the name being
 * read, labels and names bounded, section counts checked against the bytes
 * that are left before any record is read, a truncated answer (TC) read for
 * its questions only.
 *
 * @phpstan-import-type MX from Record
 * @phpstan-import-type SRV from Record
 * @phpstan-import-type CAA from Record
 * @phpstan-import-type SOA from Record
 */
final class DNS
{
   /** Port of the DNS service. */
   public const int PORT = 53;
   /** Largest message a classic (non-EDNS) UDP exchange carries. */
   public const int SIZE = 512;

   // # Record types
   public const int TYPE_A = 1;
   public const int TYPE_NS = 2;
   public const int TYPE_CNAME = 5;
   public const int TYPE_SOA = 6;
   public const int TYPE_PTR = 12;
   public const int TYPE_MX = 15;
   public const int TYPE_TXT = 16;
   public const int TYPE_AAAA = 28;
   public const int TYPE_SRV = 33;
   /** EDNS(0) pseudo-record: never in a section list, see `Message::$size`. */
   public const int TYPE_OPT = 41;
   public const int TYPE_CAA = 257;

   // # Classes
   public const int CLASS_IN = 1;
   /** RFC 2136 UPDATE: delete an RRset (no RDATA). */
   public const int CLASS_NONE = 254;
   public const int CLASS_ANY = 255;

   // # Opcodes
   public const int OPCODE_QUERY = 0;

   // # Response codes
   public const int RCODE_NOERROR = 0;
   public const int RCODE_FORMERR = 1;
   public const int RCODE_SERVFAIL = 2;
   public const int RCODE_NXDOMAIN = 3;
   public const int RCODE_NOTIMP = 4;
   public const int RCODE_REFUSED = 5;
   /** EDNS version not supported (extended code, RFC 6891). */
   public const int RCODE_BADVERS = 16;

   /** Header size. */
   private const int HEADER = 12;
   /** Largest pointer target: a pointer holds 14 bits. */
   private const int REACH = 0x3FFF;
   /** Letters and digits: a CAA tag (RFC 8659 §4.1). */
   private const string ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';


   /**
    * Write a message to bytes.
    *
    * Names are compressed (RFC 1035 §4.1.4) where a reader of any age can
    * follow it: question and owner names, and the names inside NS, CNAME,
    * PTR, MX and SOA RDATA — never an SRV target or another type's RDATA (RFC
    * 3597 §4). An OPT record is appended when `size` is set.
    *
    * @param Message $Message The message to write — its sections hold
    *    `Question` and `Record` objects only, as their types say (any other
    *    value is a programming error, not a refusal).
    *
    * @return string|Faults The bytes; `Faults::Field` for a field out of its
    *    range or more than one question in a standard query (RFC 9619),
    *    `Faults::Data` for RDATA of the wrong shape, `Faults::EDNS` for
    *    an OPT record in a section list, `Faults::Label` / `Faults::Name` for
    *    a name `Name::encode()` refuses, `Faults::Size` over 65535 bytes.
    */
   public static function encode (Message $Message): string|Faults
   {
      // ? Header fields
      $rcode = $Message->rcode;
      $size = $Message->size;
      if (
         $Message->id < 0 || $Message->id > 0xFFFF
         || $Message->opcode < 0 || $Message->opcode > 0xF
         || $rcode < 0 || $rcode > 0xFFF
         || ($rcode > 0xF && $size === null)
         || ($size !== null && ($size < self::SIZE || $size > 0xFFFF))
         // # One question per standard query (RFC 9619): decode() refuses more
         || ($Message->opcode === self::OPCODE_QUERY && count($Message->Questions) > 1)
      ) {
         return Faults::Field;
      }

      // ? Section counts
      $additionals = count($Message->Additionals) + ($size === null ? 0 : 1);
      if (
         count($Message->Questions) > 0xFFFF
         || count($Message->Answers) > 0xFFFF
         || count($Message->Authorities) > 0xFFFF
         || $additionals > 0xFFFF
      ) {
         return Faults::Field;
      }

      // ! Header
      $flags = ($Message->response ? 0x8000 : 0)
         | ($Message->opcode << 11)
         | ($Message->authoritative ? 0x0400 : 0)
         | ($Message->truncated ? 0x0200 : 0)
         | ($Message->recursion ? 0x0100 : 0)
         | ($Message->recursive ? 0x0080 : 0)
         | ($Message->authentic ? 0x0020 : 0)
         | ($Message->unchecked ? 0x0010 : 0)
         | ($rcode & 0xF);
      $bytes = pack(
         'nnnnnn',
         $Message->id,
         $flags,
         count($Message->Questions),
         count($Message->Answers),
         count($Message->Authorities),
         $additionals
      );
      // # Compression table: wire suffix → offset of its first label
      $table = [];

      // @@ Questions
      foreach ($Message->Questions as $Question) {
         if (
            $Question->type < 0 || $Question->type > 0xFFFF
            || $Question->class < 0 || $Question->class > 0xFFFF
         ) {
            return Faults::Field;
         }

         $name = self::compress($Question->name, strlen($bytes), $table);
         if ($name instanceof Faults) {
            return $name;
         }

         $bytes .= $name . pack('nn', $Question->type, $Question->class);
      }

      // @@ Records
      foreach ([$Message->Answers, $Message->Authorities, $Message->Additionals] as $Records) {
         foreach ($Records as $Record) {
            $bytes = self::write($Record, $bytes, $table);
            if ($bytes instanceof Faults) {
               return $bytes;
            }
         }
      }

      // @ EDNS(0): owner root, class = UDP size, TTL = extended rcode | version 0 | flags 0
      if ($size !== null) {
         $bytes .= "\x00" . pack('nnNn', self::TYPE_OPT, $size, ($rcode >> 4) << 24, 0);
      }

      // ? Whole-message bound
      if (strlen($bytes) > 0xFFFF) {
         return Faults::Size;
      }

      // :
      return $bytes;
   }

   /**
    * Read a message from bytes.
    *
    * A message with TC set is read for its questions only — its sections stay
    * empty, whatever follows the questions is not looked at, and a question
    * that cannot be read leaves `Questions` empty instead of refusing — so the
    * caller asks again over TCP. Its OPT record is not read either: `size` is
    * `null` and `rcode` holds the header's 4 bits only. RDATA of A, AAAA and SRV is read only for
    * class IN; RDATA of class NONE or ANY without bytes stays `''`. The names
    * of one message add up to `Name::BUDGET` characters at most; when a
    * message breaks two bounds at once, which fault comes back is not
    * specified.
    *
    * @param string $bytes One whole message (without a TCP length prefix).
    *
    * @return Message|Faults The message, or why it was refused (see
    *    `Faults`); a refused message yields nothing else.
    */
   public static function decode (string $bytes): Message|Faults
   {
      // ? Header
      $length = strlen($bytes);
      if ($length < self::HEADER) {
         return Faults::Short;
      }

      /** @var array{id: int, flags: int, questions: int, answers: int, authorities: int, additionals: int} $header */
      $header = unpack('nid/nflags/nquestions/nanswers/nauthorities/nadditionals', $bytes);
      $flags = $header['flags'];
      $opcode = ($flags >> 11) & 0xF;
      $truncated = ($flags & 0x0200) !== 0;
      $questions = $header['questions'];

      // ? One question per standard query (RFC 9619)
      if ($opcode === self::OPCODE_QUERY && $questions > 1) {
         return Faults::Count;
      }

      // !
      $offset = self::HEADER;
      $suffixes = [];
      $Questions = [];
      $Answers = [];
      $Authorities = [];
      $Additionals = [];
      $size = null;
      $extended = 0;

      if ($truncated) {
         // # TC: questions only, nothing after them is read (RFC 2181 §9)
         for ($index = 0; $index < $questions; $index++) {
            $Question = self::ask($bytes, $offset, $suffixes);
            if ($Question instanceof Faults) {
               $Questions = [];
               break;
            }
            $Questions[] = $Question;
         }
      }
      else {
         // ? Counts bounded by the bytes left: a question takes 5 at least, a record 11
         $records = $header['answers'] + $header['authorities'] + $header['additionals'];
         if ($questions * 5 + $records * 11 > $length - self::HEADER) {
            return Faults::Count;
         }

         // @@ Questions
         for ($index = 0; $index < $questions; $index++) {
            $Question = self::ask($bytes, $offset, $suffixes);
            if ($Question instanceof Faults) {
               return $Question;
            }
            $Questions[] = $Question;
         }

         // @@ Records, section by section (0 answer, 1 authority, 2 additional)
         foreach ([$header['answers'], $header['authorities'], $header['additionals']] as $section => $count) {
            for ($index = 0; $index < $count; $index++) {
               $Record = self::read($bytes, $offset, $suffixes);
               if ($Record instanceof Faults) {
                  return $Record;
               }

               // # EDNS(0) OPT: once, in the additional section, owned by the root
               if (is_array($Record)) {
                  if ($section !== 2 || $size !== null || $Record['owner'] !== '') {
                     return Faults::EDNS;
                  }
                  $size = max(self::SIZE, $Record['size']);
                  $extended = $Record['extended'];
                  continue;
               }

               match ($section) {
                  0 => $Answers[] = $Record,
                  1 => $Authorities[] = $Record,
                  default => $Additionals[] = $Record
               };
            }
         }

         // ? Nothing after the last section
         if ($offset !== $length) {
            return Faults::Trailing;
         }
      }

      // :
      return new Message(
         id: $header['id'],
         response: ($flags & 0x8000) !== 0,
         opcode: $opcode,
         authoritative: ($flags & 0x0400) !== 0,
         truncated: $truncated,
         recursion: ($flags & 0x0100) !== 0,
         recursive: ($flags & 0x0080) !== 0,
         authentic: ($flags & 0x0020) !== 0,
         unchecked: ($flags & 0x0010) !== 0,
         rcode: ($extended << 4) | ($flags & 0xF),
         size: $size,
         Questions: $Questions,
         Answers: $Answers,
         Authorities: $Authorities,
         Additionals: $Additionals
      );
   }

   // ---

   /**
    * Read one question.
    *
    * @param array<int, int|string> $suffixes
    */
   private static function ask (string $bytes, int &$offset, array &$suffixes): Question|Faults
   {
      // !
      $cursor = $offset;
      $name = Name::decode($bytes, $cursor, $suffixes);
      if ($name instanceof Faults) {
         return $name;
      }

      // ? Type and class
      if ($cursor + 4 > strlen($bytes)) {
         return Faults::Overrun;
      }
      /** @var array{type: int, class: int} $fields */
      $fields = unpack('ntype/nclass', $bytes, $cursor);

      $offset = $cursor + 4;

      // :
      return new Question($name, $fields['type'], $fields['class']);
   }

   /**
    * Read one resource record; an OPT record comes back as its fields.
    *
    * @param array<int, int|string> $suffixes
    *
    * @return Record|array{owner: string, size: int, extended: int}|Faults
    */
   private static function read (string $bytes, int &$offset, array &$suffixes): Record|array|Faults
   {
      // !
      $length = strlen($bytes);
      $cursor = $offset;
      $name = Name::decode($bytes, $cursor, $suffixes);
      if ($name instanceof Faults) {
         return $name;
      }

      // ? Fixed part: type, class, TTL, RDATA length
      if ($cursor + 10 > $length) {
         return Faults::Overrun;
      }
      /** @var array{type: int, class: int, TTL: int, length: int} $fields */
      $fields = unpack('ntype/nclass/NTTL/nlength', $bytes, $cursor);
      $cursor += 10;
      $end = $cursor + $fields['length'];

      // ? RDATA inside the message
      if ($end > $length) {
         return Faults::Overrun;
      }

      $offset = $end;

      // ?: EDNS(0) OPT — the extended rcode is the raw TTL's top byte
      if ($fields['type'] === self::TYPE_OPT) {
         return ['owner' => $name, 'size' => $fields['class'], 'extended' => $fields['TTL'] >> 24];
      }

      $data = self::parse($bytes, $cursor, $end, $fields['type'], $fields['class'], $suffixes);
      if ($data instanceof Faults) {
         return $data;
      }

      // : A TTL with its top bit set reads as 0 (RFC 2181 §8)
      $TTL = $fields['TTL'] > 0x7FFFFFFF ? 0 : $fields['TTL'];

      return new Record($name, $fields['type'], $TTL, $data, $fields['class']);
   }

   /**
    * Read RDATA by type, from `$cursor` to `$end` exactly.
    *
    * @param array<int, int|string> $suffixes
    *
    * @return string|list<string>|MX|SRV|CAA|SOA|Faults
    */
   private static function parse (
      string $bytes, int $cursor, int $end, int $type, int $class, array &$suffixes
   ): string|array|Faults
   {
      // !
      $size = $end - $cursor;
      $IN = $class === self::CLASS_IN;

      // ?: RFC 2136 delete: class NONE or ANY with no RDATA
      if ($size === 0 && ($class === self::CLASS_NONE || $class === self::CLASS_ANY)) {
         return '';
      }

      switch (true) {
         // # Addresses (class IN)
         case $type === self::TYPE_A && $IN:
         case $type === self::TYPE_AAAA && $IN:
            if ($size !== ($type === self::TYPE_A ? 4 : 16)) {
               return Faults::Data;
            }
            // :
            return (string) inet_ntop(substr($bytes, $cursor, $size));

         // # One name
         case $type === self::TYPE_CNAME:
         case $type === self::TYPE_NS:
         case $type === self::TYPE_PTR:
            $name = Name::decode($bytes, $cursor, $suffixes);
            if ($name instanceof Faults) {
               return $name;
            }
            // :
            return $cursor === $end ? $name : Faults::Data;

         // # Character-strings
         case $type === self::TYPE_TXT:
            $strings = [];
            while ($cursor < $end) {
               $count = ord($bytes[$cursor]);
               if ($cursor + 1 + $count > $end) {
                  return Faults::Data;
               }
               $strings[] = substr($bytes, $cursor + 1, $count);
               $cursor += 1 + $count;
            }
            // :
            return $strings;

         // # Preference + exchange
         case $type === self::TYPE_MX:
            if ($size < 3) {
               return Faults::Data;
            }
            /** @var array{preference: int} $fields */
            $fields = unpack('npreference', $bytes, $cursor);
            $cursor += 2;
            $exchange = Name::decode($bytes, $cursor, $suffixes);
            if ($exchange instanceof Faults) {
               return $exchange;
            }
            // :
            return $cursor === $end
               ? ['preference' => $fields['preference'], 'exchange' => $exchange]
               : Faults::Data;

         // # Priority, weight, port + target (class IN)
         case $type === self::TYPE_SRV && $IN:
            if ($size < 7) {
               return Faults::Data;
            }
            /** @var array{priority: int, weight: int, port: int} $fields */
            $fields = unpack('npriority/nweight/nport', $bytes, $cursor);
            $cursor += 6;
            $target = Name::decode($bytes, $cursor, $suffixes);
            if ($target instanceof Faults) {
               return $target;
            }
            // :
            return $cursor === $end
               ? [
                  'priority' => $fields['priority'],
                  'weight' => $fields['weight'],
                  'port' => $fields['port'],
                  'target' => $target
               ]
               : Faults::Data;

         // # Flags, tag, value
         case $type === self::TYPE_CAA:
            if ($size < 2) {
               return Faults::Data;
            }
            $flags = ord($bytes[$cursor]);
            $tag = ord($bytes[$cursor + 1]);
            // ? A tag is 1 to 15 letters and digits (RFC 8659 §4.1), as encode() requires
            if (
               $tag < 1 || $tag > 15 || $cursor + 2 + $tag > $end
               || strspn($bytes, self::ALPHANUMERIC, $cursor + 2, $tag) !== $tag
            ) {
               return Faults::Data;
            }
            // :
            return [
               'flags' => $flags,
               'tag' => substr($bytes, $cursor + 2, $tag),
               'value' => substr($bytes, $cursor + 2 + $tag, $end - $cursor - 2 - $tag)
            ];

         // # Zone authority
         case $type === self::TYPE_SOA:
            $mname = Name::decode($bytes, $cursor, $suffixes);
            if ($mname instanceof Faults) {
               return $mname;
            }
            $rname = Name::decode($bytes, $cursor, $suffixes);
            if ($rname instanceof Faults) {
               return $rname;
            }
            if ($cursor + 20 !== $end) {
               return Faults::Data;
            }
            /** @var array{serial: int, refresh: int, retry: int, expire: int, minimum: int} $fields */
            $fields = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nminimum', $bytes, $cursor);
            // :
            return [
               'mname' => $mname,
               'rname' => $rname,
               'serial' => $fields['serial'],
               'refresh' => $fields['refresh'],
               'retry' => $fields['retry'],
               'expire' => $fields['expire'],
               'minimum' => $fields['minimum']
            ];

         // # Any other type: raw (RFC 3597)
         default:
            // :
            return substr($bytes, $cursor, $size);
      }
   }

   /**
    * Write one name, compressed against the names already written.
    *
    * @param array<string, int> $table Wire suffix → offset of its first label.
    */
   private static function compress (string $name, int $at, array &$table): string|Faults
   {
      // !
      $wire = Name::encode($name);
      if ($wire instanceof Faults) {
         return $wire;
      }

      // @@ Each suffix, longest first: point at it, or remember where it starts
      $length = strlen($wire);
      for ($index = 0; $index < $length - 1; $index += ord($wire[$index]) + 1) {
         $suffix = substr($wire, $index);

         if (isset($table[$suffix])) {
            // :
            return substr($wire, 0, $index) . pack('n', 0xC000 | $table[$suffix]);
         }

         if ($at + $index <= self::REACH) {
            $table[$suffix] = $at + $index;
         }
      }

      // :
      return $wire;
   }

   /**
    * Append one record (owner, fixed part, RDATA) to the message bytes.
    *
    * @param array<string, int> $table
    */
   private static function write (Record $Record, string $bytes, array &$table): string|Faults
   {
      // ? Fields
      $type = $Record->type;
      $class = $Record->class;
      if (
         $type < 0 || $type > 0xFFFF
         || $class < 0 || $class > 0xFFFF
         || $Record->TTL < 0 || $Record->TTL > 0x7FFFFFFF
      ) {
         return Faults::Field;
      }
      if ($type === self::TYPE_OPT) {
         return Faults::EDNS;
      }

      // ! Owner
      $owner = self::compress($Record->name, strlen($bytes), $table);
      if ($owner instanceof Faults) {
         return $owner;
      }
      $bytes .= $owner;
      // # RDATA starts after the 10 fixed bytes
      $at = strlen($bytes) + 10;

      // @
      $data = self::build($Record->data, $type, $class, $at, $table);
      if ($data instanceof Faults) {
         return $data;
      }
      if (strlen($data) > 0xFFFF) {
         return Faults::Size;
      }

      // :
      return $bytes . pack('nnNn', $type, $class, $Record->TTL, strlen($data)) . $data;
   }

   /**
    * Write RDATA by type.
    *
    * @param string|array<mixed> $data
    * @param array<string, int> $table
    */
   private static function build (string|array $data, int $type, int $class, int $at, array &$table): string|Faults
   {
      // !
      $IN = $class === self::CLASS_IN;

      // ?: RFC 2136 delete: class NONE or ANY with no RDATA
      if ($data === '' && ($class === self::CLASS_NONE || $class === self::CLASS_ANY)) {
         return '';
      }

      switch (true) {
         // # Addresses (class IN)
         case $type === self::TYPE_A && $IN:
         case $type === self::TYPE_AAAA && $IN:
            $address = is_string($data) ? inet_pton($data) : false;
            if ($address === false || strlen($address) !== ($type === self::TYPE_A ? 4 : 16)) {
               return Faults::Data;
            }
            // :
            return $address;

         // # One name (compressed)
         case $type === self::TYPE_CNAME:
         case $type === self::TYPE_NS:
         case $type === self::TYPE_PTR:
            if (is_string($data) === false) {
               return Faults::Data;
            }
            // :
            return self::compress($data, $at, $table);

         // # Character-strings
         case $type === self::TYPE_TXT:
            if (is_array($data) === false || array_is_list($data) === false) {
               return Faults::Data;
            }
            $RDATA = '';
            foreach ($data as $string) {
               if (is_string($string) === false || strlen($string) > 255) {
                  return Faults::Data;
               }
               $RDATA .= pack('C', strlen($string)) . $string;
            }
            // :
            return $RDATA;

         // # Preference + exchange (compressed)
         case $type === self::TYPE_MX:
            if (
               is_array($data) === false
               || self::check($data['preference'] ?? null, 0xFFFF) === false
               || is_string($data['exchange'] ?? null) === false
            ) {
               return Faults::Data;
            }
            /** @var array{preference: int, exchange: string} $data */
            $exchange = self::compress($data['exchange'], $at + 2, $table);
            if ($exchange instanceof Faults) {
               return $exchange;
            }
            // :
            return pack('n', $data['preference']) . $exchange;

         // # Priority, weight, port + target (class IN; never compressed)
         case $type === self::TYPE_SRV && $IN:
            if (
               is_array($data) === false
               || self::check($data['priority'] ?? null, 0xFFFF) === false
               || self::check($data['weight'] ?? null, 0xFFFF) === false
               || self::check($data['port'] ?? null, 0xFFFF) === false
               || is_string($data['target'] ?? null) === false
            ) {
               return Faults::Data;
            }
            /** @var array{priority: int, weight: int, port: int, target: string} $data */
            $target = Name::encode($data['target']);
            if ($target instanceof Faults) {
               return $target;
            }
            // :
            return pack('nnn', $data['priority'], $data['weight'], $data['port']) . $target;

         // # Flags, tag, value
         case $type === self::TYPE_CAA:
            if (
               is_array($data) === false
               || self::check($data['flags'] ?? null, 0xFF) === false
               || is_string($data['tag'] ?? null) === false
               || is_string($data['value'] ?? null) === false
            ) {
               return Faults::Data;
            }
            /** @var array{flags: int, tag: string, value: string} $data */
            $tag = strlen($data['tag']);
            if ($tag < 1 || $tag > 15 || strspn($data['tag'], self::ALPHANUMERIC) !== $tag) {
               return Faults::Data;
            }
            // :
            return pack('CC', $data['flags'], $tag) . $data['tag'] . $data['value'];

         // # Zone authority (names compressed)
         case $type === self::TYPE_SOA:
            if (
               is_array($data) === false
               || is_string($data['mname'] ?? null) === false
               || is_string($data['rname'] ?? null) === false
               || self::check($data['serial'] ?? null, 0xFFFFFFFF) === false
               || self::check($data['refresh'] ?? null, 0xFFFFFFFF) === false
               || self::check($data['retry'] ?? null, 0xFFFFFFFF) === false
               || self::check($data['expire'] ?? null, 0xFFFFFFFF) === false
               || self::check($data['minimum'] ?? null, 0xFFFFFFFF) === false
            ) {
               return Faults::Data;
            }
            /** @var array{mname: string, rname: string, serial: int, refresh: int, retry: int, expire: int, minimum: int} $data */
            $mname = self::compress($data['mname'], $at, $table);
            if ($mname instanceof Faults) {
               return $mname;
            }
            $rname = self::compress($data['rname'], $at + strlen($mname), $table);
            if ($rname instanceof Faults) {
               return $rname;
            }
            // :
            return $mname . $rname . pack(
               'NNNNN',
               $data['serial'],
               $data['refresh'],
               $data['retry'],
               $data['expire'],
               $data['minimum']
            );

         // # Any other type: raw (RFC 3597)
         default:
            // :
            return is_string($data) ? $data : Faults::Data;
      }
   }

   /**
    * Check one integer RDATA field: an int from 0 to `$maximum`.
    */
   private static function check (mixed $value, int $maximum): bool
   {
      // :
      return is_int($value) && $value >= 0 && $value <= $maximum;
   }
}
