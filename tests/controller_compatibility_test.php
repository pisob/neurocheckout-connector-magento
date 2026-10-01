<?php
declare(strict_types=1);
namespace Magento\Framework\App\Action { interface HttpPostActionInterface {} }
namespace Magento\Framework\App { interface CsrfAwareActionInterface {} }
namespace {
    if (PHP_SAPI !== 'cli') { exit(1); }
    require dirname(__DIR__) . '/app/code/NeuroCheckout/Connector/Controller/Community/Pull.php';
    $controller = new ReflectionClass(\NeuroCheckout\Connector\Controller\Community\Pull::class);
    if ($controller->isFinal() || $controller->getMethod('execute')->isFinal()) {
        throw new RuntimeException('Magento must be able to generate controller interceptors');
    }
    class ControllerInterceptorFixture extends \NeuroCheckout\Connector\Controller\Community\Pull {}
    if (!is_subclass_of(ControllerInterceptorFixture::class, $controller->getName())) {
        throw new RuntimeException('Controller inheritance failed');
    }
    echo "Controller interceptor compatibility passed (framework doubles).\n";
}
