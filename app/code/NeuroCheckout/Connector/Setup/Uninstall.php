<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use NeuroCheckout\Connector\Model\Setup\DataPurger;

class Uninstall implements UninstallInterface
{
    private DataPurger $dataPurger;

    public function __construct(DataPurger $dataPurger)
    {
        $this->dataPurger = $dataPurger;
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();
        try {
            $this->dataPurger->purge();
        } finally {
            $setup->endSetup();
        }
    }
}
