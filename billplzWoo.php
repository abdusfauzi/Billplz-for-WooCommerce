<?php
/**
 * Plugin Name: Billplz for WooCommerce
 * Plugin URI: https://wordpress.org/plugins-wp/billplz-for-woocommerce/
 * Description: Billplz Payment Gateway | Accept Payment using all participating FPX Banking Channels. <a href="https://www.billplz.com/join/8ant7x743awpuaqcxtqufg" target="_blank">Sign up Now</a>.
 * Author: Wanzul Hosting Enterprise
 * Author URI: http://www.wanzul-hosting.com/
 * Version: 3.15
 * License: GPLv3
 * Text Domain: wcbillplz
 * Domain Path: /languages/
 */
// Add settings link on plugin page

function billplz_for_woocommerce_plugin_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=billplz">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'billplz_for_woocommerce_plugin_settings_link');

function wcbillplz_woocommerce_fallback_notice() {
    $message = '<div class="error">';
    $message .= '<p>' . __('WooCommerce Billplz Gateway depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!', 'wcbillplz') . '</p>';
    $message .= '</div>';
    echo $message;
}

// Load the function
add_action('plugins_loaded', 'wcbillplz_gateway_load', 0);

/**
 * Load Billplz gateway plugin function
 * 
 * @return mixed
 */
function wcbillplz_gateway_load() {
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'wcbillplz_woocommerce_fallback_notice');
        return;
    }
    // Load language
    load_plugin_textdomain('wcbillplz', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    add_filter('woocommerce_payment_gateways', 'wcbillplz_add_gateway');

    /**
     * Add Billplz gateway to ensure WooCommerce can load it
     * 
     * @param array $methods
     * @return array
     */
    function wcbillplz_add_gateway($methods) {
        $methods[] = 'WC_Billplz_Gateway';
        return $methods;
    }

    /**
     * Define the Billplz gateway
     * 
     */
    class WC_Billplz_Gateway extends WC_Payment_Gateway {

        /** @var bool Whether or not logging is enabled */
        public static $log_enabled = false;

        /** @var WC_Logger Logger instance */
        public static $log = false;

        /**
         * Construct the Billplz gateway class
         * 
         * @global mixed $woocommerce
         */
        public function __construct() {
            //global $woocommerce;

            $this->id = 'billplz';
            $this->icon = plugins_url('assets/billplz.gif', __FILE__);
            $this->has_fields = false;
            $this->method_title = __('Billplz', 'wcbillplz');
            $this->debug = 'yes' === $this->get_option('debug', 'no');

            // Load the form fields.
            $this->init_form_fields();
            // Load the settings.
            $this->init_settings();

            // Define user setting variables.
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->api_key = $this->settings['api_key'];
            $this->collection_id = $this->settings['collection_id'];
            $this->clearcart = $this->settings['clearcart'];
            $this->notification = $this->settings['notification'];
            $this->x_signature = $this->settings['x_signature'];
            $this->custom_error = $this->settings['custom_error'];

            // Payment instruction after payment
            $this->instructions = isset($this->settings['instructions']) ? $this->settings['instructions'] : '';

            add_action('woocommerce_thankyou_billplz', array($this, 'thankyou_page'));
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);

            self::$log_enabled = $this->debug;

            add_action('woocommerce_receipt_billplz', array(
                &$this,
                'receipt_page'
            ));
            // Save setting configuration
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                $this,
                'process_admin_options'
            ));
            // Payment listener/API hook
            add_action('woocommerce_api_wc_billplz_gateway', array(
                $this,
                'check_ipn_response'
            ));
            // Checking if api_key is not empty.
            $this->api_key == '' ? add_action('admin_notices', array(
                                &$this,
                                'api_key_missing_message'
                            )) : '';
            // Checking if x_signature is not empty.
            $this->x_signature == '' ? add_action('admin_notices', array(
                                &$this,
                                'x_signature_missing_message'
                            )) : '';
        }

        /**
         * Checking if this gateway is enabled and available in the user's country.
         *
         * @return bool
         */
        public function is_valid_for_use() {
            if (!in_array(get_woocommerce_currency(), array(
                        'MYR'
                    ))) {
                return false;
            }
            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis.
         *
         */
        public function admin_options() {
            ?>
            <h3><?php
                _e('Billplz Payment Gateway', 'wcbillplz');
                ?></h3>
            <p><?php
                _e('Billplz Payment Gateway works by sending the user to Billplz for payment. ', 'wcbillplz');
                ?></p>
            <p><?php
                _e('To immediately reduce stock on add to cart, we strongly recommend you to use this plugin. ', 'wcbillplz');
                ?><a href="http://bit.ly/1UDOQKi" target="_blank"><?php
                    _e('WooCommerce Cart Stock Reducer', 'wcbillplz');
                    ?></a></p>
            <p><?php
                _e('WP Cron are implemented by this plugin to manage Bills. ', 'wcbillplz');
                ?><a href="https://wordpress.org/plugins-wp/wp-crontrol/" target="_blank"><?php
                    _e('Check what inside WP-Cron', 'wcbillplz');
                    ?></a></p>
            <table class="form-table">
                <?php
                $this->generate_settings_html();
                ?>
            </table><!--/.form-table-->
            <?php
        }

        /**
         * Gateway Settings Form Fields.
         * 
         */
        public function init_form_fields() {
            $this->form_fields = include(__DIR__ . '/includes/settings-billplz.php');
        }

        /**
         * Generate the form.
         *
         * @param mixed $order_id
         * @return string
         */
        public function generate_form($order_id) {

            $order = new WC_Order($order_id);
            //----------------------------------------------------------------//
            if (sizeof($order->get_items()) > 0)
                foreach ($order->get_items() as $item)
                    if ($item['qty'])
                        $item_names[] = $item['name'] . ' x ' . $item['qty'];
            $desc = sprintf(__('Order %s', 'woocommerce'), $order->get_order_number()) . " - " . implode(', ', $item_names);

            // ---------------------------------------------------------------//

            /*
             *  Calculate MD5 Hash and Shorten it
             */
            $md5hash = substr(md5(strtolower($order->billing_first_name . $order->billing_last_name . $order->billing_email .
                                    $order->billing_phone . number_format($order->order_total) . $order_id . $this->collection_id)), 1, 6);

            if (get_post_meta($order_id, '_wc_billplz_hash', true) === '') {
                update_post_meta($order_id, '_wc_billplz_hash', $md5hash);
                $bills = $this->create_bill($order, $this->notification, $order_id, $desc);
                update_post_meta($order_id, '_wc_billplz_id', $bills['id']);
                update_post_meta($order_id, '_wc_billplz_url', $bills['url']);
                $url = $bills['url'];
            } elseif (get_post_meta($order_id, '_wc_billplz_hash', true) == $md5hash) {
                $url = get_post_meta($order_id, '_wc_billplz_url', true);
            } else {
                update_post_meta($order_id, '_wc_billplz_hash', $md5hash);
                $bills = $this->create_bill($order, $this->notification, $order_id, $desc);
                add_post_meta($order_id, '_wc_billplz_id', $bills['id']);
                update_post_meta($order_id, '_wc_billplz_url', $bills['url']);
                $url = $bills['url'];
            }

            if (!headers_sent()) {
                wp_redirect(esc_url_raw($url));
                die();
            } else {

                //----------------------------------------------------------------//
                $ready = "If you are not redirected, please click <a href=" . '"' . $url . '"' . " target='_self'>Here</a><br />"
                        . "<a class='button cancel' href='" . $order->get_cancel_order_url() . "'>" . __('Cancel order &amp; restore cart', 'woothemes') . "</a>"
                        . "<script>location.href = '" . $url . "'</script>";
                return $ready;
            }
        }

        /**
         * Create bills function
         * Save to database
         * 
         * @return string Return URL
         */
        protected function create_bill($order, $deliver, $order_id, $desc) {
            require_once(__DIR__ . '/includes/billplz.php');
            $obj = new Billplz($this->api_key);
            
            $obj->setCollection($this->collection_id)
                    ->setName($order->billing_first_name . " " . $order->billing_last_name)
                    ->setAmount($order->order_total)
                    ->setDeliver($deliver)
                    ->setMobile($order->billing_phone)
                    ->setEmail($order->billing_email)
                    ->setDescription($desc)
                    ->setReference_1($order_id)
                    ->setReference_1_Label('Order ID')
                    ->setPassbackURL(home_url('/?wc-api=WC_Billplz_Gateway'), home_url('/?wc-api=WC_Billplz_Gateway'))
                    ->create_bill(true);

            // Log the bills creation
            self::log('Creating bills ' . $obj->getID() . ' for order number #' . $order_id);
            return array(
                'url' => $obj->getURL(),
                'id' => $obj->getID(),
            );
        }

        /**
         * Logging method.
         * @param string $message
         */
        public static function log($message) {
            if (self::$log_enabled) {
                if (empty(self::$log)) {
                    self::$log = new WC_Logger();
                }
                self::$log->add('billplz', $message);
            }
        }

        /**
         * Order error button.
         *
         * @param  object $order Order data.
         * @return string Error message and cancel button.
         */
        protected function billplz_order_error($order) {
            $html = '<p>' . __('An error has occurred while processing your payment, please try again. Or contact us for assistance.', 'wcbillplz') . '</p>';
            $html .= '<a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Click to try again', 'wcbillplz') . '</a>';
            return $html;
        }

        /**
         * Process the payment and return the result.
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id) {

            if ($this->clearcart === 'yes')
                WC()->cart->empty_cart();

            $order = new WC_Order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        /**
         * Output for the order received page.
         * WooCommerce send to this method and CURL it!
         * 
         */
        public function receipt_page($order) {
            echo $this->generate_form($order);
        }

        /**
         * Check for Billplz Response
         *
         * @access public
         * @return void
         */
        function check_ipn_response() {
            @ob_clean();
            //global $woocommerce;

            require_once(__DIR__ . '/includes/billplz.php');
            if (isset($_POST['id'])) {
                $signal = 'Callback';
                $data = Billplz::getCallbackData($this->x_signature);
                sleep(10);
            } elseif (isset($_GET['billplz']['id'])) {
                $signal = 'Return';
                $data = Billplz::getRedirectData($this->x_signature);
            } else {
                exit('Nothing to do');
            }

            // Log return type
            self::log('Response is: ' . $signal . ' ' . print_r($_REQUEST, true));

            $bill_id = $data['id'];

            $billplz = new Billplz($this->api_key);
            $moreData = $billplz->check_bill($bill_id);
            self::log('Bills Re-Query: ' . print_r($data, true));

            //$order_id = $this->find_order_id($bill_id);
            $order_id = $moreData['reference_1'];

            /*
              if (empty($order_id)) {
              self::log('Bill ID' . $bill_id . ' Not valid: ');
              exit;
              }
             * 
             */

            //self::log('Bill ID found: ' . $bill_id . ' for Order ID: ' . $order_id);

            $order = new WC_Order($order_id);

            if ($moreData['paid']) {
                $this->save_payment($order, $moreData, $signal);
                $redirectpath = $order->get_checkout_order_received_url();
            } else {
                $order->add_order_note('Payment Status: CANCELLED BY USER' . '<br>Bill ID: ' . $data['id']);
                wc_add_notice(__('ERROR: ', 'woothemes') . $this->custom_error, 'error');
                $redirectpath = $order->get_cancel_order_url();
            }

            if ($signal === 'Return')
                wp_redirect($redirectpath);
            else
                echo 'RECEIVEOK';
        }

        /*
          private function find_order_id($bill_id) {
          global $wpdb;
          $query = $wpdb->get_results("SELECT post_id FROM `" . $wpdb->postmeta . "` WHERE meta_key='_wc_billplz_id' AND meta_value='" . esc_sql($bill_id) . "'");
          return $query[0]->post_id;
          }
         * 
         */

        /**
         * Save payment status to DB for successful return/callback
         */
        private function save_payment($order, $data, $type) {
            $referer = "<br>Receipt URL: " . "<a href='" . urldecode($data['url']) . "' target=_blank>" . urldecode($data['url']) . "</a>";
            $referer .= "<br>Order ID: " . $order->id;
            $referer .= "<br>Collection ID: " . $data['collection_id'];
            $referer .= "<br>Type: " . $type;

            $bill_id = get_post_meta($order->id, '_transaction_id', true);

            if (empty($bill_id)) {
                $order->add_order_note('Payment Status: SUCCESSFUL' . '<br>Bill ID: ' . $data['id'] . ' ' . $referer);
                $order->payment_complete($data['id']);
                self::log($type . ', bills ' . $data['id'] . '. Order #' . $order->id . ' updated in WooCommerce as Paid');
            } elseif ($bill_id === $data['id']) {
                self::log($type . ', bills ' . $data['id'] . '. Order #' . $order->id . ' not updated due to Duplicate');
            }
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page() {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
        }

        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false) {

            if ($this->instructions && !$sent_to_admin && 'offline' === $order->payment_method && $order->has_status('on-hold')) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            }
        }

        /**
         * Adds error message when not configured the app_key.
         * 
         */
        public function api_key_missing_message() {
            $message = '<div class="error">';
            $message .= '<p>' . sprintf(__('<strong>Gateway Disabled</strong> You should inform your API Key in Billplz. %sClick here to configure!%s', 'wcbillplz'), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section=billplz">', '</a>') . '</p>';
            $message .= '</div>';
            echo $message;
        }

        /**
         * Adds error message when not configured the app_secret.
         * 
         */
        public function x_signature_missing_message() {
            $message = '<div class="error">';
            $message .= '<p>' . sprintf(__('<strong>Gateway Disabled</strong> You should inform your X Signature Key in Billplz. %sClick here to configure!%s', 'wcbillplz'), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section=billplz">', '</a>') . '</p>';
            $message .= '</div>';
            echo $message;
        }

    }

}

require __DIR__ . '/includes/invalidatebills.php';

add_action('billplz_bills_invalidator', 'wcbillplz_delete_bills');
add_filter('cron_schedules', 'wcbillplz_cron_add');
register_activation_hook(__FILE__, 'wcbillplz_activate_cron');
register_deactivation_hook(__FILE__, 'wcbillplz_deactivate_cron');
