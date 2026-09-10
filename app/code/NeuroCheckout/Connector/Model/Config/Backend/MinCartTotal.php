<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class MinCartTotal extends Value
{
    public function beforeSave(): self
    {
        $rawValue = trim((string) $this->getValue());

        if ($rawValue === '') {
            throw new LocalizedException(__('Le champ "Montant minimum du panier" est obligatoire.'));
        }

        if (!is_numeric($rawValue) || (float) $rawValue < 0) {
            throw new LocalizedException(
                __('Le champ "Montant minimum du panier" doit etre un nombre superieur ou egal a 0.')
            );
        }

        $this->setValue((string) round((float) $rawValue, 2));

        return parent::beforeSave();
    }
}
