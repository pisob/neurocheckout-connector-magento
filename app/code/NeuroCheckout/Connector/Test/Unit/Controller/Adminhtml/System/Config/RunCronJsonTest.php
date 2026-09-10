<?php

declare(strict_types=1);

namespace NeuroCheckout\Connector\Test\Unit\Controller\Adminhtml\System\Config;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use NeuroCheckout\Connector\Controller\Adminhtml\System\Config\RunCronJson;
use NeuroCheckout\Connector\Model\Adminhtml\ScopeResolver;
use NeuroCheckout\Connector\Model\Application\CronExecutor;
use NeuroCheckout\Connector\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RunCronJsonTest extends TestCase
{
    private RequestInterface&MockObject $request;
    private ResultFactory&MockObject $resultFactory;
    private Json&MockObject $jsonResult;
    private ScopeResolver&MockObject $scopeResolver;
    private CronExecutor&MockObject $cronExecutor;
    private Config&MockObject $config;
    private RunCronJson $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->resultFactory = $this->createMock(ResultFactory::class);
        $this->jsonResult = $this->createMock(Json::class);
        $this->scopeResolver = $this->createMock(ScopeResolver::class);
        $this->cronExecutor = $this->createMock(CronExecutor::class);
        $this->config = $this->createMock(Config::class);

        $context = (new ObjectManager($this))->getObject(Context::class, [
            'request' => $this->request,
            'resultFactory' => $this->resultFactory,
        ]);

        $this->controller = (new ObjectManager($this))->getObject(RunCronJson::class, [
            'context' => $context,
            'scopeResolver' => $this->scopeResolver,
            'cronExecutor' => $this->cronExecutor,
            'config' => $this->config,
        ]);

        $this->scopeResolver->method('getScopeParams')->willReturn([]);
        $this->scopeResolver->method('getEffectiveStoreId')->willReturn(7);
        $this->resultFactory->method('create')->with(ResultFactory::TYPE_JSON)->willReturn($this->jsonResult);
        $this->jsonResult->method('setData')->willReturnSelf();
    }

    public function testExecuteReturnsInvalidModePayload(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['mode', 'test', 'oops'],
        ]);

        $this->jsonResult
            ->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $payload): bool {
                return $payload['success'] === false
                    && $payload['status'] === 400
                    && $payload['error'] === 'Mode cron invalide'
                    && $payload['store_id'] === 7;
            }))
            ->willReturnSelf();

        $this->assertSame($this->jsonResult, $this->controller->execute());
    }

    public function testExecuteReturnsDebugDisabledPayload(): void
    {
        $this->request->method('getParam')->willReturnMap([
            ['mode', 'test', 'test'],
        ]);
        $this->config->method('isDebugModeEnabled')->with(7)->willReturn(false);

        $this->jsonResult
            ->expects($this->once())
            ->method('setData')
            ->with($this->callback(function (array $payload): bool {
                return $payload['success'] === false
                    && $payload['status'] === 403
                    && $payload['error'] === 'Mode debug cron desactive'
                    && str_contains((string) $payload['message'], 'test manuel securise');
            }))
            ->willReturnSelf();

        $this->assertSame($this->jsonResult, $this->controller->execute());
    }

    public function testExecuteDelegatesToCronExecutorWhenForceModeIsAllowed(): void
    {
        $payload = [
            'success' => true,
            'status' => 200,
            'message' => 'Execution terminee avec succes',
        ];

        $this->request->method('getParam')->willReturnMap([
            ['mode', 'test', 'force'],
        ]);
        $this->config->method('isDebugAdvancedEnabled')->with(7)->willReturn(true);
        $this->cronExecutor
            ->expects($this->once())
            ->method('executeStore')
            ->with(7, false, true)
            ->willReturn($payload);

        $this->jsonResult
            ->expects($this->once())
            ->method('setData')
            ->with($payload)
            ->willReturnSelf();

        $this->assertSame($this->jsonResult, $this->controller->execute());
    }
}
