<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
HomeAlone\Application::create(database())->run();
