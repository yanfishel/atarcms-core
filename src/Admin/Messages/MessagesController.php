<?php

declare(strict_types=1);

namespace AtarCms\Admin\Messages;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Flash;
use AtarCms\Auth\CurrentUser;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\Translator;
use AtarCms\Messages\Form\FormDefinitions;
use AtarCms\Messages\Message;
use AtarCms\Messages\MessageRepository;
use AtarCms\Messages\MessageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The Messages screen (/admin/messages, MessageService::PERMISSION): the messages of the site's forms, newest first;
 * with more than one form, a filter by form (?form=key, plain links with the unread counts) and a form badge per
 * message. Opening one marks it read and shows its fields (MessageFields); it can be marked unread again or deleted
 * (a confirmation page, audited).
 */
final class MessagesController
{
    public const int PER_PAGE = 30;

    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly MessageRepository $messages,
        private readonly MessageService $service,
        private readonly Router $router,
        private readonly FormDefinitions $forms,
        private readonly MessageFields $fields,
        private readonly Translator $translator,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $locale = RequestLocale::of($request)->code;
        $several = count($this->forms->all()) > 1;
        // ?form= one of the site's forms; anything else lists all.
        $form = $several && is_string($query['form'] ?? null) && $this->forms->has($query['form']) ? $query['form'] : null;
        $pages = max(1, intdiv($this->messages->count($form) + self::PER_PAGE - 1, self::PER_PAGE));
        $requested = $query['page'] ?? null;
        $page = is_string($requested) && preg_match('/^[1-9]\d{0,8}$/', $requested) === 1 ? min((int) $requested, $pages) : 1;
        $messages = $this->messages->latest(self::PER_PAGE, ($page - 1) * self::PER_PAGE, $form);

        $tabs = [];
        if ($several) {
            $unread = $this->messages->unreadCountsByForm();
            $tabs[] = ['label' => $this->translator->translate($locale, 'admin.messages.all'), 'url' => $this->router->url('admin.messages'), 'unread' => array_sum($unread), 'current' => $form === null];
            foreach ($this->forms->all() as $definition) {
                $tabs[] = [
                    'label' => $this->fields->title($locale, $definition->key),
                    'url' => $this->router->url('admin.messages', ['form' => $definition->key]),
                    'unread' => $unread[$definition->key] ?? 0,
                    'current' => $form === $definition->key,
                ];
            }
        }
        $previews = [];
        $titles = [];
        foreach ($messages as $message) {
            $previews[$message->id] = $this->fields->preview($message, $locale);
            $titles[$message->form] ??= $this->fields->title($locale, $message->form);
        }

        return $this->responses->html($this->view->render('messages/index', $request, [
            'messages' => $messages,
            'previews' => $previews,
            // A badge per message only when the list mixes forms.
            'formTitles' => $form === null && ($several || array_diff(array_keys($titles), [FormDefinitions::CONTACT]) !== []) ? $titles : [],
            'tabs' => $tabs,
            'form' => $form,
            'page' => $page,
            'pages' => $pages,
        ]));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $message = $this->message($request);
        $this->service->markRead($message);
        $locale = RequestLocale::of($request)->code;

        return $this->responses->html($this->view->render('messages/show', $request, [
            'message' => $message,
            'formTitle' => count($this->forms->all()) > 1 || $message->form !== FormDefinitions::CONTACT ? $this->fields->title($locale, $message->form) : null,
            'rows' => $this->fields->rows($message, $locale),
        ]));
    }

    public function unread(ServerRequestInterface $request): ResponseInterface
    {
        $this->service->markUnread($this->message($request));
        Flash::success(Session::of($request), 'admin.messages.flash.unread');

        return $this->responses->redirect($this->router->url('admin.messages'), 303);
    }

    public function confirmDelete(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->html($this->view->render('messages/delete', $request, ['message' => $this->message($request)]));
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->service->delete($this->message($request), CurrentUser::require($request), ClientIp::of($request));
        Flash::success(Session::of($request), 'admin.messages.flash.deleted');

        return $this->responses->redirect($this->router->url('admin.messages'), 303);
    }

    private function message(ServerRequestInterface $request): Message
    {
        $id = $request->getAttribute('id');

        return (is_string($id) || is_int($id) ? $this->messages->find((int) $id) : null)
            ?? throw new NotFound(sprintf('No message #%s', is_scalar($id) ? (string) $id : '?'));
    }
}
