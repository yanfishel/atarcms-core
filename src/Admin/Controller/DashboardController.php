<?php

declare(strict_types=1);

namespace AtarCms\Admin\Controller;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Dashboard\DashboardData;
use AtarCms\Admin\Dashboard\DayBuckets;
use AtarCms\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DashboardController
{
    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly DashboardData $data,
    ) {}

    /**
     * The dashboard; ?days= picks the activity chart's range (7, 30 or 90, else 30).
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->html($this->view->render('dashboard', $request, [
            'dashboard' => $this->data->for($request, DayBuckets::range($request->getQueryParams()['days'] ?? null)),
        ]));
    }
}
