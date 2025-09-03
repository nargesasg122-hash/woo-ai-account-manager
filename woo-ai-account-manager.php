<?php
/**
 * Plugin Name: Woo AI Account Manager
 * Description: مدیریت و فروش خودکار اکانت‌های دیجیتال (اشتراکی/اختصاصی) برای ووکامرس + REST API + پنل ادمین + شورت‌کد نمایش به کاربر.
 * Version: 1.0.2
 * Author: Your Name
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Woo_AI_Account_Manager {
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

        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        add_action( 'woocommerce_order_status_completed', [ $this, 'assign_for_order' ], 10, 1 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'assign_for_order' ], 10, 1 );

        add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );

        add_shortcode( 'ai_account_assignments', [ $this, 'shortcode_assignments' ] );
    }

    public function on_activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE IF NOT EXISTS {$this->inventory_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id_woocommerce BIGINT UNSIGNED NOT NULL,
            account_email VARCHAR(191) NOT NULL,
            password_ref VARCHAR(255) NOT NULL,
            max_capacity INT NOT NULL DEFAULT 1,
            current_users INT NOT NULL DEFAULT 0,
            status ENUM('free','use','close') NOT NULL DEFAULT 'free',
            type ENUM('shared','dedicated') NOT NULL DEFAULT 'dedicated',
            notes TEXT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE IF NOT EXISTS {$this->assignments_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_email VARCHAR(191) NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            start_date DATETIME NOT NULL,
            end_date DATETIME DEFAULT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            PRIMARY KEY(id)
        ) $charset_collate;";

        dbDelta( $sql1 );
        dbDelta( $sql2 );
    }

    public function maybe_set_default_key() {
        if ( ! get_option('woo_ai_acc_enc_key') ) {
            update_option('woo_ai_acc_enc_key', wp_generate_password(32,true,false));
        }
    }

    public function register_admin_pages() {
        add_menu_page(
            'AI Accounts',
            'AI Accounts',
            'manage_options',
            'ai-accounts',
            [ $this, 'admin_inventory_page' ],
            'dashicons-shield',
            56
        );
        add_submenu_page(
            'ai-accounts',
            'Inventory',
            'Inventory',
            'manage_options',
            'ai-accounts',
            [ $this, 'admin_inventory_page' ]
        );
        add_submenu_page(
            'ai-accounts',
            'Reports',
            'Reports',
            'manage_options',
            'ai-accounts-reports',
            [ $this, 'admin_reports_page' ]
        );
    }

    public function admin_inventory_page() {
        if ( ! current_user_can('manage_options') ) return;
        global $wpdb;

        // Handle add
        if ( isset($_POST['ai_add_nonce']) && wp_verify_nonce($_POST['ai_add_nonce'],'ai_add') ) {
            $wpdb->insert($this->inventory_table,[
                'product_id_woocommerce' => absint($_POST['product_id']),
                'account_email' => sanitize_text_field($_POST['account_email']),
                'password_ref' => sanitize_text_field($_POST['password_plain']),
                'max_capacity' => max(1,intval($_POST['max_capacity'])),
                'current_users' => 0,
                'status' => sanitize_text_field($_POST['status']),
                'type' => in_array($_POST['type'],['shared','dedicated']) ? $_POST['type'] : 'dedicated',
                'notes' => sanitize_textarea_field($_POST['notes']),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);
            echo '<div class="notice notice-success"><p>اکانت جدید اضافه شد.</p></div>';
        }

        // Handle delete
        if ( isset($_GET['del']) && check_admin_referer('ai_del_'.intval($_GET['del'])) ) {
            $wpdb->delete($this->inventory_table,['id'=>intval($_GET['del'])],['%d']);
            echo '<div class="notice notice-success"><p>اکانت حذف شد.</p></div>';
        }

        // Display form and table
        echo '<div class="wrap"><h1>مدیریت موجودی اکانت‌ها</h1>';
        echo '<form method="post">';
        wp_nonce_field('ai_add','ai_add_nonce');
        echo '<table class="form-table"><tbody>
        <tr><th>Product ID</th><td><input required name="product_id" type="number" class="regular-text"></td></tr>
        <tr><th>Email</th><td><input required name="account_email" type="email" class="regular-text"></td></tr>
        <tr><th>Password</th><td><input required name="password_plain" type="text" class="regular-text"></td></tr>
        <tr><th>Type</th><td><select name="type"><option value="dedicated">اختصاصی</option><option value="shared">اشتراکی</option></select></td></tr>
        <tr><th>Max Capacity</th><td><input name="max_capacity" type="number" value="1" min="1"></td></tr>
        <tr><th>Status</th><td><select name="status"><option value="free">free</option><option value="use">use</option><option value="close">close</option></select></td></tr>
        <tr><th>Notes</th><td><textarea name="notes" class="large-text" rows="3"></textarea></td></tr>
        </tbody></table><p><button class="button button-primary">ذخیره</button></p></form>';

        $rows = $wpdb->get_results("SELECT * FROM {$this->inventory_table} ORDER BY id DESC LIMIT 500",ARRAY_A);
        echo '<h2>لیست موجودی</h2><table class="widefat fixed striped"><thead><tr>
        <th>ID</th><th>Product</th><th>Email</th><th>Max</th><th>Cur</th><th>Status</th><th>Type</th><th>Actions</th>
        </tr></thead><tbody>';
        foreach($rows as $r){
            $del_url = wp_nonce_url(admin_url('admin.php?page=ai-accounts&del='.$r['id']),'ai_del_'.$r['id']);
            printf('<tr><td>%d</td><td>%d</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td>
            <td><a class="button" href="%s" onclick="return confirm(\'حذف شود؟\')">Delete</a></td></tr>',
                $r['id'],$r['product_id_woocommerce'],esc_html($r['account_email']),$r['max_capacity'],$r['current_users'],
                esc_html($r['status']),esc_html($r['type']),esc_url($del_url)
            );
        }
        echo '</tbody></table></div>';
    }

    public function admin_reports_page() {
        if ( ! current_user_can('manage_options') ) return;
        global $wpdb;
        $totals = $wpdb->get_row("SELECT COUNT(*) total,
            SUM(CASE WHEN status='free' THEN 1 ELSE 0 END) free_cnt,
            SUM(CASE WHEN status='use' THEN 1 ELSE 0 END) use_cnt,
            SUM(CASE WHEN status='close' THEN 1 ELSE 0 END) close_cnt
            FROM {$this->inventory_table}",ARRAY_A);
        $assigned = $wpdb->get_var("SELECT COUNT(*) FROM {$this->assignments_table} WHERE status='active'");
        echo '<div class="wrap"><h1>گزارش وضعیت</h1><table class="widefat fixed"><tbody>';
        printf('<tr><th>کل اکانت‌ها</th><td>%d</td></tr>',$totals['total']);
        printf('<tr><th>FREE</th><td>%d</td></tr>',$totals['free_cnt']);
        printf('<tr><th>USE</th><td>%d</td></tr>',$totals['use_cnt']);
        printf('<tr><th>CLOSE</th><td>%d</td></tr>',$totals['close_cnt']);
        printf('<tr><th>تخصیص‌های فعال</th><td>%d</td></tr>',$assigned);
        echo '</tbody></table></div>';
    }

    // Stub methods (implement actual logic for production)
    public function register_rest_routes() {}
    public function assign_for_order($order_id) {}
    public function shortcode_assignments() { return 'اطلاعات اکانت شما اینجا نمایش داده می‌شود.'; }
}

Woo_AI_Account_Manager::instance();
