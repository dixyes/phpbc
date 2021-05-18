<?php

declare(strict_types=1);

include_once __DIR__ . "/vendor/autoload.php";

use PHPbc\PHPbc;
use PHPbc\Util;

// make all warnings into exceptions
Util::enable_error_handler();
PHPbc::run();
