<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\DNS_Client_CLI\Answer;


/**
 * How a DNS lookup ended.
 *
 * `Absent` and `Empty` are answers from the nameserver (negative answers);
 * `Failed` means no usable answer arrived (a server failure, a refusal, a
 * timeout on every try, a truncated answer over TCP, a broken CNAME chain).
 */
enum Outcomes
{
   /** NOERROR with records of the asked type. */
   case Answered;
   /** NXDOMAIN: the name does not exist. */
   case Absent;
   /** NODATA: the name exists, but has no records of the asked type. */
   case Empty;
   /** No usable answer. */
   case Failed;
}
