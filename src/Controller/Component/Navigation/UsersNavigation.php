<?php
namespace App\Controller\Component\Navigation;

use Cake\Core\Configure;

class UsersNavigation extends BaseNavigation
{
    public function addRoutes()
    {
        $this->bcf->addRoute(
            'Users',
            'registrations',
            [
            'label' => __('Pending Registration'),
            'url' => '/users/registrations',
            'icon' => 'user-clock',
            // 'is-go-to' => true,
            ]
        );
        $this->bcf->addRoute(
            'Users',
            'settings',
            [
            'label' => __('User settings'),
            'url' => '/users/settings/',
            'icon' => 'user-cog'
            ]
        );
    }

    public function addParents()
    {
        // $this->bcf->addParent('Users', 'settings', 'Users', 'view');
    }

    public function addLinks()
    {
        $bcf = $this->bcf;
        $request = $this->request;
        $passedData = $this->request->getParam('pass');
        $currentUserId = empty($this->currentUserId) ? null : $this->currentUserId;
        $currentUser = $this->currentUser;

        $this->bcf->addLink('Users', 'index', 'UserSettings', 'index');
        $this->bcf->addLink(
            'Users',
            'view',
            'UserSettings',
            'index',
            function ($config) use ($bcf, $request, $passedData, $currentUser) {
            if (!empty($passedData[0])) {
                $user_id = $passedData[0];
                $linkData = [
                    'label' => __('Account settings', h($user_id)),
                    'url' => sprintf('/users/settings/%s', h($user_id))
                ];
                return $linkData;
            }
            return [];
            }
        );
        $this->bcf->addLink(
            'Users',
            'view',
            'UserSettings',
            'index',
            function ($config) use ($bcf, $request, $passedData) {
            if (!empty($passedData[0])) {
                $user_id = $passedData[0];
                $linkData = [
                    'label' => __('User Setting [{0}]', h($user_id)),
                    'url' => sprintf('/user-settings/index?Users.id=%s', h($user_id))
                ];
                return $linkData;
            }
            return [];
            }
        );
        $this->bcf->addLink(
            'Users',
            'edit',
            'UserSettings',
            'index',
            function ($config) use ($bcf, $request, $passedData) {
            if (!empty($passedData[0])) {
                $user_id = $passedData[0];
                $linkData = [
                    'label' => __('Account settings', h($user_id)),
                    'url' => sprintf('/users/settings/%s', h($user_id))
                ];
                return $linkData;
            }
            return [];
            }
        );
        $this->bcf->addLink(
            'Users',
            'edit',
            'UserSettings',
            'index',
            function ($config) use ($bcf, $request, $passedData) {
            if (!empty($passedData[0])) {
                $user_id = $passedData[0];
                $linkData = [
                    'label' => __('User Setting [{0}]', h($user_id)),
                    'url' => sprintf('/user-settings/index?Users.id=%s', h($user_id))
                ];
                return $linkData;
            }
            return [];
            }
        );
        if (
            !empty($this->loggedUser['social_profile']) &&
            !empty(Configure::read('keycloak.enabled')) &&
            !empty(Configure::read('keycloak.provider.baseUrl')) &&
            !empty(Configure::read('keycloak.provider.realm')) &&
            !empty($passedData[0]) &&
            $currentUserId == $passedData[0]
        ) {
            $url = sprintf(
                '%s/realms/%s/account',
                Configure::read('keycloak.provider.baseUrl'),
                Configure::read('keycloak.provider.realm')
            );
            foreach (['edit', 'view', 'settings'] as $sourceAction) {
                $this->bcf->addCustomLink('Users', $sourceAction, $url, __('Manage KeyCloak Account'));
            }
        }

        $this->bcf->addLink(
            'Users',
            'settings',
            'Users',
            'view',
            function ($config) use ($bcf, $request, $passedData) {
            if (!empty($passedData[0])) {
                $user_id = $passedData[0];
                $linkData = [
                    'label' => __('View user', h($user_id)),
                    'url' => sprintf('/users/view/%s', h($user_id))
                ];
                return $linkData;
            }
            return [];
            }
        );
        $this->bcf->addSelfLink(
            'Users',
            'settings',
            [
            'label' => __('Account settings')
            ]
        );

        $this->bcf->addLink(
            'Users',
            'index',
            'Users',
            'registrations',
            [
            // 'badge' => ['text' => 123, 'variant' => 'warning']
            ]
        );
    }

    public function addActions()
    {
        $this->bcf->addCustomAction(
            'Users',
            'index',
            '/admin/users/email',
            __('Contact Users'),
            [
            'icon' => 'comment-dots',
            ]
        );
    }
}
