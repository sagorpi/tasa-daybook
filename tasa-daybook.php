<?php
/**
 * Plugin Name: TASA DayBook
 * Plugin URI:  https://example.com/tasa-daybook
 * Description: Track daily cash, online payments, and cash taken out before store closing.
 * Version:     1.0.0
 * Author:      TASA
 * Author URI:  https://example.com
 * License:     GPL-2.0+
 * Text Domain: tasa-daybook
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TASA_DAYBOOK_VERSION', '1.0.0' );
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
        created_by       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY record_date (record_date)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'tasa_daybook_db_version', TASA_DAYBOOK_VERSION );
}
register_activation_hook( __FILE__, 'tasa_daybook_activate' );

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