<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\DNS;


use Bootgly\ABI\Data\DNS\Message\Question;
use Bootgly\ABI\Data\DNS\Message\Record;


/**
 * One DNS message (RFC 1035 §4.1): the header and its four sections.
 *
 * Plain data: `DNS::encode()` writes one to bytes and `DNS::decode()` reads one
 * from bytes. A query and an answer are the same shape — the `response` flag
 * tells them apart.
 *
 * The EDNS(0) OPT pseudo-record (RFC 6891) is never listed in `Additionals`:
 * it is `size` (the UDP payload size it advertises) and the high bits of
 * `rcode`. Its version, its DO flag and its options are not kept; `encode()`
 * writes version 0, DO clear and no option.
 */
final class Message
{
   // * Config
   // # Header
   /**
    * Message id (0–65535): an answer carries the id of its query.
    */
   public int $id;
   /**
    * QR — `true` for an answer, `false` for a query.
    */
   public bool $response;
   /**
    * OPCODE (0–15): `DNS::OPCODE_QUERY` (0) for a standard query.
    */
   public int $opcode;
   /**
    * AA — the answering server is an authority for the name asked.
    */
   public bool $authoritative;
   /**
    * TC — the answer did not fit and was cut: `decode()` reads only its
    * questions (its sections are left empty, `size` is `null` and `rcode`
    * holds the header's 4 bits), so the caller asks again over TCP (RFC 2181
    * §9).
    */
   public bool $truncated;
   /**
    * RD — recursion desired: the client asks the server to resolve the name
    * all the way.
    */
   public bool $recursion;
   /**
    * RA — recursion available: the answering server resolves names all the
    * way.
    */
   public bool $recursive;
   /**
    * AD — authentic data: the server validated the answer (DNSSEC).
    */
   public bool $authentic;
   /**
    * CD — checking disabled: the client accepts data the server did not
    * validate.
    */
   public bool $unchecked;
   /**
    * Response code (`DNS::RCODE_*`, 0–4095): the header's 4 bits plus the 8
    * extended bits of the OPT record — above 15 only with `size` (a decoded
    * TC message has the header's 4 bits only).
    */
   public int $rcode;
   // # EDNS(0)
   /**
    * UDP payload size the sender accepts (RFC 6891), 512–65535; `null` when
    * the message has no OPT record (or is a decoded TC message). A decoded
    * value below 512 reads as 512.
    */
   public null|int $size;
   // # Sections
   /**
    * Question section, in order.
    *
    * @var list<Question>
    */
   public array $Questions;
   /**
    * Answer section, in order.
    *
    * @var list<Record>
    */
   public array $Answers;
   /**
    * Authority section, in order (the SOA of a negative answer lives here).
    *
    * @var list<Record>
    */
   public array $Authorities;
   /**
    * Additional section, in order, without the OPT pseudo-record.
    *
    * @var list<Record>
    */
   public array $Additionals;


   /**
    * @param int $id Message id (0–65535).
    * @param bool $response QR: `true` for an answer.
    * @param int $opcode OPCODE (0–15).
    * @param bool $authoritative AA flag.
    * @param bool $truncated TC flag.
    * @param bool $recursion RD flag (recursion desired).
    * @param bool $recursive RA flag (recursion available).
    * @param bool $authentic AD flag.
    * @param bool $unchecked CD flag.
    * @param int $rcode Response code (0–4095; above 15 only with `size`).
    * @param null|int $size EDNS(0) UDP payload size; `null` for no OPT record.
    * @param list<Question> $Questions Question section.
    * @param list<Record> $Answers Answer section.
    * @param list<Record> $Authorities Authority section.
    * @param list<Record> $Additionals Additional section (no OPT record).
    */
   public function __construct (
      int $id = 0,
      bool $response = false,
      int $opcode = 0,
      bool $authoritative = false,
      bool $truncated = false,
      bool $recursion = false,
      bool $recursive = false,
      bool $authentic = false,
      bool $unchecked = false,
      int $rcode = 0,
      null|int $size = null,
      array $Questions = [],
      array $Answers = [],
      array $Authorities = [],
      array $Additionals = []
   )
   {
      // * Config
      $this->id = $id;
      $this->response = $response;
      $this->opcode = $opcode;
      $this->authoritative = $authoritative;
      $this->truncated = $truncated;
      $this->recursion = $recursion;
      $this->recursive = $recursive;
      $this->authentic = $authentic;
      $this->unchecked = $unchecked;
      $this->rcode = $rcode;
      $this->size = $size;
      $this->Questions = $Questions;
      $this->Answers = $Answers;
      $this->Authorities = $Authorities;
      $this->Additionals = $Additionals;
   }
}
