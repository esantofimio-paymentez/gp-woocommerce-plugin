<?php

/*
Plugin Name: GpViComm WooCommerce Plugin
Plugin URI: https://www.gpvicomm.com
Description: This module is a solution that allows Woocommerce users to easily process credit card payments.
Version: 2.2.1
Author: GpViComm
Author URI: https://developers.gpvicomm.com/docs/payments/
Text Domain: gp_woocommerce
Domain Path: /languages
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

require( dirname( __FILE__ ) . '/includes/gp-woocommerce-refund.php' );
require(dirname(__FILE__) . '/includes/gp-woocommerce-webhook-api.php');

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

const GP_FLAVOR = "Gpvicomm";
const GP_DOMAIN = "gpvicomm.com";
const GP_REFUND = "/v2/transaction/refund/";
const GP_LTP    = "/linktopay/init_order/";
const GP_COMMERCE = "/utils/commerce/";

add_action( 'plugins_loaded', 'gp_woocommerce_plugin' );

function gpvicomm_payment_webhook( WP_REST_Request $request ) {
    $parameters = $request->get_params();
    try {
        $order = new WC_Order($parameters['transaction']['dev_reference']);
        $response_params = WC_Payment_Webhook_SG::update_order($order, $parameters);
        return new WP_REST_Response($response_params['message'], $response_params['code']);
    } catch (Exception $e){
        return new WP_REST_Response("update order fails, details: {$e}", 400);
    }
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'gpvicomm/webhook/v1', 'params', array(
        'methods' => WP_REST_SERVER::CREATABLE,
        'callback' => 'gpvicomm_payment_webhook',
        'args' => array(),
        'permission_callback' => function () {
            return true;
        }
    ) );
});

register_activation_hook( __FILE__, array( 'GP_WC_Helper', 'create_table' ) );

load_plugin_textdomain( 'gp_woocommerce', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

if (!function_exists('gp_woocommerce_plugin')) {
    function gp_woocommerce_plugin() {
        class GP_WC_Plugin extends WC_Payment_Gateway {

            public function __construct() {
                $this->id                 = 'gp_woocommerce';
                $this->method_title       = GP_FLAVOR;
                $this->method_description = __('This module is a solution that allows WooCommerce users to easily process credit card payments. Developed by: ', 'gp_woocommerce').GP_FLAVOR;
                $this->supports           = array( 'products', 'refunds' );

                $this->init_settings();
                $this->init_form_fields();

                $this->title                = $this->get_option('title');
                $this->description          = $this->get_option('description');
                $this->card_button_text     = $this->get_option('card_button_text');
                $this->ltp_button_text      = $this->get_option('ltp_button_text');

                $this->checkout_language    = $this->get_option('checkout_language');
                $this->environment          = $this->get_option('staging');
                $this->enable_ltp           = $this->get_option('enable_ltp');
                $this->enable_card          = $this->get_option('enable_card');
                $this->enable_installments  = $this->get_option('enable_installments');
                $this->ltp_expiration       = $this->get_option('ltp_expiration');

                $this->app_code_client      = $this->get_option('app_code_client');
                $this->app_key_client       = $this->get_option('app_key_client');
                $this->app_code_server      = $this->get_option('app_code_server');
                $this->app_key_server       = $this->get_option('app_key_server');

                $this->css                  = plugins_url('/assets/css/styles.css', __FILE__);

                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));

                add_action('woocommerce_receipt_gp_woocommerce', array(&$this, 'receipt_page'));
            }

            public function init_form_fields() {
                $this->form_fields = require( dirname( __FILE__ ) . '/includes/admin/gp-woocommerce-settings.php' );
            }

            function admin_options() {
                ?>
                <p>
                </p>
                <h2><?php echo GP_FLAVOR.' Gateway'; ?></h2>
                <table class="form-table">
                    <?php $this->generate_settings_html(); ?>
                </table>
                <?php
            }

            function receipt_page($orderId) {
                $order = new WC_Order($orderId);
                if ($this->enable_card == 'no' and $this->enable_ltp == 'no') {
                    ?>
                    <link rel="stylesheet" type="text/css" href="<?php echo $this->css; ?>">
                    <div>
                        <p class="alert alert-warning">
                            <?php _e( 'There are no payment methods selected by the merchant', 'gp_woocommerce' ) ?>
                        </p>
                    </div>
                    <div id="button-return">
                        <p>
                            <a class="return-button" href="<?php echo get_permalink( wc_get_page_id( 'checkout' ) ); ?>">
                                <?php _e( 'Return to checkout', 'gp_woocommerce' ) ?>
                            </a>
                        </p>
                    </div>
                    <?php
                }
                if ($this->enable_card == 'yes') {
                    WC()->cart->empty_cart();
                    $this->generate_cc_form($order);
                }
                if ($this->enable_ltp == 'yes') {
                    WC()->cart->empty_cart();
                    $order->update_status( 'on-hold', GP_FLAVOR . __( ' payment pending.', 'gp_woocommerce' ) );
                    $this->generate_ltp_form($order);
                }
            }

            public function process_refund( $order_id, $amount = null,  $reason = '' ) {
                $refund = new WC_Payment_Refund_GP();
                $refund_data = $refund->refund($order_id, $amount);
                if ($refund_data['success']) {
                    $order = new WC_Order($order_id);
                    $order->add_order_note( __('Transaction: ', 'gp_woocommerce') . $refund_data['transaction_id'] . __(' refund status: ', 'gp_woocommerce') . $refund_data['status'] . __(' reason: ', 'gp_woocommerce') . $reason);
                }
                return $refund_data['success'];
            }

            public function generate_ltp_form($order) {
                $url = GP_WC_Helper::generate_ltp($order, $this->environment);
                ?>
                <link rel="stylesheet" type="text/css" href="<?php echo $this->css; ?>">
                <div id="payment-buttons">
                    <button id="ltp-button" class="<?php if($url == NULL){echo "hide";} else {echo "ltp-button";} ?>" onclick="ltpRedirect()">
                        <?php echo $this->ltp_button_text; ?>
                    </button>
                </div>
                <script>
                    function ltpRedirect() {
                        location.replace("<?php echo $url; ?>")
                    }
                </script>
                <?php
            }

            public function generate_cc_form($order) {
                $webhook_p = plugins_url('/includes/gp-woocommerce-webhook-checkout.php', __FILE__);
                $checkout = plugins_url('/assets/js/payment_checkout.js', __FILE__);
                $order_data = GP_WC_Helper::get_checkout_params($order);
                ?>
                <link rel="stylesheet" type="text/css" href="<?php echo $this->css; ?>">

                <div id="msj-succcess" class="hide"> <p class="alert alert-success" ><?php _e('Your payment has been made successfully. Thank you for your purchase.', 'gp_woocommerce'); ?></p> </div>
                <div id="msj-failed" class="hide"> <p class="alert alert-warning"><?php _e('An error occurred while processing your payment and could not be made. Try another Credit Card.', 'gp_woocommerce'); ?></p> </div>

                <div id="button-return" class="hide">
                    <p>
                        <a class="return-button" href="<?php echo get_permalink( wc_get_page_id( 'shop' ) ); ?>"><?php _e( 'Return to Store', 'gp_woocommerce' ) ?></a>
                    </p>
                </div>

                <div id="payment-buttons">
                    <script src="https://cdn.gpvicomm.com/ccapi/sdk/payment_checkout_stable.min.js"></script>
                </div>

                <?php
                GP_WC_Helper::get_installments_type($this->enable_installments, $this->environment, $this->card_button_text);
                ?>


                <div id="order_data" class="hide">
                    <?php echo json_encode($order_data); ?>
                </div>

                <script id="woocommerce_checkout_sg"
                        webhook_p="<?php echo $webhook_p; ?>"
                        app_key="<?php echo $this->app_key_client; ?>"
                        app_code="<?php echo $this->app_code_client; ?>"
                        checkout_language="<?php echo $this->checkout_language; ?>"
                        environment="<?php echo $this->environment; ?>"
                        enable_installments="<?php echo $this->enable_installments; ?>"
                        src="<?php echo $checkout; ?>">
                </script>
                <?php
            }

            /**
             * Process the payment and return the result
             *
             * @param int $orderId
             * @return array
             */
            public function process_payment($orderId) {
                $order = new WC_Order($orderId);
                return array(
                    'result' => 'success',
                    'redirect' => $order->get_checkout_payment_url(true)
                );
            }
        }
    }
}

function add_gp_woocommerce_plugin( $methods ) {
    $methods[] = 'GP_WC_Plugin';
    return $methods;
}

add_filter( 'woocommerce_payment_gateways', 'add_gp_woocommerce_plugin' );
