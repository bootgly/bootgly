<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
   case Network;
   case Status;
   case JWKS;
   case Revoked;
   case Replay;
   case Issuer;
   case Audience;
   case Subject;
   case Identifier;
}
