[![Create Release and ZIP](https://github.com/Bannercity-cl/teva-preguntas/actions/workflows/release.yml/badge.svg)](https://github.com/Bannercity-cl/teva-preguntas/actions/workflows/release.yml)

# TEVA Preguntas - Sistema de Encuestas por Email

Un plugin de WordPress para crear y gestionar encuestas interactivas enviadas por email con validación de participantes mediante CSV y formularios de registro integrados.

## 🚀 Características

- **Gestión de Encuestas**: Crear preguntas con 3 opciones de respuesta
- **Validación por CSV**: Importar lista de participantes válidos desde archivo CSV
- **Sistema de Intentos**: Máximo 3 intentos por participante
- **Sesiones Seguras**: URLs con tokens para prevenir acceso no autorizado
- **Formulario de Registro**: Integración con Fidelizador para captura de datos médicos
- **Dashboard Administrativo**: Panel completo para gestión y estadísticas
- **Gráficos de Resultados**: Visualización de datos con gráficos de torta
- **Responsive Design**: Optimizado para dispositivos móviles

## 📋 Requisitos

- WordPress 5.0 o superior
- PHP 8.1 o superior
- MySQL 8.0 o superior
- Permisos de administrador de WordPress

## 🔧 Instalación

1. **Subir el Plugin**:
   ```bash
   # Copiar el archivo al directorio de plugins
   cp teva-preguntas.php /wp-content/plugins/
   ```

2. **Activar en WordPress**:
   - Ir a `Plugins > Plugins instalados`
   - Buscar "TEVA Preguntas"
   - Hacer clic en "Activar"

3. **Verificar Instalación**:
   - El plugin creará automáticamente las tablas necesarias
   - Aparecerá "TEVA Preguntas" en el menú de administración

## 📊 Estructura de Base de Datos

El plugin crea 4 tablas automáticamente:

```sql
-- Encuestas/Preguntas
wp_email_surveys
- id, question, option1, option2, option3, correct_answer, is_active, created_at

-- Emails válidos (participantes)
wp_survey_valid_emails  
- id, email, name, created_at

-- Votos/Respuestas
wp_survey_votes
- id, survey_id, email, selected_option, is_correct, voted_at

-- Sesiones de usuario
wp_survey_sessions
- token, survey_id, email, expires, created_at
```

## 🎯 Uso

### 1. Crear una Pregunta

1. Ir a `TEVA Preguntas` en el admin de WordPress
2. Completar el formulario "Crear Nueva Pregunta":
   - **Pregunta**: El texto de la pregunta
   - **Opciones 1-3**: Las tres opciones de respuesta
   - **Respuesta Correcta**: Seleccionar cuál es la correcta
3. Hacer clic en "Crear Pregunta"

### 2. Subir Lista de Participantes

**Formato CSV soportado**:
```csv
email,nombre
doctor1@hospital.com,Dr. Juan Pérez
doctora2@clinica.com,Dra. María González
```

**O formato con punto y coma**:
```csv
"email";"estado";"fecha";"otros_campos";"nombre"
"doctor1@hospital.com";"activo";"2024-01-01";"campo";"Dr. Juan Pérez"
```

1. Ir a "Subir Lista de Participantes (CSV)"
2. Seleccionar archivo CSV
3. Hacer clic en "Subir Lista CSV"

### 3. Generar URLs para Email

Después de crear una pregunta, el sistema genera URLs como:
```
https://tudominio.com/encuesta/?survey=1&email={EMAIL}&option=1
https://tudominio.com/encuesta/?survey=1&email={EMAIL}&option=2  
https://tudominio.com/encuesta/?survey=1&email={EMAIL}&option=3
```

**Reemplazar `{EMAIL}` con el email real del participante**.

### 4. Configurar Páginas de WordPress

Crear estas páginas con los shortcodes correspondientes:

**Página: `/encuesta/`**
```
[survey_form]
```

**Página: `/resultados/`**
```
[survey_results]
```

## 🔒 Seguridad

- **Validación de Email**: Solo participantes en el CSV pueden votar
- **Tokens de Sesión**: URLs seguras con expiración de 24 horas
- **Protección CSRF**: Nonces para todas las operaciones AJAX
- **Límite de Intentos**: Máximo 3 intentos por participante
- **Sanitización**: Todos los inputs están sanitizados

## 🛠️ Herramientas de Mantenimiento

En el panel de administración hay opciones para:

- **🗳️ Limpiar Solo Respuestas**: Elimina todos los votos
- **📧 Limpiar Solo Participantes**: Elimina la lista de emails
- **📋 Limpiar Solo Preguntas**: Elimina todas las preguntas
- **🚨 RESET COMPLETO**: Reinicia todo el plugin

## 📱 Shortcodes Disponibles

### `[survey_form]`
Muestra el formulario de encuesta. Maneja automáticamente:
- Validación de sesión
- Control de intentos
- Redirección a resultados

### `[survey_results]`
Muestra los resultados de la encuesta:
- Formulario médico (si respondió correctamente)
- Gráfico de estadísticas (si respondió incorrectamente)
- Opción de reintentar (si tiene intentos restantes)

## 🎨 Personalización CSS

El plugin incluye estilos completos, pero puedes personalizar:

```css
/* Personalizar colores del formulario */
.survey-option.selected {
    border-color: #tu-color !important;
    background: #tu-fondo !important;
}

/* Personalizar botón de envío */
.survey-submit-btn {
    background: linear-gradient(135deg, #tu-color1, #tu-color2) !important;
}
```

## 🔄 Flujo de Usuario

1. **Email Inicial**: Usuario recibe email con botones/enlaces
2. **Validación**: Sistema verifica email en lista de participantes
3. **Sesión Segura**: Se crea token de sesión y redirección limpia
4. **Formulario**: Usuario ve pregunta y selecciona respuesta
5. **Resultados**: 
   - ✅ **Correcto**: Formulario de registro médico
   - ❌ **Incorrecto**: Estadísticas + opción de reintentar

## 🐛 Troubleshooting

### Problema: "Email no autorizado"
- **Solución**: Verificar que el email esté en el archivo CSV subido

### Problema: "Sesión inválida o expirada"
- **Solución**: Usar el enlace original del email (las sesiones duran 24h)

### Problema: No se muestran las páginas
- **Solución**: Verificar que las páginas `/encuesta/` y `/resultados/` existan con los shortcodes correctos

### Problema: Error en la base de datos
- **Solución**: Usar la herramienta "RESET COMPLETO" en el admin

## 📝 Logs de Debug

Para activar logs detallados, agregar en `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Los logs se guardan en `/wp-content/debug.log`

## 🔧 Configuración Avanzada

### Cambiar límite de intentos
En `teva-preguntas.php`, línea ~1240:
```php
$max_attempts = 3; // Cambiar a tu valor deseado
```

### Cambiar duración de sesión
En `create_session_token()`, línea ~1970:
```php
'expires' => time() + (24 * 60 * 60) // 24 horas, cambiar según necesites
```

## 🚫 Desinstalación

**⚠️ ADVERTENCIA**: Esto eliminará TODOS los datos del plugin.

1. Desactivar el plugin en WordPress
2. Eliminar el plugin desde `Plugins > Plugins instalados`
3. El hook de desinstalación limpiará automáticamente:
   - Todas las tablas
   - Todas las opciones
   - Todos los transients
   
---

**⚡ Desarrollado con WordPress + PHP + MySQL + JavaScript**
