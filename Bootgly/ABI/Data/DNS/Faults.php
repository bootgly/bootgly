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


/**
 * Why the DNS codec refused a message or a name.
 *
 * `DNS::decode()` and `Name::decode()` answer bytes they cannot read with one
 * of these cases instead of throwing — a resolver receives forged datagrams
 * as a matter of course — and `DNS::encode()` / `Name::encode()` answer a
 * value they cannot write the same way. Nothing is partly decoded: a refused
 * message yields no `Message` at all.
 */
enum Faults
{
   /**
    * `decode()`: the message is shorter than its 12-byte header.
    */
   case Short;
   /**
    * `decode()`: a field runs past the end of the message — a name, the fixed
    * part of a question or a record, or RDATA longer than what is left.
    */
   case Overrun;
   /**
    * `decode()`: a section count the message cannot hold — the declared
    * questions and records need more bytes than are left (a question takes 5
    * at least, a record 11) — or more than one question in a standard query
    * (RFC 9619).
    */
   case Count;
   /**
    * `decode()`: a label of a reserved type (its length byte starts with the
    * bits `01` or `10`). `encode()`: an empty label inside a name (`a..b`,
    * `.a`) or a label longer than 63 bytes.
    */
   case Label;
   /**
    * A name longer than 255 bytes on the wire (length bytes, labels and the
    * root byte, across every compression pointer); `encode()` also refuses a
    * malformed escape (`\` at the end, `\DDD` without three digits or above
    * 255).
    */
   case Name;
   /**
    * `decode()`: a compression pointer back into the name being read, or a
    * name that takes more than 128 pointer jumps.
    */
   case Loop;
   /**
    * `decode()`: a compression pointer at or past its own position — a
    * pointer may only reach a prior occurrence (RFC 1035 §4.1.4).
    */
   case Forward;
   /**
    * `decode()`: a compression pointer into the 12-byte header.
    */
   case Pointer;
   /**
    * RDATA that does not match its type: `decode()` — an address of the wrong
    * size, a TXT string or a CAA tag past the RDATA, SOA fields of the wrong
    * size, a name that does not end where the RDATA ends; `encode()` — data
    * of the wrong shape, a TXT string over 255 bytes, a CAA tag that is not 1
    * to 15 letters and digits.
    */
   case Data;
   /**
    * The EDNS(0) OPT pseudo-record is misplaced: `decode()` — outside the
    * additional section, more than one, or an owner other than the root;
    * `encode()` — an OPT record in a section list (EDNS is the message's
    * `size`).
    */
   case EDNS;
   /**
    * `decode()`: bytes left after the last section.
    */
   case Trailing;
   /**
    * `encode()`: a header, question or record field out of its range (an id
    * above 65535, a negative type, a TTL above 2^31 - 1, an extended rcode
    * without `size`, more than 65535 entries in a section).
    */
   case Field;
   /**
    * `encode()`: the message, or the RDATA of one record, is larger than
    * 65535 bytes. `decode()`: the names of the message add up to more than
    * `Name::BUDGET` characters (compression lets 64 KiB name millions).
    */
   case Size;
}
