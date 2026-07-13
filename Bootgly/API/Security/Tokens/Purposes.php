<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tokens;


/**
 * Single-use action token purposes.
 */
enum Purposes: string
{
   case Recovery = 'recovery';         // password reset
   case Verification = 'verification'; // e-mail verification
}
