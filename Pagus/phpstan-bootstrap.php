<?php

declare(strict_types=1);

define('APPPATH', __DIR__ . '/app/');
define('ROOTPATH', __DIR__ . '/');
define('SYSTEMPATH', __DIR__ . '/vendor/codeigniter4/framework/system/');
define('WRITEPATH', __DIR__ . '/writable/');
define('FCPATH', __DIR__ . '/public/');
define('ENVIRONMENT', 'testing');
define('CI_DEBUG', true);

require APPPATH . 'Config/Constants.php';
require SYSTEMPATH . 'Common.php';
