<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Validation\Validator;

class WarninglistEntriesTable extends AppTable
{
    /**
     * initialize
     *
     * @param  mixed $config Configuration
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        // $this->addBehavior('AuditLog');
        $this->belongsTo(
            'Warninglist',
            [
                'dependent' => true,
                'propertyName' => 'Warninglist',
            ]
        );
        $this->setDisplayField('value');
    }

    /**
     * validationDefault
     *
     * @param  mixed $validator  Validator
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->notEmptyString('value')
            ->requirePresence(['value', 'warninglist_id'], 'create');

        return $validator;
    }
}
