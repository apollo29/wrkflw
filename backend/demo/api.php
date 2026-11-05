<?php

declare(strict_types=1);

/*
 * Bootstrap für `api/index.php` in der Demo:
 *
 *   WF_API_BOOTSTRAP=demo/api.php WF_API_OPEN=1 php -S 127.0.0.1:8080 -t api
 */

require_once __DIR__ . '/services.php';

return wf_demo_services()['services'];
