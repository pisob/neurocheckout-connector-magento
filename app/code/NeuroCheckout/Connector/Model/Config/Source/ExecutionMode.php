<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ExecutionMode implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'cron_module', 'label' => __('Mode cron dans le module')],
            ['value' => 'cron', 'label' => __('Mode cron serveur (manuel)')],
        ];
    }
}
