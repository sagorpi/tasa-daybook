<?php
/**
 * Plugin Name: TASA DayBook
 * Plugin URI:  https://example.com/tasa-daybook
 * Description: Track daily cash, online payments, and cash taken out before store closing.
 * Version:     1.2.0
 * Author:      TASA
 * Author URI:  https://example.com
 * License:     GPL-2.0+
 * Text Domain: tasa-daybook
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TASA_DAYBOOK_VERSION', '1.2.0' );
define( 'TASA_DAYBOOK_TABLE', 'tasa_daybook_records' );
define( 'TASA_DAYBOOK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TASA_DAYBOOK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* ─────────────────────────────────────────────
 * Helper – get table name
 * ───────────────────────────────────────────── */
function tasa_daybook_table() {
    global $wpdb;
    return $wpdb->prefix . TASA_DAYBOOK_TABLE;
}

/* ─────────────────────────────────────────────
 * Activation – create / update the DB table
 * ───────────────────────────────────────────── */
function tasa_daybook_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . TASA_DAYBOOK_TABLE;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id               BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        record_date      DATE          NOT NULL,
        opening_cash     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        cash_sales       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        online_payments  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        cash_taken_out   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        closing_cash     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        calculated_diff  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        note             TEXT          NULL,
        created_by       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY record_date (record_date)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'tasa_daybook_db_version', TASA_DAYBOOK_VERSION );

    // Upgrade: Remove UNIQUE constraint from record_date if it exists
    tasa_daybook_upgrade_database();
}
register_activation_hook( __FILE__, 'tasa_daybook_activate' );

/* ─────────────────────────────────────────────
 * Database Upgrade – Remove UNIQUE constraint from record_date
 * ───────────────────────────────────────────── */
function tasa_daybook_upgrade_database() {
    global $wpdb;
    $table = $wpdb->prefix . TASA_DAYBOOK_TABLE;

    // Check if the UNIQUE constraint exists
    $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'record_date'" );

    if ( ! empty( $indexes ) ) {
        foreach ( $indexes as $index ) {
            // If it's a UNIQUE index, drop it and recreate as a regular index
            if ( $index->Non_unique == 0 ) {
                // Drop the UNIQUE constraint
                $wpdb->query( "ALTER TABLE {$table} DROP INDEX record_date" );

                // Add it back as a regular index (non-unique)
                $wpdb->query( "ALTER TABLE {$table} ADD INDEX record_date (record_date)" );

                break;
            }
        }
    }

    // Add note column for record comments if missing.
    $note_column = $wpdb->get_var( $wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s",
        'note'
    ) );

    if ( ! $note_column ) {
        $wpdb->query( "ALTER TABLE {$table} ADD COLUMN note TEXT NULL AFTER calculated_diff" );
    }
}

/* ─────────────────────────────────────────────
 * Check for database upgrades on admin init
 * ───────────────────────────────────────────── */
function tasa_daybook_check_upgrade() {
    $current_version = get_option( 'tasa_daybook_db_version', '0' );

    if ( version_compare( $current_version, TASA_DAYBOOK_VERSION, '<' ) ) {
        tasa_daybook_activate(); // Run activation to update schema
    }
}
add_action( 'admin_init', 'tasa_daybook_check_upgrade' );

/* ─────────────────────────────────────────────
 * Include admin functionality
 * ───────────────────────────────────────────── */
if ( is_admin() ) {
    require_once TASA_DAYBOOK_PLUGIN_DIR . 'includes/admin.php';
}

/* ─────────────────────────────────────────────
 * Include frontend functionality
 * ───────────────────────────────────────────── */
require_once TASA_DAYBOOK_PLUGIN_DIR . 'includes/frontend.php';

/* ─────────────────────────────────────────────
 * Uninstall hook – clean up on plugin deletion
 * ───────────────────────────────────────────── */
function tasa_daybook_uninstall() {
    global $wpdb;
    $table = $wpdb->prefix . TASA_DAYBOOK_TABLE;
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );  // phpcs:ignore WordPress.DB.PreparedSQL
    delete_option( 'tasa_daybook_db_version' );
}
register_uninstall_hook( __FILE__, 'tasa_daybook_uninstall' );
