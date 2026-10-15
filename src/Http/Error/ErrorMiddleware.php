<?php

declare(strict_types=1);

namespace AtarCms\Http\Error;

use AtarCms\Http\Exception\HttpException;
use AtarCms\Http\Responses;
use AtarCms\Http\Security\CspSources;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class ErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ErrorRenderer $renderer,
        private readonly Responses $responses,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $error) {
            return $this->respond($error, $request);
        }
    }

    /**
     * The error page for $error: logged when it is a 5xx, rendered by the ErrorRenderer (a plain page when that fails).
     * SessionMiddleware calls it too, so an error inside the session stack still saves the session.
     */
    public function respond(Throwable $error, ServerRequestInterface $request): ResponseInterface
    {
        $status = $error instanceof HttpException ? $error->statusCode() : 500;
        if ($status >= 500) {
            $this->logger->error('Unhandled {class}: {message}', [
                'class' => $error::class,
                'message' => $error->getMessage(),
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'exception' => $error,
            ]);
        }

        // The failed page's CSP additions (consentHead() before the error) are not the error page's.
        CspSources::of($request)?->reset();
        try {
            $html = $this->renderer->render($error, $status, $request);
        } catch (Throwable $renderError) {
            $this->logger->error('Error renderer failed: {message}', [
                'message' => $renderError->getMessage(),
                'exception' => $renderError,
            ]);
            $html = PlainErrorPage::render($status);
        }

        $response = $this->responses->html($html, $status);
        if ($error instanceof HttpException) {
            foreach ($error->headers() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
