<?php
/**
 * Admin functionality for TASA DayBook
 *
 * @package TASA_DayBook
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ─────────────────────────────────────────────
 * Admin Menu – under Tools
 * ───────────────────────────────────────────── */
function tasa_daybook_admin_menu() {
    add_management_page(
        __( 'TASA DayBook', 'tasa-daybook' ),
        __( 'TASA DayBook', 'tasa-daybook' ),
        'manage_woocommerce',         // Only Shop Managers and Admins
        'tasa-daybook',
        'tasa_daybook_render_page'
    );
}
add_action( 'admin_menu', 'tasa_daybook_admin_menu' );
add_action( 'admin_init', 'tasa_daybook_maybe_export_csv' );

/* ─────────────────────────────────────────────
 * Enqueue admin styles + fonts
 * ───────────────────────────────────────────── */
function tasa_daybook_admin_styles( $hook ) {
    if ( 'tools_page_tasa-daybook' !== $hook ) {
        return;
    }
    wp_enqueue_style( 'tasa-daybook-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null );
    wp_enqueue_style( 'dashicons' );
    wp_enqueue_style( 'tasa-daybook-admin', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin.css', array(), TASA_DAYBOOK_VERSION );
}
add_action( 'admin_enqueue_scripts', 'tasa_daybook_admin_styles' );

/* ─────────────────────────────────────────────
 * Enqueue admin scripts - removed, now inline with forms
 * ───────────────────────────────────────────── */

/* ─────────────────────────────────────────────
 * Router – handle POST actions then render
 * ───────────────────────────────────────────── */
function tasa_daybook_render_page() {
    // Check if user is Shop Manager or Administrator
    $user = wp_get_current_user();
    $allowed_roles = array( 'shop_manager', 'administrator' );

    if ( ! array_intersect( $allowed_roles, $user->roles ) ) {
        wp_die(
            __( 'You do not have permission to access this page. Only Shop Managers and Administrators can access TASA DayBook.', 'tasa-daybook' ),
            __( 'Access Denied', 'tasa-daybook' ),
            array( 'response' => 403 )
        );
    }

    // Process POST actions before any output
    tasa_daybook_handle_post();

    $is_admin = current_user_can( 'manage_options' );
    $is_shop_manager = in_array( 'shop_manager', $user->roles, true );
    $today    = current_time( 'Y-m-d' );
    $day_name = wp_date( 'l, F j, Y' );

    // Determine role label and class
    if ( $is_admin ) {
        $role_label = __( 'Administrator', 'tasa-daybook' );
        $role_class = 'tdb-badge--admin';
    } elseif ( $is_shop_manager ) {
        $role_label = __( 'Shop Manager', 'tasa-daybook' );
        $role_class = 'tdb-badge--staff';
    } else {
        $role_label = __( 'Staff', 'tasa-daybook' );
        $role_class = 'tdb-badge--staff';
    }

    echo '<div class="wrap tdb-wrap">';

    // ── Branded header ──
    echo '<div class="tdb-header">';
    echo '  <div class="tdb-header__left">';
    echo '    <span class="dashicons dashicons-book tdb-header__icon"></span>';
    echo '    <div>';
    echo '      <h1 class="tdb-header__title">' . esc_html__( 'TASA DayBook', 'tasa-daybook' ) . '</h1>';
    echo '      <p class="tdb-header__date">' . esc_html( $day_name ) . '</p>';
    echo '    </div>';
    echo '  </div>';
    echo '  <span class="tdb-badge ' . esc_attr( $role_class ) . '">' . esc_html( $role_label ) . '</span>';
    echo '</div>';

    // Determine current view
    $action = isset( $_GET['cc_action'] ) ? sanitize_text_field( wp_unslash( $_GET['cc_action'] ) ) : '';

    if ( 'edit' === $action && $is_admin ) {
        tasa_daybook_render_edit_form();
    } else {
        tasa_daybook_render_add_form();
        tasa_daybook_render_records_table();
    }

    echo '</div>';
}

/* ─────────────────────────────────────────────
 * CSV export helpers
 * ───────────────────────────────────────────── */
function tasa_daybook_is_valid_filter_date( $date ) {
    if ( ! is_string( $date ) || '' === $date ) {
        return false;
    }

    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        return false;
    }

    $timestamp = strtotime( $date );
    return false !== $timestamp && date( 'Y-m-d', $timestamp ) === $date;
}

function tasa_daybook_get_record_user_info( $created_by ) {
    if ( ! $created_by ) {
        return __( 'System', 'tasa-daybook' );
    }

    $user = get_userdata( $created_by );
    if ( ! $user ) {
        return __( 'Unknown User', 'tasa-daybook' );
    }

    $display_name = $user->display_name;
    $user_roles   = $user->roles;
    $role_label   = '';

    if ( in_array( 'administrator', $user_roles, true ) ) {
        $role_label = __( 'Administrator', 'tasa-daybook' );
    } elseif ( in_array( 'shop_manager', $user_roles, true ) ) {
        $role_label = __( 'Shop Manager', 'tasa-daybook' );
    }

    if ( $role_label ) {
        return $display_name . ' (' . $role_label . ')';
    }

    return $display_name;
}

function tasa_daybook_maybe_export_csv() {
    $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
    if ( 'tasa-daybook' !== $page ) {
        return;
    }

    $action = isset( $_GET['tasa_cc_action'] ) ? sanitize_text_field( wp_unslash( $_GET['tasa_cc_action'] ) ) : '';

    if ( 'export_csv' !== $action ) {
        return;
    }

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have permission to export CSV.', 'tasa-daybook' ) );
    }

    if ( ! isset( $_GET['_tasa_cc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_tasa_cc_nonce'] ) ), 'tasa_cc_export_csv' ) ) {
        wp_die( esc_html__( 'Security check failed.', 'tasa-daybook' ) );
    }

    global $wpdb;
    $table = tasa_daybook_table();

    $export_mode = sanitize_text_field( wp_unslash( $_GET['export_mode'] ?? '' ) );
    if ( ! in_array( $export_mode, array( 'today', 'tomorrow', 'single', 'range' ), true ) ) {
        $export_mode = 'today';
    }

    $single_date = sanitize_text_field( wp_unslash( $_GET['single_date'] ?? '' ) );
    $start_date  = sanitize_text_field( wp_unslash( $_GET['start_date'] ?? '' ) );
    $end_date    = sanitize_text_field( wp_unslash( $_GET['end_date'] ?? '' ) );

    $where_parts = array();
    $params      = array();

    if ( 'today' === $export_mode ) {
        $where_parts[] = 'record_date = %s';
        $params[]      = current_time( 'Y-m-d' );
    } elseif ( 'tomorrow' === $export_mode ) {
        $tomorrow = wp_date( 'Y-m-d', current_time( 'timestamp' ) + DAY_IN_SECONDS );
        $where_parts[] = 'record_date = %s';
        $params[]      = $tomorrow;
    } elseif ( 'single' === $export_mode ) {
        if ( ! tasa_daybook_is_valid_filter_date( $single_date ) ) {
            wp_die( esc_html__( 'Please choose a valid single date.', 'tasa-daybook' ) );
        }

        $where_parts[] = 'record_date = %s';
        $params[]      = $single_date;
    } else {
        if ( '' === $start_date || '' === $end_date ) {
            wp_die( esc_html__( 'Please choose both start and end date for date range.', 'tasa-daybook' ) );
        }

        if ( ! tasa_daybook_is_valid_filter_date( $start_date ) ) {
            wp_die( esc_html__( 'Invalid start date format.', 'tasa-daybook' ) );
        }

        if ( ! tasa_daybook_is_valid_filter_date( $end_date ) ) {
            wp_die( esc_html__( 'Invalid end date format.', 'tasa-daybook' ) );
        }

        if ( $start_date > $end_date ) {
            $temp       = $start_date;
            $start_date = $end_date;
            $end_date   = $temp;
        }

        $where_parts[] = 'record_date BETWEEN %s AND %s';
        $params[]      = $start_date;
        $params[]      = $end_date;
    }

    $sql = "SELECT * FROM {$table}";
    if ( ! empty( $where_parts ) ) {
        $sql .= ' WHERE ' . implode( ' AND ', $where_parts );
    }
    $sql .= ' ORDER BY record_date DESC, id DESC';

    $records = ! empty( $params ) ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

    $filename = 'tasa-daybook-' . current_time( 'Ymd-His' ) . '.csv';

    // Ensure no buffered HTML leaks into CSV output.
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    nocache_headers();
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );

    $output = fopen( 'php://output', 'w' );

    // Build running online balance by record id so CSV matches records-table final closing logic.
    $online_balance_by_id = array();
    $online_running_total = 0.00;
    $has_online_taken_out_column = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'online_taken_out' ) );
    $online_rows_sql = $has_online_taken_out_column
        ? "SELECT id, online_payments, online_taken_out FROM {$table} ORDER BY id ASC"
        : "SELECT id, online_payments, 0.00 AS online_taken_out FROM {$table} ORDER BY id ASC";
    $online_rows = $wpdb->get_results( $online_rows_sql );
    foreach ( $online_rows as $online_row ) {
        $online_running_total += (float) $online_row->online_payments - (float) $online_row->online_taken_out;
        $online_balance_by_id[ (int) $online_row->id ] = $online_running_total;
    }

    fputcsv(
        $output,
        array(
            'Date (Time)',
            'Submitted By',
            'Opening Cash',
            'Cash Sales',
            'Online Sales',
            'Withdrawn Amount',
            'Note',
            'Closing Cash',
            'Final Closing Amount',
        )
    );

    foreach ( $records as $row ) {
        $record_time = '';
        if ( ! empty( $row->created_at ) ) {
            $timestamp = strtotime( (string) $row->created_at );
            if ( false !== $timestamp ) {
                $record_time = wp_date( 'H:i:s', $timestamp );
            }
        }

        $date_with_time = (string) $row->record_date;
        if ( '' !== $record_time ) {
            $date_with_time .= ' (' . $record_time . ')';
        }

        $cash_taken_out = (float) $row->cash_taken_out;
        $withdrawal_type = isset( $row->withdrawal_type ) && in_array( $row->withdrawal_type, array( 'cash', 'online' ), true )
            ? $row->withdrawal_type
            : 'cash';
        $withdrawal_label = $withdrawal_type === 'online' ? 'Online' : 'Cash';
        $withdrawn_amount = '₹' . number_format( $cash_taken_out, 2, '.', '' ) . ' (' . $withdrawal_label . ')';

        $online_balance = isset( $online_balance_by_id[ (int) $row->id ] ) ? (float) $online_balance_by_id[ (int) $row->id ] : (float) $row->online_payments;
        $final_closing_amount = (float) $row->closing_cash + $online_balance;

        fputcsv(
            $output,
            array(
                $date_with_time,
                tasa_daybook_get_record_user_info( (int) $row->created_by ),
                number_format( (float) $row->opening_cash, 2, '.', '' ),
                number_format( (float) $row->cash_sales, 2, '.', '' ),
                number_format( (float) $row->online_payments, 2, '.', '' ),
                $withdrawn_amount,
                (string) ( $row->note ?? '' ),
                number_format( (float) $row->closing_cash, 2, '.', '' ),
                number_format( $final_closing_amount, 2, '.', '' ),
            )
        );
    }

    fclose( $output );
    exit;
}

/* ─────────────────────────────────────────────
 * Recalculate opening/closing cash chain
 * ───────────────────────────────────────────── */
function tasa_daybook_recalculate_cash_chain() {
    global $wpdb;
    $table = tasa_daybook_table();

    $records = $wpdb->get_results(
        "SELECT id, cash_sales, cash_taken_out, withdrawal_type FROM {$table} ORDER BY id ASC"
    );

    if ( empty( $records ) ) {
        return;
    }

    $previous_closing = 0.00;

    foreach ( $records as $record ) {
        $opening_cash   = (float) $previous_closing;
        $cash_sales     = (float) $record->cash_sales;
        $cash_taken_out = (float) $record->cash_taken_out;
        $withdrawal_type = isset( $record->withdrawal_type ) && in_array( $record->withdrawal_type, array( 'cash', 'online' ), true )
            ? $record->withdrawal_type
            : 'cash';
        $effective_cash_taken_out = $withdrawal_type === 'cash' ? $cash_taken_out : 0.00;

        $closing_cash    = $cash_sales + $opening_cash - $effective_cash_taken_out;
        $expected        = $opening_cash + $cash_sales - $effective_cash_taken_out;
        $calculated_diff = $closing_cash - $expected;

        $wpdb->update(
            $table,
            array(
                'opening_cash'    => $opening_cash,
                'closing_cash'    => $closing_cash,
                'calculated_diff' => $calculated_diff,
            ),
            array( 'id' => (int) $record->id ),
            array( '%f', '%f', '%f' ),
            array( '%d' )
        );

        $previous_closing = $closing_cash;
    }
}

/**
 * Get online-sales total for the latest day before the given date.
 *
 * @param string $reference_date Date in Y-m-d format.
 * @return float
 */
function tasa_daybook_get_previous_day_online_total( $reference_date ) {
    global $wpdb;
    $table = tasa_daybook_table();

    $previous_date = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT record_date FROM {$table} WHERE record_date < %s ORDER BY record_date DESC LIMIT 1",
            $reference_date
        )
    );

    if ( empty( $previous_date ) ) {
        return 0.00;
    }

    $online_total = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(online_payments) FROM {$table} WHERE record_date = %s",
            $previous_date
        )
    );

    return null !== $online_total ? (float) $online_total : 0.00;
}

/**
 * Get net online balance up to and including the given date.
 *
 * @param string $reference_date Date in Y-m-d format.
 * @return float
 */
function tasa_daybook_get_online_balance_upto_date( $reference_date ) {
    global $wpdb;
    $table = tasa_daybook_table();

    $online_balance = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(online_payments - online_taken_out) FROM {$table} WHERE record_date <= %s",
            $reference_date
        )
    );

    return null !== $online_balance ? (float) $online_balance : 0.00;
}

/* ─────────────────────────────────────────────
 * Handle POST (add / edit / delete)
 * ───────────────────────────────────────────── */
function tasa_daybook_handle_post() {
    if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
        return;
    }

    $action = isset( $_POST['tasa_cc_action'] ) ? sanitize_text_field( wp_unslash( $_POST['tasa_cc_action'] ) ) : '';

    // --- ADD ---
    if ( 'add' === $action ) {
        tasa_daybook_process_add();
    }

    // --- EDIT (admin only) ---
    if ( 'edit' === $action && current_user_can( 'manage_options' ) ) {
        tasa_daybook_process_edit();
    }

    // --- DELETE (admin only) ---
    if ( 'delete' === $action && current_user_can( 'manage_options' ) ) {
        tasa_daybook_process_delete();
    }
}

/* ─────────────────────────────────────────────
 * Process: Add a new record (current date only)
 * ───────────────────────────────────────────── */
function tasa_daybook_process_add() {
    if ( ! check_admin_referer( 'tasa_cc_add_nonce', '_tasa_cc_nonce' ) ) {
        wp_die( esc_html__( 'Security check failed.', 'tasa-daybook' ) );
    }

    global $wpdb;
    $table = tasa_daybook_table();
    $today = current_time( 'Y-m-d' );

    // Get record type (admin only feature)
    $record_type = sanitize_text_field( wp_unslash( $_POST['record_type'] ?? 'full' ) );
    if ( in_array( $record_type, array( 'cash_out_only', 'online_out_only' ), true ) ) {
        $record_type = 'amount_out_only';
    }
    $is_admin = current_user_can( 'manage_options' );

    // Check if the current user already submitted a record for today
    // Admins can bypass this check to add multiple records (e.g., cash out transactions)
    // Shop managers can only add one record per day (checking by their user ID)
    if ( ! $is_admin ) {
        $user_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE record_date = %s AND created_by = %d",
            $today,
            get_current_user_id()
        ) );

        if ( $user_exists ) {
            add_settings_error( 'tasa_cc', 'duplicate', __( 'You have already submitted a record for today.', 'tasa-daybook' ), 'error' );
            return;
        }
    }

    $online_taken_out = 0.00;
    $withdrawal_type = 'cash';

    // Admin-only record types can force specific input values.
    if ( $record_type === 'amount_out_only' && $is_admin ) {
        $cash_sales     = 0.00;
        $online_sales   = 0.00;
        $cash_taken_out = floatval( sanitize_text_field( wp_unslash( $_POST['cash_taken_out'] ?? '0' ) ) );
    } else {
        $cash_sales     = floatval( sanitize_text_field( wp_unslash( $_POST['cash_sales'] ?? '0' ) ) );
        $online_sales   = floatval( sanitize_text_field( wp_unslash( $_POST['online_sales'] ?? '0' ) ) );
        $cash_taken_out = floatval( sanitize_text_field( wp_unslash( $_POST['cash_taken_out'] ?? '0' ) ) );
    }

    if ( $is_admin ) {
        $raw_withdrawal_type = sanitize_text_field( wp_unslash( $_POST['withdrawal_type'] ?? 'cash' ) );
        $withdrawal_type = in_array( $raw_withdrawal_type, array( 'cash', 'online' ), true ) ? $raw_withdrawal_type : 'cash';
    }
    $note            = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

    // Get most recent record's closing cash for opening cash
    // This includes records from the same day (for multiple admin entries) and previous days
    $previous_record = $wpdb->get_row( $wpdb->prepare(
        "SELECT closing_cash FROM {$table} WHERE record_date <= %s ORDER BY id DESC LIMIT 1", $today
    ) );
    $opening_cash = $previous_record ? floatval( $previous_record->closing_cash ) : 0.00;

    if ( 'online' === $withdrawal_type ) {
        $online_taken_out = $cash_taken_out;
    }
    $effective_cash_taken_out = 'cash' === $withdrawal_type ? $cash_taken_out : 0.00;

    // Closing cash tracks physical cash only.
    $closing_cash    = $cash_sales + $opening_cash - $effective_cash_taken_out;

    // Expected = opening + cash_sales - cash_taken_out
    $expected        = $opening_cash + $cash_sales - $effective_cash_taken_out;
    $calculated_diff = $closing_cash - $expected;

    $result = $wpdb->insert(
        $table,
        array(
            'record_date'     => $today,
            'opening_cash'    => $opening_cash,
            'cash_sales'      => $cash_sales,
            'online_payments' => $online_sales,  // Note: DB column is still 'online_payments'
            'online_taken_out'=> $online_taken_out,
            'cash_taken_out'  => $cash_taken_out,
            'withdrawal_type' => $withdrawal_type,
            'closing_cash'    => $closing_cash,
            'calculated_diff' => $calculated_diff,
            'note'            => $note,
            'created_by'      => get_current_user_id(),
        ),
        array( '%s', '%f', '%f', '%f', '%f', '%f', '%s', '%f', '%f', '%s', '%d' )
    );

    if ( false !== $result ) {
        add_settings_error( 'tasa_cc', 'added', __( 'Record saved successfully.', 'tasa-daybook' ), 'success' );
    } else {
        add_settings_error( 'tasa_cc', 'db_error', __( 'Failed to save record.', 'tasa-daybook' ), 'error' );
    }
}

/* ─────────────────────────────────────────────
 * Process: Edit an existing record (admin only)
 * ───────────────────────────────────────────── */
function tasa_daybook_process_edit() {
    if ( ! check_admin_referer( 'tasa_cc_edit_nonce', '_tasa_cc_nonce' ) ) {
        wp_die( esc_html__( 'Security check failed.', 'tasa-daybook' ) );
    }

    global $wpdb;
    $table = tasa_daybook_table();
    $id    = absint( $_POST['record_id'] ?? 0 );

    if ( ! $id ) {
        return;
    }

    $cash_sales      = floatval( sanitize_text_field( wp_unslash( $_POST['cash_sales'] ?? '0' ) ) );
    $online_sales    = floatval( sanitize_text_field( wp_unslash( $_POST['online_sales'] ?? '0' ) ) );
    $cash_taken_out  = floatval( sanitize_text_field( wp_unslash( $_POST['cash_taken_out'] ?? '0' ) ) );
    $raw_withdrawal_type = sanitize_text_field( wp_unslash( $_POST['withdrawal_type'] ?? 'cash' ) );
    $withdrawal_type = in_array( $raw_withdrawal_type, array( 'cash', 'online' ), true ) ? $raw_withdrawal_type : 'cash';
    $note            = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

    // Get the record being edited to find its date
    $record = $wpdb->get_row( $wpdb->prepare(
        "SELECT record_date FROM {$table} WHERE id = %d", $id
    ) );

    // Get most recent record's closing cash for opening cash (excluding the record being edited)
    // This includes records from the same day (for multiple admin entries) and previous days
    $previous_record = $wpdb->get_row( $wpdb->prepare(
        "SELECT closing_cash FROM {$table} WHERE id < %d ORDER BY id DESC LIMIT 1", $id
    ) );
    $opening_cash = $previous_record ? floatval( $previous_record->closing_cash ) : 0.00;

    $online_taken_out = 'online' === $withdrawal_type ? $cash_taken_out : 0.00;
    $effective_cash_taken_out = 'cash' === $withdrawal_type ? $cash_taken_out : 0.00;

    // Closing cash tracks physical cash only.
    $closing_cash    = $cash_sales + $opening_cash - $effective_cash_taken_out;

    $expected        = $opening_cash + $cash_sales - $effective_cash_taken_out;
    $calculated_diff = $closing_cash - $expected;

    $wpdb->update(
        $table,
        array(
            'opening_cash'    => $opening_cash,
            'cash_sales'      => $cash_sales,
            'online_payments' => $online_sales,  // Note: DB column is still 'online_payments'
            'cash_taken_out'  => $cash_taken_out,
            'online_taken_out'=> $online_taken_out,
            'withdrawal_type' => $withdrawal_type,
            'closing_cash'    => $closing_cash,
            'calculated_diff' => $calculated_diff,
            'note'            => $note,
        ),
        array( 'id' => $id ),
        array( '%f', '%f', '%f', '%f', '%f', '%s', '%f', '%f', '%s' ),
        array( '%d' )
    );

    // Recalculate all later rows so balances stay consistent after edits.
    tasa_daybook_recalculate_cash_chain();

    add_settings_error( 'tasa_cc', 'updated', __( 'Record updated successfully.', 'tasa-daybook' ), 'success' );

    // Redirect back to list
    wp_safe_redirect( admin_url( 'tools.php?page=tasa-daybook' ) );
    exit;
}

/* ─────────────────────────────────────────────
 * Process: Delete a record (admin only)
 * ───────────────────────────────────────────── */
function tasa_daybook_process_delete() {
    if ( ! check_admin_referer( 'tasa_cc_delete_nonce', '_tasa_cc_nonce' ) ) {
        wp_die( esc_html__( 'Security check failed.', 'tasa-daybook' ) );
    }

    global $wpdb;
    $table = tasa_daybook_table();
    $id    = absint( $_POST['record_id'] ?? 0 );

    if ( $id ) {
        $deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

        if ( false !== $deleted && $deleted > 0 ) {
            // Recalculate remaining rows after delete so future cash stays adjusted.
            tasa_daybook_recalculate_cash_chain();
        }

        add_settings_error( 'tasa_cc', 'deleted', __( 'Record deleted.', 'tasa-daybook' ), 'success' );
    }
}

/* ─────────────────────────────────────────────
 * Render: Add-record form (current date)
 * ───────────────────────────────────────────── */
function tasa_daybook_render_add_form() {
    global $wpdb;
    $table = tasa_daybook_table();
    $today = current_time( 'Y-m-d' );

    // Check if current user already submitted a record today
    $already_submitted = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE record_date = %s AND created_by = %d",
        $today,
        get_current_user_id()
    ) );

    // Get most recent record's closing cash for opening cash
    // This includes records from the same day (for multiple admin entries) and previous days
    $previous_record = $wpdb->get_row( $wpdb->prepare(
        "SELECT closing_cash FROM {$table} WHERE record_date <= %s ORDER BY id DESC LIMIT 1", $today
    ) );
    $opening_cash = $previous_record ? number_format( (float) $previous_record->closing_cash, 2 ) : '0.00';
    $latest_online_balance = tasa_daybook_get_online_balance_upto_date( $today );
    $total_opening_amount = number_format( (float) str_replace( ',', '', $opening_cash ) + $latest_online_balance, 2 );
    $final_closing_amount = $total_opening_amount;

    settings_errors( 'tasa_cc' );

    echo '<div class="tdb-card">';
    echo '<div class="tdb-card__header"><span class="dashicons dashicons-plus-alt2 tdb-card__icon"></span>';
    echo '<h2 class="tdb-card__title">' . esc_html__( 'Add Today\'s Record', 'tasa-daybook' ) . '</h2></div>';

    if ( $already_submitted && ! current_user_can( 'manage_options' ) ) {
        echo '<div class="tdb-alert tdb-alert--warning"><span class="dashicons dashicons-lock"></span>';
        echo esc_html__( 'You have already submitted a record for today. Only an administrator can edit it.', 'tasa-daybook' );
        echo '</div></div>';
        return;
    } elseif ( $already_submitted && current_user_can( 'manage_options' ) ) {
        echo '<div class="tdb-alert tdb-alert--info"><span class="dashicons dashicons-info-outline"></span>';
        echo esc_html__( 'Note: A record for today already exists. As an administrator, you can add additional records (e.g., for cash out transactions).', 'tasa-daybook' );
        echo '</div>';
    }

    $is_admin = current_user_can( 'manage_options' );

    $opening_row_class = $is_admin
        ? 'tdb-grid tdb-grid--opening tdb-grid--opening-admin'
        : 'tdb-grid tdb-grid--opening';

    ?>
    <form method="post" class="tdb-form" id="tdb-add-form">
        <?php wp_nonce_field( 'tasa_cc_add_nonce', '_tasa_cc_nonce' ); ?>
        <input type="hidden" name="tasa_cc_action" value="add">

        <?php if ( $is_admin ) : ?>
        <div class="tdb-field" style="margin-bottom: 20px;">
            <label for="record_type"><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Record Type', 'tasa-daybook' ); ?> <span style="color: #d63638; font-size: 11px; font-weight: normal;">(<?php esc_html_e( 'Admin Only', 'tasa-daybook' ); ?>)</span></label>
            <select id="record_type" name="record_type" class="tdb-input" style="width: 100%; max-width: 300px;">
                <option value="full"><?php esc_html_e( 'Full Record', 'tasa-daybook' ); ?></option>
                <option value="amount_out_only"><?php esc_html_e( 'Amount Out Only', 'tasa-daybook' ); ?></option>
            </select>
            <p style="margin: 8px 0 0 0; font-size: 13px; color: #646970;">
                <?php esc_html_e( 'Select "Amount Out Only" to create non-sales admin adjustment entries.', 'tasa-daybook' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <input type="hidden" id="online_balance_carry" value="<?php echo esc_attr( number_format( $latest_online_balance, 2, '.', '' ) ); ?>">

        <div class="<?php echo esc_attr( $opening_row_class ); ?>">
            <div class="tdb-field">
                <label for="opening_cash"><span class="dashicons dashicons-vault"></span> <?php esc_html_e( 'Opening Cash', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">₹</span>
                    <input type="text" id="opening_cash" name="opening_cash" value="<?php echo esc_attr( $opening_cash ); ?>" readonly class="tdb-input" style="background-color: #f0f0f1; cursor: not-allowed;">
                </div>
            </div>
            <?php if ( $is_admin ) : ?>
            <div class="tdb-field">
                <label for="total_opening_amount"><span class="dashicons dashicons-calculator"></span> <?php esc_html_e( 'Total Opening Amount (Online + Cash)', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">₹</span>
                    <input type="text" id="total_opening_amount" name="total_opening_amount" value="<?php echo esc_attr( $total_opening_amount ); ?>" readonly class="tdb-input" style="background-color: #f0f0f1; cursor: not-allowed;">
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="tdb-grid">
            <div class="tdb-field" data-field-type="full-only">
                <label for="cash_sales"><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Cash Sales', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">₹</span>
                    <input type="number" step="0.01" min="0" id="cash_sales" name="cash_sales" placeholder="0.00" required class="tdb-input" data-calc>
                </div>
            </div>
            <div class="tdb-field" data-field-type="full-only">
                <label for="online_sales"><span class="dashicons dashicons-smartphone"></span> <?php esc_html_e( 'Online Sales', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">₹</span>
                    <input type="number" step="0.01" min="0" id="online_sales" name="online_sales" placeholder="0.00" required class="tdb-input" data-calc>
                </div>
            </div>
            <div class="tdb-field" data-field-type="full-only">
                <label for="todays_sale"><span class="dashicons dashicons-chart-line"></span> <?php esc_html_e( 'Today\'s Sale', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">₹</span>
                    <input type="text" id="todays_sale" name="todays_sale" value="0.00" readonly class="tdb-input" style="background-color: #f0f0f1; cursor: not-allowed;">
                </div>
            </div>
            <div class="tdb-field" data-field-type="cash-out-enabled">
                <label for="cash_taken_out"><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( 'Amount Taken Out', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">₹</span>
                    <input type="number" step="0.01" min="0" id="cash_taken_out" name="cash_taken_out" placeholder="0.00" required class="tdb-input" data-calc>
                </div>
            </div>
            <?php if ( $is_admin ) : ?>
            <div class="tdb-field">
                <label for="withdrawal_type"><span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'Withdrawal Type', 'tasa-daybook' ); ?></label>
                <select id="withdrawal_type" name="withdrawal_type" class="tdb-input">
                    <option value="cash"><?php esc_html_e( 'Cash', 'tasa-daybook' ); ?></option>
                    <option value="online"><?php esc_html_e( 'Online Transfer', 'tasa-daybook' ); ?></option>
                </select>
            </div>
            <?php endif; ?>
            <div class="tdb-field tdb-field--full">
                <label for="note"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Note', 'tasa-daybook' ); ?></label>
                <textarea id="note" name="note" rows="3" class="tdb-input tdb-input--textarea" placeholder="<?php esc_attr_e( 'Add a note for this record (optional)', 'tasa-daybook' ); ?>"></textarea>
            </div>
        </div>

        <div class="tdb-preview">
            <div class="tdb-preview__label"><?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?></div>
            <div class="tdb-preview__value" id="tdb-closing-cash">₹<?php echo esc_html( $opening_cash ); ?></div>
            <div class="tdb-preview__formula" id="tdb-formula">
                <span data-formula-type="full"><?php esc_html_e( 'Cash Sales + Opening Cash − Cash Taken Out', 'tasa-daybook' ); ?></span>
                <span data-formula-type="amount_out_only" style="display: none;"><?php esc_html_e( 'Opening Cash − Cash Taken Out', 'tasa-daybook' ); ?></span>
            </div>
            <div class="tdb-preview__label" style="margin-top: 12px;"><?php esc_html_e( 'Final Closing Amount', 'tasa-daybook' ); ?></div>
            <div class="tdb-preview__value" id="tdb-final-closing-amount" style="background-color: #d4edda; color: #155724; border-radius: 10px; padding: 10px 14px; display: inline-block;">₹<?php echo esc_html( $final_closing_amount ); ?></div>
        </div>

        <button type="submit" class="tdb-btn tdb-btn--primary">
            <span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save Record', 'tasa-daybook' ); ?>
        </button>
    </form>

    <script>
    (function() {
        'use strict';

        var form = document.getElementById('tdb-add-form');
        if (!form) return;

        var recordTypeSelector = document.getElementById('record_type');
        var openingCashField = document.getElementById('opening_cash');
        var cashSalesField = document.getElementById('cash_sales');
        var onlineSalesField = document.getElementById('online_sales');
        var cashTakenOutField = document.getElementById('cash_taken_out');
        var withdrawalTypeField = document.getElementById('withdrawal_type');
        var todaysSaleField = document.getElementById('todays_sale');
        var closingCashPreview = document.getElementById('tdb-closing-cash');
        var finalClosingAmountPreview = document.getElementById('tdb-final-closing-amount');
        var onlineBalanceCarryField = document.getElementById('online_balance_carry');
        var cashOutEnabledField = cashTakenOutField ? cashTakenOutField.closest('[data-field-type=\"cash-out-enabled\"]') : null;

        if (!closingCashPreview || !todaysSaleField || !finalClosingAmountPreview) return;

        // Toggle fields based on record type
        function toggleFields() {
            if (!recordTypeSelector) return;

            var recordType = recordTypeSelector.value;
            var fullOnlyFields = document.querySelectorAll('[data-field-type="full-only"]');
            var fullFormula = document.querySelector('[data-formula-type="full"]');
            var amountOutFormula = document.querySelector('[data-formula-type="amount_out_only"]');

            if (recordType === 'amount_out_only') {
                // Hide full record fields
                for (var i = 0; i < fullOnlyFields.length; i++) {
                    fullOnlyFields[i].style.display = 'none';
                    var input = fullOnlyFields[i].querySelector('input');
                    if (input) {
                        input.removeAttribute('required');
                        input.value = '0';
                    }
                }

                // Show cash out only formula
                if (fullFormula) fullFormula.style.display = 'none';
                if (amountOutFormula) amountOutFormula.style.display = 'inline';
                if (cashOutEnabledField) cashOutEnabledField.style.display = '';
                if (cashTakenOutField) cashTakenOutField.setAttribute('required', 'required');
            } else {
                // Show full record fields
                for (var i = 0; i < fullOnlyFields.length; i++) {
                    fullOnlyFields[i].style.display = '';
                    var input = fullOnlyFields[i].querySelector('input');
                    if (input && input.id !== 'todays_sale') {
                        input.setAttribute('required', 'required');
                    }
                }

                // Show full record formula
                if (fullFormula) fullFormula.style.display = 'inline';
                if (amountOutFormula) amountOutFormula.style.display = 'none';
                if (cashOutEnabledField) cashOutEnabledField.style.display = '';
                if (cashTakenOutField) cashTakenOutField.setAttribute('required', 'required');
            }

            updateCalculations();
        }

        function updateCalculations() {
            // Get values and handle empty/invalid inputs
            var openingValue = openingCashField ? openingCashField.value.replace(/,/g, '') : '0';
            var opening = parseFloat(openingValue) || 0;
            var cashSales = parseFloat(cashSalesField ? cashSalesField.value : '0') || 0;
            var onlineSales = parseFloat(onlineSalesField ? onlineSalesField.value : '0') || 0;
            var takenOut = parseFloat(cashTakenOutField ? cashTakenOutField.value : '0') || 0;
            var onlineBalanceCarry = parseFloat(onlineBalanceCarryField ? onlineBalanceCarryField.value : '0') || 0;
            var withdrawalType = withdrawalTypeField ? withdrawalTypeField.value : 'cash';

            var recordType = recordTypeSelector ? recordTypeSelector.value : 'full';
            if (recordType === 'amount_out_only') {
                cashSales = 0;
                onlineSales = 0;
            }

            var todaysSale = cashSales + onlineSales;
            todaysSaleField.value = todaysSale.toFixed(2);

            var effectiveCashTakenOut = withdrawalType === 'cash' ? takenOut : 0;
            var effectiveOnlineTakenOut = withdrawalType === 'online' ? takenOut : 0;

            // Closing cash tracks physical cash only.
            var closingCash = cashSales + opening - effectiveCashTakenOut;

            // Update Closing Cash preview
            closingCashPreview.textContent = '₹' + closingCash.toFixed(2);
            closingCashPreview.style.color = '#1a1a1a';

            // Final closing amount includes online side as well.
            var finalClosingAmount = closingCash + onlineBalanceCarry + onlineSales - effectiveOnlineTakenOut;
            finalClosingAmountPreview.textContent = '₹' + finalClosingAmount.toFixed(2);
        }

        // Attach event listener to record type selector
        if (recordTypeSelector) {
            recordTypeSelector.addEventListener('change', toggleFields);
        }

        // Attach event listeners to input fields
        if (cashSalesField) {
            cashSalesField.addEventListener('input', updateCalculations);
            cashSalesField.addEventListener('change', updateCalculations);
        }
        if (onlineSalesField) {
            onlineSalesField.addEventListener('input', updateCalculations);
            onlineSalesField.addEventListener('change', updateCalculations);
        }
        if (cashTakenOutField) {
            cashTakenOutField.addEventListener('input', updateCalculations);
            cashTakenOutField.addEventListener('change', updateCalculations);
        }
        if (withdrawalTypeField) {
            withdrawalTypeField.addEventListener('change', updateCalculations);
        }

        // Initial setup
        toggleFields();
        updateCalculations();
    })();
    </script>
    <?php
    echo '</div>';
}

/* ─────────────────────────────────────────────
 * Render: Edit-record form (admin only)
 * ───────────────────────────────────────────── */
function tasa_daybook_render_edit_form() {
    global $wpdb;
    $table = tasa_daybook_table();
    $id    = absint( $_GET['record_id'] ?? 0 );

    $record = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d", $id
    ) );

    if ( ! $record ) {
        echo '<div class="tdb-alert tdb-alert--error"><span class="dashicons dashicons-warning"></span>';
        echo esc_html__( 'Record not found.', 'tasa-daybook' ) . '</div>';
        return;
    }

    // Get most recent record's closing cash for opening cash (excluding the record being edited)
    // This includes records from the same day (for multiple admin entries) and previous days
    $previous_record = $wpdb->get_row( $wpdb->prepare(
        "SELECT closing_cash FROM {$table} WHERE id < %d ORDER BY id DESC LIMIT 1", $id
    ) );
    $opening_cash = $previous_record ? number_format( (float) $previous_record->closing_cash, 2 ) : '0.00';
    $previous_day_online_total = tasa_daybook_get_previous_day_online_total( (string) $record->record_date );
    $total_opening_amount = number_format( (float) str_replace( ',', '', $opening_cash ) + $previous_day_online_total, 2 );
    $record_withdrawal_type = isset( $record->withdrawal_type ) && in_array( $record->withdrawal_type, array( 'cash', 'online' ), true )
        ? $record->withdrawal_type
        : 'cash';

    // Calculate Today's Sale for display
    $todays_sale = number_format( (float) $record->cash_sales + (float) $record->online_payments, 2 );

    $back_url = admin_url( 'tools.php?page=tasa-daybook' );
    ?>
    <div class="tdb-card">
        <div class="tdb-card__header">
            <span class="dashicons dashicons-edit tdb-card__icon"></span>
            <h2 class="tdb-card__title"><?php esc_html_e( 'Edit Record', 'tasa-daybook' ); ?> &mdash; <?php echo esc_html( $record->record_date ); ?></h2>
        </div>
        <form method="post" class="tdb-form" id="tdb-edit-form">
            <?php wp_nonce_field( 'tasa_cc_edit_nonce', '_tasa_cc_nonce' ); ?>
            <input type="hidden" name="tasa_cc_action" value="edit">
            <input type="hidden" name="record_id"      value="<?php echo esc_attr( $record->id ); ?>">

            <div class="tdb-grid">
                <div class="tdb-field">
                    <label for="opening_cash"><span class="dashicons dashicons-vault"></span> <?php esc_html_e( 'Opening Cash', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="text" id="opening_cash" name="opening_cash"
                               value="<?php echo esc_attr( $opening_cash ); ?>" readonly class="tdb-input" style="background-color: #f0f0f1; cursor: not-allowed;">
                    </div>
                </div>
                <div class="tdb-field">
                    <label for="total_opening_amount"><span class="dashicons dashicons-calculator"></span> <?php esc_html_e( 'Total Opening Amount (Online + Cash)', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="text" id="total_opening_amount" name="total_opening_amount"
                               value="<?php echo esc_attr( $total_opening_amount ); ?>" readonly class="tdb-input" style="background-color: #f0f0f1; cursor: not-allowed;">
                    </div>
                </div>
                <div class="tdb-field">
                    <label for="cash_sales"><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Cash Sales', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="number" step="0.01" min="0" id="cash_sales" name="cash_sales"
                               value="<?php echo esc_attr( $record->cash_sales ); ?>" required class="tdb-input" data-calc>
                    </div>
                </div>
                <div class="tdb-field">
                    <label for="online_sales"><span class="dashicons dashicons-smartphone"></span> <?php esc_html_e( 'Online Sales', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="number" step="0.01" min="0" id="online_sales" name="online_sales"
                               value="<?php echo esc_attr( $record->online_payments ); ?>" required class="tdb-input" data-calc>
                    </div>
                </div>
                <div class="tdb-field">
                    <label for="todays_sale"><span class="dashicons dashicons-chart-line"></span> <?php esc_html_e( 'Today\'s Sale', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="text" id="todays_sale" name="todays_sale"
                               value="<?php echo esc_attr( $todays_sale ); ?>" readonly class="tdb-input" style="background-color: #f0f0f1; cursor: not-allowed;">
                    </div>
                </div>
                <div class="tdb-field">
                    <label for="cash_taken_out"><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( 'Amound Taken Out', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="number" step="0.01" min="0" id="cash_taken_out" name="cash_taken_out"
                               value="<?php echo esc_attr( $record->cash_taken_out ); ?>" required class="tdb-input" data-calc>
                    </div>
                </div>
                <div class="tdb-field">
                    <label for="withdrawal_type"><span class="dashicons dashicons-randomize"></span> <?php esc_html_e( 'Withdrawal Type', 'tasa-daybook' ); ?></label>
                    <select id="withdrawal_type" name="withdrawal_type" class="tdb-input">
                        <option value="cash" <?php selected( $record_withdrawal_type, 'cash' ); ?>><?php esc_html_e( 'Cash', 'tasa-daybook' ); ?></option>
                        <option value="online" <?php selected( $record_withdrawal_type, 'online' ); ?>><?php esc_html_e( 'Online Transfer', 'tasa-daybook' ); ?></option>
                    </select>
                </div>
                <div class="tdb-field tdb-field--full">
                    <label for="note"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Note', 'tasa-daybook' ); ?></label>
                    <textarea id="note" name="note" rows="3" class="tdb-input tdb-input--textarea" placeholder="<?php esc_attr_e( 'Add a note for this record (optional)', 'tasa-daybook' ); ?>"><?php echo esc_textarea( $record->note ?? '' ); ?></textarea>
                </div>
            </div>

            <div class="tdb-preview">
                <div class="tdb-preview__label"><?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?></div>
                <div class="tdb-preview__value" id="tdb-closing-cash">₹<?php echo esc_html( number_format( (float) $record->closing_cash, 2 ) ); ?></div>
                <div class="tdb-preview__formula"><?php esc_html_e( 'Cash Sales + Opening Cash − Cash Taken Out', 'tasa-daybook' ); ?></div>
            </div>

            <div class="tdb-btn-group">
                <button type="submit" class="tdb-btn tdb-btn--primary">
                    <span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Update Record', 'tasa-daybook' ); ?>
                </button>
                <a href="<?php echo esc_url( $back_url ); ?>" class="tdb-btn tdb-btn--ghost">
                    <span class="dashicons dashicons-no-alt"></span> <?php esc_html_e( 'Cancel', 'tasa-daybook' ); ?>
                </a>
            </div>
        </form>

        <script>
        (function() {
            'use strict';

            var form = document.getElementById('tdb-edit-form');
            if (!form) return;

            var openingCashField = document.getElementById('opening_cash');
            var cashSalesField = document.getElementById('cash_sales');
            var onlineSalesField = document.getElementById('online_sales');
            var cashTakenOutField = document.getElementById('cash_taken_out');
            var withdrawalTypeField = document.getElementById('withdrawal_type');
            var todaysSaleField = document.getElementById('todays_sale');
            var closingCashPreview = document.getElementById('tdb-closing-cash');

            if (!closingCashPreview || !todaysSaleField) return;

            function updateCalculations() {
                // Get values and handle empty/invalid inputs
                var openingValue = openingCashField ? openingCashField.value.replace(/,/g, '') : '0';
                var opening = parseFloat(openingValue) || 0;
                var cashSales = parseFloat(cashSalesField ? cashSalesField.value : '0') || 0;
                var onlineSales = parseFloat(onlineSalesField ? onlineSalesField.value : '0') || 0;
                var takenOut = parseFloat(cashTakenOutField ? cashTakenOutField.value : '0') || 0;
                var withdrawalType = withdrawalTypeField ? withdrawalTypeField.value : 'cash';

                // Calculate Today's Sale = Cash Sales + Online Sales
                var todaysSale = cashSales + onlineSales;

                var effectiveCashTakenOut = withdrawalType === 'cash' ? takenOut : 0;

                // Closing cash tracks physical cash only.
                var closingCash = cashSales + opening - effectiveCashTakenOut;

                // Update Today's Sale field
                todaysSaleField.value = todaysSale.toFixed(2);

                // Update Closing Cash preview
                closingCashPreview.textContent = '₹' + closingCash.toFixed(2);
                closingCashPreview.style.color = '#1a1a1a';
            }

            // Attach event listeners to input fields
            if (cashSalesField) {
                cashSalesField.addEventListener('input', updateCalculations);
                cashSalesField.addEventListener('change', updateCalculations);
            }
            if (onlineSalesField) {
                onlineSalesField.addEventListener('input', updateCalculations);
                onlineSalesField.addEventListener('change', updateCalculations);
            }
            if (cashTakenOutField) {
                cashTakenOutField.addEventListener('input', updateCalculations);
                cashTakenOutField.addEventListener('change', updateCalculations);
            }
            if (withdrawalTypeField) {
                withdrawalTypeField.addEventListener('change', updateCalculations);
            }

            // Initial calculation
            updateCalculations();
        })();
        </script>
    </div>
    <?php
}

/* ─────────────────────────────────────────────
 * Render: Records table
 * ───────────────────────────────────────────── */
function tasa_daybook_render_records_table() {
    global $wpdb;
    $table   = tasa_daybook_table();
    $records = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY record_date DESC, id DESC" );
    $is_admin = current_user_can( 'manage_options' );

    echo '<div class="tasa-cc-card">';
    echo '<div class="tasa-cc-table-header">';
    echo '<h2 class="tasa-cc-table-title">' . esc_html__( 'All Records', 'tasa-daybook' ) . '</h2>';
    if ( ! empty( $records ) ) {
        echo '<button type="button" class="button button-primary tasa-cc-export-toggle" id="tasa-cc-export-toggle"><span class="dashicons dashicons-download"></span>' . esc_html__( 'Download CSV', 'tasa-daybook' ) . '</button>';
    }
    echo '</div>';

    if ( empty( $records ) ) {
        echo '<p>' . esc_html__( 'No records found yet.', 'tasa-daybook' ) . '</p>';
        echo '</div>';
        return;
    }

    // Build a running online balance by record id for final combined closing.
    $online_balance_by_id = array();
    $online_running_total = 0.00;
    $online_rows = $wpdb->get_results( "SELECT id, online_payments, online_taken_out FROM {$table} ORDER BY id ASC" );
    foreach ( $online_rows as $online_row ) {
        $online_running_total += (float) $online_row->online_payments - (float) $online_row->online_taken_out;
        $online_balance_by_id[ (int) $online_row->id ] = $online_running_total;
    }

    $filter_mode        = sanitize_text_field( wp_unslash( $_GET['export_mode'] ?? 'today' ) );
    if ( ! in_array( $filter_mode, array( 'today', 'tomorrow', 'single', 'range' ), true ) ) {
        $filter_mode = 'today';
    }
    $filter_single_date = sanitize_text_field( wp_unslash( $_GET['single_date'] ?? '' ) );
    $filter_start_date  = sanitize_text_field( wp_unslash( $_GET['start_date'] ?? '' ) );
    $filter_end_date    = sanitize_text_field( wp_unslash( $_GET['end_date'] ?? '' ) );

    ?>
    <form method="get" class="tasa-cc-export-form" id="tasa-cc-export-form" hidden>
        <input type="hidden" name="page" value="tasa-daybook">
        <input type="hidden" name="tasa_cc_action" value="export_csv">
        <?php wp_nonce_field( 'tasa_cc_export_csv', '_tasa_cc_nonce' ); ?>
        <div class="tasa-cc-export-fields">
            <div class="tasa-cc-export-field">
                <label for="tasa-cc-export-mode"><?php esc_html_e( 'Export Type', 'tasa-daybook' ); ?></label>
                <select id="tasa-cc-export-mode" name="export_mode">
                    <option value="today" <?php selected( $filter_mode, 'today' ); ?>><?php esc_html_e( 'Today', 'tasa-daybook' ); ?></option>
                    <option value="tomorrow" <?php selected( $filter_mode, 'tomorrow' ); ?>><?php esc_html_e( 'Tomorrow', 'tasa-daybook' ); ?></option>
                    <option value="single" <?php selected( $filter_mode, 'single' ); ?>><?php esc_html_e( 'Single Date', 'tasa-daybook' ); ?></option>
                    <option value="range" <?php selected( $filter_mode, 'range' ); ?>><?php esc_html_e( 'Date Range', 'tasa-daybook' ); ?></option>
                </select>
            </div>
            <div class="tasa-cc-export-field" id="tasa-cc-single-wrap">
                <label for="tasa-cc-single-date"><?php esc_html_e( 'Single Date', 'tasa-daybook' ); ?></label>
                <input type="date" id="tasa-cc-single-date" name="single_date" value="<?php echo esc_attr( $filter_single_date ); ?>">
            </div>
            <div class="tasa-cc-export-field" id="tasa-cc-range-start-wrap">
                <label for="tasa-cc-start-date"><?php esc_html_e( 'Start Date', 'tasa-daybook' ); ?></label>
                <input type="date" id="tasa-cc-start-date" name="start_date" value="<?php echo esc_attr( $filter_start_date ); ?>">
            </div>
            <div class="tasa-cc-export-field" id="tasa-cc-range-end-wrap">
                <label for="tasa-cc-end-date"><?php esc_html_e( 'End Date', 'tasa-daybook' ); ?></label>
                <input type="date" id="tasa-cc-end-date" name="end_date" value="<?php echo esc_attr( $filter_end_date ); ?>">
            </div>
        </div>
        <p class="tasa-cc-export-help"><?php esc_html_e( 'Select Today, Tomorrow, Single Date, or Date Range.', 'tasa-daybook' ); ?></p>
        <div class="tasa-cc-export-actions">
            <button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Download', 'tasa-daybook' ); ?></button>
            <button type="button" class="button button-secondary button-small" id="tasa-cc-export-cancel"><?php esc_html_e( 'Cancel', 'tasa-daybook' ); ?></button>
        </div>
    </form>
    <div class="tasa-cc-table-wrap">
    <table class="widefat striped tasa-cc-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Date', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Submitted By', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Opening Cash', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Cash Sales', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Online Sales', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Withdrawn Amount', 'tasa-daybook' ); ?></th>
                <th class="tasa-cc-note-col"><?php esc_html_e( 'Note', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Final Closing Amount', 'tasa-daybook' ); ?></th>
                <?php if ( $is_admin ) : ?>
                    <th><?php esc_html_e( 'Actions', 'tasa-daybook' ); ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $records as $row ) :
            $user_info = tasa_daybook_get_record_user_info( (int) $row->created_by );

            // Detect "Amount Out Only" records (where both cash_sales and online_payments are 0, but there's a withdrawal)
            $cash_taken_out = floatval( $row->cash_taken_out );
            $is_amount_out_only = ( floatval( $row->cash_sales ) === 0.0 && floatval( $row->online_payments ) === 0.0 && $cash_taken_out > 0.0 );

            $withdrawal_type = isset( $row->withdrawal_type ) && in_array( $row->withdrawal_type, array( 'cash', 'online' ), true )
                ? $row->withdrawal_type
                : 'cash';
            $withdrawal_label = $withdrawal_type === 'online' ? __( 'Online', 'tasa-daybook' ) : __( 'Cash', 'tasa-daybook' );
            $withdrawal_amount = $cash_taken_out;

            $online_balance = isset( $online_balance_by_id[ (int) $row->id ] ) ? (float) $online_balance_by_id[ (int) $row->id ] : floatval( $row->online_payments );
            $final_closing_amount = floatval( $row->closing_cash ) + $online_balance;
        ?>
            <tr>
                <td>
                    <?php echo esc_html( $row->record_date ); ?>
                    <?php if ( $is_amount_out_only ) : ?>
                        <span style="display: inline-block; margin-left: 8px; padding: 2px 8px; background-color: #f0f6fc; color: #0969da; border: 1px solid #0969da; border-radius: 12px; font-size: 11px; font-weight: 600; vertical-align: middle;">
                            <?php esc_html_e( 'AMOUNT OUT', 'tasa-daybook' ); ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $user_info ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->opening_cash, 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->cash_sales, 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->online_payments, 2 ) ); ?></td>
                <td>
                    <?php if ( $is_admin ) : ?>
                        <?php echo esc_html( '₹' . number_format( $withdrawal_amount, 2 ) . ' (' . $withdrawal_label . ')' ); ?>
                    <?php else : ?>
                        <?php echo esc_html( '₹' . number_format( $withdrawal_amount, 2 ) ); ?>
                    <?php endif; ?>
                </td>
                <td class="tasa-cc-note">
                    <?php if ( '' !== trim( (string) ( $row->note ?? '' ) ) ) : ?>
                        <button type="button" class="button button-small tasa-cc-view-note" data-note="<?php echo esc_attr( $row->note ); ?>">
                            <?php esc_html_e( 'View', 'tasa-daybook' ); ?>
                        </button>
                    <?php else : ?>
                        -
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( number_format( (float) $row->closing_cash, 2 ) ); ?></td>
                <td><?php echo esc_html( '₹' . number_format( $final_closing_amount, 2 ) ); ?></td>
                <?php if ( $is_admin ) : ?>
                <td class="tasa-cc-actions">
                    <a href="<?php echo esc_url( admin_url( 'tools.php?page=tasa-daybook&cc_action=edit&record_id=' . $row->id ) ); ?>"
                       class="button button-small"><?php esc_html_e( 'Edit', 'tasa-daybook' ); ?></a>

                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'Delete this record?', 'tasa-daybook' ); ?>');">
                        <?php wp_nonce_field( 'tasa_cc_delete_nonce', '_tasa_cc_nonce' ); ?>
                        <input type="hidden" name="tasa_cc_action" value="delete">
                        <input type="hidden" name="record_id"      value="<?php echo esc_attr( $row->id ); ?>">
                        <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'tasa-daybook' ); ?></button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div id="tasa-cc-note-modal" class="tasa-cc-note-modal" hidden>
        <div class="tasa-cc-note-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="tasa-cc-note-modal-title">
            <div class="tasa-cc-note-modal__header">
                <h3 id="tasa-cc-note-modal-title"><?php esc_html_e( 'Note', 'tasa-daybook' ); ?></h3>
                <button type="button" class="tasa-cc-note-modal__close" data-note-close aria-label="<?php esc_attr_e( 'Close', 'tasa-daybook' ); ?>">&times;</button>
            </div>
            <div class="tasa-cc-note-modal__content" id="tasa-cc-note-modal-content"></div>
        </div>
    </div>
    <script>
    (function() {
        'use strict';

        var modal = document.getElementById('tasa-cc-note-modal');
        var content = document.getElementById('tasa-cc-note-modal-content');
        var exportToggle = document.getElementById('tasa-cc-export-toggle');
        var exportForm = document.getElementById('tasa-cc-export-form');
        var exportCancel = document.getElementById('tasa-cc-export-cancel');
        var exportModeField = document.getElementById('tasa-cc-export-mode');
        var singleDateField = document.getElementById('tasa-cc-single-date');
        var startDateField = document.getElementById('tasa-cc-start-date');
        var endDateField = document.getElementById('tasa-cc-end-date');
        var singleDateWrap = document.getElementById('tasa-cc-single-wrap');
        var rangeStartWrap = document.getElementById('tasa-cc-range-start-wrap');
        var rangeEndWrap = document.getElementById('tasa-cc-range-end-wrap');

        if (!modal || !content) {
            return;
        }

        if (exportToggle && exportForm) {
            exportToggle.addEventListener('click', function() {
                if (exportForm.hasAttribute('hidden')) {
                    exportForm.removeAttribute('hidden');
                } else {
                    exportForm.setAttribute('hidden', 'hidden');
                }
            });
        }

        if (exportCancel && exportForm) {
            exportCancel.addEventListener('click', function() {
                exportForm.setAttribute('hidden', 'hidden');
            });
        }

        function updateExportModeFields() {
            if (!exportModeField || !singleDateField || !startDateField || !endDateField || !singleDateWrap || !rangeStartWrap || !rangeEndWrap) {
                return;
            }

            var mode = exportModeField.value;
            var isSingle = mode === 'single';
            var isRange = mode === 'range';

            singleDateWrap.style.display = isSingle ? '' : 'none';
            rangeStartWrap.style.display = isRange ? '' : 'none';
            rangeEndWrap.style.display = isRange ? '' : 'none';

            singleDateField.disabled = !isSingle;
            startDateField.disabled = !isRange;
            endDateField.disabled = !isRange;
            singleDateField.required = isSingle;
            startDateField.required = isRange;
            endDateField.required = isRange;

            if (!isSingle) {
                singleDateField.value = '';
            }

            if (!isRange) {
                startDateField.value = '';
                endDateField.value = '';
            }
        }

        if (exportModeField) {
            exportModeField.addEventListener('change', updateExportModeFields);
            updateExportModeFields();
        }

        function closeModal() {
            modal.setAttribute('hidden', 'hidden');
            document.body.classList.remove('tasa-cc-modal-open');
            content.textContent = '';
        }

        function openModal(noteText) {
            content.textContent = noteText || '';
            modal.removeAttribute('hidden');
            document.body.classList.add('tasa-cc-modal-open');
        }

        document.addEventListener('click', function(event) {
            var button = event.target.closest('.tasa-cc-view-note');
            if (button) {
                event.preventDefault();
                openModal(button.getAttribute('data-note') || '');
                return;
            }

            if (event.target.matches('[data-note-close]') || event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && !modal.hasAttribute('hidden')) {
                closeModal();
            }
        });
    })();
    </script>
    <?php
    echo '</div>';
}
