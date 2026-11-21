<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\UcpCheckout\Test\Unit\Controller;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Records what a controller put into its raw result.
 */
class ResultSpy implements ResultInterface
{
    public int $httpCode = 0;

    /** @var array<string, string> */
    public array $headers = [];

    public string $contents = '';

    public function setHttpResponseCode($httpCode)
    {
        $this->httpCode = (int) $httpCode;
        return $this;
    }

    public function setHeader($name, $value, $replace = false)
    {
        $this->headers[(string) $name] = (string) $value;
        return $this;
    }

    public function setContents($contents)
    {
        $this->contents = (string) $contents;
        return $this;
    }

    public function renderResult(ResponseInterface $response)
    {
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return (array) json_decode($this->contents, true);
    }
}
