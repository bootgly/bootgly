<?php

namespace Bootgly\PHPStan\Rules;


use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

use Bootgly\API\Environment\Configs\Config;


/**
 * Collects property chains that end in ->get() on Config objects.
 *
 * @implements Collector<MethodCall, array{list<string>, int}>
 */
class ConfigGetCollector implements Collector
{
   public function getNodeType (): string
   {
      return MethodCall::class;
   }

   /**
    * @return null|array{list<string>, int}
    */
   public function processNode (Node $node, Scope $scope): null|array
   {
      if ($node instanceof MethodCall === false) {
         return null;
      }

      if ($node->name instanceof Identifier === false || $node->name->name !== 'get') {
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

      $chain = ConfigBindCollector::extract($var);

      if ($chain === null) {
         return null;
      }

      return [$chain, $node->getStartLine()];
   }
}
