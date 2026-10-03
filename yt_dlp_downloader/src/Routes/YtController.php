<?php

namespace Simp\Pindrop\Modules\yt_dlp_downloader\src\Routes;

use DI\Container;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Message\Message;
use Simp\Pindrop\Modules\yt_dlp_downloader\src\services\Yt;
use Simp\Pindrop\Routing\AttributeRoute;
use Simp\Pindrop\Routing\Url;
use Symfony\Component\HttpFoundation\Request;

class YtController extends ControllerBase
{
   
    public function __construct(protected Yt $yt_service)
    {
        parent::__construct();
    }

    public static function create(Container $container): static
    {
        return new static(
            $container->get(Yt::class)
        );
    }

    #[AttributeRoute('/admin/yt/links', 'GET', permission: ['yt_dlp_downloader_links'])]
    public function links(Request $request, string $route_name, array $options)
    {
        return $this->renderTwig('@yt_dlp_downloader/links.html.twig', [
            'links' => $this->yt_service->getAllLinks()
        ]);
    }

    #[AttributeRoute('/admin/yt/links/new', ['GET', 'POST'], permission: ['yt_dlp_downloader_links'])]
    public function addLink(Request $request, string $route_name, array $options)
    {
        if ($request->isMethod('POST')) {
            $url = $request->request->get('link');
            $local_path = $request->request->get('local_path');
            $name = $request->request->get('name');

            // Add the link to the database
            if ($this->yt_service->addLink($url, $local_path, $name))
            {
                Message::info('Link added successfully.');
            } else {
                Message::error('Failed to add link.');
            }

            // Redirect to the links page after adding
            return $this->redirect(Url::routeByName('YtController.links'));
        }

        return $this->renderTwig('@yt_dlp_downloader/add_link.html.twig');
    }

    #[AttributeRoute('/admin/yt/links/delete/[id:int]',['GET', 'POST'], permission: ['yt_dlp_downloader_links'])]
    public function deleteLink(Request $request, string $route_name, array $options)
    {
        $id = $request->query->get('id');

        // Delete the link from the database
        if ($this->yt_service->deleteLink($id)) {
            Message::info('Link deleted successfully.');
        } else {
            Message::error('Failed to delete link.');
        }

        // Redirect to the links page after deletion
        return $this->redirect(Url::routeByName('YtController.links'));
    }

    #[AttributeRoute('/admin/yt/links/edit/[id:int]', ['GET', 'POST'], permission: ['yt_dlp_downloader_links'])]
    public function editLink(Request $request, string $route_name, array $options)
    {
        $id = $request->query->get('id');
        $link = $this->yt_service->getLinkById($id);

        if (!$link) {
            Message::error('Link not found.');
            return $this->redirect(Url::routeByName('YtController.links'));
        }

        if ($request->isMethod('POST')) {
            $url = $request->request->get('link');
            $local_path = $request->request->get('local_path');
            $name = $request->request->get('name');
            $status = $request->request->get('status');

            // Update the link in the database
            if ($this->yt_service->update($id, [
                'link' => $url,
                'local_path' => $local_path,
                'name' => $name,
                'status' => $status
            ])) {
                Message::info('Link updated successfully.');
            } else {
                Message::error('Failed to update link.');
            }

            // Redirect to the links page after updating
            return $this->redirect(Url::routeByName('YtController.links'));
        }

        return $this->renderTwig('@yt_dlp_downloader/edit_link.html.twig', [
            'link' => $link
        ]);
    }
}
