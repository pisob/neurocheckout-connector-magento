<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use NeuroCheckout\Connector\Model\Adminhtml\ScopeResolver;
use NeuroCheckout\Connector\Model\Application\CronExecutor;
use NeuroCheckout\Connector\Model\Config;

class RunCronJson extends Action
{
    public const ADMIN_RESOURCE = 'NeuroCheckout_Connector::config';

    private ScopeResolver $scopeResolver;
    private CronExecutor $cronExecutor;
    private Config $config;

    public function __construct(
        Context $context,
        ScopeResolver $scopeResolver,
        CronExecutor $cronExecutor,
        Config $config
    ) {
        parent::__construct($context);
        $this->scopeResolver = $scopeResolver;
        $this->cronExecutor = $cronExecutor;
        $this->config = $config;
    }

    public function execute(): Json
    {
        $scopeParams = $this->scopeResolver->getScopeParams();
        $storeId = $this->scopeResolver->getEffectiveStoreId($scopeParams);
        $mode = trim((string) $this->getRequest()->getParam('mode', 'test'));

        if (!in_array($mode, ['test', 'force'], true)) {
            return $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON)->setData([
                'success' => false,
                'status' => 400,
                'error' => (string) __('Mode cron invalide'),
                'message' => (string) __('Mode cron invalide. Utilisez "test" ou "force".'),
                'timestamp' => gmdate('Y-m-d H:i:s'),
                'store_id' => $storeId,
            ]);
        }

        if ($mode === 'test' && !$this->config->isDebugModeEnabled($storeId)) {
            return $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON)->setData([
                'success' => false,
                'status' => 403,
                'error' => (string) __('Mode debug cron desactive'),
                'message' => (string) __('Mode debug cron desactive. Activez-le temporairement pour un test manuel securise.'),
                'timestamp' => gmdate('Y-m-d H:i:s'),
                'store_id' => $storeId,
            ]);
        }

        if ($mode === 'force' && !$this->config->isDebugAdvancedEnabled($storeId)) {
            return $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON)->setData([
                'success' => false,
                'status' => 403,
                'error' => (string) __('Mode debug avance desactive'),
                'message' => (string) __('Mode debug avance desactive. Activez-le temporairement avant toute execution forcee.'),
                'timestamp' => gmdate('Y-m-d H:i:s'),
                'store_id' => $storeId,
            ]);
        }

        return $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_JSON)->setData(
            $this->cronExecutor->executeStore($storeId, $mode === 'test', $mode === 'force')
        );
    }
}
