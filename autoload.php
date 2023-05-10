<?php
#@include_once __DIR__ . '/@/imports/autoload.php';

define('BOOTGLY_HOME_BASE', __DIR__);
define('BOOTGLY_HOME_DIR', __DIR__ . DIRECTORY_SEPARATOR);

// TODO load with autoloader
// @ Bootables
@include_once BOOTGLY_HOME_BASE . '/Bootgly/abstract/loader.php';
@include_once BOOTGLY_HOME_BASE . '/Bootgly/base/loader.php';
@include_once BOOTGLY_HOME_BASE . '/Bootgly/core/loader.php';
// @ Features
@include_once BOOTGLY_HOME_BASE . '/Bootgly/interfaces/loader.php';
@include_once BOOTGLY_HOME_BASE . '/Bootgly/modules/loader.php';
@include_once BOOTGLY_HOME_BASE . '/Bootgly/nodes/loader.php';
@include_once BOOTGLY_HOME_BASE . '/Bootgly/platforms/loader.php';
// @ Workables
// composer?
$installed = BOOTGLY_HOME_BASE . '/../../composer/installed.php';
if ( is_file($installed) ) {
   $installed = @include @$installed;

   $root = $installed['root']['install_path'] ?? null;
   if ($root) {
      $root = realpath($root);
   }

   define('BOOTGLY_WORKABLES_BASE', $root ?? BOOTGLY_HOME_BASE);
} else {
   define('BOOTGLY_WORKABLES_BASE', BOOTGLY_HOME_BASE);
}
define('BOOTGLY_WORKABLES_DIR', BOOTGLY_HOME_BASE . DIRECTORY_SEPARATOR);

// ! Bootgly
require BOOTGLY_HOME_DIR . 'Bootgly.php';

\Bootgly\Bootgly::boot();
