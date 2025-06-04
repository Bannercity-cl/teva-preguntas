<?php
/**
 * Plugin Name: TEVA Preguntas
 * Description: Sistema de encuestas por email con validación CSV
 * Version: 1.0
 * Author: Daniel Avila
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
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
        $wpdb->prefix . 'survey_sessions' // Nueva tabla de sesiones
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
    
    // Log de desinstalación para debug
    error_log('Email Survey Plugin: Desinstalación completada - Todas las tablas y datos eliminados');
}

class EmailSurveyPlugin {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('wp_ajax_save_survey', array($this, 'save_survey'));
        add_action('wp_ajax_upload_csv', array($this, 'upload_csv'));
        add_action('wp_ajax_clear_csv', array($this, 'clear_csv'));
        add_action('wp_ajax_reset_plugin', array($this, 'reset_plugin')); // Nuevo endpoint para reset
        add_action('wp_ajax_nopriv_submit_vote', array($this, 'submit_vote'));
        add_action('wp_ajax_submit_vote', array($this, 'submit_vote'));
        add_shortcode('survey_form', array($this, 'survey_form_shortcode'));
        add_shortcode('survey_results', array($this, 'survey_results_shortcode'));
        
        // Forzar shortcode en página específica (bypass del tema)
        add_action('template_redirect', array($this, 'force_survey_page'));
        
        // Hook de desactivación (opcional, para limpiar temporales)
        register_deactivation_hook(__FILE__, array($this, 'on_deactivation'));
    }
    
    public function on_deactivation() {
        // Limpiar transients al desactivar
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_email_survey_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_email_survey_%'");
        error_log('Email Survey Plugin: Plugin desactivado - Transients limpiados');
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
            error_log('DEBUG: Found unique_vote index, attempting to remove it');
            $result = $wpdb->query("ALTER TABLE $table_votes DROP INDEX unique_vote");
            if ($result === false) {
                error_log('DEBUG: Failed to drop unique_vote index: ' . $wpdb->last_error);
            } else {
                error_log('DEBUG: Successfully dropped unique_vote index');
            }
        } else {
            error_log('DEBUG: No unique_vote index found, table is clean');
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
                        <li>CSV simple: email,nombre</li>
                        <li>CSV con punto y coma: "email";"estado";"fecha";...;"nombre"</li>
                    </ul>
                    <p><strong>Nota:</strong> Se procesará la primera columna como email y la última como nombre (opcional)</p>
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
            // Función para reset de respuestas
            $('#reset-votes-btn').on('click', function() {
                if (!confirm('¿Estás seguro? Esto eliminará TODAS las respuestas pero mantendrá las preguntas y participantes.')) return;
                if (!confirm('Esta acción NO se puede deshacer. ¿Continuar?')) return;
                
                resetData('votes', 'Respuestas eliminadas exitosamente');
            });
            
            // Función para reset de participantes
            $('#reset-emails-btn').on('click', function() {
                if (!confirm('¿Estás seguro? Esto eliminará TODOS los participantes válidos.')) return;
                if (!confirm('Esta acción NO se puede deshacer. ¿Continuar?')) return;
                
                resetData('emails', 'Lista de participantes limpiada exitosamente');
            });
            
            // Función para reset de preguntas
            $('#reset-surveys-btn').on('click', function() {
                if (!confirm('¿Estás seguro? Esto eliminará TODAS las preguntas y sus respuestas asociadas.')) return;
                if (!confirm('Esta acción NO se puede deshacer. ¿Continuar?')) return;
                
                resetData('surveys', 'Preguntas eliminadas exitosamente');
            });
            
            // Función para reset completo
            $('#reset-all-btn').on('click', function() {
                if (!confirm('⚠️ PELIGRO: Esto eliminará TODO (preguntas, participantes, respuestas). ¿Estás seguro?')) return;
                if (!confirm('🚨 ÚLTIMA CONFIRMACIÓN: Esta acción borrará TODOS los datos del plugin. ¿Continuar?')) return;
                
                resetData('all', 'Plugin reiniciado completamente');
            });
            
            function resetData(type, successMessage) {
                var btn = $('#reset-' + (type === 'all' ? 'all' : type) + '-btn');
                var originalText = btn.text();
                btn.text('Procesando...').prop('disabled', true);
                
                $.post(ajaxurl, {
                    action: 'reset_plugin',
                    reset_type: type,
                    nonce: '<?php echo wp_create_nonce("reset_nonce"); ?>'
                }, function(response) {
                    if(response.success) {
                        alert(successMessage);
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                    btn.text(originalText).prop('disabled', false);
                }).fail(function() {
                    alert('Error de conexión');
                    btn.text(originalText).prop('disabled', false);
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
                echo '<strong>URLs para botones del email:</strong><br>';
                echo '1: ' . $base_url . '?survey=' . $survey->id . '&email={EMAIL}&option=1<br>';
                echo '2: ' . $base_url . '?survey=' . $survey->id . '&email={EMAIL}&option=2<br>';
                echo '3: ' . $base_url . '?survey=' . $survey->id . '&email={EMAIL}&option=3<br>';
                echo '<small>Reemplaza {EMAIL} con el email del participante</small>';
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
        
        while (($data = fgetcsv($handle, 1000, ';')) !== FALSE) {
            $line_number++;
            
            // Saltar la primera línea si son encabezados
            if ($line_number == 1 && strpos(strtolower($data[0]), 'mail') !== false) {
                continue;
            }
            
            // Limpiar y validar el email (primera columna)
            $email = trim($data[0], '"');
            
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result = $wpdb->insert(
                    $table_emails,
                    array(
                        'email' => sanitize_email($email),
                        'name' => null // Solo guardamos el email
                    )
                );
                
                if ($result) {
                    $count++;
                }
            }
        }
        
        fclose($handle);
        wp_send_json_success("$count participantes importados exitosamente de $line_number líneas procesadas");
    }
    
    public function survey_form_shortcode($atts) {
        error_log('DEBUG: survey_form_shortcode called');
        error_log('DEBUG: GET params: ' . print_r($_GET, true));
        
        // NUEVO FLUJO: Mantenemos URLs obvias para el inicio, luego redirigimos
        $survey_id = 0;
        $email = '';
        $preselected_option = 0;
        
        // 1. PRIMERA ENTRADA: URLs obvias desde email
        if (isset($_GET['survey']) && isset($_GET['email'])) {
            $survey_id = intval($_GET['survey']);
            $email = sanitize_email($_GET['email']);
            $preselected_option = isset($_GET['option']) ? intval($_GET['option']) : 0;
            
            error_log("DEBUG: Direct URL access - survey_id=$survey_id, email=$email, option=$preselected_option");
            
            // Validar email inmediatamente
            if (!$this->is_valid_email($email)) {
                return '<div style="padding: 20px; border: 2px solid orange;"><p><strong>ERROR:</strong> Email no autorizado para participar en esta encuesta.</p><p>Email verificado: ' . $email . '</p></div>';
            }
            
            // Crear sesión y redirigir a URL limpia
            $session_token = $this->create_session_token($survey_id, $email);
            
            // USAR JAVASCRIPT PARA SETEAR LA COOKIE EN LUGAR DE PHP
            $clean_url = home_url('/encuesta/?survey=' . $session_token);
            if ($preselected_option) {
                $clean_url .= '&option=' . $preselected_option;
            }
            
            // Usar JavaScript para redirección suave y setear cookie
            ob_start();
            ?>
            <div style="padding: 20px; text-align: center; border: 2px solid #007cba;">
                <h3>🔄 Iniciando encuesta...</h3>
                <p>Configurando sesión segura...</p>
                <div style="margin: 20px 0;">
                    <div style="width: 100%; background: #ddd; border-radius: 10px; overflow: hidden;">
                        <div id="progress-bar" style="width: 0%; height: 20px; background: linear-gradient(90deg, #007cba, #005a87); transition: width 2s;"></div>
                    </div>
                </div>
                <p><small>Si no se redirige automáticamente, <a href="<?php echo $clean_url; ?>">haz clic aquí</a></small></p>
            </div>
            
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
        
        // 2. ACCESO POR SESIÓN: URLs con token en parámetro 'survey'
        if (isset($_GET['survey']) && !isset($_GET['email'])) {
            // El parámetro 'survey' contiene el token, no el ID
            $session_token = sanitize_text_field($_GET['survey']);
            $session_data = $this->validate_session_token($session_token);
            
            if ($session_data) {
                $survey_id = $session_data['survey_id'];
                $email = $session_data['email'];
                $preselected_option = isset($_GET['option']) ? intval($_GET['option']) : 0;
                error_log("DEBUG: Session access via survey param - survey_id=$survey_id, email=$email, option=$preselected_option");
            }
        }
        
        // 3. FALLBACK: Intentar recuperar de cookie
        if (!$survey_id || !$email) {
            $session_data = $this->get_session_from_cookie();
            if ($session_data) {
                $survey_id = $session_data['survey_id'];
                $email = $session_data['email'];
                error_log("DEBUG: Cookie fallback - survey_id=$survey_id, email=$email");
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
        
        // Verificar si ya completó la encuesta (acertó)
        if ($this->has_voted($survey_id, $email)) {
            $results_url = home_url('/resultados/?survey=' . $this->create_session_token($survey_id, $email)); // ← CAMBIADO
            return '<div style="padding: 20px; border: 2px solid blue;">
                <h3>✅ Encuesta Completada</h3>
                <p>Ya has completado esta encuesta exitosamente.</p>
                <p><a href="' . $results_url . '" class="button" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Ver Resultados</a></p>
            </div>';
        }
        
        // Verificar intentos agotados
        $attempts = $this->get_attempt_count($survey_id, $email);
        $max_attempts = 3;
        
        if ($attempts >= $max_attempts) {
            $results_url = home_url('/resultados/?survey=' . $this->create_session_token($survey_id, $email)); // ← CAMBIADO
            return '<div style="padding: 20px; border: 2px solid red;">
                <h3>⏰ Intentos Agotados</h3>
                <p>Has agotado todos tus intentos para esta encuesta.</p>
                <p><a href="' . $results_url . '" class="button" style="background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">Ver Resultados Finales</a></p>
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
        
        // Obtener nonce para AJAX
        $ajax_nonce = wp_create_nonce('vote_nonce');
        
        ob_start();
        ?>
        <div class="survey-container">
            <div class="survey-header">
                <h2><?php echo esc_html($survey->question); ?></h2>
                
                <?php if ($attempts > 0): ?>
                    <div class="attempt-info">
                        <p><strong>💡 Intento <?php echo $attempts + 1; ?> de <?php echo $max_attempts; ?></strong></p>
                        <?php if ($attempts == 1): ?>
                            <p>Tu primera respuesta no fue correcta. ¡Inténtalo de nuevo!</p>
                        <?php elseif ($attempts == 2): ?>
                            <p><strong>¡Última oportunidad!</strong> Tu segunda respuesta tampoco fue correcta. Este es tu último intento.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <form id="vote-form" data-survey="<?php echo $survey_id; ?>" data-email="<?php echo esc_attr($email); ?>" data-nonce="<?php echo $ajax_nonce; ?>">
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
                
                <div class="submit-section">
                    <button type="submit" class="survey-submit-btn">
                        <?php if ($attempts == 2): ?>
                            🎯 ¡Último Intento!
                        <?php else: ?>
                            📝 Enviar Respuesta
                        <?php endif; ?>
                    </button>
                </div>
            </form>
            
            <!-- Info de sesión -->
            <div class="session-info">
                <div class="session-status">
                    <span class="status-indicator">🔒</span>
                    <span>Sesión Segura Activa</span>
                </div>
                <div class="progress-indicator">
                    <span>Progreso: <?php echo $attempts; ?>/<?php echo $max_attempts; ?> intentos</span>
                </div>
            </div>
        </div>
        
        <style>
        .survey-container {
            max-width: 700px;
            margin: 0 auto;
            padding: 30px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .survey-header h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 24px;
            line-height: 1.4;
        }
        
        .attempt-info {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 1px solid #f39c12;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 8px;
            animation: slideIn 0.5s ease-out;
        }
        
        .survey-options {
            margin: 30px 0;
        }
        
        .survey-option {
            display: flex;
            align-items: center;
            margin: 15px 0;
            padding: 20px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            background: #fff;
        }
        
        .survey-option:hover {
            border-color: #007cba;
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,124,186,0.1);
        }
        
        .survey-option.selected {
            border-color: #007cba;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            box-shadow: 0 4px 12px rgba(0,124,186,0.2);
        }
        
        .survey-option input {
            margin-right: 15px;
            transform: scale(1.2);
        }
        
        .option-text {
            flex: 1;
            font-size: 16px;
            color: #2c3e50;
            font-weight: 500;
        }
        
        .option-number {
            background: #6c757d;
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        
        .survey-option.selected .option-number {
            background: #007cba;
        }
        
        .submit-section {
            text-align: center;
            margin: 30px 0;
        }
        
        .survey-submit-btn {
            background: linear-gradient(135deg, #007cba 0%, #005a87 100%);
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 18px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,124,186,0.3);
        }
        
        .survey-submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,124,186,0.4);
        }
        
        .survey-submit-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .session-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            padding: 15px;
            background: #e8f5e8;
            border-radius: 8px;
            border-left: 4px solid #28a745;
            font-size: 14px;
        }
        
        .session-status {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #155724;
            font-weight: 500;
        }
        
        .progress-indicator {
            color: #666;
        }
        
        .status-indicator {
            animation: pulse 2s infinite;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .survey-container {
                padding: 20px;
                margin: 10px;
            }
            
            .survey-option {
                padding: 15px;
            }
            
            .session-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
        }
        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('vote-form');
            const options = document.querySelectorAll('.survey-option');
            
            options.forEach(option => {
                option.addEventListener('click', function() {
                    options.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    this.querySelector('input').checked = true;
                });
            });
            
            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const selectedOption = form.querySelector('input[name="option"]:checked');
                    if (!selectedOption) {
                        alert('Por favor selecciona una opción');
                        return;
                    }
                    
                    const submitBtn = form.querySelector('.survey-submit-btn');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '⏳ Enviando...';
                    
                    const formData = new FormData();
                    formData.append('action', 'submit_vote');
                    formData.append('survey_id', form.dataset.survey);
                    formData.append('email', form.dataset.email);
                    formData.append('option', selectedOption.value);
                    formData.append('nonce', form.dataset.nonce);
                    
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(response => response.text())
                    .then(text => {
                        try {
                            const data = JSON.parse(text);
                            
                            if (data.success) {
                                // Crear URL de resultados con sesión usando parámetro correcto
                                const session_token = '<?php echo $this->create_session_token($survey_id, $email); ?>';
                                const resultsUrl = '<?php echo home_url('/resultados/?survey='); ?>' + session_token; // ← CAMBIADO
                                
                                // Animación de éxito antes de redirigir
                                submitBtn.innerHTML = '✅ ¡Enviado!';
                                submitBtn.style.background = '#28a745';
                                
                                setTimeout(() => {
                                    window.location.href = resultsUrl;
                                }, 1000);
                            } else {
                                alert('Error: ' + (data.data || 'Error desconocido'));
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = originalText;
                            }
                        } catch (e) {
                            console.error('Parse error:', e);
                            alert('Error: Respuesta inválida del servidor');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        alert('Error de conexión: ' + error.message);
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    });
                });
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    public function survey_results_shortcode($atts) {
        // NUEVA FUNCIONALIDAD: Recuperar datos desde sesión en lugar de URL
        $session_data = $this->get_session_from_request();
        
        if (!$session_data) {
            return '<div style="padding: 20px; border: 2px solid red;"><p><strong>ERROR:</strong> Sesión inválida o expirada.</p><p>Por favor, utiliza el enlace original del email.</p></div>';
        }
        
        $survey_id = $session_data['survey_id'];
        $email = $session_data['email'];
        
        error_log("DEBUG: Results page - survey_id=$survey_id, email=$email");
        
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
        
        // Si respondió correctamente, mostrar formulario médico
        if ($is_correct) {
            return $this->display_medical_form($email);
        }
        
        // Si no es correcto, mostrar resultados normales con gráfico
        return $this->display_normal_results($survey, $last_vote, $is_correct, $has_completed, $attempt_count, $max_attempts, $survey_id, $email);
    }
    
    // Nueva función para mostrar el formulario médico
    private function display_medical_form($email) {
        ob_start();
        ?>
        <div class="medical-form-wrapper">
            <style>
                .medical-form-wrapper * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                .medical-form-wrapper body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background: linear-gradient(135deg, #FF7A00 0%, #FFB347 50%, #FFA500 100%);
                    min-height: 100vh;
                    padding: 20px;
                }

                .medical-form-wrapper .container {
                    max-width: 500px;
                    margin: 0 auto;
                    background: white;
                    border-radius: 20px;
                    box-shadow: 0 20px 40px rgba(255, 122, 0, 0.3);
                    overflow: hidden;
                    animation: slideUp 0.8s ease-out;
                }

                @keyframes slideUp {
                    from {
                        opacity: 0;
                        transform: translateY(30px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                .medical-form-wrapper .header {
                    background: linear-gradient(135deg, #FF7A00, #FFB347);
                    padding: 30px 20px;
                    text-align: center;
                    color: white;
                    position: relative;
                    overflow: hidden;
                }

                .medical-form-wrapper .header::before {
                    content: '';
                    position: absolute;
                    top: -50%;
                    left: -50%;
                    width: 200%;
                    height: 200%;
                    background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
                    animation: rotate 20s linear infinite;
                }

                @keyframes rotate {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }

                .medical-form-wrapper .header h1 {
                    font-size: 24px;
                    margin-bottom: 10px;
                    position: relative;
                    z-index: 1;
                }

                .medical-form-wrapper .header p {
                    font-size: 14px;
                    opacity: 0.9;
                    position: relative;
                    z-index: 1;
                }

                .medical-form-wrapper .form-container {
                    padding: 40px 30px;
                }

                .medical-form-wrapper .form-group {
                    margin-bottom: 25px;
                    position: relative;
                }

                .medical-form-wrapper .form-group label {
                    display: block;
                    margin-bottom: 8px;
                    color: #333;
                    font-weight: 600;
                    font-size: 14px;
                }

                .medical-form-wrapper .required::after {
                    content: " *";
                    color: #FF4444;
                    font-weight: bold;
                }

                .medical-form-wrapper .form-control {
                    width: 100%;
                    padding: 15px 20px;
                    border: 2px solid #E0E0E0;
                    border-radius: 12px;
                    font-size: 16px;
                    transition: all 0.3s ease;
                    background: #FAFAFA;
                }

                .medical-form-wrapper .form-control:focus {
                    outline: none;
                    border-color: #FF7A00;
                    background: white;
                    box-shadow: 0 0 0 3px rgba(255, 122, 0, 0.1);
                    transform: translateY(-2px);
                }

                .medical-form-wrapper .form-control:hover {
                    border-color: #FFB347;
                }

                .medical-form-wrapper select.form-control {
                    cursor: pointer;
                    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e");
                    background-position: right 12px center;
                    background-repeat: no-repeat;
                    background-size: 16px;
                    padding-right: 45px;
                    appearance: none;
                }

                .medical-form-wrapper .btn-send-form-fide {
                    width: 100%;
                    padding: 18px;
                    background: linear-gradient(135deg, #FF7A00, #FFB347);
                    color: white;
                    border: none;
                    border-radius: 12px;
                    font-size: 18px;
                    font-weight: 600;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    position: relative;
                    overflow: hidden;
                }

                .medical-form-wrapper .btn-send-form-fide::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: -100%;
                    width: 100%;
                    height: 100%;
                    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
                    transition: left 0.5s;
                }

                .medical-form-wrapper .btn-send-form-fide:hover::before {
                    left: 100%;
                }

                .medical-form-wrapper .btn-send-form-fide:hover {
                    transform: translateY(-3px);
                    box-shadow: 0 10px 25px rgba(255, 122, 0, 0.4);
                }

                .medical-form-wrapper .btn-send-form-fide:active {
                    transform: translateY(-1px);
                }

                .medical-form-wrapper .medical-icon {
                    display: inline-block;
                    width: 20px;
                    height: 20px;
                    background: #FFD700;
                    border-radius: 50%;
                    position: relative;
                    margin-right: 10px;
                }

                .medical-form-wrapper .medical-icon::before {
                    content: '+';
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    color: #FF7A00;
                    font-weight: bold;
                    font-size: 14px;
                }

                .medical-form-wrapper .form-footer {
                    text-align: center;
                    padding: 20px;
                    background: #F8F9FA;
                    color: #666;
                    font-size: 12px;
                }

                .medical-form-wrapper .help-block {
                    color: #FF4444;
                    font-size: 12px;
                    margin-top: 5px;
                    min-height: 16px;
                }

                .medical-form-wrapper #fide-result {
                    margin: 10px 0;
                    padding: 10px;
                    border-radius: 8px;
                    display: none;
                }

                .medical-form-wrapper #fide-result.success {
                    background: #d4edda;
                    color: #155724;
                    border: 1px solid #c3e6cb;
                    display: block;
                }

                .medical-form-wrapper #fide-result.error {
                    background: #f8d7da;
                    color: #721c24;
                    border: 1px solid #f5c6cb;
                    display: block;
                }

                .medical-form-wrapper .control-label {
                    display: block;
                    margin-bottom: 8px;
                    color: #333;
                    font-weight: 600;
                    font-size: 14px;
                }

                .medical-form-wrapper .control-label.required::after {
                    content: " *";
                    color: #FF4444;
                    font-weight: bold;
                }

                /* Estilos para el mensaje de éxito */
                .medical-form-wrapper .success-message {
                    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                    color: white;
                    padding: 30px;
                    text-align: center;
                    border-radius: 15px;
                    margin: 20px 0;
                    box-shadow: 0 10px 25px rgba(40, 167, 69, 0.3);
                    animation: successPulse 2s ease-in-out;
                }

                @keyframes successPulse {
                    0% { transform: scale(0.95); opacity: 0; }
                    50% { transform: scale(1.02); }
                    100% { transform: scale(1); opacity: 1; }
                }

                .medical-form-wrapper .success-message h3 {
                    font-size: 24px;
                    margin-bottom: 15px;
                    font-weight: 700;
                }

                .medical-form-wrapper .success-message p {
                    font-size: 16px;
                    line-height: 1.6;
                    margin-bottom: 10px;
                    opacity: 0.95;
                }

                @media (max-width: 480px) {
                    .medical-form-wrapper .container {
                        margin: 10px;
                        border-radius: 15px;
                    }
                    
                    .medical-form-wrapper .form-container {
                        padding: 30px 20px;
                    }
                    
                    .medical-form-wrapper .header {
                        padding: 25px 15px;
                    }
                }
            </style>

            <div class="container">
                <div class="header">
                    <h1><span class="medical-icon"></span>¡Felicitaciones! 🎉</h1>
                    <p>Respondiste correctamente. Ahora completa tu registro para recibir los beneficios</p>
                </div>
                
                <div class="form-container">
                    <div id="fide-content-code-embed">
                        <form id="fide-embed-form" 
                              action="https://publiccl1.fidelizador.com/labchile/form/4D985F23A03E1BA4BDDEA1821647C7905B731D3A6420/subscription/save" 
                              method="POST"
                              data-fetch-geographic-zones-url="https://publiccl1.fidelizador.com/labchile/api/geo/zone/_country_id_"
                              data-fetch-countries-url="https://publiccl1.fidelizador.com/labchile/api/geo/countries"
                              accept-charset="UTF-8">
                            
                            <div class="form-group">
                                <label class="control-label required" for="subscription_field_3">Nombre Completo</label>
                                <input type="text" 
                                       id="subscription_field_3" 
                                       name="subscription[field_3]" 
                                       required="required" 
                                       title="Nombre Completo" 
                                       placeholder="Ingrese su nombre completo" 
                                       autocomplete="off" 
                                       data-type="string" 
                                       rel="required-field" 
                                       maxlength="64" 
                                       class="form-control" />
                                <div class="help-block"></div>
                            </div>

                            <div class="form-group">
                                <label class="control-label required" for="subscription_email">E-mail</label>
                                <input type="email" 
                                       id="subscription_email" 
                                       name="subscription[email]" 
                                       required="required" 
                                       title="E-mail" 
                                       placeholder="ejemplo@correo.com" 
                                       autocomplete="off" 
                                       rel="required-field" 
                                       value="<?php echo esc_attr($email); ?>"
                                       class="form-control" />
                                <div class="help-block"></div>
                            </div>

                            <div class="form-group">
                                <label class="control-label required" for="subscription_field_13">Especialidad</label>
                                <input type="text" 
                                       id="subscription_field_13" 
                                       name="subscription[field_13]" 
                                       required="required" 
                                       title="Especialidad" 
                                       placeholder="Ej: Cardiología, Pediatría, etc." 
                                       autocomplete="off" 
                                       data-type="string" 
                                       rel="required-field" 
                                       maxlength="64" 
                                       class="form-control" />
                                <div class="help-block"></div>
                            </div>

                            <div class="form-group">
                                <label class="control-label required" for="subscription_state">Región</label>
                                <select id="subscription_state" 
                                        name="subscription[state]" 
                                        class="form-control"
                                        required="required" 
                                        data-selected-value="">
                                    <option value="">Seleccione su región</option>
                                </select>
                                <div class="help-block"></div>
                            </div>

                            <div class="form-group">
                                <label class="control-label required" for="subscription_site">Comuna</label>
                                <select id="subscription_site" 
                                        name="subscription[site]" 
                                        class="form-control"
                                        required="required" 
                                        data-selected-value="">
                                    <option value="">Primero seleccione una región</option>
                                </select>
                                <div class="help-block"></div>
                            </div>

                            <div class="form-group">
                                <label class="control-label" for="subscription_field_101">¿Qué te motivó a participar?</label>
                                <input type="text" 
                                       id="subscription_field_101" 
                                       name="subscription[field_101]" 
                                       title="Motivo participación" 
                                       placeholder="Comparta su motivación para participar..." 
                                       autocomplete="off" 
                                       data-type="string" 
                                       maxlength="64" 
                                       class="form-control" />
                                <div class="help-block"></div>
                            </div>

                            <input type="hidden" name="embed" value="1" />
                            
                            <div id="fide-result"></div>
                            
                            <input type="submit" value="Enviar Registro" class="btn-send-form-fide" />
                        </form>
                    </div>
                </div>

                <div class="form-footer">
                    Formulario seguro y confidencial • Sus datos están protegidos
                </div>
            </div>
        </div>

        <script>
        // Prevenir conflictos con jQuery de WordPress
        (function() {
            // Cargar scripts de Fidelizador de forma asíncrona
            function loadScript(src, callback) {
                var script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.defer = true;
                script.onload = callback;
                document.head.appendChild(script);
            }

            // Cargar CSS de jQuery UI si no está presente
            if (!document.querySelector('link[href*="jquery.ui.css"]')) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://publiccl1.fidelizador.com/css/jquery.ui.css';
                document.head.appendChild(link);
            }

            // Cargar el script base de Fidelizador
            loadScript('https://publiccl1.fidelizador.com/js/form/base.js?v=1', function() {
                console.log('Fidelizador script loaded');
            });

            document.addEventListener('DOMContentLoaded', function() {
                // Configurar charset del formulario
                var charset = document.charset && document.charset !== "windows-1252" ? document.charset : 'UTF-8';
                var form = document.getElementById("fide-embed-form");
                if (form) {
                    form.acceptCharset = charset;
                }

                // Efectos visuales mejorados
                document.querySelectorAll('.medical-form-wrapper .form-control').forEach(function(input) {
                    input.addEventListener('focus', function() {
                        this.parentElement.classList.add('focused');
                    });
                    
                    input.addEventListener('blur', function() {
                        this.parentElement.classList.remove('focused');
                    });
                });

                // Interceptar el envío exitoso del formulario para mostrar mensaje personalizado
                var form = document.getElementById('fide-embed-form');
                if (form) {
                    var originalSubmit = form.onsubmit;
                    form.addEventListener('submit', function(e) {
                        var submitBtn = form.querySelector('.btn-send-form-fide');
                        var originalText = submitBtn.value;
                        submitBtn.value = 'Enviando...';
                        submitBtn.disabled = true;

                        // Simular envío exitoso después de un momento
                        setTimeout(function() {
                            // Mostrar mensaje de éxito personalizado
                            showSuccessMessage();
                        }, 2000);
                    });
                }

                // Observar cambios en el resultado para detectar éxito
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'childList') {
                            var result = document.getElementById('fide-result');
                            if (result && result.innerHTML.trim() !== '') {
                                if (result.innerHTML.toLowerCase().includes('éxito') || 
                                    result.innerHTML.toLowerCase().includes('success') ||
                                    result.innerHTML.toLowerCase().includes('gracias')) {
                                    showSuccessMessage();
                                } else if (result.innerHTML.toLowerCase().includes('error') || 
                                         result.innerHTML.toLowerCase().includes('problema')) {
                                    result.className = 'error';
                                }
                            }
                        }
                    });
                });

                var resultElement = document.getElementById('fide-result');
                if (resultElement) {
                    observer.observe(resultElement, {
                        childList: true,
                        subtree: true
                    });
                }

                function showSuccessMessage() {
                    var container = document.querySelector('.medical-form-wrapper .form-container');
                    if (container) {
                        container.innerHTML = `
                            <div class="success-message">
                                <h3>¡Gracias por participar! 🎉</h3>
                                <p>Te enviaremos el kit educativo por correo electrónico una vez confirmado tu correo.</p>
                                <p>Los accesos online al Congreso SEFIT 2025 se asignarán entre los participantes seleccionados.</p>
                                <p style="margin-top: 20px; font-size: 14px; opacity: 0.8;">
                                    <strong>Revisa tu bandeja de entrada y spam para confirmar tu registro.</strong>
                                </p>
                            </div>
                        `;
                    }
                }
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    // Función para mostrar resultados normales (cuando no es correcto)
    private function display_normal_results($survey, $last_vote, $is_correct, $has_completed, $attempt_count, $max_attempts, $survey_id, $email) {
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
            $option1_percent = 33.3;
            $option2_percent = 33.3;
            $option3_percent = 33.4;
        }

        ob_start();
        ?>
        <div class="results-container">
            <h2>📊 Resultados </h2>
            <div class="question"><?php echo esc_html($survey->question); ?></div>
            
            <?php if ($last_vote): ?>
                <div class="result-message <?php echo $is_correct ? 'correct' : 'incorrect'; ?>">
                    <?php if ($is_correct): ?>
                        <h3>¡Excelente! 🎉</h3>
                        <p>Has seleccionado la respuesta correcta. ¡Buen trabajo!</p>
                        <?php if ($attempt_count == 1): ?>
                            <p><em>¡Lo lograste en el primer intento! 🏆</em></p>
                        <?php elseif ($attempt_count == 2): ?>
                            <p><em>¡Lo lograste en el segundo intento! 👏</em></p>
                        <?php elseif ($attempt_count == 3): ?>
                            <p><em>¡Lo lograste en el último intento! 🎯</em></p>
                        <?php endif; ?>
                    <?php else: ?>
                        <h3>¡Inténtalo de nuevo! 🤔</h3>
                        <p>Tu respuesta no fue la correcta esta vez.</p>
                        
                        <?php 
                        if (!$has_completed && $attempt_count < $max_attempts): 
                            $remaining_attempts = $max_attempts - $attempt_count;
                            $retry_url = home_url('/encuesta/?survey=' . $this->create_session_token($survey_id, $email));
                        ?>
                            <div class="retry-section">
                                <a href="<?php echo $retry_url; ?>" class="retry-btn">
                                    <?php if ($remaining_attempts == 1): ?>
                                        🎯 ¡Último Intento! (<?php echo $remaining_attempts; ?> intento restante)
                                    <?php else: ?>
                                        🔄 Volver a Intentar (<?php echo $remaining_attempts; ?> intento<?php echo $remaining_attempts == 1 ? '' : 's'; ?> restante<?php echo $remaining_attempts == 1 ? '' : 's'; ?>)
                                    <?php endif; ?>
                                </a>
                                
                                <?php if ($attempt_count == 1): ?>
                                    <p style="margin-top: 10px; font-size: 14px; color: #666;">
                                        💡 <strong>Consejo:</strong> Tienes <?php echo $remaining_attempts; ?> intentos más. ¡Piénsalo bien!
                                    </p>
                                <?php elseif ($attempt_count == 2): ?>
                                    <p style="margin-top: 10px; font-size: 14px; color: #d63384;">
                                        ⚠️ <strong>¡Atención!</strong> Este será tu último intento. ¡Elige cuidadosamente!
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($attempt_count >= $max_attempts): ?>
                            <div class="max-attempts-message">
                                <p><strong>⏰ Has agotado todos tus intentos</strong></p>
                                <p>Máximo de intentos alcanzado (<?php echo $max_attempts; ?>)</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- Info de sesión segura -->
            <div style="background: #e8f5e8; padding: 10px; margin: 10px 0; font-size: 12px; border-radius: 5px; border-left: 4px solid #28a745;">
                <strong>✅ Sesión Autenticada</strong><br>
                Participante verificado<br>
                Intentos realizados: <?php echo $attempt_count; ?>/<?php echo $max_attempts; ?><br>
                Estado: <?php echo $has_completed ? 'Completado ✅' : 'En progreso ⏳'; ?><br>
            </div>
            
            <!-- Gráfico de Torta -->
            <div class="pie-chart-container">
                <h3>📈 Distribución de Respuestas</h3>
                <div class="pie-chart-wrapper">
                    <canvas id="pieChart" width="300" height="300"></canvas>
                    <div class="chart-legend">
                        <div class="legend-item">
                            <span class="legend-color" style="background-color: #3498db;"></span>
                            <span class="legend-text"><?php echo esc_html($survey->option1); ?></span>
                            <span class="legend-percent"><?php echo $option1_percent; ?>%</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color" style="background-color: #e74c3c;"></span>
                            <span class="legend-text"><?php echo esc_html($survey->option2); ?></span>
                            <span class="legend-percent"><?php echo $option2_percent; ?>%</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color" style="background-color: #f39c12;"></span>
                            <span class="legend-text"><?php echo esc_html($survey->option3); ?></span>
                            <span class="legend-percent"><?php echo $option3_percent; ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .results-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 30px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .results-container h2 {
            color: #2c3e50;
            text-align: center;
            margin-bottom: 20px;
            font-size: 28px;
        }
        
        .question {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            font-size: 18px;
            color: #495057;
            border-left: 5px solid #007cba;
        }
        
        .result-message {
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .result-message.correct {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border: 2px solid #28a745;
            color: #155724;
        }
        
        .result-message.incorrect {
            background: linear-gradient(135deg, #f8d7da 0%, #f1b0b7 100%);
            border: 2px solid #dc3545;
            color: #721c24;
        }
        
        .retry-section {
            margin-top: 20px;
        }
        
        .retry-btn {
            display: inline-block;
            background: linear-gradient(135deg, #007cba 0%, #005a87 100%);
            color: white;
            padding: 12px 25px;
            text-decoration: none;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,124,186,0.3);
        }
        
        .retry-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,124,186,0.4);
            color: white;
            text-decoration: none;
        }
        
        .max-attempts-message {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .pie-chart-container {
            margin-top: 40px;
            text-align: center;
        }
        
        .pie-chart-container h3 {
            color: #2c3e50;
            margin-bottom: 25px;
            font-size: 22px;
        }
        
        .pie-chart-wrapper {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 40px;
            flex-wrap: wrap;
        }
        
        #pieChart {
            border-radius: 50%;
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        
        .chart-legend {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            min-width: 250px;
            transition: transform 0.2s ease;
        }
        
        .legend-item:hover {
            transform: translateX(5px);
            background: #e9ecef;
        }
        
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .legend-text {
            flex: 1;
            text-align: left;
            font-weight: 500;
            color: #495057;
        }
        
        .legend-percent {
            font-weight: bold;
            color: #2c3e50;
            font-size: 16px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .results-container {
                padding: 20px;
                margin: 10px;
            }
            
            .pie-chart-wrapper {
                flex-direction: column;
                gap: 20px;
            }
            
            
           
            
            #pieChart {
                width: 250px !important;
                height: 250px !important;
            }
            
            .legend-item {
                min-width: auto;
                width: 100%;
            }
        }
        </style>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const canvas = document.getElementById('pieChart');
            if (!canvas) return;
            
            const ctx = canvas.getContext('2d');
            const centerX = canvas.width / 2;
            const centerY = canvas.height / 2;
            const radius = Math.min(centerX, centerY) - 20;
            
            // Datos del gráfico
            const data = [
                { label: '<?php echo esc_js($survey->option1); ?>', value: <?php echo $option1_percent; ?>, color: '#3498db' },
                { label: '<?php echo esc_js($survey->option2); ?>', value: <?php echo $option2_percent; ?>, color: '#e74c3c' },
                { label: '<?php echo esc_js($survey->option3); ?>', value: <?php echo $option3_percent; ?>, color: '#f39c12' }
            ];
            
            // Limpiar canvas
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            
            let currentAngle = -Math.PI / 2; // Comenzar desde arriba
            
            // Dibujar segmentos
            data.forEach(segment => {
                const sliceAngle = (segment.value / 100) * 2 * Math.PI;
                
                // Dibujar segmento
                ctx.beginPath();
                ctx.moveTo(centerX, centerY);
                ctx.arc(centerX, centerY, radius, currentAngle, currentAngle + sliceAngle);
                ctx.closePath();
                ctx.fillStyle = segment.color;
                ctx.fill();
                
                // Borde del segmento
                ctx.strokeStyle = '#fff';
                ctx.lineWidth = 3;
                ctx.stroke();
                
                // Texto del porcentaje
                if (segment.value > 5) { // Solo mostrar texto si el segmento es grande
                    const textAngle = currentAngle + sliceAngle / 2;
                    const textX = centerX + Math.cos(textAngle) * (radius * 0.7);
                    const textY = centerY + Math.sin(textAngle) * (radius * 0.7);
                    
                    ctx.fillStyle = '#fff';
                    ctx.font = 'bold 14px Arial';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(segment.value + '%', textX, textY);
                }
                
                currentAngle += sliceAngle;
            });
            
                       
            // Agregar sombra al círculo
            ctx.shadowColor = 'rgba(0,0,0,0.1)';
            ctx.shadowBlur = 10;
            ctx.shadowOffsetX = 0;
            ctx.shadowOffsetY = 5;
        });
        </script>
        <?php
        return ob_get_clean();
    }

    // NUEVAS FUNCIONES PARA MANEJO DE SESIONES SEGURAS
    private function create_session_token($survey_id, $email) {
        $data = array(
            'survey_id' => $survey_id,
            'email' => $email,
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
        
        return $this->validate_session_token($_COOKIE['survey_session']);
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
        
        return $this->validate_session_token($token);
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
                'email' => $session->email
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
        if (!wp_verify_nonce($_POST['nonce'], 'reset_nonce')) {
            wp_die('Acceso denegado');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        $reset_type = sanitize_text_field($_POST['reset_type']);
        
        global $wpdb;
        
        switch ($reset_type) {
            case 'votes':
                $table_votes = $wpdb->prefix . 'survey_votes';
                $result = $wpdb->query("TRUNCATE TABLE $table_votes");
                if ($result !== false) {
                    wp_send_json_success('Respuestas eliminadas exitosamente');
                } else {
                    wp_send_json_error('Error al eliminar respuestas');
                }
                break;
                
            case 'emails':
                $table_emails = $wpdb->prefix . 'survey_valid_emails';
                $result = $wpdb->query("TRUNCATE TABLE $table_emails");
                if ($result !== false) {
                    wp_send_json_success('Participantes eliminados exitosamente');
                } else {
                    wp_send_json_error('Error al eliminar participantes');
                }
                break;
                
            case 'surveys':
                $table_surveys = $wpdb->prefix . 'email_surveys';
                $table_votes = $wpdb->prefix . 'survey_votes';
                $wpdb->query("TRUNCATE TABLE $table_votes");
                $result = $wpdb->query("TRUNCATE TABLE $table_surveys");
                if ($result !== false) {
                    wp_send_json_success('Preguntas eliminadas exitosamente');
                } else {
                    wp_send_json_error('Error al eliminar preguntas');
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
                foreach ($tables as $table) {
                    $result = $wpdb->query("TRUNCATE TABLE $table");
                    if ($result === false) {
                        $success = false;
                        break;
                    }
                }
                
                if ($success) {
                    wp_send_json_success('Plugin reiniciado completamente');
                } else {
                    wp_send_json_error('Error al reiniciar el plugin');
                }
                break;
                
            default:
                wp_send_json_error('Tipo de reset no válido');
        }
    }
    
    public function submit_vote() {
        error_log('DEBUG: submit_vote called with POST: ' . print_r($_POST, true));
        
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'vote_nonce')) {
            error_log('DEBUG: Nonce verification failed');
            wp_send_json_error('Acceso denegado - nonce inválido');
        }
        
        $survey_id = intval($_POST['survey_id']);
        $email = sanitize_email($_POST['email']);
        $option = intval($_POST['option']);
        
        error_log("DEBUG: Processed values - survey_id=$survey_id, email=$email, option=$option");
        
        // Validaciones básicas
        if (!$survey_id || !$email || !$option) {
            error_log('DEBUG: Missing required parameters');
            wp_send_json_error('Parámetros faltantes');
        }
        
        // Validar email
        if (!$this->is_valid_email($email)) {
            error_log('DEBUG: Email not valid');
            wp_send_json_error('Email no autorizado');
        }
        
        // Verificar si ya acertó (completó la pregunta)
        if ($this->has_voted($survey_id, $email)) {
            error_log('DEBUG: User already completed survey');
            wp_send_json_error('Ya has completado esta pregunta exitosamente');
        }
        
        // Verificar intentos restantes
        $attempts = $this->get_attempt_count($survey_id, $email);
        $max_attempts = 3;
        
        error_log("DEBUG: Current attempts: $attempts, max: $max_attempts");
        
        if ($attempts >= $max_attempts) {
            error_log('DEBUG: Max attempts reached');
            wp_send_json_error('Has agotado todos tus intentos');
        }
        
        global $wpdb;
        $table_surveys = $wpdb->prefix . 'email_surveys';
        $table_votes = $wpdb->prefix . 'survey_votes';
        
        $survey = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_surveys WHERE id = %d", $survey_id));
        if (!$survey) {
            error_log('DEBUG: Survey not found');
            wp_send_json_error('Pregunta no encontrada');
        }
        
        $is_correct = ($option == $survey->correct_answer) ? 1 : 0;
        error_log("DEBUG: Is correct: $is_correct (selected: $option, correct: {$survey->correct_answer})");
        
        // SIEMPRE registrar el voto (correcto o incorrecto)
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
            error_log('DEBUG: Database insert failed: ' . $wpdb->last_error);
            wp_send_json_error('Error al registrar el voto: ' . $wpdb->last_error);
        }
        
        error_log('DEBUG: Vote registered successfully');
        wp_send_json_success(array(
            'is_correct' => $is_correct,
            'attempts' => $attempts + 1,
            'message' => $is_correct ? 'Respuesta correcta' : 'Respuesta incorrecta'
        ));
    }

    // Agregar esta función que está faltando (después de on_deactivation())
    public function force_survey_page() {
        // Verificar si estamos en la página de encuesta o resultados
        $request_uri = $_SERVER['REQUEST_URI'];
        
        // Debug
        error_log('DEBUG: force_survey_page called with URI: ' . $request_uri);
        
        // Si estamos en /encuesta/ o /resultados/, forzar que se muestre el contenido
        if (strpos($request_uri, '/encuesta/') !== false || strpos($request_uri, '/resultados/') !== false) {
            // Verificar si hay parámetros de encuesta
            if (isset($_GET['survey']) || isset($_GET['s'])) {
                error_log('DEBUG: Survey page detected, forcing content display');
                
                // Opcional: Forzar que WordPress no muestre 404
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
