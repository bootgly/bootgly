<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Environment\Configs\Config;


return new Specification(
   description: 'Config: construct, __get creates tree, navigation',
   test: function () {
      $Config = new Config(scope: 'database');

      // @ Root node
      yield assert(
         assertion: $Config->scope === 'database',
         description: 'scope set correctly on root'
      );
      yield assert(
         assertion: $Config->name === null,
         description: 'root name is null'
      );
      yield assert(
         assertion: $Config->parent === null,
         description: 'root has no parent'
      );

      // @ __get creates child
      $Child = $Config->Connections;
      yield assert(
         assertion: $Child instanceof Config,
         description: 'child is Config instance'
      );
      yield assert(
         assertion: $Child->name === 'Connections',
         description: 'child name matches property'
      );
      yield assert(
         assertion: $Child->scope === 'database',
         description: 'child inherits scope from root'
      );
      yield assert(
         assertion: $Child->parent === $Config,
         description: 'child parent is root'
      );

      // @ Same child returned on repeated access
      $Same = $Config->Connections;
      yield assert(
         assertion: $Same === $Child,
         description: 'same instance on repeated access'
      );

      // @ Deep tree
      $MySQL = $Config->Connections->MySQL;
      yield assert(
         assertion: $MySQL->name === 'MySQL',
         description: 'deep child name correct'
      );
      yield assert(
         assertion: $MySQL->scope === 'database',
         description: 'deep child inherits scope'
      );
      yield assert(
         assertion: $MySQL->parent === $Child,
         description: 'deep child parent is Connections'
      );

      // @ up() returns parent
      yield assert(
         assertion: $MySQL->up() === $Child,
         description: 'up returns parent'
      );
      yield assert(
         assertion: $Config->up() === $Config,
         description: 'up on root returns self'
      );

      // @ down() returns last accessed child
      $Config->Connections;
      yield assert(
         assertion: $Config->down() === $Child,
         description: 'down returns last accessed child'
      );

      // @ Siblings have independent names
      $Host = $Config->Host;
      $Port = $Config->Port;
      yield assert(
         assertion: $Host->name === 'Host',
         description: 'sibling Host has name Host'
      );
      yield assert(
         assertion: $Port->name === 'Port',
         description: 'sibling Port has name Port'
      );
      yield assert(
         assertion: $Host->name !== $Port->name,
         description: 'sibling names are independent'
      );

      // @ last updates on each __get call
      $Config->Host;
      yield assert(
         assertion: $Config->down() === $Host,
         description: 'down returns Host after accessing Host'
      );
      $Config->Port;
      yield assert(
         assertion: $Config->down() === $Port,
         description: 'down updates to Port after accessing Port'
      );

      // @ name is case-sensitive (stored exactly as accessed)
      $Lower = $Config->connections;
      yield assert(
         assertion: $Lower->name === 'connections',
         description: 'lowercase access stores lowercase name'
      );
      yield assert(
         assertion: $Lower !== $Child,
         description: 'lowercase key creates different child than uppercase'
      );

      // @ grandchild name is immediate key only, not full path
      $Grandchild = $Config->Connections->MySQL;
      yield assert(
         assertion: $Grandchild->name === 'MySQL',
         description: 'grandchild name is its own key, not path'
      );

      // @ parent chain intact at 3 levels
      yield assert(
         assertion: $Grandchild->parent === $Config->Connections,
         description: 'grandchild parent is Connections'
      );
      yield assert(
         assertion: $Grandchild->parent->parent === $Config,
         description: 'grandchild grandparent is root'
      );
      yield assert(
         assertion: $Grandchild->parent->parent->parent === null,
         description: 'root has no parent (null at top)'
      );

      // @ children from different root scopes are independent
      $Server = new Config(scope: 'server');
      $ServerHost = $Server->Host;
      $DbHost = $Config->Host;
      yield assert(
         assertion: $ServerHost->scope === 'server',
         description: 'server child inherits server scope'
      );
      yield assert(
         assertion: $DbHost->scope === 'database',
         description: 'database child keeps database scope'
      );
      yield assert(
         assertion: $ServerHost !== $DbHost,
         description: 'same property name on different scopes yields different instances'
      );
   }
);
