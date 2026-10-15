<?php

declare(strict_types=1);

namespace AtarCms\Admin\Controller;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Dialog;
use AtarCms\Admin\Flash;
use AtarCms\Admin\FormErrors;
use AtarCms\Auth\CurrentUser;
use AtarCms\Auth\Roles;
use AtarCms\Auth\User;
use AtarCms\Auth\UserManager;
use AtarCms\Auth\UserRepository;
use AtarCms\Auth\UserStatus;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\Forbidden;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Users: the list, the new/edit form and delete with a confirmation. Requested for the dialog (Dialog, the users
 * list), the form and the confirmation are the dialog's fragments, and a done save or delete sends the browser back
 * to the list with its flash; without JavaScript the same URLs are full pages. UserManager enforces the rules (roles
 * one may grant, nothing of one's own role or status, never deleting oneself). Routes require "users.manage".
 */
final class UsersController
{
    /** The form's fields: an error under another key (a delete refusal) is a toast. */
    private const array FIELDS = ['email', 'name', 'role', 'status'];

    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly UserRepository $users,
        private readonly UserManager $manager,
        private readonly Roles $roles,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $actor = CurrentUser::require($request);
        $users = $this->users->all();
        $manageable = [];
        $deletable = [];
        foreach ($users as $user) {
            $manageable[$user->id] = $this->manager->canManage($actor, $user);
            $deletable[$user->id] = $this->manager->canDelete($actor, $user);
        }

        return $this->responses->html($this->view->render('users/index', $request, [
            'users' => $users,
            'roles' => $this->roles->labels(),
            'manageable' => $manageable,
            'deletable' => $deletable,
            'actorId' => $actor->id,
        ]));
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->form($request, null, [
            'email' => '',
            'name' => '',
            'role' => '',
            'status' => UserStatus::Active->value,
        ], []);
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $input = self::input($request);

        try {
            $this->manager->create($input['email'], $input['role'], $input['name'], CurrentUser::require($request), ClientIp::of($request));
        } catch (ValidationFailed $e) {
            return $this->form($request, null, $input, $e->errors, $e->params, 422);
        }

        Flash::success(Session::of($request), 'admin.users.created');

        return $this->done($request);
    }

    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->manageable($request);

        return $this->form($request, $user, [
            'email' => $user->email,
            'name' => $user->name,
            'role' => $user->role,
            'status' => $user->status->value,
        ], []);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->manageable($request);
        $actor = CurrentUser::require($request);
        $input = [...self::input($request), 'email' => $user->email];
        if ($user->id === $actor->id) {
            // Own role and status cannot change here; ignore whatever was posted for them.
            $input['role'] = $user->role;
            $input['status'] = $user->status->value;
        }
        $body = $request->getParsedBody();
        $original = is_array($body) && is_string($body['original_status'] ?? null) ? UserStatus::tryFrom($body['original_status']) : null;

        try {
            $this->manager->update($user, $input['name'], $input['role'], UserStatus::tryFrom($input['status']), $actor, ClientIp::of($request), $original);
        } catch (ValidationFailed $e) {
            return $this->form($request, $user, $input, $e->errors, $e->params, 422);
        }
        Flash::success(Session::of($request), 'admin.users.saved');

        return $this->done($request);
    }

    /**
     * The confirmation, from the dialog or as a page; for oneself (no button leads here) the refusal as an error toast.
     */
    public function confirmDelete(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->manageable($request);
        if ($user->id === CurrentUser::require($request)->id) {
            Flash::error(Session::of($request), 'validation.user.self_delete');

            return $this->done($request);
        }

        return $this->responses->html($this->view->render(Dialog::requested($request) ? 'users/delete-dialog' : 'users/delete', $request, [
            'user' => $user,
        ]));
    }

    /**
     * Deletes through UserManager; a refusal (oneself) is an error toast on the list, from the dialog or the page.
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->manageable($request);
        $session = Session::of($request);

        try {
            $this->manager->delete($user, CurrentUser::require($request), ClientIp::of($request));
            Flash::success($session, 'admin.users.deleted');
        } catch (ValidationFailed $e) {
            Flash::refusal($session, $e);
        }

        return $this->done($request);
    }

    private function userFrom(ServerRequestInterface $request): User
    {
        $id = $request->getAttribute('id');
        $user = is_string($id) && strlen($id) <= 10 ? $this->users->find((int) $id) : null;

        return $user ?? throw new NotFound('No such user');
    }

    /**
     * The user of the URL, when the current user may manage them (UserManager::canManage()).
     */
    private function manageable(ServerRequestInterface $request): User
    {
        $user = $this->userFrom($request);
        if (!$this->manager->canManage(CurrentUser::require($request), $user)) {
            throw new Forbidden('Cannot manage a user with a role you cannot grant');
        }

        return $user;
    }

    /**
     * After a save or a delete: the users list, where the change shows (from the dialog through HX-Redirect).
     */
    private function done(ServerRequestInterface $request): ResponseInterface
    {
        $url = $this->router->url('admin.users');

        return Dialog::requested($request) ? Dialog::redirect($this->responses, $url) : $this->responses->redirect($url, 303);
    }

    /**
     * @return array{email: string, name: string, role: string, status: string}
     */
    private static function input(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        $value = static function (string $key) use ($body): string {
            $raw = is_array($body) ? ($body[$key] ?? null) : null;

            return is_string($raw) ? $raw : '';
        };

        return ['email' => $value('email'), 'name' => $value('name'), 'role' => $value('role'), 'status' => $value('status')];
    }

    /**
     * The form as a page, or as the dialog's fragment (always 200 there: htmx swaps no error response). In the dialog
     * an error without a field reloads the list with it as an error toast.
     *
     * @param array{email: string, name: string, role: string, status: string} $values
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params the errors' ICU parameters
     */
    private function form(ServerRequestInterface $request, ?User $user, array $values, array $errors, array $params = [], int $status = 200): ResponseInterface
    {
        $dialog = Dialog::requested($request);
        [, $toasts] = FormErrors::split($errors, $params, self::FIELDS);
        if ($dialog && $toasts !== []) {
            foreach ($toasts as $toast) {
                Flash::error(Session::of($request), $toast->key, $toast->params);
            }

            return Dialog::redirect($this->responses, $this->router->url('admin.users'));
        }
        $actor = CurrentUser::require($request);
        $isSelf = $user !== null && $user->id === $actor->id;

        return $this->responses->html($this->view->render($dialog ? 'users/dialog' : 'users/form', $request, [
            'user' => $user,
            'values' => $values,
            'errors' => $errors,
            'roles' => $isSelf
                ? $this->roles->labels()
                : array_filter($this->roles->labels(), fn(string $role): bool => $this->roles->grants($actor->role, $role), ARRAY_FILTER_USE_KEY),
            'isSelf' => $isSelf,
            'originalStatus' => $user?->status->value ?? '',
        ]), $dialog ? 200 : $status);
    }
}
