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

        // Migrar branch_id a nullable en instalaciones existentes.
        self::run_migrations( $t );

        update_option( 'cmh_version', CMH_VERSION );
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
    }

    /** Verifica la versión instalada y corre activate() si hay diferencia. */
    public static function maybe_upgrade() {
        $installed = get_option( 'cmh_version' ) ?: get_option( 'cmh_machine_history_version' );
        if ( $installed !== CMH_VERSION ) {
            self::activate();
            delete_option( 'cmh_machine_history_version' );
        }
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
