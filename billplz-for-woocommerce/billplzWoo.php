<?php

/**
 * Plugin Name: Billplz for WooCommerce
 * Plugin URI: https://wordpress.org/plugins-wp/billplz-for-woocommerce/
 * Description: Billplz Payment Gateway | Accept Payment using all participating FPX Banking Channels. <a href="https://www.billplz.com/join/8ant7x743awpuaqcxtqufg" target="_blank">Sign up Now</a>.
 * Author: Wanzul Hosting Enterprise
 * Author URI: http://www.wanzul-hosting.com/
 * Version: 3.12
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

//Load the function
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
//Load language
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
            global $woocommerce;
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
            $this->paymentverification = $this->settings['paymentverification'];
            $this->teststaging = $this->settings['teststaging'];
            // = $this->settings['autosubmit'];
            $this->custom_error = $this->settings['custom_error'];

            self::$log_enabled = $this->debug;

            add_action('woocommerce_receipt_billplz', array(
                &$this,
                'receipt_page'
            ));
            //save setting configuration
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
            // Checking if collection_id is not empty.
            $this->collection_id == '' ? add_action('admin_notices', array(
                                &$this,
                                'collection_id_missing_message'
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
            // Checking API Key & Collection ID validity.
            if ($this->api_key != '' && $this->collection_id != '') {
                require_once(__DIR__ . '/includes/billplz.php');
                $obj = new billplz;
                $status = $obj->check_apikey_collectionid($this->api_key, $this->collection_id, $this->teststaging);
                if ($status) {
                    $message = '<div class="updated">';
                    $message .= '<p>' . sprintf(__('Your API Key & Collection ID are Valid', 'wcbillplz')) . '</p>';
                    $message .= '</div>';
                } else {
                    $message = '<div class="error">';
                    $message .= '<p>' . sprintf(__('Your API Key or Collection ID are Not Valid. Check your API Key and Collection ID', 'wcbillplz')) . '</p>';
                    $message .= '</div>';
                }
                echo $message;
                unset($obj, $status, $message);
            }
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
            //----------------------------------------------------------------//

            if ($this->notification == 'None') {
                $deliver = false;
            } else {
                $deliver = true;
            }


            //----------------------------------------------------------------//
            //Prevent Bills from Duplicating
            //get_post_meta => search for existing bills

            if (get_post_meta($order_id, '_wc_billplz_orderid', true) == '') {
                $url = $this->bills_trigger($order, $deliver, $order_id, $desc);
            } else {
                //------------------------------------------------------------//
                //Read from Database
                //Test every value if not same, then, need to create bills again
                $flagBoolean = true;
                if (get_post_meta($order_id, '_wc_billplz_ordername', true) != $order->billing_first_name . " " . $order->billing_last_name) {
                    $flagBoolean = false;
                }
                if (get_post_meta($order_id, '_wc_billplz_ordercollection', true) != $this->collection_id) {
                    $flagBoolean = false;
                }
                if (get_post_meta($order_id, '_wc_billplz_orderemail', true) != $order->billing_email) {
                    $flagBoolean = false;
                }
                if (get_post_meta($order_id, '_wc_billplz_orderphone', true) != $order->billing_phone) {
                    $flagBoolean = false;
                }
                if ($flagBoolean) {
                    $url = get_post_meta($order_id, '_wc_billplz_orderurl', true);
                } else {
                    $url = $this->bills_trigger($order, $deliver, $order_id, $desc);
                }
            }

            //----------------------------------------------------------------//
            $ready = "If you are not redirected, please click <a href=" . '"' . $url . '"' . " target='_self'>Here</a><br />"
                    . "<a class='button cancel' href='" . $order->get_cancel_order_url() . "'>" . __('Cancel order &amp; restore cart', 'woothemes') . "</a>"
                    . "<script>location.href = '" . $url . "'</script>";
            unset($obj);
            return $ready;
        }

        /**
         * Create bills function
         * Save to database
         * 
         * @return string Return URL
         */
        protected function bills_trigger($order, $deliver, $order_id, $desc) {
            require_once(__DIR__ . '/includes/billplz.php');
            $obj = new billplz;
            if ($this->notification != 'SMS') {
                $obj->setEmail($order->billing_email);
            }
            if ($this->notification != 'Email') {
                $obj->setMobile($order->billing_phone);
            }
            // Generate signature to avoid amount spoofing
            $md5 = '&signature=' . md5($this->api_key . $this->collection_id . 'AABBCC' . $order->order_total);
            $obj->setCollection($this->collection_id)
                    ->setName($order->billing_first_name . " " . $order->billing_last_name)
                    ->setAmount($order->order_total)
                    ->setDeliver($deliver)
                    ->setReference_1($order_id)
                    ->setReference_1_Label('ID')
                    ->setDescription($desc)
                    ->setPassbackURL(home_url('/?wc-api=WC_Billplz_Gateway' . $md5), home_url('/?wc-api=WC_Billplz_Gateway' . $md5))
                    ->create_bill($this->api_key, $this->teststaging);
            //------------------------------------------------------------//
            //Save to Database
            update_post_meta($order_id, '_wc_billplz_orderid', $obj->getID());
            update_post_meta($order_id, '_wc_billplz_ordername', $order->billing_first_name . " " . $order->billing_last_name);
            update_post_meta($order_id, '_wc_billplz_ordercollection', $this->collection_id);
            update_post_meta($order_id, '_wc_billplz_orderemail', $order->billing_email);
            update_post_meta($order_id, '_wc_billplz_orderphone', $order->billing_phone);
            update_post_meta($order_id, '_wc_billplz_orderurl', $obj->getURL());

            // Log the bills creation
            self::log('Creating bills ' . $obj->getID() . ' for order number #' . $order_id);
            return $obj->getURL();
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

            // Callback page
            if (isset($_POST['id'])) {
                $this->callback_handle();
            }
            // Return Page
            elseif (isset($_GET['billplz']['id'])) {
                $this->return_handle();
            }
            //Fake Request. Die!
            else {
                exit('What Are You Doing Here?');
            }
            exit;
        }

        private function callback_handle() {
            // Log the bills callback response
            self::log('Callback response: ' . print_r($_POST, true));
            $id = $_POST['id'];
            $obj = new billplz;
            $data = $obj->check_bill($this->api_key, $id, $this->teststaging);
            unset($obj);
            $order = new WC_Order($data['reference_1']);
            // Verify signature to avoid amount spoofing
            $amount = number_format(($data['amount'] / 100), 2);
            $md5 = md5($this->api_key . $this->collection_id . 'AABBCC' . $amount);
            if ($_GET['signature'] === $md5) {
                self::log('Callback response for bills ' . $data['id'] . 'hash checked SUCCESS for order number #' . $data['reference_1']);
            } else {
                self::log('Callback response hash checked FAILED. Possible of order spoofing prevented');
                exit('Amount spoofing detected');
            }
            echo 'ALL IS WELL';
            $this->save_payment($order, $data, 'Callback');
        }

        private function return_handle() {
            // Log the bills return response
            self::log('Return response: ' . print_r($_GET['billplz'], true));
            $id = $_GET['billplz']['id'];
            $obj = new billplz;
            $data = $obj->check_bill($this->api_key, $id, $this->teststaging);
            $order = new WC_Order($data['reference_1']);
            // Verify signature to avoid amount spoofing
            $amount = number_format(($data['amount'] / 100), 2);
            var_dump($amount);
            $md5 = md5($this->api_key . $this->collection_id . 'AABBCC' . $amount);
            if ($_GET['signature'] === $md5) {
                self::log('Return response for bills ' . $data['id'] . 'hash checked SUCCESS for order number #' . $data['reference_1']);
            } else {
                self::log('Return response hash checked FAILED. Possible of order spoofing prevented');
                exit('Amount spoofing detected');
            }
            $this->save_payment($order, $data, 'Return');
            if ($data['paid']) {
                wp_redirect($order->get_checkout_order_received_url());
            } else {
                wc_add_notice(__('ERROR: ', 'woothemes') . $this->custom_error, 'error');
                wp_redirect($order->get_cancel_order_url());
            }
        }

        /**
         * Save payment status to DB for successful return/callback
         */
        private function save_payment($order, $data, $type) {
            $referer = "<br>Receipt URL: " . "<a href='" . urldecode($data['url']) . "' target=_blank>" . urldecode($data['url']) . "</a>";
            $referer .= "<br>Order ID: " . $data['reference_1'];
            $referer .= "<br>Collection ID: " . $data['collection_id'];
            $referer .= "<br>Type: " . $type;
            if ($order->status == 'pending' || $order->status == 'failed' || $order->status == 'cancelled') {
                if ($data['paid']) {
                    //Backward compatibility: !isset($this->paymentverification
                    if ($this->paymentverification == $type || $this->paymentverification == 'Both' || (!isset($this->paymentverification))) {
                        $order->add_order_note('Payment Status: SUCCESSFUL' . '<br>Bill ID: ' . $data['id'] . $referer);
                        $order->payment_complete();
                        self::log($type . ', bills ' . $data['id'] . '. Order #' . $data['reference_1'] . ' updated in WooCommerce as Paid');
                    }
                } else {
                    $order->add_order_note('Payment Status: CANCELLED BY USER' . '<br>Bill ID: ' . $data['id'] . $referer);
                }
            }
            return $this;
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
        public function collection_id_missing_message() {
            $message = '<div class="error">';
            $message .= '<p>' . sprintf(__('<strong>Gateway Disabled</strong> You should inform your Collection ID in Billplz. %sClick here to configure!%s', 'wcbillplz'), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section=billplz">', '</a>') . '</p>';
            $message .= '</div>';
            echo $message;
        }

    }

}

/*
 * Automaticallly invalidate bills when overdue or not paid
 * Load the function
 */
add_action('plugins_loaded', 'wcbillplz_invalidate_bills', 0);

function wcbillplz_invalidate_bills() {
    require_once __DIR__ . '/includes/invalidatebills.php';
    $inv = new invalidatebills;
}

/*
 *  Backward Compatible
 *  Perform database upgrade if Version older than 3.9
 *  This function will be removed starting version 4.0
 *  
 */
/*
 *  Removed started in version 3.10
  function wcbillplz_update_db() {
  require_once 'updatedb.php';
  $obj = new updatedb;
  unset($obj);
  }

  add_action('plugins_loaded', 'wcbillplz_update_db', 0);
 */
