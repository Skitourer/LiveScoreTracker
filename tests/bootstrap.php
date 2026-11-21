<?php

declare(strict_types=1);

use LiveScoreTracker\Tests\Support\TempDir;

require dirname(__DIR__) . '/vendor/autoload.php';

TempDir::purgeStale();
