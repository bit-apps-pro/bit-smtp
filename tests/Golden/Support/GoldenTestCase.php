<?php

declare(strict_types=1);

namespace BitApps\SMTP\Tests\Golden\Support;

use BitApps\SMTP\Tests\BaseUnitTestCase;

abstract class GoldenTestCase extends BaseUnitTestCase
{
    use MatchesGolden;
}
