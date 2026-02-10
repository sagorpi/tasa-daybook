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
        'read',                       // any logged-in user can view
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
 * Enqueue admin scripts
 * ───────────────────────────────────────────── */
function tasa_daybook_admin_scripts( $hook ) {
    if ( 'tools_page_tasa-daybook' !== $hook ) {
        return;
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = document.querySelectorAll('[data-calc]');
        const preview = document.getElementById('tdb-live-diff');
        
        if (!preview) return;
        
        function updatePreview() {
            const opening = parseFloat(document.getElementById('opening_cash')?.value || 0);
            const sales = parseFloat(document.getElementById('cash_sales')?.value || 0);
            const takenOut = parseFloat(document.getElementById('cash_taken_out')?.value || 0);
            const closing = parseFloat(document.getElementById('closing_cash')?.value || 0);
            
            const expected = opening + sales - takenOut;
            const diff = closing - expected;
            
            preview.textContent = '$' + diff.toFixed(2);
            preview.style.color = diff > 0 ? '#00a32a' : (diff < 0 ? '#d63638' : '#1a1a1a');
        }
        
        inputs.forEach(input => {
            input.addEventListener('input', updatePreview);
        });
        
        updatePreview();
    });
    </script>
    <?php
}
add_action( 'admin_footer', 'tasa_daybook_admin_scripts' );

/* ─────────────────────────────────────────────
 * Router – handle POST actions then render
 * ───────────────────────────────────────────── */
function tasa_daybook_render_page() {
    // Process POST actions before any output
    tasa_daybook_handle_post();

    $is_admin = current_user_can( 'manage_options' );
    $today    = current_time( 'Y-m-d' );
    $day_name = wp_date( 'l, F j, Y' );
    $role_label = $is_admin ? __( 'Administrator', 'tasa-daybook' ) : __( 'Staff', 'tasa-daybook' );
    $role_class = $is_admin ? 'tdb-badge--admin' : 'tdb-badge--staff';

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

    // Check if a record already exists for today
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE record_date = %s", $today
    ) );

    if ( $exists ) {
        add_settings_error( 'tasa_cc', 'duplicate', __( 'A record for today already exists.', 'tasa-daybook' ), 'error' );
        return;
    }

    $opening_cash    = floatval( sanitize_text_field( wp_unslash( $_POST['opening_cash'] ?? '0' ) ) );
    $cash_sales      = floatval( sanitize_text_field( wp_unslash( $_POST['cash_sales'] ?? '0' ) ) );
    $online_payments = floatval( sanitize_text_field( wp_unslash( $_POST['online_payments'] ?? '0' ) ) );
    $cash_taken_out  = floatval( sanitize_text_field( wp_unslash( $_POST['cash_taken_out'] ?? '0' ) ) );
    $closing_cash    = floatval( sanitize_text_field( wp_unslash( $_POST['closing_cash'] ?? '0' ) ) );

    // Expected = opening + cash_sales - cash_taken_out
    $expected        = $opening_cash + $cash_sales - $cash_taken_out;
    $calculated_diff = $closing_cash - $expected;

    $result = $wpdb->insert(
        $table,
        array(
            'record_date'     => $today,
            'opening_cash'    => $opening_cash,
            'cash_sales'      => $cash_sales,
            'online_payments' => $online_payments,
            'cash_taken_out'  => $cash_taken_out,
            'closing_cash'    => $closing_cash,
            'calculated_diff' => $calculated_diff,
            'created_by'      => get_current_user_id(),
        ),
        array( '%s', '%f', '%f', '%f', '%f', '%f', '%f', '%d' )
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

    $opening_cash    = floatval( sanitize_text_field( wp_unslash( $_POST['opening_cash'] ?? '0' ) ) );
    $cash_sales      = floatval( sanitize_text_field( wp_unslash( $_POST['cash_sales'] ?? '0' ) ) );
    $online_payments = floatval( sanitize_text_field( wp_unslash( $_POST['online_payments'] ?? '0' ) ) );
    $cash_taken_out  = floatval( sanitize_text_field( wp_unslash( $_POST['cash_taken_out'] ?? '0' ) ) );
    $closing_cash    = floatval( sanitize_text_field( wp_unslash( $_POST['closing_cash'] ?? '0' ) ) );

    $expected        = $opening_cash + $cash_sales - $cash_taken_out;
    $calculated_diff = $closing_cash - $expected;

    $wpdb->update(
        $table,
        array(
            'opening_cash'    => $opening_cash,
            'cash_sales'      => $cash_sales,
            'online_payments' => $online_payments,
            'cash_taken_out'  => $cash_taken_out,
            'closing_cash'    => $closing_cash,
            'calculated_diff' => $calculated_diff,
        ),
        array( 'id' => $id ),
        array( '%f', '%f', '%f', '%f', '%f', '%f' ),
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

    $already_submitted = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE record_date = %s", $today
    ) );

    settings_errors( 'tasa_cc' );

    echo '<div class="tdb-card">';
    echo '<div class="tdb-card__header"><span class="dashicons dashicons-plus-alt2 tdb-card__icon"></span>';
    echo '<h2 class="tdb-card__title">' . esc_html__( 'Add Today\'s Record', 'tasa-daybook' ) . '</h2></div>';

    if ( $already_submitted && ! current_user_can( 'manage_options' ) ) {
        echo '<div class="tdb-alert tdb-alert--warning"><span class="dashicons dashicons-lock"></span>';
        echo esc_html__( 'Today\'s record has already been submitted. Only an administrator can edit it.', 'tasa-daybook' );
        echo '</div></div>';
        return;
    } elseif ( $already_submitted ) {
        echo '<div class="tdb-alert tdb-alert--info"><span class="dashicons dashicons-info-outline"></span>';
        echo esc_html__( 'Today\'s record already exists. Use the Edit button in the table below to modify it.', 'tasa-daybook' );
        echo '</div></div>';
        return;
    }

    ?>
    <form method="post" class="tdb-form" id="tdb-add-form">
        <?php wp_nonce_field( 'tasa_cc_add_nonce', '_tasa_cc_nonce' ); ?>
        <input type="hidden" name="tasa_cc_action" value="add">

        <div class="tdb-grid">
            <div class="tdb-field">
                <label for="opening_cash"><span class="dashicons dashicons-vault"></span> <?php esc_html_e( 'Opening Cash', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">$</span>
                    <input type="number" step="0.01" min="0" id="opening_cash" name="opening_cash" value="0.00" required class="tdb-input" data-calc>
                </div>
            </div>
            <div class="tdb-field">
                <label for="cash_sales"><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Cash Sales', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">$</span>
                    <input type="number" step="0.01" min="0" id="cash_sales" name="cash_sales" value="0.00" required class="tdb-input" data-calc>
                </div>
            </div>
            <div class="tdb-field">
                <label for="online_payments"><span class="dashicons dashicons-smartphone"></span> <?php esc_html_e( 'Online Payments', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">$</span>
                    <input type="number" step="0.01" min="0" id="online_payments" name="online_payments" value="0.00" required class="tdb-input">
                </div>
            </div>
            <div class="tdb-field">
                <label for="cash_taken_out"><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( 'Cash Taken Out', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">$</span>
                    <input type="number" step="0.01" min="0" id="cash_taken_out" name="cash_taken_out" value="0.00" required class="tdb-input" data-calc>
                </div>
            </div>
            <div class="tdb-field">
                <label for="closing_cash"><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?></label>
                <div class="tdb-input-wrap">
                    <span class="tdb-input-prefix">$</span>
                    <input type="number" step="0.01" min="0" id="closing_cash" name="closing_cash" value="0.00" required class="tdb-input" data-calc>
                </div>
            </div>
        </div>

        <div class="tdb-preview">
            <div class="tdb-preview__label"><?php esc_html_e( 'Live Difference Preview', 'tasa-daybook' ); ?></div>
            <div class="tdb-preview__value" id="tdb-live-diff">$0.00</div>
            <div class="tdb-preview__formula"><?php esc_html_e( 'Closing Cash − (Opening Cash + Cash Sales − Cash Taken Out)', 'tasa-daybook' ); ?></div>
        </div>

        <button type="submit" class="tdb-btn tdb-btn--primary">
            <span class="dashicons dashicons-saved"></span> <?php esc_html_e( 'Save Record', 'tasa-daybook' ); ?>
        </button>
    </form>
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
                        <span class="tdb-input-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="opening_cash" name="opening_cash"
                               value="<?php echo esc_attr( $record->opening_cash ); ?>" required class="tdb-input" data-calc>
                    </div>
                </div>
                <div class="tdb-field">
                    <label for="cash_sales"><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Cash Sales', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="cash_sales" name="cash_sales"
                               value="<?php echo esc_attr( $record->cash_sales ); ?>" required class="tdb-input" data-calc>
                    </div>
                </div>
                <div class="tdb-field">
                    <label for="online_payments"><span class="dashicons dashicons-smartphone"></span> <?php esc_html_e( 'Online Payments', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="online_payments" name="online_payments"
                               value="<?php echo esc_attr( $record->online_payments ); ?>" required class="tdb-input">
                    </div>
                </div>
                <div class="tdb-field">
                    <label for="cash_taken_out"><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( 'Cash Taken Out', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="cash_taken_out" name="cash_taken_out"
                               value="<?php echo esc_attr( $record->cash_taken_out ); ?>" required class="tdb-input" data-calc>
                    </div>
                </div>
                <div class="tdb-field">
                    <label for="closing_cash"><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?></label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="closing_cash" name="closing_cash"
                               value="<?php echo esc_attr( $record->closing_cash ); ?>" required class="tdb-input" data-calc>
                    </div>
                </div>
            </div>

            <div class="tdb-preview">
                <div class="tdb-preview__label"><?php esc_html_e( 'Live Difference Preview', 'tasa-daybook' ); ?></div>
                <div class="tdb-preview__value" id="tdb-live-diff">$<?php echo esc_html( number_format( (float) $record->calculated_diff, 2 ) ); ?></div>
                <div class="tdb-preview__formula"><?php esc_html_e( 'Closing Cash − (Opening Cash + Cash Sales − Cash Taken Out)', 'tasa-daybook' ); ?></div>
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
    </div>
    <?php
}

/* ─────────────────────────────────────────────
 * Render: Records table
 * ───────────────────────────────────────────── */
function tasa_daybook_render_records_table() {
    global $wpdb;
    $table   = tasa_daybook_table();
    $records = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY record_date DESC" );
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
                <th><?php esc_html_e( 'Opening Cash', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Cash Sales', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Online Payments', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Cash Taken Out', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?></th>
                <th><?php esc_html_e( 'Difference', 'tasa-daybook' ); ?></th>
                <?php if ( $is_admin ) : ?>
                    <th><?php esc_html_e( 'Actions', 'tasa-daybook' ); ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $records as $row ) :
            $diff_class = '';
            if ( floatval( $row->calculated_diff ) > 0 ) {
                $diff_class = 'tasa-cc-positive';
            } elseif ( floatval( $row->calculated_diff ) < 0 ) {
                $diff_class = 'tasa-cc-negative';
            }
        ?>
            <tr>
                <td><?php echo esc_html( $row->record_date ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->opening_cash, 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->cash_sales, 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->online_payments, 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->cash_taken_out, 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) $row->closing_cash, 2 ) ); ?></td>
                <td class="<?php echo esc_attr( $diff_class ); ?>">
                    <?php echo esc_html( number_format( (float) $row->calculated_diff, 2 ) ); ?>
                </td>
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
    <?php
    echo '</div>';
}

