<?php
defined('MOODLE_INTERNAL') || die();

function local_exportarnotas_extend_navigation_course($navigation, $course, $context) {
    global $USER;

    if (!has_capability('local/exportarnotas:view', $context)) {
        return;
    }

    $url = new moodle_url('/local/exportarnotas/index.php', ['id' => $course->id]);

    $node = navigation_node::create(
        get_string('pluginname', 'local_exportarnotas'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_exportarnotas',
        new pix_icon('i/export', '')
    );

    $navigation->add_node($node);
}


