<?php
    if ($setting['setting'] !== 'Security.salt') {
        $bgColour = '';
        $colour_coding = array(
            0 => 'error',
            1 => 'warning',
            2 => 'success',
            3 => 'info'
        );
        if ($setting['type'] == 'boolean') $setting['value'] = ($setting['value'] === true ? 'true' : 'false');
        if (isset($setting['options'])) {
            $setting['value'] = $setting['options'][$setting['value']];
        }
        if (!empty($setting['redacted'])) {
            $setting['value'] = '*****';
        }
        $column_data = array(
            'level' => array(
                'html' => $priorities[$setting['level']],
                'class' => 'short'
            ),
            'setting' => array(
                'html' => h($setting['setting']),
                'class' => 'short',
            ),
            'value_passive' => array(
                'html' => nl2br(h($setting['value'])),
                'class' => 'inline-field-solid',
                'requirement' => ((isset($setting['editable']) && !$setting['editable'])),
                'style' => 'width:500px;',
                'id' => sprintf(
                    'setting_%s_%s_passive',
                    h($subGroup),
                    h($k)
                )
            ),
            'value_solid' => array(
                'html' => nl2br(h($setting['value'])),
                'class' => 'inline-field-solid',
                'requirement' => ((!isset($setting['editable']) || $setting['editable'])),
                'style' => 'width:500px;',
                'id' => sprintf(
                    'setting_%s_%s_solid',
                    h($subGroup),
                    h($k)
                ),
                'ondblclick' => 'serverSettingsActivateField',
                'ondblclickParams' => array(h($setting['setting']), h($k))
            ),
            'value_placeholder' => array(
                'class' => 'inline-field-placeholder hidden',
                'requirement' => ((!isset($setting['editable']) || $setting['editable'])),
                'style' => 'width:500px;',
                'id' => sprintf(
                    'setting_%s_%s_placeholder',
                    h($subGroup),
                    h($k)
                )
            ),
            'description' => array(
                'html' => h($setting['description'])
            ),
            'error' => array(
                'html' => isset($setting['error']) ? h($setting['errorMessage']) : ''
            )
        );
        $columns = '';
        foreach ($column_data as $field => $data) {
            if (!isset($data['requirement']) || $data['requirement']) {
                $columns .= sprintf(
                    '<td %s class="%s" %s %s>%s</td>',
                    empty($data['id']) ? '' : sprintf('id="%s"', h($data['id'])),
                    empty($data['class']) ? '' : h($data['class']),
                    empty($data['style']) ? '' : sprintf('style="%s"', h($data['class'])),
                    empty($data['ondblclick']) ? '' : sprintf(
                        'ondblclick="%s(%s)"',
                        h($data['ondblclick']),
                        empty($data['ondblclickParams']) ? '' : sprintf("'%s'", implode("','", $data['ondblclickParams']))
                    ),
                    empty($data['html']) ? '' : $data['html']
                );
            }
        }
        echo sprintf(
            '<tr id="%s" class="subGroup_%s %s">%s</tr>',
            sprintf(
                '%s_%s_row',
                h($subGroup),
                $k
            ),
            h($subGroup),
            !empty($setting['error']) ? $colour_coding[$setting['level']] : '',
            $columns
        );
    }
?>
