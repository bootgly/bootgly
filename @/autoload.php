<?php
namespace Bootgly;

#@include_once __DIR__ . '/imports/autoload.php';

define('HOME_BASE', rtrim(__DIR__, '/@'));
const HOME_DIR = HOME_BASE . DIRECTORY_SEPARATOR;

// TODO load with autoloader
// @ Bootables
@include_once HOME_BASE . '/abstract/@loader.php';
@include_once HOME_BASE . '/base/@loader.php';
@include_once HOME_BASE . '/core/@loader.php';
// @ Features
@include_once HOME_BASE . '/interfaces/@loader.php';
@include_once HOME_BASE . '/modules/@loader.php';
@include_once HOME_BASE . '/nodes/@loader.php';
@include_once HOME_BASE . '/platforms/@loader.php';
// @ Workables
// composer?
$installed = HOME_BASE . '/../../composer/installed.php';
if ( is_file($installed) ) {
   $installed = @include @$installed;

   $root = $installed['root']['install_path'] ?? null;
   if ($root) {
      $root = realpath($root);
   }

   define('WORKABLES_BASE', $root ?? HOME_BASE);
} else {
   define('WORKABLES_BASE', HOME_BASE);
}

Bootgly::boot();
