<?php
namespace Bootgly\Template;

use Bootgly\Configuring;

enum Config
{
   use Configuring;

   case EXECUTE_MODE_REQUIRE;
   case EXECUTE_MODE_EVAL;
   const EXECUTE_MODE_DEFAULT = self::EXECUTE_MODE_EVAL;

   case COMPILE_ECHO;
   case COMPILE_IF;
   case COMPILE_FOREACH;
   case COMPILE_FOR;
   case COMPILE_WHILE;
}
