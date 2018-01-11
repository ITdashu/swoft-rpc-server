<?php

namespace Swoft\Rpc\Server;

use Swoft\App;
use Swoft\Core\DispatcherInterface;
use Swoft\Core\RequestHandler;
use Swoft\Helper\ResponseHelper;
use Swoft\Rpc\Server\Event\RpcServerEvent;
use Swoft\Rpc\Server\Middleware\HandlerAdapterMiddleware;
use Swoft\Rpc\Server\Middleware\PackerMiddleware;
use Swoft\Rpc\Server\Middleware\RouterMiddleware;
use Swoft\Rpc\Server\Middleware\UserMiddleware;
use Swoft\Rpc\Server\Middleware\ValidatorMiddleware;
use Swoft\Rpc\Server\Router\HandlerAdapter;
use Swoft\Rpc\Server\Rpc\Request;
use Swoole\Server;

/**
 * service dispatcher
 *
 * @uses      DispatcherService
 * @version   2017年11月23日
 * @author    stelin <phpcrazy@126.com>
 * @copyright Copyright 2010-2016 swoft software
 * @license   PHP Version 7.x {@link http://www.php.net/license/3_0.txt}
 */
class DispatcherService implements DispatcherInterface
{
    /**
     * service middlewares
     *
     * @var array
     */
    private $middlewares
        = [

        ];

    /**
     * the default of handler adapter
     *
     * @var string
     */
    private $handlerAdapter = HandlerAdapterMiddleware::class;

    /**
     * do dispatcher
     *
     * @param array ...$params
     */
    public function doDispatcher(...$params)
    {
        /**
         * @var Server $server
         * @var int    $fd
         * @var int    $fromid
         * @var string $data
         */
        list($server, $fd, $fromid, $data) = $params;

        try {
            // request middlewares
            $serviceRequest = $this->getRequest($server, $fd, $fromid, $data);
            $middlewares    = $this->requestMiddlewares();
            $requestHandler = new RequestHandler($middlewares, $this->handlerAdapter);

            /* @var \Swoft\Rpc\Server\Rpc\Response $response */
            $response = $requestHandler->handle($serviceRequest);
            $data     = $response->getAttribute(HandlerAdapter::ATTRIBUTE);
        } catch (\Throwable $t) {
            $message = $t->getMessage() . " " . $t->getFile() . " " . $t->getLine();
            $data    = ResponseHelper::formatData("", $message, $t->getCode());
            $data    = service_packer()->pack($data);
        } finally {
            App::trigger(RpcServerEvent::AFTER_RECEIVE);
            $server->send($fd, $data);
        }
    }

    /**
     * middlewares of request
     *
     * @return array
     */
    public function requestMiddlewares()
    {
        return array_merge($this->firstMiddleware(), $this->middlewares, $this->lastMiddleware());
    }

    /**
     * the firsted middlewares
     *
     * @return array
     */
    public function firstMiddleware()
    {
        return [
            PackerMiddleware::class,
            RouterMiddleware::class,
        ];
    }

    /**
     * the lasted middlewares
     *
     * @return array
     */
    public function lastMiddleware()
    {
        return [
            ValidatorMiddleware::class,
            UserMiddleware::class,
        ];
    }

    /**
     * @param \Swoole\Server $server
     * @param int            $fd
     * @param int            $fromid
     * @param string         $data
     *
     * @return Request
     */
    private function getRequest(Server $server, int $fd, int $fromid, string $data)
    {
        $serviceRequest = new Request('get', '/');

        return $serviceRequest->withAttribute(PackerMiddleware::ATTRIBUTE_SERVER, $server)
            ->withAttribute(PackerMiddleware::ATTRIBUTE_FD, $fd)
            ->withAttribute(PackerMiddleware::ATTRIBUTE_FROMID, $fromid)
            ->withAttribute(PackerMiddleware::ATTRIBUTE_DATA, $data);
    }
}
