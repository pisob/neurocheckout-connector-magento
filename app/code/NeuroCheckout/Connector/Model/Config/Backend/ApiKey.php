<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Encryption\EncryptorInterface;

class ApiKey extends Value
{
    private const SECRET_VALUE_PREFIX = 'ncenc:';

    private ?EncryptorInterface $encryptor = null;

    /**
     * @return list<string>
     */
    public function __sleep()
    {
        $properties = parent::__sleep();
        return array_values(array_diff($properties, ['encryptor']));
    }

    public function __wakeup()
    {
        parent::__wakeup();
        $this->encryptor = null;
    }

    private function getEncryptor(): EncryptorInterface
    {
        if (!$this->encryptor instanceof EncryptorInterface) {
            $this->encryptor = ObjectManager::getInstance()->get(EncryptorInterface::class);
        }

        return $this->encryptor;
    }

    public function beforeSave(): self
    {
        $value = trim((string)$this->getValue());

        // Magento obscure fields may submit only mask characters ("******")
        // when the value is unchanged. Keep the stored key in that case.
        if ($value !== '' && preg_match('/^\*+$/', $value) === 1) {
            $value = trim((string)$this->getOldValue());
        }

        if ($value !== '' && !str_starts_with($value, self::SECRET_VALUE_PREFIX)) {
            $value = self::SECRET_VALUE_PREFIX . $this->getEncryptor()->encrypt($value);
        }

        $this->setValue($value);

        return parent::beforeSave();
    }
}
