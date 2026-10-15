<?php

declare(strict_types=1);

namespace AtarCms\Admin\Controller;

use AtarCms\Admin\AdminView;
use AtarCms\Auth\AuditLog;
use AtarCms\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuditLogController
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly AuditLog $audit,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $pages = max(1, intdiv($this->audit->count() + self::PER_PAGE - 1, self::PER_PAGE));
        $requested = $request->getQueryParams()['page'] ?? null;
        $page = is_string($requested) && preg_match('/^[1-9]\d{0,8}$/', $requested) === 1 ? min((int) $requested, $pages) : 1;

        return $this->responses->html($this->view->render('audit/index', $request, [
            'entries' => $this->audit->latest(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'pages' => $pages,
        ]));
    }
}
