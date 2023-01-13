<?php
namespace Bootgly;

@include_once __DIR__ . '/imports/autoload.php';

const HOME_DIR = __DIR__ . DIRECTORY_SEPARATOR . '../';

require_once __DIR__ . '/../boot/..php';
require_once __DIR__ . '/../core/@loader.php';
require_once __DIR__ . '/../interfaces/@loader.php';

require_once __DIR__ . '/../nodes/@loader.php';
require_once __DIR__ . '/../platforms/@loader.php';

Bootgly::boot();

require HOME_DIR . 'projects/bootgly.constructor.php';