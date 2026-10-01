<?php
declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Monitoring;

final class SchedulerHealth
{
    public static function needsAttention(int $lastRun, int $validatedAt, int $interval, int $now): bool
    {
        if ($validatedAt <= 0) {
            return false;
        }
        $reference = $lastRun > 0 ? $lastRun : $validatedAt;
        return $reference > $now || $now - $reference > max(600, 2 * max(60, $interval));
    }
}
