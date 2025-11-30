<?php
/**
 * Plugin Name: DC Customers Manager
 * Description: ניהול רשימת לקוחות מרכזית לכל התוספים + ייבוא/ייצוא מאקסל (CSV).
 * Version: 1.2.0
 * Author: Ofri
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DC_Customers_Manager {

    private static $instance = null;
    private $table_name;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'dc_customers';

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_shortcode( 'dc_customers_manager', array( $this, 'render_shortcode' ) );
        add_shortcode( 'dc_customers_trash', array( $this, 'render_trash_shortcode' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'init', array( $this, 'handle_post_requests' ) );
    }

    /**
     * הפעלת התוסף – יצירת הטבלה
     */
    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_name VARCHAR(255) NOT NULL,
            customer_number VARCHAR(6) NOT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY unique_customer_name (customer_name),
            UNIQUE KEY unique_customer_number (customer_number),
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * טעינת CSS מודרני
     */
    public function enqueue_assets() {
        wp_register_style(
            'dc-customers-css',
            plugins_url( 'assets/customers.css', __FILE__ ),
            array(),
            '1.2.0'
        );
        wp_enqueue_style( 'dc-customers-css' );

        // אפשר להוסיף גם JS לעריכה מהירה/Select All וכו'
        wp_register_script(
            'dc-customers-js',
            plugins_url( 'assets/customers.js', __FILE__ ),
            array( 'jquery' ),
            '1.2.0',
            true
        );
        wp_enqueue_script( 'dc-customers-js' );
    }

    /**
     * ולידציה ללקוח, כולל מניעת כפילות
     */
    private function validate_customer( $name, $number, $id = null, &$errors = array() ) {
        $name   = trim( $name );
        $number = trim( $number );

        if ( $name === '' ) {
            $errors[] = 'שם הלקוח חובה.';
        }

        if ( ! preg_match( '/^[0-9]{2,6}$/', $number ) ) {
            $errors[] = 'מספר הלקוח חייב להיות מספר בן 2 עד 6 ספרות.';
        }

        if ( ! empty( $errors ) ) {
            return false;
        }

        global $wpdb;

        // בדיקת כפילות שם (ללקוחות לא-נמחקים)
        $query  = "SELECT id FROM {$this->table_name} WHERE customer_name = %s AND is_deleted = 0";
        $params = array( $name );
        if ( $id ) {
            $query .= " AND id != %d";
            $params[] = $id;
        }
        $existing_name = $wpdb->get_var( $wpdb->prepare( $query, $params ) );
        if ( $existing_name ) {
            $errors[] = 'שם הלקוח כבר קיים.';
        }

        // בדיקת כפילות מספר (ללקוחות לא-נמחקים)
        $query  = "SELECT id FROM {$this->table_name} WHERE customer_number = %s AND is_deleted = 0";
        $params = array( $number );
        if ( $id ) {
            $query .= " AND id != %d";
            $params[] = $id;
        }
        $existing_number = $wpdb->get_var( $wpdb->prepare( $query, $params ) );
        if ( $existing_number ) {
            $errors[] = 'מספר הלקוח כבר קיים.';
        }

        return empty( $errors );
    }

    /**
     * טיפול בכל POST מהשורטקוד (CRUD + Import/Export)
     */
    public function handle_post_requests() {
        if ( empty( $_POST['dc_customers_action'] ) ) {
            return;
        }
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'dc_customers_action' ) ) {
            return;
        }

        $action = sanitize_text_field( $_POST['dc_customers_action'] );

        // ייצוא CSV – מייד מוציאים קובץ ויוצאים
        if ( $action === 'export_csv' ) {
            $this->export_csv();
            exit;
        }

        switch ( $action ) {
            case 'add_or_update':
                $this->handle_add_or_update();
                break;

            case 'soft_delete':
                $this->handle_soft_delete();
                break;

            case 'soft_delete_bulk':
                $this->handle_soft_delete_bulk();
                break;

            case 'delete_permanent':
                $this->handle_delete_permanent();
                break;

            case 'delete_permanent_all':
                $this->handle_delete_permanent_all();
                break;

            case 'import_csv':
                $this->handle_import_csv();
                break;
        }

        wp_safe_redirect( wp_get_referer() );
        exit;
    }

    /**
     * הוספה/עדכון לקוח
     */
    private function handle_add_or_update() {
        global $wpdb;

        $id              = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : null;
        $customer_name   = isset( $_POST['customer_name'] ) ? sanitize_text_field( $_POST['customer_name'] ) : '';
        $customer_number = isset( $_POST['customer_number'] ) ? sanitize_text_field( $_POST['customer_number'] ) : '';

        $errors = array();
        if ( ! $this->validate_customer( $customer_name, $customer_number, $id, $errors ) ) {
            set_transient( 'dc_customers_errors', $errors, 60 );
            return;
        }

        $now = current_time( 'mysql' );

        if ( $id ) {
            $wpdb->update(
                $this->table_name,
                array(
                    'customer_name'   => $customer_name,
                    'customer_number' => $customer_number,
                    'updated_at'      => $now,
                ),
                array( 'id' => $id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $this->table_name,
                array(
                    'customer_name'   => $customer_name,
                    'customer_number' => $customer_number,
                    'is_deleted'      => 0,
                    'deleted_at'      => null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ),
                array( '%s', '%s', '%d', '%s', '%s', '%s' )
            );
        }
    }

    /**
     * מחיקה רכה של רשומה בודדת (סל מחזור)
     */
    private function handle_soft_delete() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) return;

        $wpdb->update(
            $this->table_name,
            array(
                'is_deleted' => 1,
                'deleted_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $id ),
            array( '%d', '%s' ),
            array( '%d' )
        );
    }

    /**
     * מחיקה רכה של רשומות מסומנות (Bulk)
     */
    private function handle_soft_delete_bulk() {
        global $wpdb;
        if ( empty( $_POST['ids'] ) || ! is_array( $_POST['ids'] ) ) {
            return;
        }

        $ids = array_map( 'intval', $_POST['ids'] );
        $ids = array_filter( $ids );
        if ( empty( $ids ) ) return;

        $in_placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "UPDATE {$this->table_name}
                SET is_deleted = 1, deleted_at = %s
                WHERE id IN ($in_placeholder)";
        $params = array_merge( array( current_time( 'mysql' ) ), $ids );
        $wpdb->query( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * מחיקה לצמיתות של רשומה בודדת
     */
    private function handle_delete_permanent() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
        if ( ! $id ) return;

        $wpdb->delete( $this->table_name, array( 'id' => $id ), array( '%d' ) );
    }

    /**
     * מחיקה לצמיתות של כל סל המחזור
     */
    private function handle_delete_permanent_all() {
        global $wpdb;
        $sql = "DELETE FROM {$this->table_name} WHERE is_deleted = 1";
        $wpdb->query( $sql );
    }

    /**
     * ייבוא לקוחות מתוך CSV (שנפתח באקסל)
     */
    private function handle_import_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            set_transient( 'dc_customers_errors', array( 'אין לך הרשאות ליבוא.' ), 60 );
            return;
        }

        if ( empty( $_FILES['customers_file']['tmp_name'] ) ) {
            set_transient( 'dc_customers_errors', array( 'לא נבחר קובץ ליבוא.' ), 60 );
            return;
        }

        $file_tmp = $_FILES['customers_file']['tmp_name'];

        $handle = fopen( $file_tmp, 'r' );
        if ( ! $handle ) {
            set_transient( 'dc_customers_errors', array( 'לא ניתן לקרוא את הקובץ.' ), 60 );
            return;
        }

        $row_num  = 0;
        $imported = 0;
        $skipped  = 0;
        $errors   = array();

        global $wpdb;

        /*
         * פורמט צפוי (כמו ביצוא):
         * customer_name, customer_number, is_deleted, deleted_at, created_at, updated_at
         * בפועל – לייבוא מספיקים לנו customer_name, customer_number.
         */
        while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
            $row_num++;

            // דילוג על שורת כותרת אם יש
            if ( $row_num === 1 ) {
                if ( isset( $data[0] ) && strtolower( $data[0] ) === 'customer_name' ) {
                    continue;
                }
            }

            $customer_name   = isset( $data[0] ) ? trim( $data[0] ) : '';
            $customer_number = isset( $data[1] ) ? trim( $data[1] ) : '';

            if ( $customer_name === '' && $customer_number === '' ) {
                continue; // שורה ריקה
            }

            $row_errors = array();
            if ( ! $this->validate_customer( $customer_name, $customer_number, null, $row_errors ) ) {
                $skipped++;
                foreach ( $row_errors as $re ) {
                    $errors[] = "שורה {$row_num}: " . $re;
                }
                continue;
            }

            $now = current_time( 'mysql' );

            // כרגע – מוסיפים רק רשומות חדשות, לא עדכון קיימות
            $wpdb->insert(
                $this->table_name,
                array(
                    'customer_name'   => $customer_name,
                    'customer_number' => $customer_number,
                    'is_deleted'      => 0,
                    'deleted_at'      => null,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ),
                array( '%s', '%s', '%d', '%s', '%s', '%s' )
            );

            $imported++;
        }

        fclose( $handle );

        $summary = array();
        $summary[] = "ייבוא הסתיים. נוספו {$imported} רשומות חדשות.";
        if ( $skipped > 0 ) {
            $summary[] = "דילגנו על {$skipped} שורות בגלל שגיאות או כפילויות.";
        }
        $errors = array_merge( $summary, $errors );

        set_transient( 'dc_customers_errors', $errors, 120 );
    }

    /**
     * ייצוא לקוחות ל-CSV (אקסל)
     */
    public function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'אין לך הרשאות לייצוא.' );
        }

        // לוקחים את כל הלקוחות (כולל נמחקים) כדי שיהיה גיבוי מלא
        $customers = $this->get_customers( true, '', 'customer_name', 'ASC' );

        $filename = 'customers-' . date( 'Ymd-His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );

        $output = fopen( 'php://output', 'w' );

        // כותרת
        fputcsv( $output, array(
            'customer_name',
            'customer_number',
            'is_deleted',
            'deleted_at',
            'created_at',
            'updated_at',
        ) );

        if ( $customers ) {
            foreach ( $customers as $c ) {
                fputcsv( $output, array(
                    $c->customer_name,
                    $c->customer_number,
                    $c->is_deleted,
                    $c->deleted_at,
                    $c->created_at,
                    $c->updated_at,
                ) );
            }
        }

        fclose( $output );
    }

    /**
     * קבלת רשימת לקוחות עם אפשרות לסינון/חיפוש/מיון
     */
    private function get_customers( $include_deleted = false, $search = '', $orderby = 'customer_name', $order = 'ASC' ) {
        global $wpdb;

        $allowed_orderby = array( 'id', 'customer_name', 'customer_number', 'created_at' );
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
            $orderby = 'customer_name';
        }

        $order = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';

        $where  = 'WHERE 1=1';
        $params = array();

        if ( ! $include_deleted ) {
            $where .= ' AND is_deleted = 0';
        }

        if ( $search !== '' ) {
            $where .= ' AND (customer_name LIKE %s OR customer_number LIKE %s)';
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = "SELECT * FROM {$this->table_name} {$where} ORDER BY {$orderby} {$order}";
        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare( $sql, $params );
        }

        return $wpdb->get_results( $sql );
    }

    /**
     * שורטקוד – ניהול לקוחות
     */
    public function render_shortcode( $atts ) {
        return $this->render_layout( 'customers' );
    }

    /**
     * שורטקוד – סל מחזור
     */
    public function render_trash_shortcode( $atts ) {
        return $this->render_layout( 'trash' );
    }

    /**
     * שרטוט הממשק לפי תצוגה
     */
    private function render_layout( $view = 'customers' ) {
        $view_request = isset( $_GET['dc_c_view'] ) ? sanitize_key( $_GET['dc_c_view'] ) : $view;
        $view         = in_array( $view_request, array( 'customers', 'trash' ), true ) ? $view_request : 'customers';

        $search  = isset( $_GET['dc_c_search'] ) ? sanitize_text_field( $_GET['dc_c_search'] ) : '';
        $orderby = isset( $_GET['dc_c_orderby'] ) ? sanitize_key( $_GET['dc_c_orderby'] ) : 'customer_name';
        $order   = isset( $_GET['dc_c_order'] ) ? sanitize_key( $_GET['dc_c_order'] )   : 'ASC';

        $customers         = $view === 'customers' ? $this->get_customers( false, $search, $orderby, $order ) : array();
        $deleted_customers = $this->get_customers( true,  $search, $orderby, $order );

        $errors = get_transient( 'dc_customers_errors' );
        delete_transient( 'dc_customers_errors' );

        $base_url    = remove_query_arg( array( 'dc_c_view', 'dc_c_orderby', 'dc_c_order' ) );
        $manager_url = add_query_arg( 'dc_c_view', 'customers', $base_url );
        $trash_url   = add_query_arg( 'dc_c_view', 'trash', $base_url );

        ob_start();
        ?>
        <div class="dc-customers-wrap">
            <div class="dc-nav-buttons">
                <a class="dc-nav-button <?php echo $view === 'customers' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $manager_url ); ?>">
                    <span class="dc-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </span>
                    ניהול לקוחות
                </a>
                <a class="dc-nav-button" href="<?php echo esc_url( $manager_url . '#bulk-actions' ); ?>">
                    <span class="dc-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M5 7h14M5 12h10M5 17h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    </span>
                    עריכה קבוצתית
                </a>
                <a class="dc-nav-button <?php echo $view === 'trash' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $trash_url ); ?>">
                    <span class="dc-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M9 6h6m-7 3h8l-.7 9.1a2 2 0 0 1-2 1.9H10a2 2 0 0 1-2-1.9L7.3 9M10 11v6m4-6v6M5 6h14M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    סל מחזור
                </a>
            </div>

            <?php if ( ! empty( $errors ) ) : ?>
                <div class="dc-errors">
                    <?php foreach ( $errors as $e ) : ?>
                        <p><?php echo esc_html( $e ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="get" class="dc-search-form">
                <input type="text"
                       name="dc_c_search"
                       value="<?php echo esc_attr( $search ); ?>"
                       placeholder="חיפוש לפי שם או מספר לקוח">
                <button type="submit" class="dc-btn-primary">
                    <span class="dc-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="m15 15 4 4m-7-3a5 5 0 1 1 0-10 5 5 0 0 1 0 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    חיפוש
                </button>
            </form>

            <?php if ( $view === 'customers' ) : ?>
                <h3>הוספת / עריכת לקוח</h3>
                <form method="post" class="dc-form-modern dc-form-customer">
                    <?php wp_nonce_field( 'dc_customers_action' ); ?>
                    <input type="hidden" name="dc_customers_action" value="add_or_update">
                    <input type="hidden" name="id" value="">

                    <div class="dc-field">
                        <label>שם לקוח</label>
                        <input type="text" name="customer_name" required>
                    </div>
                    <div class="dc-field">
                        <label>מספר לקוח (2–6 ספרות)</label>
                        <input type="text"
                               name="customer_number"
                               pattern="[0-9]{2,6}"
                               required>
                    </div>

                    <button type="submit" class="dc-btn-primary">
                        <span class="dc-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M5 12h14M12 5v14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        </span>
                        שמירה
                    </button>
                </form>

                <h3>רשימת לקוחות</h3>
                <form method="post">
                    <?php wp_nonce_field( 'dc_customers_action' ); ?>
                    <input type="hidden" name="dc_customers_action" value="soft_delete_bulk">

                    <table class="dc-table-modern">
                        <thead>
                            <tr>
                                <th><input type="checkbox" class="dc-select-all"></th>
                                <th>
                                    <a href="?dc_c_orderby=customer_name&dc_c_order=<?php echo $order === 'ASC' ? 'DESC' : 'ASC'; ?>">
                                        שם לקוח
                                    </a>
                                </th>
                                <th>
                                    <a href="?dc_c_orderby=customer_number&dc_c_order=<?php echo $order === 'ASC' ? 'DESC' : 'ASC'; ?>">
                                        מספר לקוח
                                    </a>
                                </th>
                                <th>פעולות</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( $customers ) : ?>
                                <?php foreach ( $customers as $c ) : ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="ids[]" value="<?php echo esc_attr( $c->id ); ?>">
                                        </td>
                                        <td><?php echo esc_html( $c->customer_name ); ?></td>
                                        <td><?php echo esc_html( $c->customer_number ); ?></td>
                                        <td>
                                            <button type="button"
                                                    class="dc-btn-secondary dc-edit-customer"
                                                    data-id="<?php echo esc_attr( $c->id ); ?>"
                                                    data-name="<?php echo esc_attr( $c->customer_name ); ?>"
                                                    data-number="<?php echo esc_attr( $c->customer_number ); ?>">
                                                <span class="dc-icon" aria-hidden="true">
                                                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="m5 17 4.5-.8 9-9a1 1 0 0 0-1.4-1.4l-9 9L5 17Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 6 18 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </span>
                                                עריכה
                                            </button>

                                            <form method="post" style="display:inline;">
                                                <?php wp_nonce_field( 'dc_customers_action' ); ?>
                                                <input type="hidden" name="dc_customers_action" value="soft_delete">
                                                <input type="hidden" name="id" value="<?php echo esc_attr( $c->id ); ?>">
                                                <button type="submit" class="dc-btn-danger">
                                                    <span class="dc-icon" aria-hidden="true">
                                                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M9 6h6m-7 3h8l-.7 9.1a2 2 0 0 1-2 1.9H10a2 2 0 0 1-2-1.9L7.3 9M10 11v6m4-6v6M5 6h14M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                    </span>
                                                    מחיקה
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="4">לא נמצאו לקוחות.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <div id="bulk-actions">
                        <button type="submit" class="dc-btn-danger">
                            <span class="dc-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M5 6h14M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M9 11v6m4-6v6m-6-8h10l-.7 9.1a2 2 0 0 1-2 1.9H9.7a2 2 0 0 1-2-1.9L7 11Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            מחיקת רשומות מסומנות (לסל מחזור)
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ( $view === 'trash' ) : ?>
                <h3>סל מחזור</h3>
                <table class="dc-table-modern dc-trash-table">
                    <thead>
                        <tr>
                            <th>שם לקוח</th>
                            <th>מספר לקוח</th>
                            <th>נמחק בתאריך</th>
                            <th>מחיקה לצמיתות</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $has_deleted = false;
                        if ( $deleted_customers ) :
                            foreach ( $deleted_customers as $c ) :
                                if ( ! $c->is_deleted ) {
                                    continue;
                                }
                                $has_deleted = true;
                                ?>
                                <tr>
                                    <td><?php echo esc_html( $c->customer_name ); ?></td>
                                    <td><?php echo esc_html( $c->customer_number ); ?></td>
                                    <td><?php echo esc_html( $c->deleted_at ); ?></td>
                                    <td>
                                        <form method="post">
                                            <?php wp_nonce_field( 'dc_customers_action' ); ?>
                                            <input type="hidden" name="dc_customers_action" value="delete_permanent">
                                            <input type="hidden" name="id" value="<?php echo esc_attr( $c->id ); ?>">
                                            <button type="submit" class="dc-btn-danger">
                                                <span class="dc-icon" aria-hidden="true">
                                                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M5 6h14M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M9 11v6m4-6v6m-6-8h10l-.7 9.1a2 2 0 0 1-2 1.9H9.7a2 2 0 0 1-2-1.9L7 11Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                </span>
                                                מחיקה לצמיתות
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php
                            endforeach;
                        endif;

                        if ( ! $has_deleted ) :
                            ?>
                            <tr>
                                <td colspan="4">סל המחזור ריק.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ( $has_deleted ) : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'dc_customers_action' ); ?>
                        <input type="hidden" name="dc_customers_action" value="delete_permanent_all">
                        <button type="submit" class="dc-btn-danger">
                            <span class="dc-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M5 6h14M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M9 11v6m4-6v6m-6-8h10l-.7 9.1a2 2 0 0 1-2 1.9H9.7a2 2 0 0 1-2-1.9L7 11Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            מחיקת כל סל המחזור לצמיתות
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <div class="dc-import-export">
                <form method="post" class="dc-icon-only">
                    <?php wp_nonce_field( 'dc_customers_action' ); ?>
                    <input type="hidden" name="dc_customers_action" value="export_csv">
                    <button type="submit" class="dc-btn-secondary">
                        <span class="dc-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 16v-9m0 9-3-3m3 3 3-3M6 18h12a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        ייצוא ל-Excel (CSV)
                    </button>
                </form>

                <form method="post" enctype="multipart/form-data" class="dc-icon-only">
                    <?php wp_nonce_field( 'dc_customers_action' ); ?>
                    <input type="hidden" name="dc_customers_action" value="import_csv">
                    <input type="file" name="customers_file" accept=".csv" required>
                    <button type="submit" class="dc-btn-secondary">
                        <span class="dc-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12 8v9m0-9-3 3m3-3 3 3M6 6h12a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        ייבוא מ-Excel (CSV)
                    </button>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * פונקציה סטטית לשימוש מתוספים אחרים – כל הלקוחות הפעילים
     */
    public static function get_all_customers() {
        $self = self::instance();
        global $wpdb;
        $sql = "SELECT * FROM {$self->table_name} WHERE is_deleted = 0 ORDER BY customer_name ASC";
        return $wpdb->get_results( $sql );
    }

    /**
     * פונקציה סטטית – חיפוש לקוח לפי שם/מספר
     */
    public static function find_customer_by_name_or_number( $query ) {
        $self = self::instance();
        global $wpdb;
        $q = '%' . $wpdb->esc_like( $query ) . '%';
        $sql = $wpdb->prepare(
            "SELECT * FROM {$self->table_name}
             WHERE is_deleted = 0 AND (customer_name LIKE %s OR customer_number LIKE %s)",
            $q, $q
        );
        return $wpdb->get_results( $sql );
    }
}

DC_Customers_Manager::instance();
