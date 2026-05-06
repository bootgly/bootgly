<?php

namespace Bootgly\PHPStan\Rules;


use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;


/**
 * Reports ->get() calls on Config properties that were never ->bind()ed in the same file.
 *
 * @implements Rule<CollectedDataNode>
 */
class UnboundConfigAccessRule implements Rule
{
   public function getNodeType (): string
   {
      return CollectedDataNode::class;
   }

   public function processNode (Node $node, Scope $scope): array
   {
      /** @var CollectedDataNode $node */
      $bindData = $node->get(ConfigBindCollector::class);
      $getData = $node->get(ConfigGetCollector::class);

      $errors = [];

      foreach ($getData as $file => $getEntries) {
         $boundChains = [];

         if (isset($bindData[$file])) {
            foreach ($bindData[$file] as $chain) {
               $boundChains[] = implode('.', $chain);
            }
         }

         foreach ($getEntries as [$chain, $line]) {
            $chainStr = implode('.', $chain);

            if (in_array($chainStr, $boundChains, true)) {
               continue;
            }

            $errors[] = RuleErrorBuilder::message(
               sprintf(
                  'Config property chain "%s" was never bound via ->bind() in this file.',
                  $chainStr
               )
            )
               ->file($file)
               ->line($line)
               ->identifier('bootgly.config.unbound')
               ->build();
         }
      }

      return $errors;
   }
}
