<?php
/**
 * Plugin Name: TEVA Preguntas
 * Description: Sistema de encuestas por email con validación CSV
 * Version: 1.2
 * Author: Daniel Avila
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 8.1
 * Text Domain: teva-preguntas
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Función auxiliar para debug interno (solo CLI)
function teva_debug_log($message) {
    // Solo log en CLI o si WP_DEBUG está activo y el usuario es admin
    if (defined('WP_CLI') && WP_CLI || 
        (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options'))) {
        error_log('TEVA Plugin: ' . $message);
    }
}

// Hook de desinstalación
register_uninstall_hook(__FILE__, 'email_survey_plugin_uninstall');

function email_survey_plugin_uninstall() {
    global $wpdb;
    
    // Eliminar todas las tablas del plugin
    $tables = array(
        $wpdb->prefix . 'email_surveys',
        $wpdb->prefix . 'survey_valid_emails',
        $wpdb->prefix . 'survey_votes',
        $wpdb->prefix . 'survey_sessions'
    );
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS $table");
    }
    
    // Limpiar opciones si las hubiera (por si acaso)
    delete_option('email_survey_plugin_version');
    delete_option('email_survey_plugin_settings');
    
    // Limpiar cualquier transient del plugin
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_email_survey_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_email_survey_%'");
    
    teva_debug_log('Desinstalación completada - Todas las tablas y datos eliminados');
}

class EmailSurveyPlugin {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('wp_ajax_save_survey', array($this, 'save_survey'));
        add_action('wp_ajax_upload_csv', array($this, 'upload_csv'));
        add_action('wp_ajax_clear_csv', array($this, 'clear_csv'));
        add_action('wp_ajax_reset_plugin', array($this, 'reset_plugin'));
        add_action('wp_ajax_nopriv_submit_vote', array($this, 'submit_vote'));
        add_action('wp_ajax_submit_vote', array($this, 'submit_vote'));
        add_shortcode('survey_form', array($this, 'survey_form_shortcode'));
        add_shortcode('survey_results', array($this, 'survey_results_shortcode'));
        
        // Forzar shortcode en página específica (bypass del tema)
        add_action('template_redirect', array($this, 'force_survey_page'));
        
        // Hook de desactivación (opcional, para limpiar temporales)
        register_deactivation_hook(__FILE__, array($this, 'on_deactivation'));
        
        // AGREGAR: Enqueue scripts en admin
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
    }
    
    // NUEVA FUNCIÓN: Cargar scripts de admin
    public function admin_scripts($hook) {
        // Solo cargar en nuestra página
        if ($hook !== 'toplevel_page_teva-preguntas') {
            return;
        }
        
        // Asegurar que jQuery esté disponible
        wp_enqueue_script('jquery');
        
        // Localizar el script con variables necesarias
        wp_localize_script('jquery', 'tevaAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'reset_nonce' => wp_create_nonce('reset_nonce'),
            'survey_nonce' => wp_create_nonce('survey_nonce'),
            'csv_nonce' => wp_create_nonce('csv_nonce')
        ));
    }
    
    public function on_deactivation() {
        // Limpiar transients al desactivar
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_email_survey_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_email_survey_%'");
        teva_debug_log('Plugin desactivado - Transients limpiados');
    }
    
    public function init() {
        $this->create_tables();
    }
    
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Tabla para encuestas
        $table_surveys = $wpdb->prefix . 'email_surveys';
        $sql_surveys = "CREATE TABLE $table_surveys (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            question text NOT NULL,
            option1 varchar(255) NOT NULL,
            option2 varchar(255) NOT NULL,
            option3 varchar(255) NOT NULL,
            correct_answer int(1) NOT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        // Tabla para emails válidos
        $table_emails = $wpdb->prefix . 'survey_valid_emails';
        $sql_emails = "CREATE TABLE $table_emails (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL UNIQUE,
            name varchar(255),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        // Tabla para votos - SIN UNIQUE KEY para permitir múltiples respuestas
        $table_votes = $wpdb->prefix . 'survey_votes';
        $sql_votes = "CREATE TABLE $table_votes (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            survey_id mediumint(9) NOT NULL,
            email varchar(255) NOT NULL,
            selected_option int(1) NOT NULL,
            is_correct tinyint(1) NOT NULL,
            voted_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX survey_email_idx (survey_id, email)
        ) $charset_collate;";
        
        // Tabla para sesiones (nueva)
        $table_sessions = $wpdb->prefix . 'survey_sessions';
        $sql_sessions = "CREATE TABLE $table_sessions (
            token varchar(500) NOT NULL PRIMARY KEY,
            survey_id mediumint(9) NOT NULL,
            email varchar(255) NOT NULL,
            expires int(11) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            INDEX email_idx (email),
            INDEX expires_idx (expires)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_surveys);
        dbDelta($sql_emails);
        dbDelta($sql_votes);
        dbDelta($sql_sessions);
        
        // IMPORTANTE: Eliminar la clave única si existe (compatible con MySQL 8.0)
        $table_votes = $wpdb->prefix . 'survey_votes';
        
        // Método compatible con MySQL 8.0 para verificar y eliminar índices
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table_votes WHERE Key_name = 'unique_vote'");
        if (!empty($indexes)) {
            teva_debug_log('Found unique_vote index, attempting to remove it');
            $result = $wpdb->query("ALTER TABLE $table_votes DROP INDEX unique_vote");
            if ($result === false) {
                teva_debug_log('Failed to drop unique_vote index: ' . $wpdb->last_error);
            } else {
                teva_debug_log('Successfully dropped unique_vote index');
            }
        } else {
            teva_debug_log('No unique_vote index found, table is clean');
        }
        
        // Guardar versión para futuras migraciones
        update_option('email_survey_plugin_version', '1.0');
    }
    
    public function admin_menu() {
        add_menu_page(
            'TEVA Preguntas',           // Page title
            'TEVA Preguntas',           // Menu title
            'manage_options',
            'teva-preguntas',           // Cambiar slug también
            array($this, 'admin_page'),
            'dashicons-chart-pie',
            30
        );
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>Sistema TEVA Preguntas</h1>
            
            <!-- Panel de Control/Reset -->
            <div class="postbox" style="margin-top: 20px; border-left: 4px solid #dc3545;">
                <h2 class="hndle" style="color: #dc3545;">🔧 Panel de Control</h2>
                <div class="inside">
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                        <h4>⚠️ Herramientas de Mantenimiento</h4>
                        <p>Estas herramientas te permiten reiniciar completamente el plugin para solucionar problemas o empezar de cero.</p>
                    </div>
                    
                    <div class="reset-options" style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <button type="button" id="reset-votes-btn" class="button" style="background: #ffc107; border-color: #ffc107; color: #000;">
                            🗳️ Limpiar Solo Respuestas
                        </button>
                        <button type="button" id="reset-emails-btn" class="button" style="background: #fd7e14; border-color: #fd7e14; color: white;">
                            📧 Limpiar Solo Participantes
                        </button>
                        <button type="button" id="reset-surveys-btn" class="button" style="background: #6f42c1; border-color: #6f42c1; color: white;">
                            📋 Limpiar Solo Preguntas
                        </button>
                        <button type="button" id="reset-all-btn" class="button" style="background: #dc3545; border-color: #dc3545; color: white;">
                            🚨 RESET COMPLETO
                        </button>
                    </div>
                    
                    <div style="margin-top: 15px; font-size: 12px; color: #666;">
                        <p><strong>Nota:</strong> Estas acciones no se pueden deshacer. Siempre haz una copia de seguridad antes de usar estas herramientas.</p>
                    </div>
                </div>
            </div>
            
            <!-- Debug Info -->
            <div class="postbox" style="margin-top: 20px;">
                <h2 class="hndle">🔍 Debug Info</h2>
                <div class="inside">
                    <div id="debug-info" style="background: #f1f1f1; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 12px;">
                        AJAX URL: <?php echo admin_url('admin-ajax.php'); ?><br>
                        Current User Can Manage: <?php echo current_user_can('manage_options') ? 'YES' : 'NO'; ?><br>
                        WordPress Version: <?php echo get_bloginfo('version'); ?><br>
                        Plugin Path: <?php echo plugin_dir_path(__FILE__); ?>
                    </div>
                </div>
            </div>
            
            <div class="postbox" style="margin-top: 20px;">
                <h2 class="hndle">Crear Nueva Pregunta</h2>
                <div class="inside">
                    <form id="survey-form">
                        <table class="form-table">
                            <tr>
                                <th><label>Pregunta:</label></th>
                                <td><textarea name="question" rows="3" cols="50" required placeholder="Ejemplo: ¿Cuál es la capital de España?"></textarea></td>
                            </tr>
                            <tr>
                                <th><label>Opción 1:</label></th>
                                <td><input type="text" name="option1" required placeholder="Ejemplo: Madrid"></td>
                            </tr>
                            <tr>
                                <th><label>Opción 2:</label></th>
                                <td><input type="text" name="option2" required placeholder="Ejemplo: Barcelona"></td>
                            </tr>
                            <tr>
                                <th><label>Opción 3:</label></th>
                                <td><input type="text" name="option3" required placeholder="Ejemplo: Sevilla"></td>
                            </tr>
                            <tr>
                                <th><label>Respuesta Correcta:</label></th>
                                <td>
                                    <select name="correct_answer" required>
                                        <option value="">Seleccionar respuesta correcta...</option>
                                        <option value="1">Opción 1</option>
                                        <option value="2">Opción 2</option>
                                        <option value="3">Opción 3</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" class="button-primary" value="Crear Pregunta">
                        </p>
                    </form>
                </div>
            </div>
            
            <div class="postbox" style="margin-top: 20px;">
                <h2 class="hndle">Subir Lista de Participantes (CSV)</h2>
                <div class="inside">
                    <p><strong>Formatos soportados:</strong></p>
                    <ul>
                        <li><strong>Formato actual (TEVA):</strong> <code>specialty_target;Email_Account</code></li>
                        <li>Ejemplo: <code>MEDICINA INTENSIVA;doctor@hospital.cl</code></li>
                        <li><strong>Formato simple:</strong> <code>email,nombre</code></li>
                        <li><strong>Formato con punto y coma:</strong> <code>"email";"estado";"fecha";...;"nombre"</code></li>
                    </ul>
                    
                    <div style="background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 15px 0;">
                        <h4>📧 Importante sobre emails y nombres:</h4>
                        <ul>
                            <li>✅ <strong>Email:</strong> Se extrae automáticamente del CSV para validación</li>
                            <li>✅ <strong>Nombre:</strong> Se captura desde la URL del email (parámetro <code>&nombre=</code>)</li>
                            <li>✅ <strong>Especialidad:</strong> Solo se usa para referencia (no se guarda)</li>
                        </ul>
                    </div>
                    
                    <p><strong>Estructura esperada del CSV:</strong></p>
                    <pre style="background: #f5f5f5; padding: 10px; border-radius: 5px; font-size: 12px;">specialty_target;Email_Account
MEDICINA INTENSIVA;doctor1@hospital.cl
INFECTOLOGIA;doctor2@clinica.cl
MEDICINA GENERAL;doctor3@consultorio.cl</pre>
                    
                    <form id="csv-upload-form">
                        <p><input type="file" name="csv_file" accept=".csv" required></p>
                        <p class="submit">
                            <input type="submit" class="button-primary" value="Subir Lista CSV">
                        </p>
                    </form>
                    
                    <!-- Estado actual del CSV -->
                    <div class="csv-status" style="margin-top: 20px; padding: 15px; background-color: #f9f9f9; border-radius: 5px;">
                        <h4>Estado Actual de Participantes:</h4>
                        <?php $this->display_csv_status(); ?>
                    </div>
                </div>
            </div>
            
            <div class="postbox" style="margin-top: 20px;">
                <h2 class="hndle">Preguntas Activas</h2>
                <div class="inside">
                    <?php $this->display_surveys(); ?>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            console.log('TEVA Admin Script Loaded');
            console.log('AJAX URL:', '<?php echo admin_url('admin-ajax.php'); ?>');
            
            // Función para reset de respuestas
            $('#reset-votes-btn').on('click', function() {
                console.log('Reset votes button clicked');
                if (!confirm('¿Estás seguro? Esto eliminará TODAS las respuestas pero mantendrá las preguntas y participantes.')) return;
                if (!confirm('Esta acción NO se puede deshacer. ¿Continuar?')) return;
                
                resetData('votes', 'Respuestas eliminadas exitosamente');
            });
            
            // Función para reset de participantes
            $('#reset-emails-btn').on('click', function() {
                console.log('Reset emails button clicked');
                if (!confirm('¿Estás seguro? Esto eliminará TODOS los participantes válidos.')) return;
                if (!confirm('Esta acción NO se puede deshacer. ¿Continuar?')) return;
                
                resetData('emails', 'Lista de participantes limpiada exitosamente');
            });
            
            // Función para reset de preguntas
            $('#reset-surveys-btn').on('click', function() {
                console.log('Reset surveys button clicked');
                if (!confirm('¿Estás seguro? Esto eliminará TODAS las preguntas y sus respuestas asociadas.')) return;
                if (!confirm('Esta acción NO se puede deshacer. ¿Continuar?')) return;
                
                resetData('surveys', 'Preguntas eliminadas exitosamente');
            });
            
            // Función para reset completo
            $('#reset-all-btn').on('click', function() {
                console.log('Reset all button clicked');
                if (!confirm('⚠️ PELIGRO: Esto eliminará TODO (preguntas, participantes, respuestas). ¿Estás seguro?')) return;
                if (!confirm('🚨 ÚLTIMA CONFIRMACIÓN: Esta acción borrará TODOS los datos del plugin. ¿Continuar?')) return;
                
                resetData('all', 'Plugin reiniciado completamente');
            });
            
            function resetData(type, successMessage) {
                console.log('Reset function called with type:', type);
                
                var btn = $('#reset-' + (type === 'all' ? 'all' : type) + '-btn');
                var originalText = btn.text();
                btn.text('Procesando...').prop('disabled', true);
                
                var postData = {
                    action: 'reset_plugin',
                    reset_type: type,
                    nonce: '<?php echo wp_create_nonce("reset_nonce"); ?>'
                };
                
                console.log('Sending AJAX request:', postData);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: postData,
                    dataType: 'json',
                    timeout: 30000,
                    success: function(response) {
                        console.log('AJAX Success Response:', response);
                        if(response.success) {
                            alert(successMessage);
                            location.reload();
                        } else {
                            alert('Error: ' + (response.data || 'Error desconocido'));
                            console.error('Server Error:', response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', {
                            status: status,
                            error: error,
                            response: xhr.responseText
                        });
                        alert('Error de conexión: ' + error + '\nRevisa la consola para más detalles.');
                    },
                    complete: function() {
                        btn.text(originalText).prop('disabled', false);
                    }
                });
            }
            
            $('#survey-form').on('submit', function(e) {
                e.preventDefault();
                $.post(ajaxurl, {
                    action: 'save_survey',
                    question: $('[name="question"]').val(),
                    option1: $('[name="option1"]').val(),
                    option2: $('[name="option2"]').val(),
                    option3: $('[name="option3"]').val(),
                    correct_answer: $('[name="correct_answer"]').val(),
                    nonce: '<?php echo wp_create_nonce("survey_nonce"); ?>'
                }, function(response) {
                    if(response.success) {
                        alert('Pregunta creada exitosamente');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                });
            });
            
            $('#csv-upload-form').on('submit', function(e) {
                e.preventDefault();
                var formData = new FormData();
                formData.append('action', 'upload_csv');
                formData.append('csv_file', $('[name="csv_file"]')[0].files[0]);
                formData.append('nonce', '<?php echo wp_create_nonce("csv_nonce"); ?>');
                
                // Mostrar indicador de carga
                var submitBtn = $(this).find('input[type="submit"]');
                var originalText = submitBtn.val();
                submitBtn.val('Subiendo...').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if(response.success) {
                            alert('Lista CSV subida exitosamente: ' + response.data);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data);
                        }
                        submitBtn.val(originalText).prop('disabled', false);
                    },
                    error: function() {
                        alert('Error al subir el archivo');
                        submitBtn.val(originalText).prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function display_surveys() {
        global $wpdb;
        $table_surveys = $wpdb->prefix . 'email_surveys';
        $surveys = $wpdb->get_results("SELECT * FROM $table_surveys WHERE is_active = 1 ORDER BY created_at DESC");
        
        if ($surveys) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>ID</th><th>Pregunta</th><th>Opciones</th><th>Respuesta Correcta</th><th>URLs para Email</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($surveys as $survey) {
                $base_url = home_url('/encuesta/');
                echo '<tr>';
                echo '<td>' . $survey->id . '</td>';
                echo '<td>' . esc_html($survey->question) . '</td>';
                echo '<td>1: ' . esc_html($survey->option1) . '<br>2: ' . esc_html($survey->option2) . '<br>3: ' . esc_html($survey->option3) . '</td>';
                echo '<td>Opción ' . $survey->correct_answer . '</td>';
                echo '<td>';
                echo '<strong>URLs para botones del email (con nombre):</strong><br>';
                echo '1: ' . $base_url . '?survey=' . $survey->id . '&email={EMAIL}&nombre={NOMBRE}&option=1<br>';
                echo '2: ' . $base_url . '?survey=' . $survey->id . '&email={EMAIL}&nombre={NOMBRE}&option=2<br>';
                echo '3: ' . $base_url . '?survey=' . $survey->id . '&email={EMAIL}&nombre={NOMBRE}&option=3<br>';
                echo '<br><strong>URLs sin nombre (solo validación):</strong><br>';
                echo '1: ' . $base_url . '?survey=' . $survey->id . '&email={EMAIL}&option=1<br>';
                echo '2: ' . $base_url . '?survey=' . $survey->id . '&email={EMAIL}&option=2<br>';
                echo '3: ' . $base_url . '?survey=' . $survey->id . '&email={EMAIL}&option=3<br>';
                echo '<small><strong>Reemplaza:</strong> {EMAIL} con el email del participante, {NOMBRE} con el nombre (opcional)</small>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<p>No hay preguntas activas.</p>';
        }
    }
    
    public function save_survey() {
        if (!wp_verify_nonce($_POST['nonce'], 'survey_nonce')) {
            wp_die('Acceso denegado');
        }
        
        global $wpdb;
        $table_surveys = $wpdb->prefix . 'email_surveys';
        
        $result = $wpdb->insert(
            $table_surveys,
            array(
                'question' => sanitize_textarea_field($_POST['question']),
                'option1' => sanitize_text_field($_POST['option1']),
                'option2' => sanitize_text_field($_POST['option2']),
                'option3' => sanitize_text_field($_POST['option3']),
                'correct_answer' => intval($_POST['correct_answer'])
            )
        );
        
        if ($result) {
            wp_send_json_success('Pregunta creada exitosamente');
        } else {
            wp_send_json_error('Error al crear la pregunta');
        }
    }
    
    public function display_csv_status() {
        global $wpdb;
        $table_emails = $wpdb->prefix . 'survey_valid_emails';
        
        $total_emails = $wpdb->get_var("SELECT COUNT(*) FROM $table_emails");
        $recent_emails = $wpdb->get_results("SELECT email, created_at FROM $table_emails ORDER BY created_at DESC LIMIT 10");
        
        if ($total_emails > 0) {
            echo '<div style="color: #155724; background-color: #d4edda; padding: 10px; border-radius: 4px; margin-bottom: 15px;">';
            echo '<strong>✅ Lista de Participantes Cargada Correctamente</strong><br>';
            echo "Total de participantes válidos: <strong>$total_emails</strong><br>";
            echo "Última actualización: " . ($recent_emails ? date('d/m/Y H:i:s', strtotime($recent_emails[0]->created_at)) : 'N/A');
            echo '</div>';
            
            if ($recent_emails) {
                echo '<div class="csv-preview">';
                echo '<h5>Últimos participantes agregados:</h5>';
                echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: white;">';
                
                foreach ($recent_emails as $email_record) {
                    echo '<div style="padding: 3px 0; border-bottom: 1px solid #eee; font-size: 12px;">';
                    echo esc_html($email_record->email) . ' <span style="color: #999;">(' . date('d/m H:i', strtotime($email_record->created_at)) . ')</span>';
                    echo '</div>';
                }
                
                echo '</div>';
                
                if ($total_emails > 10) {
                    echo '<p style="font-size: 11px; color: #666; margin-top: 5px;">... y ' . ($total_emails - 10) . ' participantes más</p>';
                }
                
                echo '</div>';
            }
            
        } else {
            echo '<div style="color: #856404; background-color: #fff3cd; padding: 10px; border-radius: 4px;">';
            echo '<strong>⚠️ No hay participantes cargados</strong><br>';
            echo 'Debes subir un archivo CSV con la lista de participantes para poder enviar preguntas por email.';
            echo '</div>';
        }
    }
    
    public function upload_csv() {
        if (!wp_verify_nonce($_POST['nonce'], 'csv_nonce')) {
            wp_die('Acceso denegado');
        }
        
        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error('No se seleccionó archivo');
        }
        
        $file = $_FILES['csv_file'];
        $handle = fopen($file['tmp_name'], 'r');
        
        if (!$handle) {
            wp_send_json_error('Error al leer el archivo');
        }
        
        global $wpdb;
        $table_emails = $wpdb->prefix . 'survey_valid_emails';
        
        // Limpiar tabla existente
        $wpdb->query("TRUNCATE TABLE $table_emails");
        
        $count = 0;
        $line_number = 0;
        $skipped_lines = array();
        
        while (($data = fgetcsv($handle, 1000, ';')) !== FALSE) {
            $line_number++;
            
            // Saltar la primera línea si son encabezados
            if ($line_number == 1 && (
                strpos(strtolower($data[0]), 'specialty') !== false || 
                strpos(strtolower($data[0]), 'especialidad') !== false ||
                strpos(strtolower($data[1] ?? ''), 'email') !== false ||
                strpos(strtolower($data[1] ?? ''), 'mail') !== false
            )) {
                teva_debug_log("Skipping header line: " . implode(';', $data));
                continue;
            }
            
            // ✅ NUEVO FORMATO: specialty_target;Email_Account
            // La columna 2 (índice 1) contiene el email
            if (count($data) >= 2) {
                $email = trim($data[1], '"');
                $specialty = trim($data[0], '"'); // Opcional para debug
                
                teva_debug_log("Processing line $line_number: specialty='$specialty', email='$email'");
                
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result = $wpdb->insert(
                        $table_emails,
                        array(
                            'email' => sanitize_email($email),
                            'name' => null // Solo guardamos el email, el nombre viene de la URL
                        )
                    );
                    
                    if ($result) {
                        $count++;
                        teva_debug_log("Successfully inserted email: $email");
                    } else {
                        teva_debug_log("Failed to insert email: $email - " . $wpdb->last_error);
                        $skipped_lines[] = $line_number . " (DB error): $email";
                    }
                } else {
                    teva_debug_log("Invalid email format: $email");
                    $skipped_lines[] = $line_number . " (invalid format): $email";
                }
            } else {
                teva_debug_log("Line $line_number has insufficient columns: " . implode(';', $data));
                $skipped_lines[] = $line_number . " (insufficient columns)";
            }
        }
        
        fclose($handle);
        
        $message = "$count participantes importados exitosamente de $line_number líneas procesadas";
        
        if (!empty($skipped_lines)) {
            $message .= ". Líneas omitidas: " . count($skipped_lines);
            teva_debug_log("Skipped lines: " . implode(', ', $skipped_lines));
        }
        
        wp_send_json_success($message);
    }
    
    public function survey_form_shortcode($atts) {
        teva_debug_log('survey_form_shortcode called');
        teva_debug_log('GET params: ' . print_r($_GET, true));
        
        // NUEVO FLUJO: Mantenemos URLs obvias para el inicio, luego redirigimos
        $survey_id = 0;
        $email = '';
        $nombre = '';
        $preselected_option = 0;
        
        // 1. PRIMERA ENTRADA: URLs obvias desde email
        if (isset($_GET['survey']) && isset($_GET['email'])) {
            $survey_id = intval($_GET['survey']);
            // DECODIFICAR URL antes de sanitizar
            $email = sanitize_email(urldecode($_GET['email']));
            $nombre = isset($_GET['nombre']) ? sanitize_text_field(urldecode($_GET['nombre'])) : ''; // AGREGAR: Capturar nombre
            $preselected_option = isset($_GET['option']) ? intval($_GET['option']) : 0;
            
            teva_debug_log("Direct URL access - survey_id=$survey_id, email=$email, nombre=$nombre, option=$preselected_option");
            
            // Validar email inmediatamente
            if (!$this->is_valid_email($email)) {
                return '<div style="padding: 20px; border: 2px solid orange;"><p><strong>ERROR:</strong> Email no autorizado para participar en esta encuesta.</p><p>Email verificado: ' . $email . '</p></div>';
            }
            
            // Crear sesión y redirigir a URL limpia
            $session_token = $this->create_session_token($survey_id, $email, $nombre); // MODIFICAR: Pasar nombre
            
            // USAR JAVASCRIPT PARA SETEAR LA COOKIE EN LUGAR DE PHP
            $clean_url = home_url('/encuesta/?survey=' . $session_token);
            if ($preselected_option) {
                $clean_url .= '&option=' . $preselected_option;
            }
            
            // Usar JavaScript para redirección suave y setear cookie
            ob_start();
            ?>
            <div class="survey-loading-wrapper">
                <!-- Header imagen -->
                <div class="survey-header-image">
                    <img src="<?php echo plugin_dir_url(__FILE__) . 'assets/images/header.jpg'; ?>" alt="TEVA Survey Header" />
                </div>
                
                <div class="loading-content">
                    <h3>🔄 Iniciando encuesta...</h3>
                    <p>Configurando sesión segura...</p>
                    <div class="progress-container">
                        <div class="progress-bar-bg">
                            <div id="progress-bar" class="progress-bar-fill"></div>
                        </div>
                    </div>
                    <p><small>Si no se redirige automáticamente, <a href="<?php echo $clean_url; ?>">haz clic aquí</a></small></p>
                </div>
            </div>
            
            <style>
            .survey-loading-wrapper {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                max-width: 800px;
                margin: 0 auto;
                background: white;
                border-radius: 15px;
                overflow: hidden;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            }
            
            .survey-header-image {
                position: relative;
                width: 100%;
                height: 250px;
                overflow: hidden;
            }
            
            .survey-header-image img {
                width: 100%;
                height: 100%;
                object-fit: cover;
                object-position: center;
            }
            
            .header-overlay {
                position: absolute;
                bottom: 0;
                left: 0;
                right: 0;
                background: linear-gradient(transparent, rgba(0,0,0,0.7));
                color: white;
                padding: 30px 20px 20px;
            }
            
            .header-overlay h2 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
            }
            
            .loading-content {
                padding: 30px;
                text-align: center;
                border: 2px solid #007cba;
                margin: -2px;
            }
            
            .progress-container {
                margin: 20px 0;
            }
            
            .progress-bar-bg {
                width: 100%;
                height: 20px;
                background: #ddd;
                border-radius: 10px;
                overflow: hidden;
                box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .progress-bar-fill {
                width: 0%;
                height: 100%;
                background: linear-gradient(90deg, #007cba, #005a87);
                transition: width 2s ease;
                border-radius: 10px;
            }
            
            /* Mobile responsive */
            @media (max-width: 768px) {
                .survey-loading-wrapper {
                    margin: 10px;
                    border-radius: 10px;
                }
                
                .survey-header-image {
                    height: 180px;
                }
                
                .header-overlay h2 {
                    font-size: 20px;
                }
                
                .loading-content {
                    padding: 20px;
                }
            }
            </style>
            
            <script>
            // Setear cookie con JavaScript para evitar el warning de headers
            document.cookie = 'survey_session=<?php echo $session_token; ?>; path=/; max-age=86400; secure=false; samesite=strict';
            
            // Animación de progreso
            setTimeout(function() {
                document.getElementById('progress-bar').style.width = '100%';
            }, 100);
            
            // Redirección después de la animación
            setTimeout(function() {
                window.location.href = '<?php echo $clean_url; ?>';
            }, 2200);
            </script>
            <?php
            return ob_get_clean();
        }
        
        // AGREGAR: Manejar caso donde no hay survey_id pero sí hay email (URL del email)
        if (!isset($_GET['survey']) && isset($_GET['email'])) {
            // URL desde email sin survey_id especificado - buscar encuesta activa
            $email = sanitize_email(urldecode($_GET['email']));
            $nombre = isset($_GET['nombre']) ? sanitize_text_field(urldecode($_GET['nombre'])) : ''; // AGREGAR: Capturar nombre
            $preselected_option = isset($_GET['option']) ? intval($_GET['option']) : 0;
            
            // Buscar la encuesta activa más reciente
            global $wpdb;
            $table_surveys = $wpdb->prefix . 'email_surveys';
            $active_survey = $wpdb->get_row("SELECT id FROM $table_surveys WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
            
            if ($active_survey) {
                $survey_id = $active_survey->id;
                
                teva_debug_log("Email URL without survey_id - found active survey: $survey_id, email: $email, nombre: $nombre, option: $preselected_option");
                
                // Validar email
                if (!$this->is_valid_email($email)) {
                    return '<div style="padding: 20px; border: 2px solid orange;"><p><strong>ERROR:</strong> Email no autorizado para participar en esta encuesta.</p><p>Email verificado: ' . $email . '</p></div>';
                }
                
                // Crear sesión y redirigir
                $session_token = $this->create_session_token($survey_id, $email, $nombre); // MODIFICAR: Pasar nombre
                $clean_url = home_url('/encuesta/?survey=' . $session_token);
                if ($preselected_option) {
                    $clean_url .= '&option=' . $preselected_option;
                }
                
                // Redirección inmediata
                ob_start();
                ?>
                <div style="padding: 20px; text-align: center; border: 2px solid #28a745;">
                    <h3>✅ Email detectado</h3>
                    <p>Redirigiendo a la encuesta activa...</p>
                </div>
                
                <script>
                document.cookie = 'survey_session=<?php echo $session_token; ?>; path=/; max-age=86400; secure=false; samesite=strict';
                setTimeout(function() {
                    window.location.href = '<?php echo $clean_url; ?>';
                }, 1000);
                </script>
                <?php
                return ob_get_clean();
            } else {
                return '<div style="padding: 20px; border: 2px solid red;"><p><strong>ERROR:</strong> No hay encuestas activas disponibles.</p></div>';
            }
        }
        
        // 2. ACCESO POR SESIÓN: URLs con token en parámetro 'survey'
        if (isset($_GET['survey']) && !isset($_GET['email'])) {
            // El parámetro 'survey' contiene el token, no el ID
            $session_token = sanitize_text_field($_GET['survey']);
            $session_data = $this->validate_session_token($session_token);
            
            if ($session_data) {
                $survey_id = $session_data['survey_id'];
                $email = $session_data['email'];
                $nombre = isset($session_data['nombre']) ? $session_data['nombre'] : ''; // AGREGAR: Validación
                $preselected_option = isset($_GET['option']) ? intval($_GET['option']) : 0;
                teva_debug_log("Session access via survey param - survey_id=$survey_id, email=$email, nombre=$nombre, option=$preselected_option");
            }
        }
        
        // 3. FALLBACK: Intentar recuperar de cookie
        if (!$survey_id || !$email) {
            $session_data = $this->get_session_from_cookie();
            if ($session_data) {
                $survey_id = $session_data['survey_id'];
                $email = $session_data['email'];
                $nombre = isset($session_data['nombre']) ? $session_data['nombre'] : ''; // AGREGAR: Validación
                teva_debug_log("Cookie fallback - survey_id=$survey_id, email=$email, nombre=$nombre");
            }
        }
        
        // Validar que tenemos datos válidos
        if (!$survey_id || !$email) {
            return '<div style="padding: 20px; border: 2px solid red;">
                <h3>❌ Acceso Inválido</h3>
                <p><strong>ERROR:</strong> No se pudo identificar la sesión.</p>
                <p>Posibles causas:</p>
                <ul>
                    <li>El enlace del email ha expirado</li>
                    <li>Las cookies están deshabilitadas</li>
                    <li>Acceso directo sin enlace del email</li>
                </ul>
                <p><strong>Solución:</strong> Utiliza el enlace original del email.</p>
            </div>';
        }
        
        global $wpdb;
        $table_surveys = $wpdb->prefix . 'email_surveys';
        $survey = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_surveys WHERE id = %d AND is_active = 1", $survey_id));
        
        if (!$survey) {
            return '<div style="padding: 20px; border: 2px solid red;">
                <h3>❌ Encuesta No Encontrada</h3>
                <p><strong>ERROR:</strong> La encuesta solicitada no existe o no está activa.</p>
                <p>ID de encuesta: ' . $survey_id . '</p>
            </div>';
        }
        
        // Verificar si ya completó la encuesta (acertó)
        $has_completed = $this->has_voted($survey_id, $email);
        if ($has_completed) {
            $results_url = home_url('/resultados/?survey=' . $this->create_session_token($survey_id, $email, $nombre));
            return '<div style="padding: 20px; border: 2px solid blue;">
                <h3>✅ Encuesta Completada</h3>
                <p>Ya has completado esta encuesta exitosamente.</p>
                <p><a href="' . $results_url . '" class="button" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Ver Resultados</a></p>
            </div>';
        }
        
        // Obtener estadísticas para mostrar SIEMPRE
        $table_votes = $wpdb->prefix . 'survey_votes';
        $total_votes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_votes WHERE survey_id = %d", $survey_id));
        $option1_votes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_votes WHERE survey_id = %d AND selected_option = 1", $survey_id));
        $option2_votes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_votes WHERE survey_id = %d AND selected_option = 2", $survey_id));
        $option3_votes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_votes WHERE survey_id = %d AND selected_option = 3", $survey_id));
        
        // Calcular porcentajes
        if ($total_votes > 0) {
            $option1_percent = round(($option1_votes / $total_votes) * 100, 1);
            $option2_percent = round(($option2_votes / $total_votes) * 100, 1);
            $option3_percent = round(($option3_votes / $total_votes) * 100, 1);
        } else {
            $option1_percent = 0;
            $option2_percent = 0;
            $option3_percent = 0;
        }
        
        // Obtener número de intentos solo para mostrar
        $attempts = $this->get_attempt_count($survey_id, $email);
        
        // Obtener nonce para AJAX
        $ajax_nonce = wp_create_nonce('vote_nonce');
        
        ob_start();
        ?>
        <link rel="stylesheet" href="<?php echo plugin_dir_url(__FILE__) . 'css/survey-form.css'; ?>">
        <div class="survey-container">
            <!-- Header con imagen -->
            <div class="survey-header-image">
                <img src="<?php echo plugin_dir_url(__FILE__) . 'assets/images/header.jpg'; ?>" alt="TEVA Survey Header" />
            </div>
            
            <!-- NUEVO: Estadísticas compactas siempre visibles -->
            <div class="compact-stats">
                <h3>👥 ¿Cómo está votando el resto?</h3>
                <div class="horizontal-chart">
                    <div class="chart-bar">
                        <div class="bar-segment option1" style="width: <?php echo $option1_percent; ?>%" title="<?php echo esc_attr($survey->option1); ?>: <?php echo $option1_percent; ?>%"></div>
                        <div class="bar-segment option2" style="width: <?php echo $option2_percent; ?>%" title="<?php echo esc_attr($survey->option2); ?>: <?php echo $option2_percent; ?>%"></div>
                        <div class="bar-segment option3" style="width: <?php echo $option3_percent; ?>%" title="<?php echo esc_attr($survey->option3); ?>: <?php echo $option3_percent; ?>%"></div>
                    </div>
                    <div class="chart-labels">
                        <div class="label-item">
                            <span class="label-color option1-color"></span>
                            <span class="label-text"><?php echo esc_html($survey->option1); ?></span>
                            <span class="label-percent"><?php echo $option1_percent; ?>%</span>
                        </div>
                        <div class="label-item">
                            <span class="label-color option2-color"></span>
                            <span class="label-text"><?php echo esc_html($survey->option2); ?></span>
                            <span class="label-percent"><?php echo $option2_percent; ?>%</span>
                        </div>
                        <div class="label-item">
                            <span class="label-color option3-color"></span>
                            <span class="label-text"><?php echo esc_html($survey->option3); ?></span>
                            <span class="label-percent"><?php echo $option3_percent; ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="survey-content">
                <div class="question-section">
                    <h2><?php echo esc_html($survey->question); ?></h2>
                    
                    <?php if ($attempts > 0): ?>
                        <div class="attempt-info">
                            <!-- ✅ MOSTRAR CORRECTAMENTE: próximo intento = attempts + 1 -->
                            <p><strong>💡 Intento número <?php echo $attempts + 1; ?></strong></p>
                            <?php if ($attempts == 1): ?>
                                <p>Tu primera respuesta no fue correcta. ¡Inténtalo de nuevo!</p>
                            <?php elseif ($attempts >= 2): ?>
                                <p>Has intentado <?php echo $attempts; ?> veces. ¡Sigue intentando!</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <form id="vote-form" 
                      data-survey="<?php echo $survey_id; ?>" 
                      data-email="<?php echo esc_attr($email); ?>" 
                      data-nonce="<?php echo $ajax_nonce; ?>"
                      data-preselected="<?php echo $preselected_option; ?>"
                      data-attempts="<?php echo $attempts; ?>"
                      data-ajax-url="<?php echo admin_url('admin-ajax.php'); ?>"
                      data-results-url="<?php echo home_url('/resultados/?survey=' . (isset($_GET['survey']) ? sanitize_text_field($_GET['survey']) : '')); ?>">
                    <div class="survey-options">
                        <label class="survey-option <?php echo $preselected_option == 1 ? 'selected' : ''; ?>">
                            <input type="radio" name="option" value="1" <?php echo $preselected_option == 1 ? 'checked' : ''; ?>>
                            <span class="option-text"><?php echo esc_html($survey->option1); ?></span>
                            <span class="option-number">1</span>
                        </label>
                        <label class="survey-option <?php echo $preselected_option == 2 ? 'selected' : ''; ?>">
                            <input type="radio" name="option" value="2" <?php echo $preselected_option == 2 ? 'checked' : ''; ?>>
                            <span class="option-text"><?php echo esc_html($survey->option2); ?></span>
                            <span class="option-number">2</span>
                        </label>
                        <label class="survey-option <?php echo $preselected_option == 3 ? 'selected' : ''; ?>">
                            <input type="radio" name="option" value="3" <?php echo $preselected_option == 3 ? 'checked' : ''; ?>>
                            <span class="option-text"><?php echo esc_html($survey->option3); ?></span>
                            <span class="option-number">3</span>
                        </label>
                    </div>
                    
                    <!-- Mensaje de estado oculto inicialmente -->
                    <div id="vote-status" class="vote-status" style="display: none;">
                        <div class="status-content">
                            <svg class="status-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="3"></circle>
                                <path d="m12 1 0 6m0 6 0 6"></path>
                                <path d="m12 1 0 6m0 6 0 6" transform="rotate(60 12 12)"></path>
                                <path d="m12 1 0 6m0 6 0 6" transform="rotate(120 12 12)"></path>
                            </svg>
                            <span class="status-text">Enviando respuesta...</span>
                        </div>
                    </div>
                </form>
                
                <!-- Info de sesión -->
                <div class="session-info">
                    <div class="session-status">
                        <span class="status-indicator">🔒</span>
                        <span>Sesión Segura Activa</span>
                    </div>
                    <div class="progress-indicator">
                        <!-- ✅ MOSTRAR: intentos ya realizados -->
                        <span>Intentos realizados: <?php echo $attempts; ?></span>
                    </div>
                </div>
            </div>
            
        <!-- NUEVO: Footer con imagen para resultados -->
            <div class="results-footer-image">
                <a href="https://www.fitomedicamentos.cl/" target="_blank" rel="noopener noreferrer">
                    <img src="<?php echo plugin_dir_url(__FILE__) . 'assets/images/bottom.png'; ?>" alt="TEVA Survey Footer" />
                </a>
            </div>

        </div>
        <script src="<?php echo plugin_dir_url(__FILE__) . 'js/survey-form.js'; ?>"></script>
        <?php
    }
    
    public function survey_results_shortcode($atts) {
        // NUEVA FUNCIONALIDAD: Recuperar datos desde sesión en lugar de URL
        $session_data = $this->get_session_from_request();
        
        if (!$session_data) {
            return '<div style="padding: 20px; border: 2px solid red;"><p><strong>ERROR:</strong> Sesión inválida o expirada.</p><p>Por favor, utiliza el enlace original del email.</p></div>';
        }
        
        $survey_id = $session_data['survey_id'];
        $email = $session_data['email'];
        $nombre = isset($session_data['nombre']) ? $session_data['nombre'] : ''; // AGREGAR: Obtener nombre
        
        teva_debug_log("Results page - survey_id=$survey_id, email=$email, nombre=$nombre");
        
        global $wpdb;
        $table_surveys = $wpdb->prefix . 'email_surveys';
        $table_votes = $wpdb->prefix . 'survey_votes';
        
        $survey = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_surveys WHERE id = %d", $survey_id));
        if (!$survey) {
            return '<p>Preguntas no encontradas.</p>';
        }
        
        // Obtener datos del usuario actual
        $has_completed = $this->has_voted($survey_id, $email);
        $attempt_count = $this->get_attempt_count($survey_id, $email);
        $max_attempts = 3;
        
        $last_vote = $wpdb->get_row($wpdb->prepare(
            "SELECT selected_option, is_correct FROM $table_votes 
             WHERE survey_id = %d AND email = %s 
             ORDER BY voted_at DESC LIMIT 1", 
            $survey_id, $email
        ));
        
        $is_correct = $last_vote ? $last_vote->is_correct : null;
        
        // CAMBIO AQUÍ: SIEMPRE mostrar resultados estadísticos primero
        $results_output = $this->display_normal_results($survey, $last_vote, $is_correct, $has_completed, $attempt_count, $max_attempts, $survey_id, $email, $nombre); // AGREGAR: Pasar nombre
        
        // Si respondió correctamente, agregar el formulario al final con separación
        if ($is_correct) {
            // Quitar el separador visual duplicado
            $results_output .= $this->display_confetti_celebration($email, $nombre);
        }
        
        return $results_output;
    }

    // Función para mostrar resultados normales
    private function display_normal_results($survey, $last_vote, $is_correct, $has_completed, $attempt_count, $max_attempts, $survey_id, $email, $nombre) {
        // Obtener estadísticas generales
        global $wpdb;
        $table_votes = $wpdb->prefix . 'survey_votes';
        
        $total_votes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_votes WHERE survey_id = %d", $survey_id));
        $option1_votes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_votes WHERE survey_id = %d AND selected_option = 1", $survey_id));
        $option2_votes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_votes WHERE survey_id = %d AND selected_option = 2", $survey_id));
        $option3_votes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_votes WHERE survey_id = %d AND selected_option = 3", $survey_id));
        
        // Calcular porcentajes
        if ($total_votes > 0) {
            $option1_percent = round(($option1_votes / $total_votes) * 100, 1);
            $option2_percent = round(($option2_votes / $total_votes) * 100, 1);
            $option3_percent = round(($option3_votes / $total_votes) * 100, 1);
        } else {
            $option1_percent = 0;
            $option2_percent = 0;
            $option3_percent = 0;
        }

        ob_start();
        ?>
        <link rel="stylesheet" href="<?php echo plugin_dir_url(__FILE__) . 'css/results.css'; ?>">

        <div class="results-container">
            <!-- NUEVO: Header con imagen también en resultados -->
            <div class="results-header-image">
                <img src="<?php echo plugin_dir_url(__FILE__) . 'assets/images/header.jpg'; ?>" alt="TEVA Survey Header" />
                <div class="header-overlay">
                    <div class="header-content">
                        <h1>Resultados TEVA</h1>
                        <?php if (!empty($nombre)): ?>
                            <p class="welcome-text">Resultados para <?php echo esc_html($nombre); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="results-content">
                <!-- NUEVO: Estadísticas compactas siempre en la parte superior -->
                <div class="compact-stats">
                    <h3>👥 ¿Cómo está votando el resto?</h3>
                    <div class="horizontal-chart">
                        <div class="chart-bar">
                            <div class="bar-segment option1" style="width: <?php echo $option1_percent; ?>%" title="<?php echo esc_attr($survey->option1); ?>: <?php echo $option1_percent; ?>%"></div>
                            <div class="bar-segment option2" style="width: <?php echo $option2_percent; ?>%" title="<?php echo esc_attr($survey->option2); ?>: <?php echo $option2_percent; ?>%"></div>
                            <div class="bar-segment option3" style="width: <?php echo $option3_percent; ?>%" title="<?php echo esc_attr($survey->option3); ?>: <?php echo $option3_percent; ?>%"></div>
                        </div>
                        <div class="chart-labels">
                            <div class="label-item">
                                <span class="label-color option1-color"></span>
                                <span class="label-text"><?php echo esc_html($survey->option1); ?></span>
                                <span class="label-percent"><?php echo $option1_percent; ?>%</span>
                            </div>
                            <div class="label-item">
                                <span class="label-color option2-color"></span>
                                <span class="label-text"><?php echo esc_html($survey->option2); ?></span>
                                <span class="label-percent"><?php echo $option2_percent; ?>%</span>
                            </div>
                            <div class="label-item">
                                <span class="label-color option3-color"></span>
                                <span class="label-text"><?php echo esc_html($survey->option3); ?></span>
                                <span class="label-percent"><?php echo $option3_percent; ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <h2>📊 Resultados</h2>
                <div class="question"><?php echo esc_html($survey->question); ?></div>
                
                <?php if ($last_vote): ?>
                    <div class="result-message <?php echo $is_correct ? 'correct' : 'incorrect'; ?>">
                        <?php if ($is_correct): ?>
                            <h3>¡Excelente<?php echo !empty($nombre) ? ', ' . esc_html($nombre) : ''; ?>! 🎉</h3>
                            <p>Has seleccionado la respuesta correcta. ¡Buen trabajo!</p>
                            <?php if ($attempt_count == 1): ?>
                                <p><em>¡Lo lograste en el primer intento! 🏆</em></p>
                            <?php elseif ($attempt_count == 2): ?>
                                <p><em>¡Lo lograste en el segundo intento! 👏</em></p>
                            <?php else: ?>
                                <p><em>¡Persistencia recompensada<?php echo !empty($nombre) ? ', ' . esc_html($nombre) : ''; ?>! Lo lograste en el intento <?php echo $attempt_count; ?>! 🎯</em></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <h3>¡Inténtalo de nuevo<?php echo !empty($nombre) ? ', ' . esc_html($nombre) : ''; ?>! 🤔</h3>
                            <p>Tu respuesta no fue la correcta esta vez.</p>
                            
                            <?php if (!$has_completed): ?>
                                <div class="retry-section">
                                    <?php 
                                    // ✅ FIX: Usar el MISMO token, no crear uno nuevo
                                    $current_token = isset($_GET['survey']) ? sanitize_text_field($_GET['survey']) : '';
                                    if (empty($current_token)) {
                                        // Solo crear nuevo token si no hay uno actual
                                        $current_token = $this->create_session_token($survey_id, $email, $nombre);
                                    }
                                    $retry_url = home_url('/encuesta/?survey=' . $current_token); 
                                    ?>
                                    <a href="<?php echo $retry_url; ?>" class="retry-btn">
                                        🔄 Volver a Intentar
                                    </a>
                                    
                                    <p style="margin-top: 10px; font-size: 14px; color: #666;">
                                        💡 <strong>Consejo:</strong> ¡No te rindas! Puedes seguir intentando hasta encontrar la respuesta correcta.
                                    </p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Info de sesión segura -->
                <div style="background: #e8f5e8; padding: 10px; margin: 10px 0; font-size: 12px; border-radius: 5px; border-left: 4px solid #28a745;">
                    <strong>✅ Sesión Autenticada</strong><br>
                    <?php if (!empty($nombre)): ?>
                        Participante: <?php echo esc_html($nombre); ?> (<?php echo esc_html($email); ?>)<br>
                    <?php else: ?>
                        Participante verificado: <?php echo esc_html($email); ?><br>
                    <?php endif; ?>
                    Intentos realizados: <?php echo $attempt_count; ?><br>
                    Estado: <?php echo $has_completed ? 'Completado ✅' : 'En progreso ⏳'; ?><br>
                </div>
            </div>
            
            <!-- NUEVO: Footer con imagen para resultados -->
            <div class="results-footer-image">
                <a href="https://www.fitomedicamentos.cl/" target="_blank" rel="noopener noreferrer">
                    <img src="<?php echo plugin_dir_url(__FILE__) . 'assets/images/bottom.png'; ?>" alt="TEVA Survey Footer" />
                </a>
            </div>
        </div>
                
        <?php
    }
    
    public function display_confetti_celebration($email, $nombre) {
        ob_start();
        ?>
        <canvas id="confetti-canvas"></canvas>
        
        <link rel="stylesheet" href="<?php echo plugin_dir_url(__FILE__) . 'css/confetti.css'; ?>">

        
        <script src="<?php echo plugin_dir_url(__FILE__) . 'js/confetti.js'; ?>"></script>
        
        
        <?php
        return ob_get_clean();
    }
    // NUEVAS FUNCIONES PARA MANEJO DE SESIONES SEGURAS
    private function create_session_token($survey_id, $email, $nombre = '') {
        $data = array(
            'survey_id' => $survey_id,
            'email' => $email,
            'nombre' => $nombre, // AGREGAR: Incluir nombre en el token
            'timestamp' => time(),
            'expires' => time() + (24 * 60 * 60) // 24 horas
        );
        
        // Crear hash seguro
        $token = base64_encode(json_encode($data));
        
        // Guardar en base de datos para validación extra
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'survey_sessions';
        
        // Crear tabla de sesiones si no existe
        $this->create_sessions_table();
        
        // Limpiar sesiones expiradas
        $wpdb->query("DELETE FROM $table_sessions WHERE expires < " . time());
        
        // Guardar nueva sesión
        $wpdb->replace(
            $table_sessions,
            array(
                'token' => $token,
                'survey_id' => $survey_id,
                'email' => $email,
                'expires' => $data['expires']
            ),
            array('%s', '%d', '%s', '%d')
        );
        
        return $token;
    }
    
    private function get_session_from_cookie() {
        if (!isset($_COOKIE['survey_session'])) {
            return false;
        }
        
        $session_data = $this->validate_session_token($_COOKIE['survey_session']);
        
        // Asegurar que siempre tenga la clave 'nombre'
        if ($session_data && !isset($session_data['nombre'])) {
            $session_data['nombre'] = '';
        }
        
        return $session_data;
    }
    
    private function get_session_from_request() {
        // Buscar token en parámetro 'survey' (cuando viene de redirección) o 's' (fallback)
        $token = '';
        
        if (isset($_GET['survey']) && !isset($_GET['email'])) {
            // Si hay 'survey' pero no 'email', es un token
            $token = sanitize_text_field($_GET['survey']);
        } elseif (isset($_GET['s'])) {
            // Fallback para parámetro 's'
            $token = sanitize_text_field($_GET['s']);
        }
        
        if (!$token && isset($_COOKIE['survey_session'])) {
            $token = $_COOKIE['survey_session'];
        }
        
        if (!$token) {
            return false;
        }
        
        $session_data = $this->validate_session_token($token);
        
        // Asegurar que siempre tenga la clave 'nombre'
        if ($session_data && !isset($session_data['nombre'])) {
            $session_data['nombre'] = '';
        }
        
        return $session_data;
    }
    
    private function validate_session_token($token) {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'survey_sessions';
        
        // Verificar en base de datos
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_sessions WHERE token = %s AND expires > %d",
            $token, time()
        ));
        
        if (!$session) {
            return false;
        }
        
        // Validar también el contenido del token
        try {
            $data = json_decode(base64_decode($token), true);
            
            if (!$data || $data['expires'] < time()) {
                return false;
            }
            
            return array(
                'survey_id' => $session->survey_id,
                'email' => $session->email,
                'nombre' => isset($data['nombre']) ? $data['nombre'] : '' // AGREGAR: Incluir nombre con validación
            );
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function create_sessions_table() {
        global $wpdb;
        
        $table_sessions = $wpdb->prefix . 'survey_sessions';
        $charset_collate = $wpdb->get_charset_collate();
        
               
        $sql = "CREATE TABLE IF NOT EXISTS $table_sessions (
           
            token varchar(500) NOT NULL PRIMARY KEY,
            survey_id mediumint(9) NOT NULL,
            email varchar(255) NOT NULL,
            expires int(11) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            INDEX email_idx (email),
            INDEX expires_idx (expires)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private function is_valid_email($email) {
        global $wpdb;
        $table_emails = $wpdb->prefix . 'survey_valid_emails';
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_emails WHERE email = %s", $email));
        return $count > 0;
    }
    
    private function has_voted($survey_id, $email) {
        global $wpdb;
        $table_votes = $wpdb->prefix . 'survey_votes';
        // Solo cuenta como "completado" si acertó
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_votes WHERE survey_id = %d AND email = %s AND is_correct = 1", $survey_id, $email));
        return $count > 0;
    }
    
    private function get_attempt_count($survey_id, $email) {
        global $wpdb;
        $table_votes = $wpdb->prefix . 'survey_votes';
        // Contar TODOS los intentos (correctos e incorrectos)
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_votes WHERE survey_id = %d AND email = %s", $survey_id, $email));
        return $count;
    }
    
    public function clear_csv() {
        if (!wp_verify_nonce($_POST['nonce'], 'csv_nonce')) {
            wp_die('Acceso denegado');
        }
        
        global $wpdb;
        $table_emails = $wpdb->prefix . 'survey_valid_emails';
        
        $result = $wpdb->query("TRUNCATE TABLE $table_emails");
        
        if ($result !== false) {
            wp_send_json_success('Lista de participantes limpiada exitosamente');
        } else {
            wp_send_json_error('Error al limpiar la lista de participantes');
        }
    }
    
    public function reset_plugin() {
        // Debug log
        teva_debug_log('Reset plugin function called');
        teva_debug_log('POST data: ' . print_r($_POST, true));
        
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'reset_nonce')) {
            teva_debug_log('Nonce verification failed');
            wp_send_json_error('Acceso denegado - Nonce inválido');
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            teva_debug_log('Insufficient permissions');
            wp_send_json_error('Permisos insuficientes');
            return;
        }
        
        $reset_type = isset($_POST['reset_type']) ? sanitize_text_field($_POST['reset_type']) : '';
        
        if (empty($reset_type)) {
            teva_debug_log('No reset type provided');
            wp_send_json_error('Tipo de reset no especificado');
            return;
        }
        
        teva_debug_log('Processing reset type: ' . $reset_type);
        
        global $wpdb;
        
        try {
            switch ($reset_type) {
                case 'votes':
                    $table_votes = $wpdb->prefix . 'survey_votes';
                    $result = $wpdb->query("TRUNCATE TABLE `$table_votes`");
                    teva_debug_log('Votes reset result: ' . ($result !== false ? 'SUCCESS' : 'FAILED'));
                    if ($result !== false) {
                        wp_send_json_success('Respuestas eliminadas exitosamente');
                    } else {
                        wp_send_json_error('Error al eliminar respuestas: ' . $wpdb->last_error);
                    }
                    break;
                    
                case 'emails':
                    $table_emails = $wpdb->prefix . 'survey_valid_emails';
                    $result = $wpdb->query("TRUNCATE TABLE `$table_emails`");
                    teva_debug_log('Emails reset result: ' . ($result !== false ? 'SUCCESS' : 'FAILED'));
                    if ($result !== false) {
                        wp_send_json_success('Participantes eliminados exitosamente');
                    } else {
                        wp_send_json_error('Error al eliminar participantes: ' . $wpdb->last_error);
                    }
                    break;
                    
                case 'surveys':
                    $table_surveys = $wpdb->prefix . 'email_surveys';
                    $table_votes = $wpdb->prefix . 'survey_votes';
                    $wpdb->query("TRUNCATE TABLE `$table_votes`");
                    $result = $wpdb->query("TRUNCATE TABLE `$table_surveys`");
                    teva_debug_log('Surveys reset result: ' . ($result !== false ? 'SUCCESS' : 'FAILED'));
                    if ($result !== false) {
                        wp_send_json_success('Preguntas eliminadas exitosamente');
                    } else {
                        wp_send_json_error('Error al eliminar preguntas: ' . $wpdb->last_error);
                    }
                    break;
                    
                case 'all':
                    $tables = array(
                        $wpdb->prefix . 'email_surveys',
                        $wpdb->prefix . 'survey_valid_emails',
                        $wpdb->prefix . 'survey_votes',
                        $wpdb->prefix . 'survey_sessions'
                    );
                    
                    $success = true;
                    $errors = array();
                    
                    foreach ($tables as $table) {
                        $result = $wpdb->query("TRUNCATE TABLE `$table`");
                        if ($result === false) {
                            $success = false;
                            $errors[] = "Error en tabla $table: " . $wpdb->last_error;
                        }
                    }
                    
                    teva_debug_log('Full reset result: ' . ($success ? 'SUCCESS' : 'FAILED'));
                    teva_debug_log('Reset errors: ' . print_r($errors, true));
                    
                    if ($success) {
                        wp_send_json_success('Plugin reiniciado completamente');
                    } else {
                        wp_send_json_error('Error al reiniciar: ' . implode(', ', $errors));
                    }
                    break;
                    
                default:
                    teva_debug_log('Invalid reset type: ' . $reset_type);
                    wp_send_json_error('Tipo de reset no válido');
            }
        } catch (Exception $e) {
            teva_debug_log('Exception in reset_plugin: ' . $e->getMessage());
            wp_send_json_error('Error interno: ' . $e->getMessage());
        }
    }

    public function submit_vote() {
        teva_debug_log('submit_vote called with POST: ' . print_r($_POST, true));
        
        // Verificar nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'vote_nonce')) {
            teva_debug_log('Nonce verification failed');
            wp_send_json_error('Acceso denegado');
            return;
        }
        
        $survey_id = intval($_POST['survey_id']);
        $email = sanitize_email($_POST['email']);
        $option = intval($_POST['option']);
        
        teva_debug_log("Vote data - Survey: $survey_id, Email: $email, Option: $option");
        
        // Validaciones básicas
        if (!$survey_id || !$email || !$option) {
            teva_debug_log('Missing parameters');
            wp_send_json_error('Parámetros faltantes');
            return;
        }
        
        // Validar email
        if (!$this->is_valid_email($email)) {
            teva_debug_log('Invalid email: ' . $email);
            wp_send_json_error('Email no autorizado');
            return;
        }
        
        global $wpdb;
        $table_surveys = $wpdb->prefix . 'email_surveys';
        $table_votes = $wpdb->prefix . 'survey_votes';
        
        $survey = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_surveys WHERE id = %d", $survey_id));
        if (!$survey) {
            teva_debug_log('Survey not found: ' . $survey_id);
            wp_send_json_error('Pregunta no encontrada');
            return;
        }
        
        // ✅ VERIFICACIÓN MÁS INTELIGENTE: verificar if ya completó PERO con timeout
        $already_completed = $this->has_voted($survey_id, $email);
        
        if ($already_completed) {
            // ✅ VERIFICAR si el último voto correcto fue hace menos de 5 segundos
            $last_correct_vote = $wpdb->get_var($wpdb->prepare(
                "SELECT voted_at FROM $table_votes 
                 WHERE survey_id = %d AND email = %s AND is_correct = 1 
                 ORDER BY voted_at DESC LIMIT 1", 
                $survey_id, $email
            ));
            
            if ($last_correct_vote) {
                $time_diff = time() - strtotime($last_correct_vote);
                
                // Si el último voto correcto fue hace menos de 5 segundos, ignorar silenciosamente
                if ($time_diff < 5) {
                    teva_debug_log("Duplicate request detected - last correct vote was $time_diff seconds ago, ignoring");
                    wp_send_json_success(array(
                        'is_correct' => true,
                        'attempts' => $this->get_attempt_count($survey_id, $email),
                        'message' => 'Respuesta ya procesada'
                    ));
                    return;
                } else {
                    teva_debug_log('User already completed survey with correct answer');
                    wp_send_json_error('Ya has completado esta pregunta exitosamente');
                    return;
                }
            }
        }
        
        // ✅ CONTAR INTENTOS ANTES DE INSERTAR
        $attempts_before = $this->get_attempt_count($survey_id, $email);
        teva_debug_log("Attempts before inserting new vote: $attempts_before");
        
        $is_correct = ($option == $survey->correct_answer) ? 1 : 0;
        
        teva_debug_log("Correct answer: {$survey->correct_answer}, Selected: $option, Is correct: $is_correct");
        
        // ✅ INSERTAR EL VOTO
        $result = $wpdb->insert(
            $table_votes,
            array(
                'survey_id' => $survey_id,
                'email' => $email,
                'selected_option' => $option,
                'is_correct' => $is_correct
            ),
            array('%d', '%s', '%d', '%d')
        );
        
        if ($result === false) {
            teva_debug_log('Error al insertar voto: ' . $wpdb->last_error);
            wp_send_json_error('Error al registrar el voto');
            return;
        }
        
        // ✅ CALCULAR INTENTOS CORRECTAMENTE
        $attempts_after = $attempts_before + 1;
        
        teva_debug_log("Vote registered successfully - Attempts before: $attempts_before, After: $attempts_after, Is correct: $is_correct");
        
        wp_send_json_success(array(
            'is_correct' => $is_correct,
            'attempts' => $attempts_after,
            'message' => $is_correct ? 'Respuesta correcta' : 'Respuesta incorrecta'
        ));
    }

    public function force_survey_page() {
        $request_uri = $_SERVER['REQUEST_URI'];
        
        if (strpos($request_uri, '/encuesta/') !== false || strpos($request_uri, '/resultados/') !== false) {
            if (isset($_GET['survey']) || isset($_GET['s'])) {
                global $wp_query;
                if (isset($wp_query)) {
                    $wp_query->is_404 = false;
                    status_header(200);
                }
            }
        }
    }
}

// Instanciar el plugin
$email_survey_plugin = new EmailSurveyPlugin();
