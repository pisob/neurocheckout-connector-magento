<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/code/NeuroCheckout/Connector/Model/Monitoring/SchedulerHealth.php';
use NeuroCheckout\Connector\Model\Monitoring\SchedulerHealth;
foreach ([
    [0, 0, 300, 2000, false],
    [0, 1800, 300, 2000, false],
    [0, 1000, 300, 2000, true],
    [1900, 1000, 300, 2000, false],
    [1000, 900, 300, 2000, true],
    [1000, 900, 900, 2000, false],
    [1400, 900, 300, 2000, false],
    [2100, 900, 300, 2000, true],
] as [$last, $validated, $interval, $now, $expected]) {
    if (SchedulerHealth::needsAttention($last, $validated, $interval, $now) !== $expected) {
        throw new RuntimeException('Incorrect scheduler warning');
    }
}
echo "8 scheduler health checks passed.\n";
