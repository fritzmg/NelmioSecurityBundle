<?php

declare(strict_types=1);

/*
 * This file is part of the Nelmio SecurityBundle.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\SecurityBundle\EventListener;

use Nelmio\SecurityBundle\ContentSecurityPolicy\DirectiveSet;
use Nelmio\SecurityBundle\ContentSecurityPolicy\NonceGenerator;
use Nelmio\SecurityBundle\ContentSecurityPolicy\ShaComputer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @final
 */
class ContentSecurityPolicyListener extends AbstractContentTypeRestrictableListener
{
    private DirectiveSet $report;
    private DirectiveSet $enforce;
    private bool $compatHeaders;

    /**
     * @var list<string>
     */
    private array $hosts;
    private ?string $_nonce = null;
    private ?string $scriptNonce = null;
    private ?string $styleNonce = null;

    /**
     * @var array<string, list<string>>|null
     */
    private ?array $sha = null;
    private NonceGenerator $nonceGenerator;
    private ShaComputer $shaComputer;

    /**
     * @param list<string> $hosts
     * @param list<string> $contentTypes
     */
    public function __construct(
        DirectiveSet $report,
        DirectiveSet $enforce,
        NonceGenerator $nonceGenerator,
        ShaComputer $shaComputer,
        bool $compatHeaders = true,
        array $hosts = [],
        array $contentTypes = []
    ) {
        parent::__construct($contentTypes);
        $this->report = $report;
        $this->enforce = $enforce;
        $this->compatHeaders = $compatHeaders;
        $this->hosts = $hosts;
        $this->nonceGenerator = $nonceGenerator;
        $this->shaComputer = $shaComputer;
    }

    public function onKernelRequest(RequestEvent $e): void
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $e->getRequestType()) {
            return;
        }

        $this->sha = [];
    }

    public function addSha(string $directive, string $sha): void
    {
        if (null === $this->sha) {
            // We're not in a request context, probably in a worker
            // let's disable it to avoid memory leak
            return;
        }

        $this->sha[$directive][] = $sha;
    }

    public function addScript(string $html): void
    {
        if (null === $this->sha) {
            // We're not in a request context, probably in a worker
            // let's disable it to avoid memory leak
            return;
        }

        $this->sha['script-src'][] = $this->shaComputer->computeForScript($html);
    }

    public function addStyle(string $html): void
    {
        if (null === $this->sha) {
            // We're not in a request context, probably in a worker
            // let's disable it to avoid memory leak
            return;
        }

        $this->sha['style-src'][] = $this->shaComputer->computeForStyle($html);
    }

    public function getReport(): DirectiveSet
    {
        return $this->report;
    }

    public function getEnforcement(): DirectiveSet
    {
        return $this->enforce;
    }

    public function getNonce(string $usage): string
    {
        $nonce = $this->doGetNonce();

        if ('script' === $usage) {
            $this->scriptNonce = $nonce;
        } elseif ('style' === $usage) {
            $this->styleNonce = $nonce;
        } else {
            throw new \InvalidArgumentException('Invalid usage provided');
        }

        return $nonce;
    }

    private function doGetNonce(): string
    {
        if (null === $this->_nonce) {
            $this->_nonce = $this->nonceGenerator->generate();
        }

        return $this->_nonce;
    }

    public function onKernelResponse(ResponseEvent $e): void
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $e->getRequestType()) {
            return;
        }

        $request = $e->getRequest();
        $response = $e->getResponse();

        if ($response->isRedirection()) {
            $this->_nonce = null;
            $this->styleNonce = null;
            $this->scriptNonce = null;
            $this->sha = null;

            return;
        }

        if (([] === $this->hosts || in_array($e->getRequest()->getHost(), $this->hosts, true)) && $this->isContentTypeValid($response)) {
            $signatures = $this->sha;
            if (null !== $this->scriptNonce) {
                $signatures['script-src'][] = 'nonce-'.$this->scriptNonce;
            }
            if (null !== $this->styleNonce) {
                $signatures['style-src'][] = 'nonce-'.$this->styleNonce;
            }

            $response->headers->add($this->buildHeaders($request, $this->report, true, $this->compatHeaders, $signatures));
            $response->headers->add($this->buildHeaders($request, $this->enforce, false, $this->compatHeaders, $signatures));
        }

        $this->_nonce = null;
        $this->styleNonce = null;
        $this->scriptNonce = null;
        $this->sha = null;
    }

    /**
     * @param array<string, list<string>>|null $signatures
     *
     * @return array<string, string>
     */
    private function buildHeaders(
        Request $request,
        DirectiveSet $directiveSet,
        bool $reportOnly,
        bool $compatHeaders,
        array $signatures = null
    ): array {
        // $signatures might be null if no KernelEvents::REQUEST has been triggered.
        // for instance if a security.authentication.failure has been dispatched
        $headerValue = $directiveSet->buildHeaderValue($request, $signatures);

        if ('' === $headerValue) {
            return [];
        }

        $hn = function (string $name) use ($reportOnly): string {
            return $name.($reportOnly ? '-Report-Only' : '');
        };

        $headers = [
            $hn('Content-Security-Policy') => $headerValue,
        ];

        if ($compatHeaders) {
            $headers[$hn('X-Content-Security-Policy')] = $headerValue;
        }

        return $headers;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 512],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }
}