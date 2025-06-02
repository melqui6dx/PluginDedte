<?php
defined('MOODLE_INTERNAL') || die();

function local_exportarnotas_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('local/exportarnotas:view', $context)) {
        return;
    }

    $url = new moodle_url('/local/exportarnotas/index.php', ['id' => $course->id]);
    $node = navigation_node::create(
        get_string('pluginname', 'local_exportarnotas'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'exportarnotas',
        new pix_icon('i/export', '')
    );
    $navigation->add_node($node);
}

function local_exportarnotas_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($filearea !== 'exportados') {
        return false;
    }

    require_login($course);
    if (!has_capability('local/exportarnotas:view', $context)) {
        return false;
    }

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = '/' . implode('/', $args) . '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_exportarnotas', $filearea, $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}
