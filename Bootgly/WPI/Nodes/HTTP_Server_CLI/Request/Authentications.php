<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


/**
 * Supported HTTP authentication credential schemes parsed from requests.
 *
 * The enum identifies parser-level schemes only. It does not imply that a
 * scheme was verified; verification is performed by route authentication
 * guards and application resolvers.
 */
enum Authentications
{
   /**
    * `Authorization: Basic ...` credentials.
    */
   case Basic;
}
