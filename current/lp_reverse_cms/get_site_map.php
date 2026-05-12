<?php

declare(strict_types=1);

/**
 * Legacy path shim: clients must use store/get_site_map.php; this avoids Apache
 * "script not found" when /current/lp_reverse_cms/get_site_map.php is requested.
 */
require __DIR__ . '/store/get_site_map.php';
