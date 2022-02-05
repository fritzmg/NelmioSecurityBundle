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

namespace Nelmio\SecurityBundle\Tests\Listener;

use Nelmio\SecurityBundle\EventListener\ClickjackingListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ClickjackingListenerTest extends ListenerTestCase
{
    private ClickjackingListener $listener;

    protected function setUp(): void
    {
        $this->listener = new ClickjackingListener([
            '^/frames/' => ['header' => 'ALLOW'],
            '/frames/' => ['header' => 'SAMEORIGIN'],
            '^.*\?[^\?]*foo=bar' => ['header' => 'ALLOW'],
            '/this/allow' => ['header' => 'ALLOW-FROM http://biz.domain.com'],
            '^/.*' => ['header' => 'DENY'],
            '.*' => ['header' => 'ALLOW'],
        ]);
    }

    /**
     * @dataProvider provideClickjackingMatches
     */
    public function testClickjackingMatches(string $path, ?string $result): void
    {
        $response = $this->callListener($this->listener, $path, true);
        $this->assertSame($result, $response->headers->get('X-Frame-Options'));
    }

    public function provideClickjackingMatches(): array
    {
        return [
            ['', 'DENY'],
            ['/', 'DENY'],
            ['/test', 'DENY'],
            ['/path?test&foo=bar&another', null],
            ['/path?foo=bar', null],
            ['/frames/foo', null],
            ['/this/allow', 'ALLOW-FROM http://biz.domain.com'],
            ['/sub/frames/foo', 'SAMEORIGIN'],
        ];
    }

    public function testClickjackingSkipsSubReqs(): void
    {
        $response = $this->callListener($this->listener, '/', false);
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    public function testClickjackingSkipsOnRedirection(): void
    {
        $request = Request::create('/');
        $response = new RedirectResponse('/redirect');

        $event = $this->createResponseEvent($request, true, $response);
        $this->listener->onKernelResponse($event);
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    protected function callListener(ClickjackingListener $listener, string $path, bool $mainReq, string $contentType = 'text/html'): Response
    {
        $request = Request::create($path);
        $response = new Response();
        $response->headers->add(['content-type' => $contentType]);

        $event = $this->createResponseEvent($request, $mainReq, $response);
        $listener->onKernelResponse($event);

        return $response;
    }

    /**
     * @dataProvider provideContentTypeForRestrictions
     */
    public function testClickjackingWithContentTypes(string $contentType, ?string $result): void
    {
        $this->listener = new ClickjackingListener([
            '^/frames/' => ['header' => 'ALLOW'],
            '/frames/' => ['header' => 'SAMEORIGIN'],
            '^/.*' => ['header' => 'DENY'],
            '.*' => ['header' => 'ALLOW'],
        ], ['text/html']);

        $response = $this->callListener($this->listener, '/', true, $contentType);
        $this->assertSame($result, $response->headers->get('X-Frame-Options'));
    }

    public function provideContentTypeForRestrictions(): array
    {
        return [
            ['application/json', null],
            ['text/html', 'DENY'],
        ];
    }
}
