<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


/**
 * Why a datagram wait (`Packages::receive()`) ended.
 *
 * A caller that keeps waiting (a spoofed or unrelated answer), moves on (a
 * refused or expired peer) or gives up (a closed flow, a stalled bridge, a
 * socket the reactor refused) tells the cases apart by name.
 */
enum Outcomes
{
   /** A datagram arrived — `input` holds it (`''` for a zero-byte datagram). */
   case Received;
   /** The timeout elapsed with no datagram. */
   case Expired;
   /** The read failed after the socket became readable (e.g. ICMP port unreachable). */
   case Refused;
   /** The flow was closed while the wait was in progress. */
   case Closed;
   /** The wait bridge kept returning without suspending (non-suspending-wait tripwire). */
   case Stalled;
   /** The reactor or the selector could not watch the socket (selector admission). */
   case Rejected;
}
