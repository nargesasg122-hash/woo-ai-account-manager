<?php
/**
 * Plugin Name: Woo AI Account Manager
 * Description: مدیریت و فروش خودکار اکانت‌های دیجیتال (اشتراکی/اختصاصی) با WooCommerce + جداول MySQL + ایمیل + شورت‌کد.
 * Version: 1.2.0
 * Author: Your Name
 * License: GPLv2 or later
 */

if ( ! defined('ABSPATH') ) exit;

class Woo_AI_Account_Manager {
    private static $inst = null;
    private $tbl_inv;
    private $tbl_asg;

    public static function instance() {
        return self::$inst ?: (self::$inst = new self());
    }

    private function __construct() {
        global $wpdb;
        $this->tbl_inv = $wpdb->prefix . 'inventory_accounts';
        $this->tbl_asg = $wpdb->prefix . 'customer_assignments';

        register_activation_hook(__FILE__, [$this, 'on_activate']);

        // Admin UI
        add_action('admin_menu', [$this, 'admin_menu']);

        // WooCommerce order hooks
        add_action('woocommerce_order_status_processing', [$this, 'assign_for_order']);
        add_action('woocommerce_order_status_completed',  [$this, 'assign_for_order']);

        // Shortcode (customer panel)
        add_shortcode('ai_account_assignments', [$this, 'shortcode_assignments']);
    }

    /* ================= Activation: create tables ================= */
    public function on_activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE {$this->tbl_inv} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id_woocommerce BIGINT UNSIGNED NOT NULL,
            account_email VARCHAR(191) NOT NULL,
            password_ref VARCHAR(255) NOT NULL,
            max_capacity INT NOT NULL DEFAULT 1,
            current_users INT NOT NULL DEFAULT 0,
            status ENUM('free','use','close') NOT NULL DEFAULT 'free',
            type ENUM('shared','dedicated') NOT NULL DEFAULT 'dedicated',
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY pid (product_id_woocommerce),
            KEY status (status),
            KEY type (type)
        ) $collate;";

        $sql2 = "CREATE TABLE {$this->tbl_asg} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_email VARCHAR(191) NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            start_date DATETIME NOT NULL,
            end_date DATETIME NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            PRIMARY KEY (id),
            KEY cust (customer_email),
            KEY acc (account_id),
            KEY ord (order_id)
        ) $collate;";

        dbDelta($sql1);
        dbDelta($sql2);
    }

    /* ================= Admin Menu & Pages ================= */
    public function admin_menu() {
        add_menu_page(
            'AI Accounts', 'AI Accounts', 'manage_options', 'ai-accounts',
            [$this, 'page_inventory'], 'dashicons-shield', 56
        );
        add_submenu_page('ai-accounts', 'Inventory', 'Inventory', 'manage_options', 'ai-accounts',        [$this, 'page_inventory']);
        add_submenu_page('ai-accounts', 'Reports',   'Reports',   'manage_options', 'ai-accounts-reports',[$this, 'page_reports']);
        add_submenu_page('ai-accounts', 'Import CSV','Import CSV','manage_options', 'ai-accounts-import', [$this, 'page_import']);
    }

    public function page_inventory() {
        if ( ! current_user_can('manage_options') ) return;
        global $wpdb;

        // Add row
        if ( isset($_POST['ai_add_nonce']) && wp_verify_nonce($_POST['ai_add_nonce'], 'ai_add') ) {
            $pid     = absint($_POST['product_id']);
            $email   = sanitize_text_field($_POST['account_email']);
            $pass    = sanitize_text_field($_POST['password_plain']);
            $type    = in_array($_POST['type'] ?? 'dedicated', ['shared','dedicated'], true) ? $_POST['type'] : 'dedicated';
            $max     = max(1, absint($_POST['max_capacity']));
            $status  = sanitize_text_field($_POST['status'] ?? 'free');
            $notes   = sanitize_textarea_field($_POST['notes'] ?? '');

            $wpdb->insert($this->tbl_inv, [
                'product_id_woocommerce' => $pid,
                'account_email'          => $email,
                'password_ref'           => $pass,              // ساده؛ در صورت نیاز رمزنگاری اضافه کنید
                'max_capacity'           => $max,
                'current_users'          => 0,
                'status'                 => $status,
                'type'                   => $type,
                'notes'                  => $notes,
                'created_at'             => current_time('mysql'),
                'updated_at'             => current_time('mysql'),
            ], ['%d','%s','%s','%d','%d','%s','%s','%s','%s','%s']);
            echo '<div class="notice notice-success"><p>اکانت جدید اضافه شد.</p></div>';
        }

        // Delete row
        if ( isset($_GET['del']) && check_admin_referer('ai_del_' . absint($_GET['del'])) ) {
            $wpdb->delete($this->tbl_inv, ['id' => absint($_GET['del'])], ['%d']);
            echo '<div class="notice notice-success"><p>اکانت حذف شد.</p></div>';
        }

        echo '<div class="wrap"><h1>مدیریت موجودی اکانت‌ها</h1>';
        echo '<h2>افزودن</h2><form method="post">';
        wp_nonce_field('ai_add', 'ai_add_nonce');
        echo '<table class="form-table"><tbody>
            <tr><th>Product ID (Woo)</th><td><input required name="product_id" type="number" class="regular-text"></td></tr>
            <tr><th>ایمیل اکانت</th><td><input required name="account_email" type="text" class="regular-text"></td></tr>
            <tr><th>رمز عبور</th><td><input required name="password_plain" type="text" class="regular-text"></td></tr>
            <tr><th>نوع</th><td><select name="type"><option value="dedicated">اختصاصی</option><option value="shared">اشتراکی</option></select></td></tr>
            <tr><th>ظرفیت حداکثر</th><td><input name="max_capacity" type="number" value="1" min="1"></td></tr>
            <tr><th>وضعیت</th><td><select name="status"><option value="free">free</option><option value="use">use</option><option value="close">close</option></select></td></tr>
            <tr><th>یادداشت</th><td><textarea name="notes" class="large-text" rows="3"></textarea></td></tr>
        </tbody></table><p><button class="button button-primary">ذخیره</button></p></form>';

        $rows = $wpdb->get_results("SELECT * FROM {$this->tbl_inv} ORDER BY id DESC LIMIT 500", ARRAY_A);
        echo '<hr><h2>لیست موجودی</h2>';
        echo '<table class="widefat fixed striped"><thead><tr>
                <th>ID</th><th>Product</th><th>Email</th><th>Max</th><th>Cur</th><th>Status</th><th>Type</th><th>Actions</th>
              </tr></thead><tbody>';
        foreach ($rows as $r) {
            $del = wp_nonce_url(admin_url('admin.php?page=ai-accounts&del=' . (int)$r['id']), 'ai_del_' . (int)$r['id']);
            printf(
                '<tr><td>%d</td><td>%d</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td>
                <td><a class="button" href="%s" onclick="return confirm(\'حذف شود؟\')">Delete</a></td></tr>',
                (int)$r['id'], (int)$r['product_id_woocommerce'], esc_html($r['account_email']),
                (int)$r['max_capacity'], (int)$r['current_users'], esc_html($r['status']), esc_html($r['type']),
                esc_url($del)
            );
        }
        echo '</tbody></table></div>';
    }

    public function page_reports() {
        if ( ! current_user_can('manage_options') ) return;
        global $wpdb;
        $totals = $wpdb->get_row("SELECT COUNT(*) total,
            SUM(CASE WHEN status='free'  THEN 1 ELSE 0 END) free_cnt,
            SUM(CASE WHEN status='use'   THEN 1 ELSE 0 END) use_cnt,
            SUM(CASE WHEN status='close' THEN 1 ELSE 0 END) close_cnt
            FROM {$this->tbl_inv}", ARRAY_A);

        $assigned = $wpdb->get_var("SELECT COUNT(*) FROM {$this->tbl_asg} WHERE status='active'");

        echo '<div class="wrap"><h1>گزارش وضعیت</h1><table class="widefat fixed"><tbody>';
        printf('<tr><th>کل اکانت‌ها</th><td>%d</td></tr>', (int)($totals['total'] ?? 0));
        printf('<tr><th>FREE</th><td>%d</td></tr>', (int)($totals['free_cnt'] ?? 0));
        printf('<tr><th>USE</th><td>%d</td></tr>',  (int)($totals['use_cnt'] ?? 0));
        printf('<tr><th>CLOSE</th><td>%d</td></tr>',(int)($totals['close_cnt'] ?? 0));
        printf('<tr><th>تخصیص‌های فعال</th><td>%d</td></tr>', (int)$assigned);
        echo '</tbody></table>';

        echo '<h2>آخرین 50 تخصیص</h2>';
        $rows = $wpdb->get_results("SELECT a.id, a.customer_email, a.order_id, a.start_date, inv.account_email, inv.type
            FROM {$this->tbl_asg} a JOIN {$this->tbl_inv} inv ON inv.id = a.account_id
            ORDER BY a.id DESC LIMIT 50", ARRAY_A);
        echo '<table class="widefat fixed striped"><thead><tr>
                <th>ID</th><th>Customer</th><th>Order</th><th>Start</th><th>Account</th><th>Type</th>
              </tr></thead><tbody>';
        foreach ($rows as $r) {
            printf('<tr><td>%d</td><td>%s</td><td>#%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                (int)$r['id'], esc_html($r['customer_email']), (int)$r['order_id'],
                esc_html($r['start_date']), esc_html($r['account_email']), esc_html($r['type'])
            );
        }
        echo '</tbody></table></div>';
    }

    /* ================= CSV Import Page ================= */
    public function page_import() {
        if ( ! current_user_can('manage_options') ) return;
        echo '<div class="wrap"><h1>Import CSV → Inventory</h1>';
        echo '<p>Expected headers (UTF-8): <code>status,current_users,max_capacity,password_ref,account_email,plan_type,duration,Product_Type,product_id,account_id</code></p>';

        if ( isset($_POST['ai_csv_nonce']) && wp_verify_nonce($_POST['ai_csv_nonce'], 'ai_csv') && !empty($_FILES['csv']['tmp_name']) ) {
            $file = $_FILES['csv'];
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle) {
                // read header
                $header = fgetcsv($handle);
                $count  = 0;
                while (($row = fgetcsv($handle)) !== false) {
                    $row = array_map('trim', $row);
                    $map = array_combine($header, $row);

                    $status  = $this->sanitize_status($map['status'] ?? 'free');
                    $cur     = max(0, intval($map['current_users'] ?? 0));
                    $max     = max(1, intval($map['max_capacity'] ?? 1));
                    $pass    = sanitize_text_field($map['password_ref'] ?? '');
                    $email   = sanitize_text_field($map['account_email'] ?? '');
                    $plan    = sanitize_text_field($map['plan_type'] ?? '');
                    $dur     = sanitize_text_field($map['duration'] ?? '');
                    $ptype   = sanitize_text_field($map['Product_Type'] ?? ($map['product_type'] ?? ''));
                    $pid_raw = $map['product_id'] ?? ($map['WooCommerce Product ID'] ?? '');
                    $pid     = absint(str_replace('#','', $pid_raw));

                    $type    = ($plan === 'اشتراکی' || strtolower($plan) === 'shared') ? 'shared' : 'dedicated';
                    $notes   = trim($plan . ' - ' . $dur . ' - ' . $ptype, ' -');

                    global $wpdb;
                    $wpdb->insert($this->tbl_inv, [
                        'product_id_woocommerce' => $pid,
                        'account_email'          => $email,
                        'password_ref'           => $pass,
                        'max_capacity'           => $max,
                        'current_users'          => $cur,
                        'status'                 => $status,
                        'type'                   => $type,
                        'notes'                  => $notes,
                        'created_at'             => current_time('mysql'),
                        'updated_at'             => current_time('mysql'),
                    ], ['%d','%s','%s','%d','%d','%s','%s','%s','%s','%s']);
                    $count++;
                }
                fclose($handle);
                echo '<div class="notice notice-success"><p>وارد شد: ' . intval($count) . ' ردیف.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>فایل قابل خواندن نیست.</p></div>';
            }
        }

        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('ai_csv', 'ai_csv_nonce');
        echo '<input type="file" name="csv" accept=".csv" required> ';
        echo '<button class="button button-primary">Import</button>';
        echo '</form></div>';
    }

    private function sanitize_status($s) {
        $s = strtolower(trim($s));
        if (!in_array($s, ['free','use','close'], true)) $s = 'free';
        return $s;
    }

    /* ================= WooCommerce: assignment logic ================= */
    public function assign_for_order($order_id) {
        if ( ! function_exists('wc_get_order') ) return;
        $order = wc_get_order($order_id);
        if ( ! $order ) return;

        $customer_email = $order->get_billing_email();
        if ( ! $customer_email ) {
            $user = $order->get_user();
            if ($user) $customer_email = $user->user_email;
        }
        if ( ! $customer_email ) return;

        $assigned_msgs = [];

        foreach ($order->get_items() as $item) {
            $pid = $item->get_product_id();
            if ( ! $pid ) continue;

            $result = $this->allocate_account($pid, $customer_email, $order_id);
            if ($result && !empty($result['account_email'])) {
                $assigned_msgs[] = sprintf(
                    "Product #%d → %s / %s",
                    $pid, $result['account_email'], $result['password_ref']
                );
            }
        }

        // Send email to customer (if we assigned anything)
        if ($assigned_msgs) {
            $subject = __('Your AI Account Details', 'woo-ai-acc');
            $body  = "سلام,\n\nاطلاعات دسترسی شما:\n";
            $body .= implode("\n", $assigned_msgs);
            $body .= "\n\nبا تشکر.";
            wp_mail($customer_email, $subject, $body);
        }
    }

    /**
     * Allocate an account for a Woo product ID.
     * - If any dedicated rows exist for product → assign first FREE dedicated.
     * - Else treat as shared pool → pick row with current_users < max_capacity, preferring highest current_users.
     */
    private function allocate_account($product_id, $customer_email, $order_id) {
        global $wpdb;

        // First check if there are "dedicated" rows for this product
        $has_dedicated = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tbl_inv} WHERE product_id_woocommerce=%d AND type='dedicated'", $product_id
        ));

        if ($has_dedicated) {
            // Dedicated logic: first free
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->tbl_inv}
                 WHERE product_id_woocommerce=%d AND type='dedicated' AND status='free'
                 ORDER BY id ASC LIMIT 1", $product_id
            ), ARRAY_A);

            if ( ! $row ) return false;

            // Mark as used
            $wpdb->update($this->tbl_inv,
                ['status' => 'use', 'updated_at' => current_time('mysql')],
                ['id' => $row['id']],
                ['%s','%s'], ['%d']
            );

            $this->record_assignment($customer_email, $row['id'], $order_id);

            return $row; // contains account_email/password_ref
        }

        // Shared logic: prefer rows close to full (highest current_users) but not full
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tbl_inv}
             WHERE product_id_woocommerce=%d AND type='shared' AND current_users < max_capacity AND status IN ('free','use')
             ORDER BY current_users DESC, id ASC LIMIT 1", $product_id
        ), ARRAY_A);

        if ( ! $row ) return false;

        $new_cur = (int)$row['current_users'] + 1;
        $new_status = ($new_cur >= (int)$row['max_capacity']) ? 'close' : 'use';

        $wpdb->update($this->tbl_inv,
            ['current_users' => $new_cur, 'status' => $new_status, 'updated_at' => current_time('mysql')],
            ['id' => $row['id']],
            ['%d','%s','%s'], ['%d']
        );

        $this->record_assignment($customer_email, $row['id'], $order_id);

        return $row;
    }

    private function record_assignment($customer_email, $account_id, $order_id) {
        global $wpdb;
        $wpdb->insert($this->tbl_asg, [
            'customer_email' => sanitize_email($customer_email),
            'account_id'     => (int)$account_id,
            'order_id'       => (int)$order_id,
            'start_date'     => current_time('mysql'),
            'end_date'       => null,
            'status'         => 'active',
        ], ['%s','%d','%d','%s','%s','%s']);
    }

    /* ================= Shortcode (customer view) ================= */
    public function shortcode_assignments($atts = []) {
        if ( ! is_user_logged_in() ) return '<p>لطفاً وارد شوید.</p>';
        $user = wp_get_current_user();
        if ( ! $user || ! $user->user_email ) return '<p>کاربر معتبر نیست.</p>';

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.start_date, inv.account_email, inv.password_ref, inv.product_id_woocommerce, inv.type
             FROM {$this->tbl_asg} a
             JOIN {$this->tbl_inv} inv ON inv.id = a.account_id
             WHERE a.customer_email = %s AND a.status='active'
             ORDER BY a.id DESC LIMIT 100", $user->user_email
        ), ARRAY_A);

        if ( ! $rows ) return '<p>هیچ اکانت فعالی برای شما ثبت نشده است.</p>';

        ob_start();
        echo '<table class="widefat fixed striped"><thead><tr>
                <th>شروع</th><th>ایمیل اکانت</th><th>رمز</th><th>Product ID</th><th>نوع</th>
              </tr></thead><tbody>';
        foreach ($rows as $r) {
            printf('<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>#%d</td><td>%s</td></tr>',
                esc_html($r['start_date']), esc_html($r['account_email']), esc_html($r['password_ref']),
                (int)$r['product_id_woocommerce'], esc_html($r['type'])
            );
        }
        echo '</tbody></table>';
        return ob_get_clean();
    }
}

Woo_AI_Account_Manager::instance();

