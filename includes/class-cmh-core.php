<?php
/**
 * CMH_Core — activación, esquema de BD y migraciones de versión.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class CMH_Core {

    /** Mapa de nombres de tablas. */
    public static function tables() {
        global $wpdb;
        $p = $wpdb->prefix . 'cmh_';
        return [
            'companies'     => $p . 'companies',
            'cities'        => $p . 'cities',
            'branches'      => $p . 'branches',
            'machines'      => $p . 'machines',
            'interventions' => $p . 'interventions',
            'files'         => $p . 'files',
            'logs'          => $p . 'logs',
            'assignments'   => $p . 'assignments',
            'tasks'         => $p . 'tasks',
            'clients'       => $p . 'client_companies',
            'client_cities' => $p . 'client_cities',
            'task_time'     => $p . 'task_time',
        ];
    }

    /** Crea/actualiza todas las tablas. Seguro de ejecutar múltiples veces. */
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $t = self::tables();

        // v2.2 — Empresa: además del nombre y el código, los datos de contacto,
        // ubicación y facturación que el técnico necesita al llegar y que ahora
        // pueden viajar prellenados al formulario.
        dbDelta( "CREATE TABLE {$t['companies']} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name           VARCHAR(190)    NOT NULL,
            code           VARCHAR(20)     NOT NULL,
            contact_name   VARCHAR(190)    NULL,
            contact_role   VARCHAR(120)    NULL,
            contact_phone  VARCHAR(60)     NULL,
            contact_mobile VARCHAR(60)     NULL,
            contact_email  VARCHAR(190)    NULL,
            contact2_name  VARCHAR(190)    NULL,
            contact2_phone VARCHAR(60)     NULL,
            contact2_email VARCHAR(190)    NULL,
            address        VARCHAR(190)    NULL,
            area           VARCHAR(120)    NULL,
            access_notes   TEXT            NULL,
            tax_id         VARCHAR(40)     NULL,
            legal_name     VARCHAR(190)    NULL,
            billing_email  VARCHAR(190)    NULL,
            payment_terms  VARCHAR(190)    NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $c;" );

        // v2.2 — Sucursal: los mismos contactos y ubicación que la empresa, sin
        // el bloque de facturación (eso se factura a la empresa, no a la sede).
        // Lo que quede vacío aquí hereda de la empresa al resolver el prellenado.
        dbDelta( "CREATE TABLE {$t['cities']} (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id     BIGINT UNSIGNED NOT NULL,
            name           VARCHAR(190)    NOT NULL,
            code           VARCHAR(20)     NOT NULL,
            contact_name   VARCHAR(190)    NULL,
            contact_role   VARCHAR(120)    NULL,
            contact_phone  VARCHAR(60)     NULL,
            contact_mobile VARCHAR(60)     NULL,
            contact_email  VARCHAR(190)    NULL,
            contact2_name  VARCHAR(190)    NULL,
            contact2_phone VARCHAR(60)     NULL,
            contact2_email VARCHAR(190)    NULL,
            address        VARCHAR(190)    NULL,
            area           VARCHAR(120)    NULL,
            access_notes   TEXT            NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY company_id (company_id)
        ) $c;" );

        dbDelta( "CREATE TABLE {$t['branches']} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            city_id    BIGINT UNSIGNED NOT NULL,
            name       VARCHAR(190)    NOT NULL,
            code       VARCHAR(20)     NOT NULL,
            address    TEXT            NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY city_id (city_id)
        ) $c;" );

        // branch_id es nullable — la sucursal es opcional por máquina.
        dbDelta( "CREATE TABLE {$t['machines']} (
            id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id              BIGINT UNSIGNED NOT NULL,
            city_id                 BIGINT UNSIGNED NOT NULL,
            branch_id               BIGINT UNSIGNED NULL,
            machine_code            VARCHAR(80)     NOT NULL,
            brand                   VARCHAR(120)    NOT NULL,
            brand_code              VARCHAR(20)     NOT NULL,
            model                   VARCHAR(120)    NULL,
            serial                  VARCHAR(120)    NULL,
            contact                 VARCHAR(190)    NULL,
            current_hourmeter       DECIMAL(12,2)   DEFAULT 0,
            scheduled_hours_monthly DECIMAL(10,2)   NOT NULL DEFAULT 480,
            status                  VARCHAR(40)     NOT NULL DEFAULT 'activa',
            notes                   TEXT            NULL,
            next_maintenance_date   DATE            NULL,
            maintenance_interval_days INT UNSIGNED  NULL,
            created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at              DATETIME        NULL,
            PRIMARY KEY (id),
            UNIQUE KEY machine_code (machine_code),
            KEY company_id (company_id),
            KEY city_id (city_id),
            KEY branch_id (branch_id),
            KEY serial (serial)
        ) $c;" );

        dbDelta( "CREATE TABLE {$t['interventions']} (
            id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            machine_id           BIGINT UNSIGNED NOT NULL,
            forminator_form_id   BIGINT UNSIGNED NULL,
            e2pdf_entry_id       VARCHAR(120)    NULL,
            intervention_date    DATE            NOT NULL,
            form_type            VARCHAR(80)     NOT NULL,
            maintenance_type     VARCHAR(80)     NULL,
            technician           VARCHAR(190)    NULL,
            hourmeter            DECIMAL(12,2)   DEFAULT 0,
            worked_hours         DECIMAL(10,2)   DEFAULT 0,
            downtime_hours       DECIMAL(10,2)   DEFAULT 0,
            cost                 DECIMAL(14,2)   DEFAULT 0,
            payment_status       VARCHAR(64)     NOT NULL DEFAULT 'pendiente',
            paid_amount          DECIMAL(14,2)   NOT NULL DEFAULT 0,
            affects_availability TINYINT(1)      NOT NULL DEFAULT 0,
            failure_system       VARCHAR(190)    NULL,
            mtto_level           VARCHAR(120)    NULL,
            parts                TEXT            NULL,
            services             TEXT            NULL,
            observations         TEXT            NULL,
            created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY machine_id (machine_id),
            KEY intervention_date (intervention_date),
            KEY affects_availability (affects_availability)
        ) $c;" );

        dbDelta( "CREATE TABLE {$t['files']} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            machine_id      BIGINT UNSIGNED NOT NULL,
            intervention_id BIGINT UNSIGNED NULL,
            file_url        TEXT            NOT NULL,
            file_path       TEXT            NULL,
            file_name       VARCHAR(255)    NOT NULL,
            file_type       VARCHAR(80)     NULL,
            uploaded_by     BIGINT UNSIGNED NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY machine_id (machine_id),
            KEY intervention_id (intervention_id)
        ) $c;" );

        dbDelta( "CREATE TABLE {$t['logs']} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level           VARCHAR(30)     NOT NULL DEFAULT 'info',
            form_id         BIGINT UNSIGNED NULL,
            machine_code    VARCHAR(120)    NULL,
            intervention_id BIGINT UNSIGNED NULL,
            message         TEXT            NOT NULL,
            payload         LONGTEXT        NULL,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY machine_code (machine_code),
            KEY intervention_id (intervention_id)
        ) $c;" );

        // v0.9 — Asignaciones técnico ↔ máquina.
        dbDelta( "CREATE TABLE {$t['assignments']} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            machine_id BIGINT UNSIGNED NOT NULL,
            user_id    BIGINT UNSIGNED NOT NULL,
            is_primary TINYINT(1)       NOT NULL DEFAULT 0,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY machine_user (machine_id, user_id),
            KEY machine_id (machine_id),
            KEY user_id (user_id)
        ) $c;" );

        // v0.9 — Tareas de mantenimiento asignadas a técnicos.
        dbDelta( "CREATE TABLE {$t['tasks']} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            machine_id  BIGINT UNSIGNED NOT NULL,
            assigned_to BIGINT UNSIGNED NULL,
            title       VARCHAR(190)    NOT NULL,
            notes       TEXT            NULL,
            due_date    DATE            NULL,
            status      VARCHAR(40)     NOT NULL DEFAULT 'pendiente',
            source      VARCHAR(20)     NOT NULL DEFAULT 'manual',
            form_id     BIGINT UNSIGNED NULL,
            created_by  BIGINT UNSIGNED NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME        NULL,
            PRIMARY KEY (id),
            KEY machine_id (machine_id),
            KEY assigned_to (assigned_to),
            KEY status (status)
        ) $c;" );

        // v2.1 — Tramos de trabajo del técnico sobre una tarea. El reloj arranca
        // al pasar a «En progreso» y se cierra al completar o pausar; una tarea
        // puede tener varios tramos. `ended_at` NULL = tramo abierto (corriendo).
        dbDelta( "CREATE TABLE {$t['task_time']} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id    BIGINT UNSIGNED NOT NULL,
            machine_id BIGINT UNSIGNED NOT NULL,
            user_id    BIGINT UNSIGNED NOT NULL,
            started_at DATETIME        NOT NULL,
            ended_at   DATETIME        NULL,
            seconds    INT UNSIGNED    NULL,
            source     VARCHAR(20)     NOT NULL DEFAULT 'auto',
            note       VARCHAR(190)    NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY task_id (task_id),
            KEY machine_id (machine_id),
            KEY user_id (user_id),
            KEY started_at (started_at),
            KEY ended_at (ended_at)
        ) $c;" );

        // v0.10 — Acceso de clientes por empresa.
        dbDelta( "CREATE TABLE {$t['clients']} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_company (user_id, company_id),
            KEY user_id (user_id),
            KEY company_id (company_id)
        ) $c;" );

        // v2.0 — Acceso de clientes acotado a ciudades/sucursales concretas.
        // Convive con client_companies: el acceso efectivo es la UNIÓN de ambas
        // (empresa completa por un lado, sucursales sueltas por el otro), de modo
        // que se puede dar acceso solo a una sucursal sin abrir toda la empresa.
        dbDelta( "CREATE TABLE {$t['client_cities']} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id    BIGINT UNSIGNED NOT NULL,
            city_id    BIGINT UNSIGNED NOT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_city (user_id, city_id),
            KEY user_id (user_id),
            KEY city_id (city_id)
        ) $c;" );

        // Migrar branch_id a nullable en instalaciones existentes.
        self::run_migrations( $t );

        // v0.9 — Rol de técnico y capacidades.
        self::setup_roles();

        // v2.0 — Siembra la configuración de formatos desde el mapeo histórico.
        if ( class_exists( 'CMH_Forms' ) ) CMH_Forms::maybe_seed();

        // v0.11 — Job diario de alertas de mantenimiento.
        if ( class_exists( 'CMH_Schedule' ) ) CMH_Schedule::schedule_cron();

        update_option( 'cmh_version', CMH_VERSION );
    }

    /**
     * v0.9 — Crea el rol `cmh_technician` y reparte la capacidad `cmh_tech`.
     *
     * - `cmh_technician`: puede entrar a wp-admin (read) y ver el panel del técnico (cmh_tech).
     *   NO recibe `edit_others_posts`, por lo que no ve el menú de administración completo.
     * - `administrator`: recibe `cmh_tech` para poder previsualizar el panel del técnico.
     *
     * Idempotente: seguro de ejecutar en cada upgrade.
     */
    public static function setup_roles() {
        if ( ! get_role( 'cmh_technician' ) ) {
            add_role( 'cmh_technician', 'Técnico (CM)', [
                'read'     => true,
                'cmh_tech' => true,
            ] );
        } else {
            $role = get_role( 'cmh_technician' );
            $role->add_cap( 'read' );
            $role->add_cap( 'cmh_tech' );
        }

        // v0.10 — Rol de cliente: acceso de solo lectura al portal (cmh_client).
        if ( ! get_role( 'cmh_client' ) ) {
            add_role( 'cmh_client', 'Cliente (CM)', [
                'read'       => true,
                'cmh_client' => true,
            ] );
        } else {
            $role = get_role( 'cmh_client' );
            $role->add_cap( 'read' );
            $role->add_cap( 'cmh_client' );
        }

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'cmh_tech' );
            $admin->add_cap( 'cmh_client' );
        }
    }

    /**
     * v1.0.1 — Devuelve el acceso a wp-admin a los roles del plugin.
     *
     * WooCommerce expulsa a `wp-admin` a todo usuario sin `edit_posts` y lo manda
     * a `my-account`. Nuestros roles `cmh_technician` y `cmh_client` tienen
     * permisos mínimos a propósito (`read` + su capacidad), justo el perfil que
     * Woo bloquea — así que no podían llegar a «Mis Máquinas» ni «Mis Equipos».
     *
     * Solo levanta el bloqueo de Woo: no otorga ninguna capacidad extra, y los
     * paneles siguen protegidos por sus propias comprobaciones de acceso.
     */
    public static function init() {
        add_filter( 'woocommerce_prevent_admin_access', [ __CLASS__, 'allow_admin_access' ] );
        add_filter( 'woocommerce_disable_admin_bar',    [ __CLASS__, 'allow_admin_access' ] );

        // v2.9 — Roles adicionales en el perfil del usuario.
        add_action( 'show_user_profile', [ __CLASS__, 'extra_roles_field' ] );
        add_action( 'edit_user_profile', [ __CLASS__, 'extra_roles_field' ] );
        add_action( 'profile_update',    [ __CLASS__, 'save_extra_roles' ], 20 );
    }

    /** Devuelve false (no bloquear) si el usuario actual es técnico o cliente del plugin. */
    public static function allow_admin_access( $prevent ) {
        return self::is_cmh_panel_user() ? false : $prevent;
    }

    /**
     * v2.9 — ¿El usuario TIENE este rol? Mira los roles, no las capacidades.
     *
     * Hace falta porque el administrador recibe `cmh_tech` y `cmh_client` para
     * poder previsualizar los paneles: preguntar por la capacidad no distingue
     * a un administrador cualquiera de uno que además trabaja como técnico.
     */
    public static function has_role( $role, $user_id = 0 ) {
        $user = $user_id ? get_userdata( (int) $user_id ) : wp_get_current_user();
        return $user && $user->exists() && in_array( $role, (array) $user->roles, true );
    }

    /**
     * v2.9 — ¿Este usuario trabaja como técnico? Sí si tiene el rol, o si tiene
     * la capacidad sin ser administrador (un rol propio al que se le dio).
     */
    public static function is_tech_user( $user_id = 0 ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) return false;
        if ( self::has_role( 'cmh_technician', $user_id ) ) return true;
        return user_can( $user_id, 'cmh_tech' ) && ! user_can( $user_id, 'edit_others_posts' );
    }

    /** v2.9 — Lo mismo para el portal del cliente. */
    public static function is_client_user( $user_id = 0 ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) return false;
        if ( self::has_role( 'cmh_client', $user_id ) ) return true;
        return user_can( $user_id, 'cmh_client' ) && ! user_can( $user_id, 'edit_others_posts' );
    }

    // -------------------------------------------------------------------------
    // v2.9 — Roles adicionales desde el perfil del usuario
    // -------------------------------------------------------------------------

    /**
     * WordPress solo deja elegir UN rol en la pantalla de usuario. Para que un
     * administrador pueda ser también técnico o cliente (o las tres cosas), el
     * perfil gana dos casillas que suman el rol del plugin sin tocar el principal.
     */
    public static function extra_roles_field( $user ) {
        if ( ! current_user_can( 'promote_users' ) ) return;
        $roles = [ 'cmh_technician' => 'Técnico (CM) — ve «Mis Máquinas» y sus tareas',
                   'cmh_client'     => 'Cliente (CM) — ve «Mis Equipos» de las empresas que se le asignen' ];

        echo '<h2>Historial de Máquinas — roles adicionales</h2>'
            . '<table class="form-table" role="presentation"><tr><th>Además de su rol, es</th><td>'
            . wp_nonce_field( 'cmh_extra_roles', 'cmh_extra_roles_nonce', true, false )
            . '<input type="hidden" name="cmh_extra_roles_sent" value="1">';
        foreach ( $roles as $role => $label ) {
            if ( ! get_role( $role ) ) continue;
            echo '<label style="display:block;margin-bottom:6px"><input type="checkbox" name="cmh_extra_roles[]" value="' . esc_attr( $role ) . '" '
                . checked( in_array( $role, (array) $user->roles, true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
        }
        echo '<p class="description">Un usuario puede tener varios: por ejemplo, un administrador que también hace tareas de técnico ve los dos menús.</p>'
            . '</td></tr></table>';
    }

    /**
     * Se aplica en `profile_update`, DESPUÉS de que WordPress guarde el rol
     * principal: `set_role()` borra los roles extra, así que hacerlo antes no
     * serviría de nada.
     */
    public static function save_extra_roles( $user_id ) {
        if ( empty( $_POST['cmh_extra_roles_sent'] ) ) return;
        if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user_id ) ) return;
        if ( ! isset( $_POST['cmh_extra_roles_nonce'] )
             || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cmh_extra_roles_nonce'] ) ), 'cmh_extra_roles' ) ) return;

        $user = get_userdata( (int) $user_id );
        if ( ! $user ) return;

        $wanted = array_map( 'sanitize_key', (array) ( $_POST['cmh_extra_roles'] ?? [] ) );
        foreach ( [ 'cmh_technician', 'cmh_client' ] as $role ) {
            if ( ! get_role( $role ) ) continue;
            $has = in_array( $role, (array) $user->roles, true );
            if ( in_array( $role, $wanted, true ) && ! $has ) {
                $user->add_role( $role );
            } elseif ( ! in_array( $role, $wanted, true ) && $has && count( $user->roles ) > 1 ) {
                // Nunca se deja al usuario sin ningún rol.
                $user->remove_role( $role );
            }
        }
    }

    /** ¿El usuario actual entra por alguno de los paneles del plugin? */
    public static function is_cmh_panel_user() {
        return is_user_logged_in()
            && ( current_user_can( 'cmh_tech' ) || current_user_can( 'cmh_client' ) );
    }

    /** Ejecuta migraciones específicas de versión. */
    private static function run_migrations( $t ) {
        global $wpdb;

        // Hace branch_id nullable si aún es NOT NULL (instalaciones previas a v0.7).
        $col = $wpdb->get_row( "SHOW COLUMNS FROM {$t['machines']} LIKE 'branch_id'" );
        if ( $col && $col->Null === 'NO' ) {
            $wpdb->query( "ALTER TABLE {$t['machines']} MODIFY COLUMN branch_id BIGINT UNSIGNED NULL" );
        }

        // Agrega scheduled_hours_monthly si no existe (instalaciones previas a v0.7).
        $col2 = $wpdb->get_row( "SHOW COLUMNS FROM {$t['machines']} LIKE 'scheduled_hours_monthly'" );
        if ( ! $col2 ) {
            $wpdb->query( "ALTER TABLE {$t['machines']} ADD COLUMN scheduled_hours_monthly DECIMAL(10,2) NOT NULL DEFAULT 480 AFTER current_hourmeter" );
        }

        // Agrega next_maintenance_date si no existe (instalaciones previas a v0.8.6).
        $col3 = $wpdb->get_row( "SHOW COLUMNS FROM {$t['machines']} LIKE 'next_maintenance_date'" );
        if ( ! $col3 ) {
            $wpdb->query( "ALTER TABLE {$t['machines']} ADD COLUMN next_maintenance_date DATE NULL DEFAULT NULL" );
        }

        // v0.10.1 — Columnas de control de pago en intervenciones.
        $colp = $wpdb->get_row( "SHOW COLUMNS FROM {$t['interventions']} LIKE 'payment_status'" );
        if ( ! $colp ) {
            $wpdb->query( "ALTER TABLE {$t['interventions']} ADD COLUMN payment_status VARCHAR(64) NOT NULL DEFAULT 'pendiente' AFTER cost" );
            $wpdb->query( "ALTER TABLE {$t['interventions']} ADD COLUMN paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER payment_status" );
        }

        // v0.11 — Intervalo de mantenimiento recurrente por máquina.
        $coli = $wpdb->get_row( "SHOW COLUMNS FROM {$t['machines']} LIKE 'maintenance_interval_days'" );
        if ( ! $coli ) {
            $wpdb->query( "ALTER TABLE {$t['machines']} ADD COLUMN maintenance_interval_days INT UNSIGNED NULL DEFAULT NULL AFTER next_maintenance_date" );
        }

        // v0.11 — Origen de la tarea (manual / auto) para las autogeneradas por el cron.
        $cols = $wpdb->get_row( "SHOW COLUMNS FROM {$t['tasks']} LIKE 'source'" );
        if ( ! $cols ) {
            $wpdb->query( "ALTER TABLE {$t['tasks']} ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER status" );
        }

        // v2.0 — Formato de Forminator que corresponde a la tarea (opcional: si
        // queda NULL, el técnico elige el formato al abrirla).
        $colf = $wpdb->get_row( "SHOW COLUMNS FROM {$t['tasks']} LIKE 'form_id'" );
        if ( ! $colf ) {
            $wpdb->query( "ALTER TABLE {$t['tasks']} ADD COLUMN form_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER source" );
        }

        // v2.5 — payment_status nació como VARCHAR(20), cuando los tres estados
        // eran fijos y cortos. Desde la v2.3 el usuario los crea a su gusto y los
        // slugs se generan hasta de 40 caracteres: «pendiente_de_cotizacion» son
        // 23 y NO CABÍA, así que la intervención no se guardaba. Se ensancha a 64.
        $colps = $wpdb->get_row( "SHOW COLUMNS FROM {$t['interventions']} LIKE 'payment_status'" );
        if ( $colps && stripos( $colps->Type, 'varchar(20)' ) !== false ) {
            $wpdb->query( "ALTER TABLE {$t['interventions']} MODIFY COLUMN payment_status VARCHAR(64) NOT NULL DEFAULT 'pendiente'" );
        }

        // v1.0.1 — Concilia las intervenciones marcadas «Pagado» que quedaron con
        // paid_amount = 0. Los KPIs y reportes calculan el saldo como cost − paid_amount
        // e ignoran el estado, así que seguían contando como por cobrar. Corre una sola vez.
        if ( ! get_option( 'cmh_migrated_paid_amount' ) ) {
            $wpdb->query( "UPDATE {$t['interventions']} SET paid_amount = cost WHERE payment_status = 'pagado' AND paid_amount < cost" );
            $wpdb->query( "UPDATE {$t['interventions']} SET paid_amount = 0 WHERE payment_status = 'pendiente' AND paid_amount > 0" );
            update_option( 'cmh_migrated_paid_amount', CMH_VERSION, false );
        }
    }

    /** Verifica la versión instalada y corre activate() si hay diferencia. */
    public static function maybe_upgrade() {
        $installed = get_option( 'cmh_version' ) ?: get_option( 'cmh_machine_history_version' );
        if ( $installed !== CMH_VERSION ) {
            self::activate();
            delete_option( 'cmh_machine_history_version' );

            // v2.9 — Las máquinas cuya tarea de mantenimiento ya estaba cerrada
            // dejan de figurar como vencidas. Corre una sola vez, al subir.
            if ( $installed && version_compare( $installed, '2.9.0', '<' ) && class_exists( 'CMH_Schedule' ) ) {
                $n = CMH_Schedule::repair_done_maintenance();
                if ( $n ) self::log( 'info', null, '', null, 'v2.9: ' . $n . ' máquina(s) con mantenimiento ya hecho dejaron de figurar como vencidas.' );
            }
        }
    }

    /** Limpia lo que no debe sobrevivir a la desactivación del plugin. */
    public static function deactivate() {
        if ( class_exists( 'CMH_Schedule' ) ) CMH_Schedule::unschedule_cron();
    }

    /** Registra una entrada en la tabla de logs de integración. */
    public static function log( $level, $form_id, $machine_code, $intervention_id, $message, $payload = null ) {
        global $wpdb;
        $wpdb->insert( self::tables()['logs'], [
            'level'           => sanitize_text_field( $level ),
            'form_id'         => $form_id       ? (int) $form_id       : null,
            'machine_code'    => sanitize_text_field( $machine_code ),
            'intervention_id' => $intervention_id ? (int) $intervention_id : null,
            'message'         => sanitize_textarea_field( $message ),
            'payload'         => $payload !== null ? maybe_serialize( $payload ) : null,
        ] );
    }
}
