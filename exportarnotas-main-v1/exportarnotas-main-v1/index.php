<?php
require('../../config.php');

$courseid = required_param('id', PARAM_INT);

require_course_login($courseid);
$course = get_course($courseid);
$context = context_course::instance($courseid);

require_capability('local/exportarnotas:view', $context);

$PAGE->set_url(new moodle_url('/local/exportarnotas/index.php', ['id' => $courseid]));
$PAGE->set_pagelayout('incourse'); // Integración con layout del curso
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_exportarnotas'));
$PAGE->set_heading(format_string($course->fullname));

$sent = false;
$filename = "notas_curso_{$courseid}.csv";
$error = '';
$previewrows = [];

// ✅ Generar vista previa SIEMPRE
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$users = get_enrolled_users($context, '', 0, 'u.id, u.firstname, u.lastname, u.email');
$gradeitem = $DB->get_record('grade_items', ['courseid' => $courseid, 'itemtype' => 'course']);

foreach ($users as $user) {
    $grade = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $user->id]);
    $finalgrade = $grade ? round($grade->finalgrade, 2) : '-';

    $roles = get_user_roles($context, $user->id);
    $rolenames = array_map(function ($role) {
        global $DB;
        return $DB->get_field('role', 'shortname', ['id' => $role->roleid]);
    }, $roles);

    $row = [
        $course->shortname,
        fullname($user),
        $user->email,
        implode(', ', $rolenames),
        $finalgrade
    ];

    $previewrows[] = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $toemail = required_param('toemail', PARAM_EMAIL);
    $subject = required_param('subject', PARAM_TEXT);
    $message = required_param('message', PARAM_RAW);

    // Generar archivo CSV automáticamente
    $tempfile = $CFG->tempdir . '/' . $filename;
    $output = fopen($tempfile, 'w');
    if (!$output) {
        $error = '❌ No se pudo crear el archivo temporal.';
    } else {
        fputcsv($output, ['Curso', 'Nombre Estudiante', 'Correo', 'Rol', 'Nota Final']);

        foreach ($previewrows as $row) {
            fputcsv($output, $row); // reutilizamos $previewrows
        }

        fclose($output);

        // Preparar destinatario y enviar correo
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
    <h3>Enviar calificaciones finales por correo</h3>

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

    <!-- Botón para abrir modal -->
    <button type="button" class="btn btn-secondary mb-3" onclick="document.getElementById('modal').classList.add('show'); document.getElementById('modal').style.display = 'block';">
        Previsualizar calificaciones
    </button>

    <!-- Modal con header, body y footer estilo Bootstrap -->
    <div class="modal fade" id="modal" tabindex="-1" role="dialog" aria-labelledby="previewModalLabel" aria-hidden="true" style="display:none;">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">

                <div class="modal-header">
                    <div class="w-100 text-center">
                        <h5 class="modal-title m-0" id="previewModalLabel">Vista previa de calificaciones</h5>
                    </div>
                    <button type="button" class="close position-absolute end-0 me-3" aria-label="Cerrar"
                        onclick="document.getElementById('modal').classList.remove('show'); document.getElementById('modal').style.display='none';">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>


                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="thead-light">
                                <tr>
                                    <th>Curso</th>
                                    <th>Nombre</th>
                                    <th>Correo</th>
                                    <th>Rol</th>
                                    <th>Nota Final</th>
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
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('modal').classList.remove('show'); document.getElementById('modal').style.display='none';">Cerrar</button>
                </div>

            </div>
        </div>
    </div>


    <!-- Formulario -->
    <form method="post">
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
        <input type="hidden" name="id" value="<?php echo $courseid; ?>">

        <div class="mb-3">
            <label for="toemail" class="form-label">Correo destino</label>
            <input type="email" class="form-control" id="toemail" name="toemail" required value="jefe.carrera@ejemplo.com">
        </div>

        <div class="mb-3">
            <label for="subject" class="form-label">Asunto</label>
            <input type="text" class="form-control" id="subject" name="subject" required value="Calificaciones finales del curso <?php echo $courseid; ?>">
        </div>

        <div class="mb-3">
            <label for="message" class="form-label">Mensaje</label>
            <textarea class="form-control" id="message" name="message" rows="4">Adjunto encontrará el archivo con las notas finales del curso exportadas automáticamente desde Moodle.</textarea>
        </div>

        <button type="submit" class="btn btn-success">✉️ Enviar correo con archivo generado</button>
    </form>
</div>

<?php echo $OUTPUT->footer(); ?>