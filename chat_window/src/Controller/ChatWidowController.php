<?php

namespace Simp\Pindrop\Modules\chat_window\src\Controller;

use DateMalformedStringException;
use DI\DependencyException;
use DI\NotFoundException;
use http\Url;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Simp\Pindrop\Controller\ControllerBase;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Entity\User\CurrentUser;
use Simp\Pindrop\Entity\User\User;
use Simp\Pindrop\Modules\chat_window\src\Chat\Agent;
use Simp\Pindrop\Modules\chat_window\src\Chat\ChatItem;
use Simp\Pindrop\Modules\chat_window\src\Customer\Customer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ChatWidowController extends ControllerBase {

    public function __construct(protected Customer $customer, protected ChatItem $chatItem, protected Agent $agent)
    {
        parent::__construct();
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function create(ContainerInterface $container): static
    {
        return new static(
            $container->get('chat.customer'),
            $container->get('chat.item.manager'),
            $container->get('chat.agent')
        );
    }

    /**
     * @throws DatabaseException
     */
    public function startChat(Request $request, string $route_name, array $options): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $first_name = $content['firstName'] ?? null;
        $last_name = $content['lastName'] ?? null;
        $email = $content['email'] ?? null;
        $phone = $content['phone'] ?? null;

        if (!empty($first_name) && !empty($last_name) && !empty($email)) {
            $customerId = $this->customer->createCustomer(
                $first_name,
                $last_name,
                $email,
                $phone
            );
            return new JsonResponse(['status' => $customerId, 'customerId' => $this->customer->getId()]);
        }


        return new JsonResponse(['status' => false ]);
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws DatabaseException
     * @throws DateMalformedStringException
     */
    public function chatSupportPanel(Request $request, string $route_name, array $options): Response
    {

        /**@var User $currentUser**/
        $currentUser = \getAppContainer()->get('current_user')?->getUser();
        if (!$this->agent->getAgent($currentUser->getId())){
            return $this->redirect(\Simp\Pindrop\Routing\Url::routeByName("chat_window.support.panel.agent.form"));
        }
        $agent = $this->agent->getAgent($currentUser->getId());

        $tickets = $this->chatItem->getChatItems();
        $agents = $this->agent->getAgents();

        return $this->renderTwig("@chat_window/panel.html.twig",[
            'agent' => $agent,
            'tickets' => $tickets,
            'agents' => $agents,
        ]);
    }

    /**
     * @throws DatabaseException
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function chatSupportRegister(Request $request, string $route_name, array $options): Response
    {
        /**@var User $currentUser**/
        $currentUser = \getAppContainer()->get('current_user')?->getUser();
        $data = $request->request->all();
        if (!empty($data['first_name']) && !empty($data['last_name']) && !empty($data['email'])) {
            if ($this->agent->addAgent($data['first_name'], $data['last_name'], $data['email'], $currentUser->getId(), $data['phone'] ?? null)) {
                return $this->redirect(\Simp\Pindrop\Routing\Url::routeByName("chat_window.support.panel"));
            }
        }
        return $this->renderTwig("@chat_window/register.html.twig",[
            'user' => $currentUser
        ]);
    }
}