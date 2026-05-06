<?php

namespace Bootgly\PHPStan\Rules;


use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

use Bootgly\API\Environment\Configs\Config;


/**
 * Collects property chains that end in ->bind() on Config objects.
 *
 * @implements Collector<MethodCall, list<string>>
 */
class ConfigBindCollector implements Collector
{
   public function getNodeType (): string
   {
      return MethodCall::class;
   }

   /**
    * @return null|list<string>
    */
   public function processNode (Node $node, Scope $scope): null|array
   {
      if ($node instanceof MethodCall === false) {
         return null;
      }

      if ($node->name instanceof Identifier === false || $node->name->name !== 'bind') {
         return null;
      }

      $var = $node->var;
      if ($var instanceof PropertyFetch === false) {
         return null;
      }

      $varType = $scope->getType($var);
      $classNames = $varType->getObjectClassNames();

      if (in_array(Config::class, $classNames, true) === false) {
         return null;
      }

      return self::extract($var);
   }

   /**
    * @return null|list<string>
    */
   public static function extract (PropertyFetch $node): null|array
   {
      $chain = [];
      $current = $node;

      while ($current instanceof PropertyFetch) {
         if ($current->name instanceof Identifier === false) {
            return null;
         }

         $chain[] = $current->name->name;

         if ($current->var instanceof MethodCall) {
            $current = $current->var->var;
         } else {
            $current = $current->var;
         }
      }

      if ($current instanceof Variable === false) {
         return null;
      }

      return array_reverse($chain);
   }
}
