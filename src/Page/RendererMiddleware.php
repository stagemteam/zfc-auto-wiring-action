<?php
/**
 * The MIT License (MIT)
 * Copyright (c) 2018 Serhii Popov
 * This source file is subject to The MIT License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @category Stagem
 * @package Stagem_ZfcAction
 * @author Serhii Popov <popow.serhii@gmail.com>
 * @license https://opensource.org/licenses/MIT The MIT License (MIT)
 */

namespace Stagem\ZfcAction\Page;

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

// @todo wait until they will start to use Pst in codebase @see https://github.com/zendframework/zend-mvc/blob/master/src/MiddlewareListener.php#L11
//use Psr\Http\Server\MiddlewareInterface;
//use Psr\Http\Server\RequestHandlerInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Interop\Http\Server\RequestHandlerInterface;

use Zend\Diactoros\Response\HtmlResponse;
use Zend\Diactoros\Response\JsonResponse;
use Zend\Diactoros\Response\SapiEmitter;
use Zend\Diactoros\Response\TextResponse;
//use Zend\Expressive\Router;
//use Zend\Expressive\Template;
use Zend\Stdlib\Exception\RuntimeException;
use Zend\View\Model\ViewModel;
use Zend\View\Model\JsonModel;
//use Zend\View\Renderer\PhpRenderer;
use Zend\View\Renderer\RendererInterface;
use Zend\Mvc\View\Http\ViewManager;
use Zend\Http\Response\Stream as HttpStream;
use Zend\Diactoros\Stream;
use Zend\Diactoros\Response;

class RendererMiddleware implements MiddlewareInterface
{
    const AREA_DEFAULT = 'default';

    /**
     * @var RendererInterface
     */
    protected $renderer;

    /**
     * @var ViewManager
     */
    protected $view;

    public function __construct(RendererInterface $renderer, $view = null)
    {
        $this->renderer = $renderer;
        $this->view = $view;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!($viewModel = $request->getAttribute(ViewModel::class))) {
            return $handler->handle($request);
        }

        if ($viewModel instanceof HttpStream) {
            // @todo Improve realization
            // maybe will be created bridge Stream
            // @see https://gist.github.com/settermjd/feffc1c15a5bade95967be2229fa5537
            // there is great Symfony Bundle https://github.com/symfony/psr-http-message-bridge
            $body = new Stream('php://temp', 'w+');
            $body->write($viewModel->getBody());

            $response = new Response();
            foreach ($viewModel->getHeaders() as $name => $header) {
                $response = $response->withHeader($header->getFieldName(), $header->getFieldValue());
            }
            return $response->withBody($body)
                ->withHeader('Content-Length', "{$viewModel->getContentLength()}");
        }

        if ($viewModel instanceof JsonModel && $viewModel->terminate()) {
             return new JsonResponse($viewModel->getVariables());
        }

        $templates = $this->resolveTemplates($request);
        $viewModel->getVariable('layout') || $viewModel->setVariable('layout', $templates['layout']);
        $viewModel->getTemplate() || $viewModel->setTemplate($templates['name']);

        //$content = $this->renderer->render($viewModel->getTemplate(), $viewModel);
        $content = $this->renderer->render($viewModel);
        if ($this->view) {
            $layout = $this->view->getViewModel();
            $layout->setVariable('content', $content);
            $content = $this->renderer->render($layout);
        }

        return new HtmlResponse($content);
    }

    /**
     * Get template name based on module and action name
     *
     * @param $request
     * @return array
     */
    protected function resolveTemplates($request)
    {
        #$module = $request->getAttribute('resource', $request->getAttribute('controller'));
        $module = $request->getAttribute('resource');
        $action = $request->getAttribute('action');
        $area = $request->getAttribute('area', self::AREA_DEFAULT);

        if (!$module || !$action) {
            throw new RuntimeException(
                'Cannot resolve action name. '
                . 'Check if your route has "resource" and "action" as named variables or add relative options to route'
            );
        }

        $layout = 'layout::' . $area;

        $templateName = '';
        if ($area !== self::AREA_DEFAULT) {
            $templateName .= $area . '-';
        }
        $templateName .= $module . '::' . $action;

        return [
            'layout' => $layout,
            'name' => $templateName,
        ];
    }
}