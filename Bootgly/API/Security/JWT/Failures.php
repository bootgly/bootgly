<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\JWT;


/**
 * JWT verification failure reasons.
 *
 * Failures are intentionally internal diagnostics. HTTP authentication guards
 * still expose only generic Bearer errors to clients.
 */
enum Failures
{
   case Malformed;
   case Header;
   case Payload;
   case Algorithm;
   case Key;
   case Signature;
   case Expired;
   case Before;
   case Issued;
   case JSON;
   case OpenSSL;
   case Issuer;
   case Audience;
   case Subject;
   case Identifier;
}
