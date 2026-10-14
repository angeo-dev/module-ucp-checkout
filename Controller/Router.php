<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Controller;

use Angeo\Ucp\Model\Config as UcpConfig;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Routes the UCP checkout REST paths (services/shopping/rest.openapi.json,
 * tag v2026-08-25) and the continue_url page:
 *
 *   POST {endpoint}/checkout-sessions               -> Checkout\Create
 *   GET  {endpoint}/checkout-sessions/{id}          -> Checkout\Session (get)
 *   PUT  {endpoint}/checkout-sessions/{id}          -> Checkout\Session (update)
 *   POST {endpoint}/checkout-sessions/{id}/complete -> Checkout\Complete
 *   POST {endpoint}/checkout-sessions/{id}/cancel   -> Checkout\Cancel
 *   GET  /ucp/checkout/continue/{token}             -> Checkout\Resume
 *
 * {endpoint} is /ucp/v1 (Angeo_Ucp 2.2.0 default) or the path of the REST
 * endpoint configured in admin. Path sources are checked as in
 * Angeo_UcpCatalog, because LiteSpeed leaves PATH_INFO unreliable.
 */
class Router implements RouterInterface
{
    public const PARAM_ID    = 'ucp_checkout_id';
    public const PARAM_TOKEN = 'ucp_continue_token';

    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly UcpConfig $ucpConfig
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof HttpRequest) {
            return null;
        }

        foreach ($this->candidatePaths($request) as $path) {
            $route = self::route($path, $this->basePaths());
            if ($route === null) {
                continue;
            }
            [$class, $action, $params] = $route;
            foreach ($params as $key => $value) {
                $request->setParam($key, $value);
            }
            $request->setModuleName('angeo_ucp_checkout')
                ->setControllerName('checkout')
                ->setActionName($action);
            return $this->actionFactory->create($class);
        }
        return null;
    }

    /**
     * Pure path matcher (unit-tested).
     *
     * @param array<int, string> $basePaths
     * @return array{0: class-string, 1: string, 2: array<string, string>}|null
     */
    public static function route(string $path, array $basePaths): ?array
    {
        $path = '/' . trim($path, '/');

        if (preg_match('#^/ucp/checkout/continue/([0-9a-f]{48})$#', $path, $m)) {
            return [Checkout\Resume::class, 'resume', [self::PARAM_TOKEN => $m[1]]];
        }

        foreach ($basePaths as $base) {
            $base = '/' . trim($base, '/');
            if (!str_starts_with($path, $base . '/checkout-sessions')) {
                continue;
            }
            $rest = substr($path, strlen($base . '/checkout-sessions'));
            if ($rest === '') {
                return [Checkout\Create::class, 'create', []];
            }
            if (preg_match('#^/([A-Za-z0-9_\-]{1,64})$#', $rest, $m)) {
                return [Checkout\Session::class, 'session', [self::PARAM_ID => $m[1]]];
            }
            if (preg_match('#^/([A-Za-z0-9_\-]{1,64})/(complete|cancel)$#', $rest, $m)) {
                $class = $m[2] === 'complete' ? Checkout\Complete::class : Checkout\Cancel::class;
                return [$class, $m[2], [self::PARAM_ID => $m[1]]];
            }
        }
        return null;
    }

    /**
     * @return array<int, string>
     */
    private function basePaths(): array
    {
        $paths = [UcpConfig::DEFAULT_REST_PATH];
        $configured = (string) parse_url($this->ucpConfig->getRestEndpoint(), PHP_URL_PATH);
        $configured = '/' . trim($configured, '/');
        if ($configured !== '/' && !in_array($configured, $paths, true)) {
            $paths[] = $configured;
        }
        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private function candidatePaths(HttpRequest $request): array
    {
        $out = [];
        foreach ([$request->getPathInfo(), $request->getOriginalPathInfo(), $request->getRequestUri()] as $raw) {
            $raw = (string) $raw;
            if ($raw !== '') {
                $out[] = (string) (parse_url($raw, PHP_URL_PATH) ?: $raw);
            }
        }
        return array_values(array_unique($out));
    }
}
