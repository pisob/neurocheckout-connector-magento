<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class MaxDiscountPercent extends Value
{
    public function beforeSave(): self
    {
        $rawValue = trim((string) $this->getValue());

        if ($rawValue === '') {
            throw new LocalizedException(__('Le champ "Reduction maximale IA (%)" est obligatoire.'));
        }

        if (!is_numeric($rawValue)) {
            throw new LocalizedException(
                __('Le champ "Reduction maximale IA (%)" doit etre un nombre entre 0 et 100.')
            );
        }

        $numericValue = (float) $rawValue;
        if ($numericValue < 0 || $numericValue > 100) {
            throw new LocalizedException(
                __('Le champ "Reduction maximale IA (%)" doit etre un nombre entre 0 et 100.')
            );
        }

        $this->setValue((string) round($numericValue, 2));

        return parent::beforeSave();
    }
}
