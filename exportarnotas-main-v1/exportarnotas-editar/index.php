<?php
require('../../config.php');

$courseid = required_param('id', PARAM_INT);
require_course_login($courseid);
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/exportarnotas:view', $context);

$PAGE->set_url(new moodle_url('/local/exportarnotas/index.php', ['id' => $courseid]));
$PAGE->set_pagelayout('incourse');
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_exportarnotas'));
$PAGE->set_heading(format_string($course->fullname));

$sent = false;
$filename = "notas_curso_{$courseid}.csv";
$error = '';
$previewrows = [];

// Correos predefinidos
$director_correos = [
    'derecho' => 'director.derecho@ejemplo.com',
    'informatica' => 'director.informatica@ejemplo.com',
    'contabilidad' => 'director.contabilidad@ejemplo.com',
];

$export_mode = optional_param('exportmode', 'custom', PARAM_TEXT);
$selected_director = optional_param('director', '', PARAM_TEXT);

// Obtener ítems de calificación
$gradeitems = $DB->get_records('grade_items', [
    'courseid' => $courseid,
    'itemtype' => 'mod'
]);
$finalitem = $DB->get_record('grade_items', [
    'courseid' => $courseid,
    'itemtype' => 'course'
]);

if (empty($gradeitems) && !$finalitem) {
    echo $OUTPUT->header();
    echo '<div class="container mt-4">';
    echo '<div class="alert alert-warning">⚠️ Este curso no tiene actividades calificables ni nota final.</div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

// Obtener usuarios inscritos
$users = get_enrolled_users($context, '', 0, 'u.id, u.firstname, u.lastname, u.email');

// Columnas disponibles
$available_columns = ['fullname' => 'Nombre Estudiante', 'email' => 'Correo'];
foreach ($gradeitems as $item) {
    $available_columns['gradeitem_' . $item->id] = format_string($item->itemname);
}
if ($finalitem) {
    $available_columns['gradeitem_' . $finalitem->id] = 'Nota Final';
}

// Columnas por defecto
$default_columns = ['fullname' => 1];
$count = 0;
foreach ($gradeitems as $item) {
    if ($count < 3) {
        $default_columns['gradeitem_' . $item->id] = 1;
        $count++;
    }
}

// Determinar columnas seleccionadas
switch ($export_mode) {
    case 'final':
        $selectedcols = ['fullname' => 1];
        if ($finalitem) {
            $selectedcols['gradeitem_' . $finalitem->id] = 1;
        }
        break;
    case 'exams':
        $selectedcols = ['fullname' => 1];
        foreach ($gradeitems as $item) {
            if (stripos($item->itemname, 'examen') !== false) {
                $selectedcols['gradeitem_' . $item->id] = 1;
            }
        }
        if ($finalitem) {
            $selectedcols['gradeitem_' . $finalitem->id] = 1;
        }
        break;
    default:
        $selectedcols = optional_param_array('columns', $default_columns, PARAM_BOOL);
}

// Generar vista previa
foreach ($users as $user) {
    $row = [];
    if (!empty($selectedcols['fullname'])) $row[] = fullname($user);
    if (!empty($selectedcols['email'])) $row[] = $user->email;

    foreach ($selectedcols as $key => $include) {
        if (strpos($key, 'gradeitem_') === 0 && $include) {
            $itemid = intval(substr($key, 10));
            $grade = $DB->get_record('grade_grades', ['itemid' => $itemid, 'userid' => $user->id]);
            $gradeval = (isset($grade->finalgrade) && $grade->finalgrade !== null)
                ? round($grade->finalgrade, 2)
                : '-';
            $row[] = $gradeval;
        }
    }
    $previewrows[] = $row;
}

// Enviar correo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey() && isset($_POST['sendemail'])) {
    $toemail = required_param('toemail', PARAM_EMAIL);
    $_SESSION['exportarnotas_lastemail'] = $toemail;

    $subject = required_param('subject', PARAM_TEXT);
    $message = required_param('message', PARAM_RAW);

    $tempfile = $CFG->tempdir . '/' . $filename;
    $output = fopen($tempfile, 'w');

    if (!$output) {
        $error = '❌ No se pudo crear el archivo temporal.';
    } else {
        $headers = [];
        if (!empty($selectedcols['fullname'])) $headers[] = 'Nombre Estudiante';
        if (!empty($selectedcols['email'])) $headers[] = 'Correo';
        foreach ($selectedcols as $key => $include) {
            if (strpos($key, 'gradeitem_') === 0 && $include) {
                $headers[] = $available_columns[$key] ?? "Actividad";
            }
        }
        fputcsv($output, $headers);
        foreach ($previewrows as $row) {
            fputcsv($output, $row);
        }
        fclose($output);

        $user = (object)[
            'id' => -1,
            'email' => $toemail,
            'firstname' => 'Destinatario',
            'lastname' => '',
            'maildisplay' => true
        ];
        $from = core_user::get_support_user();
        $remitente = fullname($USER);
        $htmlmessage = '<p><strong>Profesor:</strong> ' . s($remitente) . '</p><p>' . nl2br(s($message)) . '</p>';

        $sent = email_to_user($user, $from, $subject, strip_tags($message), $htmlmessage, $tempfile, $filename);
        @unlink($tempfile);

        if (!$sent) {
            $error = '❌ Error al enviar el correo.';
        }
    }
}

echo $OUTPUT->header();
?>

<div class="container mt-4">
    <h3>Enviar calificaciones por actividad del curso</h3>

    <?php if ($sent): ?>
        <div class="alert alert-success">
            Correo enviado exitosamente con el archivo adjunto:
            <div class="card mt-3 p-3 border d-inline-block" style="background:#f8f9fa;">
                <strong>📎 <?php echo $filename; ?></strong><br>
                <small>Generado automáticamente desde Moodle</small>
            </div>
        </div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="id" value="<?php echo $courseid; ?>">
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>

        <div class="mb-3">
            <label class="form-label">Tipo de Exportación</label>
            <select name="exportmode" class="form-select" onchange="this.form.submit()">
                <option value="custom" <?php if ($export_mode == 'custom') echo 'selected'; ?>>🔧 Personalizado</option>
                <option value="final" <?php if ($export_mode == 'final') echo 'selected'; ?>>📘 Solo Nota Final</option>
                <option value="exams" <?php if ($export_mode == 'exams') echo 'selected'; ?>>📝 Exámenes + Nota Final</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Director de Carrera</label>
            <select name="director" class="form-select" onchange="this.form.submit()">
                <option value="">Seleccione</option>
                <?php foreach ($director_correos as $key => $email): ?>
                    <option value="<?php echo $key; ?>" <?php if ($selected_director == $key) echo 'selected'; ?>>
                        <?php echo ucfirst($key); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($export_mode == 'custom'): ?>
            <div class="mb-3">
                <label class="form-label">Seleccione las columnas a incluir:</label><br>
                <?php foreach ($available_columns as $key => $label): ?>
                    <label class="me-3">
                        <input type="checkbox" name="columns[<?php echo $key; ?>]" value="1" <?php echo !empty($selectedcols[$key]) ? 'checked' : ''; ?>>
                        <?php echo $label; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-outline-primary mb-4">🔍 Actualizar vista previa</button>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-bordered">
                <thead class="thead-light">
                    <tr>
                        <?php foreach ($available_columns as $key => $label): ?>
                            <?php if (!empty($selectedcols[$key])): ?>
                                <th><?php echo $label; ?></th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewrows as $r): ?>
                        <tr>
                            <?php foreach ($r as $col): ?>
                                <td><?php echo s($col); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php
        $correo_predeterminado = 'jefe.carrera@ejemplo.com';
        if ($selected_director && isset($director_correos[$selected_director])) {
            $correo_predeterminado = $director_correos[$selected_director];
        } elseif (!empty($_SESSION['exportarnotas_lastemail'])) {
            $correo_predeterminado = $_SESSION['exportarnotas_lastemail'];
        }
        ?>

        <div class="mb-3">
            <label for="toemail" class="form-label">Correo destino</label>
            <input type="email" class="form-control" id="toemail" name="toemail" required value="<?php echo s($correo_predeterminado); ?>">
        </div>

        <div class="mb-3">
            <label for="subject" class="form-label">Asunto</label>
            <input type="text" class="form-control" id="subject" name="subject" required value="Calificaciones del curso <?php echo $course->fullname; ?>">
        </div>

        <div class="mb-3">
            <label for="message" class="form-label">Mensaje</label>
            <textarea class="form-control" id="message" name="message" rows="4">Adjunto encontrará el archivo con las calificaciones exportadas automáticamente desde Moodle.</textarea>
        </div>

        <button type="submit" name="sendemail" class="btn btn-success">✉️ Enviar correo con archivo generado</button>
    </form>
</div>

<?php echo $OUTPUT->footer(); ?>
