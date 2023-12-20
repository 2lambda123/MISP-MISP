<?php

namespace App\Controller;

use App\Controller\AppController;

class RolesController extends AppController
{
    public $filterFields = ['name', 'uuid', 'perm_admin', 'Users.id', 'perm_org_admin'];
    public $quickFilterFields = ['name'];
    public $containFields = [];

    public $paginate = [
        'limit' => 60,
        'order' => [
            'Role.name' => 'ASC'
        ]
    ];

    public function view($id)
    {
        $this->CRUD->view($id);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        $this->set('permissionLevelName', $this->Role->premissionLevelName);
        $this->set('permFlags', $this->Role->permFlags);
        $this->set('metaGroup', 'Roles');
    }


    public function index()
    {
        $this->CRUD->index(
            [
                'filters' => $this->filterFields,
                'quickFilters' => $this->quickFilterFields
            ]
        );
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        $this->set('options', $this->Roles->premissionLevelName);
        $this->set('permFlags', $this->Roles->permFlags());
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
    }
}
