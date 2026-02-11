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
 * Fullscreen shortcode helpers
 * ───────────────────────────────────────────── */
function tasa_daybook_is_truthy( $value ) {
    if ( is_bool( $value ) ) {
        return $value;
    }

    return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
}

function tasa_daybook_get_shortcode_matches( $content ) {
    if ( ! is_string( $content ) || false === strpos( $content, 'tasa_daybook_add_form' ) ) {
        return array();
    }

    preg_match_all( '/' . get_shortcode_regex( array( 'tasa_daybook_add_form' ) ) . '/', $content, $matches, PREG_SET_ORDER );
    return is_array( $matches ) ? $matches : array();
}

function tasa_daybook_has_fullscreen_shortcode( $content ) {
    $matches = tasa_daybook_get_shortcode_matches( $content );

    if ( empty( $matches ) ) {
        return false;
    }

    foreach ( $matches as $match ) {
        $atts = shortcode_parse_atts( $match[3] ?? '' );

        if ( isset( $atts['fullscreen'] ) && tasa_daybook_is_truthy( $atts['fullscreen'] ) ) {
            return true;
        }
    }

    return false;
}

function tasa_daybook_render_fullscreen_shortcodes( $content ) {
    $matches = tasa_daybook_get_shortcode_matches( $content );
    $output  = array();

    if ( empty( $matches ) ) {
        return '';
    }

    foreach ( $matches as $match ) {
        $atts = shortcode_parse_atts( $match[3] ?? '' );

        if ( isset( $atts['fullscreen'] ) && tasa_daybook_is_truthy( $atts['fullscreen'] ) ) {
            $output[] = do_shortcode( $match[0] );
        }
    }

    return implode( "\n", $output );
}

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

    // Check if user is Shop Manager or Administrator
    $user = wp_get_current_user();
    $allowed_roles = array( 'shop_manager', 'administrator' );

    if ( ! array_intersect( $allowed_roles, $user->roles ) ) {
        tasa_daybook_set_frontend_message( 'error', __( 'You do not have permission to submit records. This action is restricted to approved users.', 'tasa-daybook' ) );
        return;
    }

    global $wpdb;
    $table = tasa_daybook_table();
    $today = current_time( 'Y-m-d' );

    // Check if the current user already submitted a record for today
    $user_exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE record_date = %s AND created_by = %d",
        $today,
        get_current_user_id()
    ) );

    if ( $user_exists ) {
        tasa_daybook_set_frontend_message( 'error', __( 'You have already submitted a record for today. You can only submit one record per day.', 'tasa-daybook' ) );
        return;
    }

    // Sanitize and validate input
    $cash_sales      = floatval( sanitize_text_field( wp_unslash( $_POST['cash_sales'] ?? '0' ) ) );
    $online_sales    = floatval( sanitize_text_field( wp_unslash( $_POST['online_sales'] ?? '0' ) ) );
    $cash_taken_out  = floatval( sanitize_text_field( wp_unslash( $_POST['cash_taken_out'] ?? '0' ) ) );

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

    // Calculate difference (for backward compatibility, though not displayed)
    $expected        = $opening_cash + $cash_sales - $cash_taken_out;
    $calculated_diff = $closing_cash - $expected;

    // Insert record
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
 * Render shortcode in fullscreen mode (no theme shell)
 * ───────────────────────────────────────────── */
function tasa_daybook_maybe_render_fullscreen_page() {
    if ( is_admin() || ! is_singular() ) {
        return;
    }

    global $post;

    if ( ! ( $post instanceof WP_Post ) || ! tasa_daybook_has_fullscreen_shortcode( $post->post_content ) ) {
        return;
    }

    $content = tasa_daybook_render_fullscreen_shortcodes( $post->post_content );

    if ( '' === trim( $content ) ) {
        return;
    }

    ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class( 'tdb-fullscreen-page' ); ?>>
<?php wp_body_open(); ?>
<main class="tdb-fullscreen-shell">
    <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
    <?php
    exit;
}
add_action( 'template_redirect', 'tasa_daybook_maybe_render_fullscreen_page', 99 );

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
    $atts = shortcode_atts(
        array(
            'fullscreen' => 'false',
        ),
        $atts,
        'tasa_daybook_add_form'
    );
    $is_fullscreen = tasa_daybook_is_truthy( $atts['fullscreen'] );
    $wrapper_class = $is_fullscreen ? 'tdb-frontend-wrap tdb-frontend-wrap--fullscreen' : 'tdb-frontend-wrap';

    // Check if user is logged in
    if ( ! is_user_logged_in() ) {
        return tasa_daybook_render_login_message( $wrapper_class );
    }

    // Check if user is Shop Manager or Administrator
    $user = wp_get_current_user();
    $allowed_roles = array( 'shop_manager', 'administrator' );

    if ( ! array_intersect( $allowed_roles, $user->roles ) ) {
        return tasa_daybook_render_access_denied_message( $wrapper_class );
    }

    global $wpdb;
    $table = tasa_daybook_table();
    $today = current_time( 'Y-m-d' );

    // Check if current user already submitted a record today
    $already_submitted = (bool) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE record_date = %s AND created_by = %d",
        $today,
        get_current_user_id()
    ) );

    ob_start();
    ?>
    <div class="<?php echo esc_attr( $wrapper_class ); ?>">
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
function tasa_daybook_render_login_message( $wrapper_class = 'tdb-frontend-wrap' ) {
    ob_start();
    ?>
    <div class="<?php echo esc_attr( $wrapper_class ); ?>">
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
 * Render access denied message
 * ───────────────────────────────────────────── */
function tasa_daybook_render_access_denied_message( $wrapper_class = 'tdb-frontend-wrap' ) {
    ob_start();
    ?>
    <div class="<?php echo esc_attr( $wrapper_class ); ?>">
        <div class="tdb-alert tdb-alert--error">
            <span class="dashicons dashicons-warning"></span>
            <div>
                <strong><?php esc_html_e( 'Access Denied', 'tasa-daybook' ); ?></strong>
                <p><?php esc_html_e( 'You do not have permission to access this page. Only Shop Managers and Administrators can submit records.', 'tasa-daybook' ); ?></p>
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
    global $wpdb;
    $table = tasa_daybook_table();
    $today = current_time( 'Y-m-d' );
    $day_name = wp_date( 'l, F j, Y' );
    $current_user = wp_get_current_user();

    // Get most recent record's closing cash for opening cash
    // This includes records from the same day (for multiple admin entries) and previous days
    $previous_record = $wpdb->get_row( $wpdb->prepare(
        "SELECT closing_cash FROM {$table} WHERE record_date <= %s ORDER BY id DESC LIMIT 1", $today
    ) );
    $opening_cash = $previous_record ? number_format( (float) $previous_record->closing_cash, 2 ) : '0.00';

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
                        <span class="tdb-input-prefix">₹</span>
                        <input type="text" id="opening_cash" name="opening_cash"
                               value="<?php echo esc_attr( $opening_cash ); ?>" readonly class="tdb-input" style="background-color: #f0f0f1; cursor: not-allowed;">
                    </div>
                </div>

                <div class="tdb-field">
                    <label for="cash_sales">
                        <span class="dashicons dashicons-money-alt"></span>
                        <?php esc_html_e( 'Cash Sales', 'tasa-daybook' ); ?>
                    </label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="number" step="0.01" min="0" id="cash_sales" name="cash_sales"
                               placeholder="0.00" required class="tdb-input" data-calc>
                    </div>
                </div>

                <div class="tdb-field">
                    <label for="online_sales">
                        <span class="dashicons dashicons-smartphone"></span>
                        <?php esc_html_e( 'Online Sales', 'tasa-daybook' ); ?>
                    </label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="number" step="0.01" min="0" id="online_sales" name="online_sales"
                               placeholder="0.00" required class="tdb-input" data-calc>
                    </div>
                </div>

                <div class="tdb-field">
                    <label for="todays_sale">
                        <span class="dashicons dashicons-chart-line"></span>
                        <?php esc_html_e( 'Today\'s Sale', 'tasa-daybook' ); ?>
                    </label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="text" id="todays_sale" name="todays_sale"
                               value="0.00" readonly class="tdb-input" style="background-color: #f0f0f1; cursor: not-allowed;">
                    </div>
                </div>

                <div class="tdb-field">
                    <label for="cash_taken_out">
                        <span class="dashicons dashicons-migrate"></span>
                        <?php esc_html_e( 'Cash Taken Out', 'tasa-daybook' ); ?>
                    </label>
                    <div class="tdb-input-wrap">
                        <span class="tdb-input-prefix">₹</span>
                        <input type="number" step="0.01" min="0" id="cash_taken_out" name="cash_taken_out"
                               placeholder="0.00" required class="tdb-input" data-calc>
                    </div>
                </div>
            </div>

            <div class="tdb-preview">
                <div class="tdb-preview__label"><?php esc_html_e( 'Closing Cash', 'tasa-daybook' ); ?></div>
                <div class="tdb-preview__value" id="tdb-closing-cash">₹<?php echo esc_html( $opening_cash ); ?></div>
                <div class="tdb-preview__formula">
                    <?php esc_html_e( 'Today\'s Sale + Opening Cash − Cash Taken Out', 'tasa-daybook' ); ?>
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
        'use strict';

        function initCalculations() {
            var form = document.getElementById('tdb-frontend-form');
            if (!form) {
                console.log('TASA DayBook: Form not found');
                return;
            }

            var openingCashField = document.getElementById('opening_cash');
            var cashSalesField = document.getElementById('cash_sales');
            var onlineSalesField = document.getElementById('online_sales');
            var cashTakenOutField = document.getElementById('cash_taken_out');
            var todaysSaleField = document.getElementById('todays_sale');
            var closingCashPreview = document.getElementById('tdb-closing-cash');

            if (!closingCashPreview || !todaysSaleField) {
                console.log('TASA DayBook: Required elements not found');
                return;
            }

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
        }

        // Initialize when DOM is ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initCalculations);
        } else {
            initCalculations();
        }
    })();
    </script>
    <?php
    return ob_get_clean();
}
