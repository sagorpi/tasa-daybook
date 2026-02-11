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

    // For "Cash Out Only" records, set sales to 0
    if ( $record_type === 'cash_out_only' && $is_admin ) {
        $cash_sales     = 0.00;
        $online_sales   = 0.00;
    } else {
        $cash_sales     = floatval( sanitize_text_field( wp_unslash( $_POST['cash_sales'] ?? '0' ) ) );
        $online_sales   = floatval( sanitize_text_field( wp_unslash( $_POST['online_sales'] ?? '0' ) ) );
    }

    $cash_taken_out  = floatval( sanitize_text_field( wp_unslash( $_POST['cash_taken_out'] ?? '0' ) ) );
    $note            = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );

    // Get most recent record's closing cash for opening cash
    // This includes records from the same day (for multiple admin entries) and previous days
    $previous_record = $wpdb->get_row( $wpdb->prepare(
        "SELECT closing_cash FROM {$table} WHERE record_date <= %s ORDER BY id DESC LIMIT 1", $today
    ) );
    $opening_cash = $previous_record ? floatval( $previous_record->closing_cash ) : 0.00;

    // Calculate closing cash: Today's Sale + Opening Cash - Cash Taken Out
    // Where Today's Sale = Cash Sales + Online Sales
    $todays_sale     = $cash_sales + $online_sales;
    $closing_cash    = $todays_sale + $opening_cash - $cash_taken_out;

    // Expected = opening + cash_sales - cash_taken_out
    $expected        = $opening_cash + $cash_sales - $cash_taken_out;
    $calculated_diff = $closing_cash - $expected;

    $result = $wpdb->insert(
        $table,
        array(
            'record_date'     => $today,
            'opening_cash'    => $opening_cash,
            'cash_sales'      => $cash_sales,
            'online_payments' => $online_sales,  // Note: DB column is still 'online_payments'
            'cash_taken_out'  => $cash_taken_out,
            'closing_cash'    => $closing_cash,
            'calculated_diff' => $calculated_diff,
            'note'            => $note,
            'created_by'      => get_current_user_id(),
        ),
        array( '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%d' )
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

    // Calculate closing cash: Today's Sale + Opening Cash - Cash Taken Out
    // Where Today's Sale = Cash Sales + Online Sales
    $todays_sale     = $cash_sales + $online_sales;
    $closing_cash    = $todays_sale + $opening_cash - $cash_taken_out;

    $expected        = $opening_cash + $cash_sales - $cash_taken_out;
    $calculated_diff = $closing_cash - $expected;

    $wpdb->update(
        $table,
        array(
            'opening_cash'    => $opening_cash,
            'cash_sales'      => $cash_sales,
            'online_payments' => $online_sales,  // Note: DB column is still 'online_payments'
            'cash_taken_out'  => $cash_taken_out,
            'closing_cash'    => $closing_cash,
            'calculated_diff' => $calculated_diff,
            'note'            => $note,
        ),
        array( 'id' => $id ),
        array( '%f', '%f', '%f', '%f', '%f', '%f', '%s' ),
        array( '%d' )
    );

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
        $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
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

    ?>
    <form method="post" class="tdb-form" id="tdb-add-form">
        <?php wp_nonce_field( 'tasa_cc_add_nonce', '_tasa_cc_nonce' ); ?>
        <input type="hidden" name="tasa_cc_action" value="add">

        <?php if ( $is_admin ) : ?>
        <div class="tdb-field" style="margin-bottom: 20px;">
            <label for="record_type"><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Record Type', 'tasa-daybook' ); ?> <span style="color: #d63638; font-size: 11px; font-weight: normal;">(<?php esc_html_e( 'Admin Only', 'tasa-daybook' ); ?>)</span></label>
            <select id="record_type" name="record_type" class="tdb-input" style="width: 100%; max-width: 300px;">
                <option value="full"><?php esc_html_e( 'Full Record', 'tasa-daybook' ); ?></option>
                <option value="cash_out_only"><?php esc_html_e( 'Cash Out Only', 'tasa-daybook' ); ?></option>
            </select>
            <p style="margin: 8px 0 0 0; font-size: 13px; color: #646970;">
                <?php esc_html_e( 'Select "Cash Out Only" to record only cash withdrawals without sales data.', 'tasa-daybook' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <div class="tdb-grid">
            <div class="tdb-field">
                <label for="opening_cash"><span class="dashicons dashicons-vault"></span> <?php esc_html_e( 'Opening Cash', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">₹</span>
                    <input type="text" id="opening_cash" name="opening_cash" value="<?php echo esc_attr( $opening_cash ); ?>" readonly class="tdb-input" style="background-color: #f0f0f1; cursor: not-allowed;">
                </div>
            </div>
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
            <div class="tdb-field">
                <label for="cash_taken_out"><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( 'Cash Taken Out', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">₹</span>
                    <input type="number" step="0.01" min="0" id="cash_taken_out" name="cash_taken_out" placeholder="0.00" required class="tdb-input" data-calc>
                </div>
            </div>
            <div class="tdb-field tdb-field--full">
                <label for="note"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Note', 'tasa-daybook' ); ?></label>
                <textarea id="note" name="note" rows="3" class="tdb-input tdb-input--textarea" placeholder="<?php esc_attr_e( 'Add a note for this record (optional)', 'tasa-daybook' ); ?>"></textarea>
            </div>
        </div>

        <div class="tdb-preview">
            <div class="tdb-preview__label"><?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?></div>
            <div class="tdb-preview__value" id="tdb-closing-cash">₹<?php echo esc_html( $opening_cash ); ?></div>
            <div class="tdb-preview__formula" id="tdb-formula">
                <span data-formula-type="full"><?php esc_html_e( 'Today\'s Sale + Opening Cash − Cash Taken Out', 'tasa-daybook' ); ?></span>
                <span data-formula-type="cash_out_only" style="display: none;"><?php esc_html_e( 'Opening Cash − Cash Taken Out', 'tasa-daybook' ); ?></span>
            </div>
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
        var todaysSaleField = document.getElementById('todays_sale');
        var closingCashPreview = document.getElementById('tdb-closing-cash');

        if (!closingCashPreview || !todaysSaleField) return;

        // Toggle fields based on record type
        function toggleFields() {
            if (!recordTypeSelector) return;

            var recordType = recordTypeSelector.value;
            var fullOnlyFields = document.querySelectorAll('[data-field-type="full-only"]');
            var fullFormula = document.querySelector('[data-formula-type="full"]');
            var cashOutFormula = document.querySelector('[data-formula-type="cash_out_only"]');

            if (recordType === 'cash_out_only') {
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
                if (cashOutFormula) cashOutFormula.style.display = 'inline';
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
                if (cashOutFormula) cashOutFormula.style.display = 'none';
            }

            updateCalculations();
        }

        function updateCalculations() {
            // Get values and handle empty/invalid inputs
            var openingValue = openingCashField ? openingCashField.value.replace(/,/g, '') : '0';
            var opening = parseFloat(openingValue) || 0;
            var takenOut = parseFloat(cashTakenOutField ? cashTakenOutField.value : '0') || 0;

            var recordType = recordTypeSelector ? recordTypeSelector.value : 'full';
            var closingCash;

            if (recordType === 'cash_out_only') {
                // Cash Out Only: Closing Cash = Opening Cash - Cash Taken Out
                closingCash = opening - takenOut;
            } else {
                // Full Record: Closing Cash = Today's Sale + Opening Cash - Cash Taken Out
                var cashSales = parseFloat(cashSalesField ? cashSalesField.value : '0') || 0;
                var onlineSales = parseFloat(onlineSalesField ? onlineSalesField.value : '0') || 0;
                var todaysSale = cashSales + onlineSales;

                // Update Today's Sale field
                todaysSaleField.value = todaysSale.toFixed(2);

                closingCash = todaysSale + opening - takenOut;
            }

            // Update Closing Cash preview
            closingCashPreview.textContent = '₹' + closingCash.toFixed(2);
            closingCashPreview.style.color = '#1a1a1a';
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
                    <label for="cash_taken_out"><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( 'Cash Taken Out', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="number" step="0.01" min="0" id="cash_taken_out" name="cash_taken_out"
                               value="<?php echo esc_attr( $record->cash_taken_out ); ?>" required class="tdb-input" data-calc>
                    </div>
                </div>
                <div class="tdb-field tdb-field--full">
                    <label for="note"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Note', 'tasa-daybook' ); ?></label>
                    <textarea id="note" name="note" rows="3" class="tdb-input tdb-input--textarea" placeholder="<?php esc_attr_e( 'Add a note for this record (optional)', 'tasa-daybook' ); ?>"><?php echo esc_textarea( $record->note ?? '' ); ?></textarea>
                </div>
            </div>

            <div class="tdb-preview">
                <div class="tdb-preview__label"><?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?></div>
                <div class="tdb-preview__value" id="tdb-closing-cash">₹<?php echo esc_html( number_format( (float) $record->closing_cash, 2 ) ); ?></div>
                <div class="tdb-preview__formula"><?php esc_html_e( 'Today\'s Sale + Opening Cash − Cash Taken Out', 'tasa-daybook' ); ?></div>
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

                // Calculate Today's Sale = Cash Sales + Online Sales
                var todaysSale = cashSales + onlineSales;

                // Calculate Closing Cash = Today's Sale + Opening Cash - Cash Taken Out
                var closingCash = todaysSale + opening - takenOut;

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
    echo '<h2>' . esc_html__( 'All Records', 'tasa-daybook' ) . '</h2>';

    if ( empty( $records ) ) {
        echo '<p>' . esc_html__( 'No records found yet.', 'tasa-daybook' ) . '</p>';
        echo '</div>';
        return;
    }

    ?>
    <div class="tasa-cc-table-wrap">
    <table class="widefat striped tasa-cc-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Date', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Submitted By', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Opening Cash', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Cash Sales', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Online Sales', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Cash Taken Out', 'tasa-daybook' ); ?></th>
                <th class="tasa-cc-note-col"><?php esc_html_e( 'Note', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?></th>
                <?php if ( $is_admin ) : ?>
                    <th><?php esc_html_e( 'Actions', 'tasa-daybook' ); ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $records as $row ) :
            // Get user information
            $user_info = '';
            if ( $row->created_by ) {
                $user = get_userdata( $row->created_by );
                if ( $user ) {
                    $display_name = $user->display_name;
                    $user_roles = $user->roles;
                    $role_label = '';

                    if ( in_array( 'administrator', $user_roles, true ) ) {
                        $role_label = __( 'Administrator', 'tasa-daybook' );
                    } elseif ( in_array( 'shop_manager', $user_roles, true ) ) {
                        $role_label = __( 'Shop Manager', 'tasa-daybook' );
                    }

                    $user_info = $display_name;
                    if ( $role_label ) {
                        $user_info .= ' (' . $role_label . ')';
                    }
                } else {
                    $user_info = __( 'Unknown User', 'tasa-daybook' );
                }
            } else {
                $user_info = __( 'System', 'tasa-daybook' );
            }

            // Detect "Cash Out Only" records (where both cash_sales and online_payments are 0)
            $is_cash_out_only = ( floatval( $row->cash_sales ) === 0.0 && floatval( $row->online_payments ) === 0.0 && floatval( $row->cash_taken_out ) > 0.0 );
        ?>
            <tr>
                <td>
                    <?php echo esc_html( $row->record_date ); ?>
                    <?php if ( $is_cash_out_only ) : ?>
                        <span style="display: inline-block; margin-left: 8px; padding: 2px 8px; background-color: #f0f6fc; color: #0969da; border: 1px solid #0969da; border-radius: 12px; font-size: 11px; font-weight: 600; vertical-align: middle;">
                            <?php esc_html_e( 'CASH OUT', 'tasa-daybook' ); ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $user_info ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->opening_cash, 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->cash_sales, 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->online_payments, 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->cash_taken_out, 2 ) ); ?></td>
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

        if (!modal || !content) {
            return;
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
