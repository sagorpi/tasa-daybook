<?php
/**
 * Frontend functionality for TASA DayBook
 *
 * @package TASA_DayBook
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ─────────────────────────────────────────────
 * Register shortcode
 * ───────────────────────────────────────────── */
function tasa_daybook_register_shortcode() {
    add_shortcode( 'tasa_daybook_add_form', 'tasa_daybook_frontend_form' );
}
add_action( 'init', 'tasa_daybook_register_shortcode' );

/* ─────────────────────────────────────────────
 * Enqueue frontend styles and scripts
 * ───────────────────────────────────────────── */
function tasa_daybook_frontend_enqueue() {
    // Only enqueue if shortcode is present on the page
    global $post;
    if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'tasa_daybook_add_form' ) ) {
        wp_enqueue_style( 'tasa-daybook-google-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap', array(), null );
        wp_enqueue_style( 'dashicons' );
        wp_enqueue_style( 'tasa-daybook-frontend', TASA_DAYBOOK_PLUGIN_URL . 'assets/css/frontend.css', array(), TASA_DAYBOOK_VERSION );
    }
}
add_action( 'wp_enqueue_scripts', 'tasa_daybook_frontend_enqueue' );

/* ─────────────────────────────────────────────
 * Process frontend form submission
 * ───────────────────────────────────────────── */
function tasa_daybook_process_frontend_submission() {
    // Check if this is a frontend form submission
    if ( ! isset( $_POST['tasa_daybook_frontend_submit'] ) ) {
        return;
    }

    // Verify nonce
    if ( ! isset( $_POST['_tasa_daybook_nonce'] ) || ! wp_verify_nonce( $_POST['_tasa_daybook_nonce'], 'tasa_daybook_frontend_add' ) ) {
        return;
    }

    // Check if user is logged in
    if ( ! is_user_logged_in() ) {
        return;
    }

    global $wpdb;
    $table = tasa_daybook_table();
    $today = current_time( 'Y-m-d' );

    // Check if a record already exists for today
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE record_date = %s", $today
    ) );

    if ( $exists ) {
        tasa_daybook_set_frontend_message( 'error', __( 'A record for today already exists. You can only submit one record per day.', 'tasa-daybook' ) );
        return;
    }

    // Sanitize and validate input
    $opening_cash    = floatval( sanitize_text_field( wp_unslash( $_POST['opening_cash'] ?? '0' ) ) );
    $cash_sales      = floatval( sanitize_text_field( wp_unslash( $_POST['cash_sales'] ?? '0' ) ) );
    $online_payments = floatval( sanitize_text_field( wp_unslash( $_POST['online_payments'] ?? '0' ) ) );
    $cash_taken_out  = floatval( sanitize_text_field( wp_unslash( $_POST['cash_taken_out'] ?? '0' ) ) );
    $closing_cash    = floatval( sanitize_text_field( wp_unslash( $_POST['closing_cash'] ?? '0' ) ) );

    // Calculate difference
    $expected        = $opening_cash + $cash_sales - $cash_taken_out;
    $calculated_diff = $closing_cash - $expected;

    // Insert record
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
        tasa_daybook_set_frontend_message( 'success', __( 'Record saved successfully! Thank you for submitting today\'s record.', 'tasa-daybook' ) );
    } else {
        tasa_daybook_set_frontend_message( 'error', __( 'Failed to save record. Please try again.', 'tasa-daybook' ) );
    }
}
add_action( 'template_redirect', 'tasa_daybook_process_frontend_submission' );

/* ─────────────────────────────────────────────
 * Set frontend message in transient
 * ───────────────────────────────────────────── */
function tasa_daybook_set_frontend_message( $type, $message ) {
    set_transient( 'tasa_daybook_message_' . get_current_user_id(), array(
        'type'    => $type,
        'message' => $message,
    ), 30 );
}

/* ─────────────────────────────────────────────
 * Get and delete frontend message
 * ───────────────────────────────────────────── */
function tasa_daybook_get_frontend_message() {
    $user_id = get_current_user_id();
    $message = get_transient( 'tasa_daybook_message_' . $user_id );
    
    if ( $message ) {
        delete_transient( 'tasa_daybook_message_' . $user_id );
        return $message;
    }
    
    return null;
}

/* ─────────────────────────────────────────────
 * Shortcode: Display frontend add form
 * ───────────────────────────────────────────── */
function tasa_daybook_frontend_form( $atts ) {
    // Check if user is logged in
    if ( ! is_user_logged_in() ) {
        return tasa_daybook_render_login_message();
    }

    global $wpdb;
    $table = tasa_daybook_table();
    $today = current_time( 'Y-m-d' );

    // Check if already submitted today
    $already_submitted = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE record_date = %s", $today
    ) );

    ob_start();
    ?>
    <div class="tdb-frontend-wrap">
        <?php echo tasa_daybook_render_frontend_message(); ?>
        
        <?php if ( $already_submitted ) : ?>
            <div class="tdb-alert tdb-alert--success">
                <span class="dashicons dashicons-yes-alt"></span>
                <div>
                    <strong><?php esc_html_e( 'Already Submitted', 'tasa-daybook' ); ?></strong>
                    <p><?php esc_html_e( 'You have already submitted today\'s record. Thank you!', 'tasa-daybook' ); ?></p>
                </div>
            </div>
        <?php else : ?>
            <?php echo tasa_daybook_render_frontend_add_form(); ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* ─────────────────────────────────────────────
 * Render login message
 * ───────────────────────────────────────────── */
function tasa_daybook_render_login_message() {
    ob_start();
    ?>
    <div class="tdb-frontend-wrap">
        <div class="tdb-alert tdb-alert--warning">
            <span class="dashicons dashicons-lock"></span>
            <div>
                <strong><?php esc_html_e( 'Login Required', 'tasa-daybook' ); ?></strong>
                <p><?php esc_html_e( 'You must be logged in to submit a record.', 'tasa-daybook' ); ?></p>
                <p><a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="tdb-btn tdb-btn--primary">
                    <?php esc_html_e( 'Log In', 'tasa-daybook' ); ?>
                </a></p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* ─────────────────────────────────────────────
 * Render frontend message
 * ───────────────────────────────────────────── */
function tasa_daybook_render_frontend_message() {
    $message = tasa_daybook_get_frontend_message();

    if ( ! $message ) {
        return '';
    }

    $type = $message['type'];
    $text = $message['message'];
    $class = 'tdb-alert--' . ( $type === 'success' ? 'success' : 'error' );
    $icon = $type === 'success' ? 'yes-alt' : 'warning';

    ob_start();
    ?>
    <div class="tdb-alert <?php echo esc_attr( $class ); ?>">
        <span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span>
        <div><?php echo esc_html( $text ); ?></div>
    </div>
    <?php
    return ob_get_clean();
}

/* ─────────────────────────────────────────────
 * Render frontend add form
 * ───────────────────────────────────────────── */
function tasa_daybook_render_frontend_add_form() {
    $today = current_time( 'Y-m-d' );
    $day_name = wp_date( 'l, F j, Y' );
    $current_user = wp_get_current_user();

    ob_start();
    ?>
    <div class="tdb-card">
        <div class="tdb-card__header">
            <span class="dashicons dashicons-book tdb-card__icon"></span>
            <div>
                <h2 class="tdb-card__title"><?php esc_html_e( 'Submit Today\'s Record', 'tasa-daybook' ); ?></h2>
                <p class="tdb-card__subtitle"><?php echo esc_html( $day_name ); ?></p>
            </div>
        </div>

        <form method="post" class="tdb-form" id="tdb-frontend-form">
            <?php wp_nonce_field( 'tasa_daybook_frontend_add', '_tasa_daybook_nonce' ); ?>
            <input type="hidden" name="tasa_daybook_frontend_submit" value="1">

            <div class="tdb-grid">
                <div class="tdb-field">
                    <label for="opening_cash">
                        <span class="dashicons dashicons-vault"></span>
                        <?php esc_html_e( 'Opening Cash', 'tasa-daybook' ); ?>
                    </label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="opening_cash" name="opening_cash"
                               value="0.00" required class="tdb-input" data-calc>
                    </div>
                </div>

                <div class="tdb-field">
                    <label for="cash_sales">
                        <span class="dashicons dashicons-money-alt"></span>
                        <?php esc_html_e( 'Cash Sales', 'tasa-daybook' ); ?>
                    </label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="cash_sales" name="cash_sales"
                               value="0.00" required class="tdb-input" data-calc>
                    </div>
                </div>

                <div class="tdb-field">
                    <label for="online_payments">
                        <span class="dashicons dashicons-smartphone"></span>
                        <?php esc_html_e( 'Online Payments', 'tasa-daybook' ); ?>
                    </label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="online_payments" name="online_payments"
                               value="0.00" required class="tdb-input">
                    </div>
                </div>

                <div class="tdb-field">
                    <label for="cash_taken_out">
                        <span class="dashicons dashicons-migrate"></span>
                        <?php esc_html_e( 'Cash Taken Out', 'tasa-daybook' ); ?>
                    </label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="cash_taken_out" name="cash_taken_out"
                               value="0.00" required class="tdb-input" data-calc>
                    </div>
                </div>

                <div class="tdb-field">
                    <label for="closing_cash">
                        <span class="dashicons dashicons-lock"></span>
                        <?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?>
                    </label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">$</span>
                        <input type="number" step="0.01" min="0" id="closing_cash" name="closing_cash"
                               value="0.00" required class="tdb-input" data-calc>
                    </div>
                </div>
            </div>

            <div class="tdb-preview">
                <div class="tdb-preview__label"><?php esc_html_e( 'Live Difference Preview', 'tasa-daybook' ); ?></div>
                <div class="tdb-preview__value" id="tdb-live-diff">$0.00</div>
                <div class="tdb-preview__formula">
                    <?php esc_html_e( 'Closing Cash − (Opening Cash + Cash Sales − Cash Taken Out)', 'tasa-daybook' ); ?>
                </div>
            </div>

            <button type="submit" class="tdb-btn tdb-btn--primary tdb-btn--large">
                <span class="dashicons dashicons-saved"></span>
                <?php esc_html_e( 'Submit Record', 'tasa-daybook' ); ?>
            </button>
        </form>
    </div>

    <script>
    (function() {
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
    })();
    </script>
    <?php
    return ob_get_clean();
}

