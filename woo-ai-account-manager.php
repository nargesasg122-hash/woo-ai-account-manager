<?php
/**
 * Plugin Name: Woo AI Account Manager
 * Description: مدیریت و فروش خودکار اکانت‌های دیجیتال (اشتراکی/اختصاصی) برای ووکامرس + REST API + پنل ادمین + شورت‌کد نمایش به کاربر.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Woo_AI_Account_Manager {
    const VERSION = '1.0.0';
    const OPTION_ENC_KEY = 'woo_ai_acc_enc_key';

    private static $instance = null;
    private $inventory_table;
    private $assignments_table;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->inventory_table   = $wpdb->prefix . 'inventory_accounts';
        $this->assignments_table = $wpdb->prefix . 'customer_assignments';

        register_activation_hook( __FILE__, [ $this, 'on_activate' ] );
        add_action( 'plugins_loaded', [ $this, 'maybe_set_default_key' ] );

        // REST API
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Hook after successful WooCommerce payment (order completed/processing)
        add_action( 'woocommerce_order_status_completed', [ $this, 'assign_for_order' ], 10, 1 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'assign_for_order' ], 10, 1 );

        // Admin Menu
        add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );

        // Shortcode for user panel (e.g. MihanPanel -> قرار دادن شورت‌کد در پنل کاربر)
        add_shortcode( 'ai_account_assignments', [ $this, 'shortcode_assignments' ] );
    }

    public function on_activate() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // inventory_accounts
        $sql1 = "CREATE TABLE {$this->inventory_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id_woocommerce BIGINT UNSIGNED NOT NULL,
            account_email VARCHAR(191) NOT NULL,
            password_ref TEXT NULL,
            max_capacity INT UNSIGNED NOT NULL DEFAULT 1,
            current_users INT UNSIGNED NOT NULL DEFAULT 0,
            status ENUM('free','use','close') NOT NULL DEFAULT 'free',
            type ENUM('shared','dedicated') NOT NULL DEFAULT 'dedicated',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY pid (product_id_woocommerce),
            KEY status_idx (status),
            KEY type_idx (type)
        ) $charset_collate;";

        // customer_assignments
        $sql2 = "CREATE TABLE {$this->assignments_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            customer_email VARCHAR(191) NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            start_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            end_date DATETIME NULL,
            status ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY account_idx (account_id),
            KEY user_idx (user_id),
            KEY order_idx (order_id)
        ) $charset_collate;";

        dbDelta( $sql1 );
        dbDelta( $sql2 );

        // create encryption key if missing
        if ( ! get_option( self::OPTION_ENC_KEY ) ) {
            $key = wp_generate_password( 32, true, true );
            add_option( self::OPTION_ENC_KEY, $key );
        }
    }

    public function maybe_set_default_key() {
        if ( ! get_option( self::OPTION_ENC_KEY ) ) {
            add_option( self::OPTION_ENC_KEY, wp_generate_password( 32, true, true ) );
        }
    }

    /* ===================== REST API ===================== */
    public function register_rest_routes() {
        register_rest_route( 'ai-accounts/v1', '/assign', [
            'methods'  => 'POST',
            'permission_callback' => function( $request ) {
                return current_user_can( 'manage_woocommerce' ) || is_user_logged_in();
            },
            'callback' => function( $request ) {
                $order_id = absint( $request->get_param('order_id') );
                if ( ! $order_id ) return new WP_Error( 'bad_request', 'order_id is required', [ 'status' => 400 ] );
                $result = $this->assign_for_order( $order_id, true );
                if ( is_wp_error( $result ) ) return $result;
                return [ 'ok' => true, 'assigned' => $result ];
            }
        ] );
    }

    /**
     * Assign accounts for each line item in an order
     * @param int $order_id
     * @param bool $return_data when true, returns assignments summary
     */
    public function assign_for_order( $order_id, $return_data = false ) {
        if ( ! function_exists( 'wc_get_order' ) ) return new WP_Error('no_wc','WooCommerce required');
        $order = wc_get_order( $order_id );
        if ( ! $order ) return new WP_Error( 'not_found', 'Order not found' );

        $user_id = $order->get_user_id();
        $customer_email = $order->get_billing_email();

        $summary = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ( ! $product ) continue;

            $product_id = $product->get_id();
            $qty = max( 1, (int) $item->get_quantity() );

            // نوع اکانت از متای محصول (پیش‌فرض: اختصاصی)
            $acc_type = get_post_meta( $product_id, '_ai_account_type', true );
            if ( ! in_array( $acc_type, [ 'shared', 'dedicated' ], true ) ) {
                $acc_type = 'dedicated';
            }

            for ( $i = 0; $i < $qty; $i++ ) {
                $assignment = ( 'shared' === $acc_type )
                    ? $this->assign_shared( $product_id, $user_id, $customer_email, $order_id )
                    : $this->assign_dedicated( $product_id, $user_id, $customer_email, $order_id );

                if ( is_wp_error( $assignment ) ) {
                    // لاگ خطا ولی ادامه آیتم‌های بعدی
                    error_log( 'AI Account assign error: ' . $assignment->get_error_message() );
                    continue;
                }

                $summary[] = $assignment;

                // ارسال ایمیل به مشتری برای هر تخصیص
                $this->send_assignment_email( $customer_email, $assignment, $order );
            }
        }

        if ( $return_data ) return $summary;
        return true;
    }

    private function enc_key() { return get_option( self::OPTION_ENC_KEY ); }

    private function encrypt( $plain ) {
        if ( empty( $plain ) ) return '';
        $key = $this->enc_key();
        $iv = wp_generate_password( 16, false, false );
        $cipher = openssl_encrypt( $plain, 'AES-256-CBC', $key, 0, $iv );
        return base64_encode( $iv . '::' . $cipher );
    }

    private function decrypt( $blob ) {
        if ( empty( $blob ) ) return '';
        $key = $this->enc_key();
        $data = base64_decode( $blob );
        if ( ! $data || strpos( $data, '::' ) === false ) return '';
        list( $iv, $cipher ) = explode( '::', $data, 2 );
        return openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
    }

    /**
     * Shared account logic (capacity-based)
     */
    private function assign_shared( $product_id, $user_id, $email, $order_id ) {
        global $wpdb;

        // انتخاب ردیفی که current_users < max_capacity و نزدیک به پر شدن (بیشترین current_users)
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->inventory_table}
             WHERE product_id_woocommerce = %d AND type='shared' AND status IN('free','use') AND current_users < max_capacity
             ORDER BY current_users DESC, id ASC
             LIMIT 1",
            $product_id
        ), ARRAY_A );

        if ( ! $row ) return new WP_Error( 'no_inventory', 'No shared account with available capacity' );

        // افزایش شمارنده و به‌روزرسانی وضعیت
        $row['current_users'] = (int) $row['current_users'] + 1;
        $new_status = ( $row['current_users'] >= (int) $row['max_capacity'] ) ? 'close' : 'use';

        $updated = $wpdb->update( $this->inventory_table, [
            'current_users' => $row['current_users'],
            'status'        => $new_status,
            'updated_at'    => current_time('mysql'),
        ], [ 'id' => $row['id'] ], [ '%d','%s','%s' ], [ '%d' ] );

        if ( $updated === false ) return new WP_Error( 'db_error', 'Failed to update shared account row' );

        // ثبت تخصیص
        $wpdb->insert( $this->assignments_table, [
            'user_id'        => $user_id ?: null,
            'customer_email' => $email,
            'account_id'     => $row['id'],
            'order_id'       => $order_id,
            'start_date'     => current_time('mysql'),
            'status'         => 'active',
            'created_at'     => current_time('mysql'),
        ], [ '%d','%s','%d','%d','%s','%s','%s' ] );

        $assignment_id = $wpdb->insert_id;

        return $this->format_assignment_payload( $assignment_id, $row );
    }

    /**
     * Dedicated account logic (status-based)
     */
    private function assign_dedicated( $product_id, $user_id, $email, $order_id ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->inventory_table}
             WHERE product_id_woocommerce = %d AND type='dedicated' AND status='free'
             ORDER BY id ASC
             LIMIT 1",
            $product_id
        ), ARRAY_A );

        if ( ! $row ) return new WP_Error( 'no_inventory', 'No free dedicated account found' );

        $updated = $wpdb->update( $this->inventory_table, [
            'status'     => 'use', // طبق نیاز شما می‌تواند 'used' نیز باشد
            'updated_at' => current_time('mysql'),
        ], [ 'id' => $row['id'] ], [ '%s','%s' ], [ '%d' ] );

        if ( $updated === false ) return new WP_Error( 'db_error', 'Failed to mark dedicated account as used' );

        $wpdb->insert( $this->assignments_table, [
            'user_id'        => $user_id ?: null,
            'customer_email' => $email,
            'account_id'     => $row['id'],
            'order_id'       => $order_id,
            'start_date'     => current_time('mysql'),
            'status'         => 'active',
            'created_at'     => current_time('mysql'),
        ], [ '%d','%s','%d','%d','%s','%s','%s' ] );

        $assignment_id = $wpdb->insert_id;

        return $this->format_assignment_payload( $assignment_id, $row );
    }

    private function format_assignment_payload( $assignment_id, $inv_row ) {
        return [
            'assignment_id' => (int) $assignment_id,
            'account_id'    => (int) $inv_row['id'],
            'product_id'    => (int) $inv_row['product_id_woocommerce'],
            'account_email' => $inv_row['account_email'],
            'password'      => $this->decrypt( $inv_row['password_ref'] ),
            'type'          => $inv_row['type'],
            'status'        => $inv_row['status'],
        ];
    }

    /* ===================== Email ===================== */
    private function send_assignment_email( $to, $payload, $order ) {
        $subject = sprintf( __( 'اطلاعات اکانت شما | سفارش #%s', 'woo-ai-acc' ), $order->get_order_number() );
        $body    = sprintf(
            "سلام،\n\nاطلاعات دسترسی شما:\nایمیل: %s\nرمز عبور: %s\nنوع اکانت: %s\n\nبا تشکر",
            $payload['account_email'],
            $payload['password'],
            ( 'shared' === $payload['type'] ? 'اشتراکی' : 'اختصاصی' )
        );
        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];
        wp_mail( $to, $subject, $body, $headers );
    }

    /* ===================== Shortcode ===================== */
    public function shortcode_assignments( $atts ) {
        if ( ! is_user_logged_in() ) return '<p>برای مشاهده اطلاعات وارد شوید.</p>';
        $user = wp_get_current_user();
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.id as assign_id, inv.account_email, inv.password_ref, inv.type, inv.status, a.start_date, a.end_date, a.order_id
             FROM {$this->assignments_table} a
             JOIN {$this->inventory_table} inv ON inv.id = a.account_id
             WHERE a.user_id = %d AND a.status='active'
             ORDER BY a.id DESC",
            $user->ID
        ), ARRAY_A );

        if ( ! $rows ) return '<p>هیچ اکانت فعالی برای شما ثبت نشده است.</p>';

        ob_start();
        echo '<div class="ai-accounts">';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>ایمیل اکانت</th><th>رمز عبور</th><th>نوع</th><th>وضعیت</th><th>شروع</th><th>سفارش</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>#%d</td></tr>',
                esc_html( $r['account_email'] ),
                esc_html( $this->decrypt( $r['password_ref'] ) ),
                ( $r['type'] === 'shared' ? 'اشتراکی' : 'اختصاصی' ),
                esc_html( $r['status'] ),
                esc_html( $r['start_date'] ),
                (int) $r['order_id']
            );
        }
        echo '</tbody></table></div>';
        return ob_get_clean();
    }

    /* ===================== Admin Pages ===================== */
    public function register_admin_pages() {
        add_menu_page(
            'AI Accounts', 'AI Accounts', 'manage_woocommerce', 'ai-accounts', [ $this, 'admin_inventory_page' ], 'dashicons-shield', 56
        );
        add_submenu_page( 'ai-accounts', 'Inventory', 'Inventory', 'manage_woocommerce', 'ai-accounts', [ $this, 'admin_inventory_page' ] );
        add_submenu_page( 'ai-accounts', 'Reports', 'Reports', 'manage_woocommerce', 'ai-accounts-reports', [ $this, 'admin_reports_page' ] );
    }

    public function admin_inventory_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        global $wpdb;

        // Add new
        if ( isset($_POST['ai_add_nonce']) && wp_verify_nonce( $_POST['ai_add_nonce'], 'ai_add' ) ) {
            $product_id = absint( $_POST['product_id'] );
            $account_email = sanitize_text_field( $_POST['account_email'] );
            $password_plain = sanitize_text_field( $_POST['password_plain'] );
            $max_capacity = max(1, absint( $_POST['max_capacity'] ));
            $type = in_array( $_POST['type'] ?? 'dedicated', [ 'shared','dedicated' ], true ) ? $_POST['type'] : 'dedicated';
            $status = sanitize_text_field( $_POST['status'] ?? 'free' );
            $notes = sanitize_textarea_field( $_POST['notes'] ?? '' );

            $wpdb->insert( $this->inventory_table, [
                'product_id_woocommerce' => $product_id,
                'account_email' => $account_email,
                'password_ref' => $this->encrypt( $password_plain ),
                'max_capacity' => $max_capacity,
                'current_users' => 0,
                'status' => $status,
                'type' => $type,
                'notes' => $notes,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], [ '%d','%s','%s','%d','%d','%s','%s','%s','%s','%s' ] );

            echo '<div class="notice notice-success"><p>اکانت جدید اضافه شد.</p></div>';
        }

        // Delete
        if ( isset($_GET['del']) && check_admin_referer( 'ai_del_' . absint($_GET['del']) ) ) {
            $wpdb->delete( $this->inventory_table, [ 'id' => absint($_GET['del']) ], [ '%d' ] );
            echo '<div class="notice notice-success"><p>حذف شد.</p></div>';
        }

        echo '<div class="wrap"><h1>مدیریت موجودی اکانت‌ها</h1>';
        echo '<h2>افزودن</h2>';
        echo '<form method="post">';
        wp_nonce_field( 'ai_add', 'ai_add_nonce' );
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Product ID (Woo)</th><td><input required name="product_id" type="number" class="regular-text"></td></tr>';
        echo '<tr><th>ایمیل اکانت</th><td><input required name="account_email" type="email" class="regular-text"></td></tr>';
        echo '<tr><th>رمز عبور</th><td><input required name="password_plain" type="text" class="regular-text"></td></tr>';
        echo '<tr><th>نوع</th><td><select name="type"><option value="dedicated">اختصاصی</option><option value="shared">اشتراکی</option></select></td></tr>';
        echo '<tr><th>ظرفیت حداکثر</th><td><input name="max_capacity" type="number" value="1" min="1"></td></tr>';
        echo '<tr><th>وضعیت</th><td><select name="status"><option value="free">free</option><option value="use">use</option><option value="close">close</option></select></td></tr>';
        echo '<tr><th>یادداشت</th><td><textarea name="notes" class="large-text" rows="3"></textarea></td></tr>';
        echo '</tbody></table>';
        echo '<p><button class="button button-primary">ذخیره</button></p>';
        echo '</form>';

        echo '<hr><h2>لیست موجودی</h2>';
        $rows = $wpdb->get_results( "SELECT * FROM {$this->inventory_table} ORDER BY id DESC LIMIT 500", ARRAY_A );
        echo '<table class="widefat fixed striped"><thead><tr><th>ID</th><th>Product</th><th>Email</th><th>Max</th><th>Cur</th><th>Status</th><th>Type</th><th>Actions</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $del_url = wp_nonce_url( admin_url( 'admin.php?page=ai-accounts&del=' . (int)$r['id'] ), 'ai_del_' . (int)$r['id'] );
            printf('<tr><td>%d</td><td>%d</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td><td><a class="button" href="%s" onclick="return confirm(\'حذف شود؟\')">Delete</a></td></tr>',
                (int)$r['id'], (int)$r['product_id_woocommerce'], esc_html($r['account_email']), (int)$r['max_capacity'], (int)$r['current_users'], esc_html($r['status']), esc_html($r['type']), esc_url($del_url)
            );
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public function admin_reports_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        global $wpdb;

        $totals = $wpdb->get_row( "SELECT COUNT(*) total,
            SUM(CASE WHEN status='free' THEN 1 ELSE 0 END) free_cnt,
            SUM(CASE WHEN status='use' THEN 1 ELSE 0 END) use_cnt,
            SUM(CASE WHEN status='close' THEN 1 ELSE 0 END) close_cnt
        FROM {$this->inventory_table}", ARRAY_A );

        $assigned = $wpdb->get_var( "SELECT COUNT(*) FROM {$this->assignments_table} WHERE status='active'" );

        echo '<div class="wrap"><h1>گزارش وضعیت</h1>';
        echo '<table class="widefat fixed"><tbody>';
        printf('<tr><th>کل اکانت‌ها</th><td>%d</td></tr>', (int)$totals['total'] );
        printf('<tr><th>FREE</th><td>%d</td></tr>', (int)$totals['free_cnt'] );
        printf('<tr><th>USE</th><td>%d</td></tr>', (int)$totals['use_cnt'] );
        printf('<tr><th>CLOSE</th><td>%d</td></tr>', (int)$totals['close_cnt'] );
        printf('<tr><th>تخصیص‌های فعال</th><td>%d</td></tr>', (int)$assigned );
        echo '</tbody></table>';

        echo '<h2>آخرین 50 تخصیص</h2>';
        $rows = $wpdb->get_results( "SELECT a.id, a.customer_email, a.order_id, a.start_date, inv.account_email, inv.type FROM {$this->assignments_table} a JOIN {$this->inventory_table} inv ON inv.id=a.account_id ORDER BY a.id DESC LIMIT 50", ARRAY_A );
        echo '<table class="widefat fixed striped"><thead><tr><th>ID</th><th>Customer</th><th>Order</th><th>Start</th><th>Account</th><th>Type</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            printf('<tr><td>%d</td><td>%s</td><td>#%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                (int)$r['id'], esc_html($r['customer_email']), (int)$r['order_id'], esc_html($r['start_date']), esc_html($r['account_email']), esc_html($r['type'])
            );
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}

Woo_AI_Account_Manager::instance();

/* ===================== Helper: Product Meta UI (اختیاری) ===================== */
// متاباکس ساده برای تعیین نوع اکانت محصول (اشتراکی/اختصاصی)
add_action( 'add_meta_boxes', function() {
    add_meta_box( 'ai_acc_meta', 'AI Account Type', function( $post ) {
        $val = get_post_meta( $post->ID, '_ai_account_type', true );
        if ( ! in_array( $val, [ 'shared','dedicated' ], true ) ) $val = 'dedicated';
        echo '<label for="ai_acc_type">نوع اکانت:</label> ';
        echo '<select name="ai_acc_type" id="ai_acc_type">';
        echo '<option value="dedicated"' . selected( $val, 'dedicated', false ) . '>اختصاصی</option>';
        echo '<option value="shared"'   . selected( $val, 'shared', false )   . '>اشتراکی</option>';
        echo '</select>';
        wp_nonce_field( 'ai_acc_meta_save', 'ai_acc_meta_nonce' );
    }, 'product', 'side', 'default' );
});

add_action( 'save_post_product', function( $post_id ) {
    if ( ! isset( $_POST['ai_acc_meta_nonce'] ) || ! wp_verify_nonce( $_POST['ai_acc_meta_nonce'], 'ai_acc_meta_save' ) ) return;
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( isset( $_POST['ai_acc_type'] ) ) {
        $val = $_POST['ai_acc_type'] === 'shared' ? 'shared' : 'dedicated';
        update_post_meta( $post_id, '_ai_account_type', $val );
    }
});

/* ===================== SQL DDL Quick Copy (اختیاری) =====================
-- این بخش صرفا برای ارجاع سریع است؛ جداول در Activation ساخته می‌شوند.
-- CREATE TABLE wp_inventory_accounts (...)
-- CREATE TABLE wp_customer_assignments (...)
*/
