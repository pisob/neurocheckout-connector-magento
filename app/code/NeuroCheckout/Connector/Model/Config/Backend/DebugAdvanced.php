<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

class DebugAdvanced extends Value
{
    public function beforeSave(): self
    {
        $debugMode = (string) $this->getFieldsetDataValue('debug_mode') === '1';
        $debugAdvanced = (string) $this->getValue() === '1';

        if ($debugMode && $debugAdvanced) {
            $this->setValue('0');
        }

        return parent::beforeSave();
    }
}
