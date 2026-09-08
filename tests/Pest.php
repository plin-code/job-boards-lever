<?php

declare(strict_types=1);

use PlinCode\JobBoards\Lever\Tests\TestCase;

// Unit tests construct the client by hand and need no framework at all, which
// is the whole point of keeping LeverClient Laravel free. Only the Feature
// suite boots Testbench.
uses(TestCase::class)->in(__DIR__.'/Feature');
