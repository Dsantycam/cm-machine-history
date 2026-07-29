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
        ];
    }

    /** Crea/actualiza todas las tablas. Seguro de ejecutar múltiples veces. */
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $t = self::tables();

        dbDelta( "CREATE TABLE {$t['companies']} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name       VARCHAR(190)    NOT NULL,
            code       VARCHAR(20)     NOT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $c;" );

        dbDelta( "CREATE TABLE {$t['cities']} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            name       VARCHAR(190)    NOT NULL,
            code       VARCHAR(20)     NOT NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
            payment_status       VARCHAR(20)     NOT NULL DEFAULT 'pendiente',
            paid_amount          DECIMAL(14,2)   NOT NULL DEFAULT 0,
            affects_availability TINYINT(1)      NOT NULL DEFAULT 0,
            failure_system       VARCHAR(190)    NULL,
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
            created_by  BIGINT UNSIGNED NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME        NULL,
            PRIMARY KEY (id),
            KEY machine_id (machine_id),
            KEY assigned_to (assigned_to),
            KEY status (status)
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

        // Migrar branch_id a nullable en instalaciones existentes.
        self::run_migrations( $t );

        // v0.9 — Rol de técnico y capacidades.
        self::setup_roles();

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
            $wpdb->query( "ALTER TABLE {$t['interventions']} ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'pendiente' AFTER cost" );
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
    }

    /** Verifica la versión instalada y corre activate() si hay diferencia. */
    public static function maybe_upgrade() {
        $installed = get_option( 'cmh_version' ) ?: get_option( 'cmh_machine_history_version' );
        if ( $installed !== CMH_VERSION ) {
            self::activate();
            delete_option( 'cmh_machine_history_version' );
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
