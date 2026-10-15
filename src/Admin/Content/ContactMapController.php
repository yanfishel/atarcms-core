<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

use AtarCms\Admin\AdminView;
use AtarCms\Admin\Dialog;
use AtarCms\Admin\Flash;
use AtarCms\Auth\CurrentUser;
use AtarCms\Content\Collection;
use AtarCms\Content\EntryAccess;
use AtarCms\Http\ClientIp;
use AtarCms\Http\Exception\BadRequest;
use AtarCms\Http\Exception\NotFound;
use AtarCms\Http\Exception\ValidationFailed;
use AtarCms\Http\Responses;
use AtarCms\Http\Routing\Router;
use AtarCms\Http\Session\Session;
use AtarCms\I18n\RequestLocale;
use AtarCms\I18n\Translator;
use AtarCms\Settings\ContactPage;
use AtarCms\Settings\GoogleMap;
use AtarCms\Settings\GoogleMapInput;
use AtarCms\Settings\SettingsService;
use AtarCms\Settings\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The site's map (GoogleMap), added, changed and removed from the contact page's entry form (config content.contact_page): its
 * map column (map/panel.php) opens these pages in the form's dialog (dialog.js). The dialog has a tab per way to enter
 * the map, each its own form. A save or a removal answers htmx with the new column, swapped in place of the old one
 * (HX-Retarget), and the "atar-dialog-done" event, which closes the dialog and shows the toast, so the entry form's
 * unsaved text stays; a refused input comes back into the dialog at its field. Without JavaScript the same addresses
 * are full pages that go back to the entry form with a flash. Only who may save the contact page's published entry
 * (EntryAccess::canEditPublished()) may change the map.
 */
final class ContactMapController
{
    /** The ways of the dialog, its tabs in order (GoogleMapInput's methods without the stored shape). */
    public const array METHODS = ['embed', 'link', 'address', 'coordinates'];

    /** The id of the map column, which the answers of the dialog replace. */
    public const string PANEL = 'contact-map';

    /** The event dialog.js handles: close the dialog, show the toast, focus the replaced column's button. */
    public const string DONE_EVENT = 'atar-dialog-done';

    public function __construct(
        private readonly AdminView $view,
        private readonly Responses $responses,
        private readonly Router $router,
        private readonly ContactPage $page,
        private readonly SiteSettings $settings,
        private readonly SettingsService $service,
        private readonly EntryAccess $access,
        private readonly Translator $translator,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->authorize($request);
        [$method, $values] = self::current($this->settings->of('')->map);

        return $this->form($request, $collection, $method, $values, [], []);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->authorize($request);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $method = $body['method'] ?? null;
        if (!is_string($method) || !in_array($method, self::METHODS, true)) {
            throw new BadRequest('Unknown way to enter the map');
        }
        $input = [];
        foreach (GoogleMapInput::FIELDS[$method] as $field) {
            $input[$field] = is_string($body[$field] ?? null) ? $body[$field] : '';
        }
        try {
            $map = GoogleMapInput::parse($method, $input);
        } catch (ValidationFailed $e) {
            return $this->form($request, $collection, $method, $input, $e->errors, $e->params, 422);
        }
        $this->service->saveMap($map, CurrentUser::require($request), ClientIp::of($request));

        return $this->done($request, $collection, $map, 'admin.map.saved');
    }

    public function confirmDelete(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->authorize($request);

        return $this->responses->html($this->view->render(Dialog::requested($request) ? 'map/delete-dialog' : 'map/delete', $request, [
            'collection' => $collection,
            'back' => $this->back($collection),
        ]));
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $collection = $this->authorize($request);
        $this->service->removeMap(CurrentUser::require($request), ClientIp::of($request));

        return $this->done($request, $collection, null, 'admin.map.removed');
    }

    /**
     * The tab a map was entered with and its values: an embed shows its address, a point its coordinates, any other
     * search its text; without a map the first tab.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function current(?GoogleMap $map): array
    {
        if ($map === null) {
            return [self::METHODS[0], []];
        }
        if ($map->isEmbed()) {
            return ['embed', ['embed' => $map->embedUrl('')]];
        }
        $point = $map->coordinates();

        return $point === null
            ? ['address', ['address' => (string) $map->query]]
            : ['coordinates', ['latitude' => $point[0], 'longitude' => $point[1], 'zoom' => (string) $map->zoom]];
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    private function form(ServerRequestInterface $request, Collection $collection, string $method, array $values, array $errors, array $params, int $status = 200): ResponseInterface
    {
        $dialog = Dialog::requested($request);

        return $this->responses->html($this->view->render($dialog ? 'map/dialog' : 'map/form', $request, [
            'collection' => $collection,
            'method' => $method,
            'values' => $values,
            'errors' => $errors,
            'params' => $params,
            'back' => $this->back($collection),
        ]), $dialog ? 200 : $status); // htmx swaps no error response: a refused dialog form comes back as 200
    }

    /**
     * The new map column for htmx, in place of the old one, with the event that closes the dialog; without
     * JavaScript back to the entry form with the flash.
     */
    private function done(ServerRequestInterface $request, Collection $collection, ?GoogleMap $map, string $toast): ResponseInterface
    {
        if (!Dialog::requested($request)) {
            Flash::success(Session::of($request), $toast);

            return $this->responses->redirect($this->back($collection), 303);
        }
        // The event's detail travels in a header: json_encode() escapes every non-ASCII character, as headers need.
        $event = json_encode([self::DONE_EVENT => [
            'toast' => $this->translator->translate(RequestLocale::of($request)->code, $toast),
            'focus' => self::PANEL,
        ]], JSON_THROW_ON_ERROR);

        return $this->responses->html($this->view->render('map/panel', $request, ['map' => $map, 'canManage' => true]))
            ->withHeader('HX-Retarget', '#' . self::PANEL)
            ->withHeader('HX-Reswap', 'outerHTML')
            ->withHeader('HX-Trigger-After-Settle', $event);
    }

    /**
     * The contact page's collection, once the user may change the map.
     */
    private function authorize(ServerRequestInterface $request): Collection
    {
        $collection = $this->page->collection ?? throw new NotFound('The site has no contact page (config content.contact_page)');
        $this->access->authorize($this->access->canEditPublished(CurrentUser::require($request), $collection), 'change the map');

        return $collection;
    }

    /**
     * The contact page's entry form (the entry list of a single opens the entry of the content language).
     */
    private function back(Collection $collection): string
    {
        return $this->router->url('admin.entries', ['collection' => $collection->name]);
    }
}
