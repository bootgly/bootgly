<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;


/**
 * Why a dial failed: the stage it stopped at.
 *
 * A dial that tries several addresses moves on to the next one only after
 * `Refused` or `Timeout`; the other faults end it.
 */
enum Faults
{
   /** The dial's budget was spent before it could finish. */
   case Expired;
   /** The address refused the connection, or could not be reached. */
   case Refused;
   /** The address did not answer within its share of the budget, and budget is left for another. */
   case Timeout;
   /** A local fault: no client, a socket that could not be prepared, a reactor that refused it or a wait bridge that stopped suspending. */
   case Local;
   /** The TCP connection was established and the TLS handshake failed. */
   case TLS;
}
