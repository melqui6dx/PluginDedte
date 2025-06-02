<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/exportarnotas:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ]
    ]
];
