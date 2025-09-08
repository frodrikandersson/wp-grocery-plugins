<?php
/**
 * Plugin Name: WooCommerce Collections (Recipes)
 * Description: Let users create "collections" (recipes) made of existing WC products, send coupon via Mailgun, GTM integration, "Purchase all" button, and archive/filter UI.
 * Version: 0.1
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Collections_Plugin {

    private static $instance;
    public $option_key = 'wc_collections_settings';

    public static function instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        // Hooks
        add_action( 'init', [ $this, 'register_collection_cpt' ] );
        add_action( 'init', [ $this, 'register_collection_taxonomy' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_collection_metaboxes' ] );
        add_action( 'save_post_collection', [ $this, 'save_collection_meta' ], 10, 2 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );


        // Shortcodes & AJAX endpoints
        add_shortcode( 'collection_create_form', [ $this, 'shortcode_collection_form' ] );
        add_action( 'wp_ajax_wc_collections_create', [ $this, 'handle_frontend_create' ] );
        add_action( 'wp_ajax_nopriv_wc_collections_create', [ $this, 'handle_frontend_create' ] );
        add_action( 'wp_ajax_wc_collections_admin_search_products', [ $this, 'ajax_admin_search_products' ] );
        add_action( 'wp_ajax_wc_collections_search_products', [ $this, 'ajax_front_search_products' ] );

        // Add purchase all endpoint for theme to call
        add_action( 'admin_post_nopriv_wc_collections_purchase_all', [ $this, 'purchase_all_handler' ] );
        add_action( 'admin_post_wc_collections_purchase_all', [ $this, 'purchase_all_handler' ] );

        // single template override (if theme doesn't provide)
        add_filter( 'single_template', [ $this, 'maybe_load_single_template' ] );

        // expose REST API field for product IDs
        add_action( 'rest_api_init', function() {
            register_rest_field( 'collection',
                'product_ids',
                array(
                    'get_callback'    => [ $this, 'rest_get_product_ids' ],
                    'update_callback' => null,
                    'schema'          => null,
                )
            );
        });
    }

    /* ----------------------------
       CPT & Taxonomy
       ---------------------------- */
    public function register_collection_cpt() {
        $labels = array(
            'name' => 'Collections',
            'singular_name' => 'Collection',
            'add_new_item' => 'Add New Collection',
            'edit_item' => 'Edit Collection'
        );
        register_post_type( 'collection', array(
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'rewrite' => array('slug' => 'collections'),
            'supports' => array('title','editor','author','thumbnail'),
            'show_in_rest' => true,
        ));
    }

    public function register_collection_taxonomy() {
        $labels = array(
            'name' => 'Collection Categories',
            'singular_name' => 'Collection Category',
        );
        register_taxonomy( 'collection_category', 'collection', array(
            'labels' => $labels,
            'public' => true,
            'hierarchical' => true,
            'show_in_rest' => true,
        ));
    }

    /* ----------------------------
       Admin metabox for product selection
       ---------------------------- */

    public function add_collection_metaboxes() {
        add_meta_box( 'collection_products', 'Attached Products', [ $this, 'metabox_products_markup' ], 'collection', 'normal', 'high' );
    }

    public function metabox_products_markup( $post ) {
        wp_nonce_field( 'wc_collections_save', 'wc_collections_nonce' );
        $product_ids = get_post_meta( $post->ID, '_collection_product_ids', true );
        if ( ! is_array( $product_ids ) ) $product_ids = array();

        echo '<p>Select at least 2 products to attach to this collection.</p>';
        echo '<select id="wc-collection-products" name="wc_collection_products[]" multiple style="width:100%;">';
        // We'll lazy-load via AJAX on page; but for simplicity, output current ones
        foreach ( $product_ids as $pid ) {
            $p = get_post( $pid );
            if ( $p ) printf( '<option value="%d" selected>%s</option>', esc_attr($pid), esc_html($p->post_title) );
        }
        echo '</select>';
        echo '<p class="description">Start typing to search products.</p>';

        // JS hook will initialize select2 and AJAX search
    }

    public function save_collection_meta( $post_id, $post ) {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['wc_collections_nonce'] ) || ! wp_verify_nonce( $_POST['wc_collections_nonce'], 'wc_collections_save' ) ) return;
        if ( $post->post_type !== 'collection' ) return;

        $product_ids = isset( $_POST['wc_collection_products'] ) ? array_map('intval', (array) $_POST['wc_collection_products']) : array();

        // enforce at least 2 unique products
        $product_ids = array_values( array_unique( array_filter( $product_ids ) ) );
        if ( count( $product_ids ) < 2 ) {
            // Prevent publishing if not enough products: set status to draft & add admin notice
            if ( $post->post_status === 'publish' ) {
                // move back to draft
                wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
                add_action( 'admin_notices', function() {
                    echo '<div class="notice notice-error"><p>Collections must contain at least 2 products. Saved as draft.</p></div>';
                } );
            }
        }

        update_post_meta( $post_id, '_collection_product_ids', $product_ids );
    }

    /* ----------------------------
       Enqueue frontend + admin assets
       ---------------------------- */
    public function enqueue_frontend_assets() {
        // Select2 for product selection on admin screen; for simplicity we target both admin & front
        wp_register_style( 'wc-collections-select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css' );
        wp_register_script( 'wc-collections-select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'), null, true );

        // Frontend form JS
        wp_enqueue_script( 'wc-collections-frontend', plugin_dir_url(__FILE__) . 'assets/js/collections-frontend.js', array('jquery'), '0.1', true );
        wp_localize_script( 'wc-collections-frontend', 'WCCollections', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wc_collections_frontend' ),
            'purchase_all_nonce' => wp_create_nonce( 'wc_collections_purchase' ),
            'plugin_url'=> plugin_dir_url(__FILE__),
        ) );

        // Only enqueue select2 if on admin edit screen for collection
        if ( is_admin() ) {
            global $pagenow;
            if ( in_array( $pagenow, array('post.php','post-new.php') ) && isset($_GET['post_type']) && $_GET['post_type'] === 'collection' ) {
                wp_enqueue_style( 'wc-collections-select2' );
                wp_enqueue_script( 'wc-collections-select2' );
                wp_enqueue_script( 'wc-collections-admin', plugin_dir_url(__FILE__) . 'assets/js/collections-admin.js', array('jquery','wc-collections-select2'), '0.1', true );
                wp_localize_script( 'wc-collections-admin', 'WCCollectionsAdmin', array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( 'wc_collections_admin' ),
                ) );
            }
        }
    }

    /* ----------------------------
       Shortcode: front-end create form
       ---------------------------- */
    public function shortcode_collection_form( $atts ) {
        ob_start();

        // We'll load categories
        $terms = get_terms( array( 'taxonomy' => 'collection_category', 'hide_empty' => false ) );

        ?>
        <form id="wc-collection-create-form" method="post">
            <p><label>Recipe / Collection name<br><input type="text" name="collection_title" required></label></p>
            <p><label>Description<br><textarea name="collection_content" rows="6"></textarea></label></p>

            <p>
                <label>Products (select at least 2). Start typing to search products:</label><br>
                <select id="wc_collection_products_front" name="collection_products[]" multiple style="width:100%"></select>
            </p>

            <p>
                <label>Category:<br>
                    <select name="collection_category">
                        <option value="">— choose —</option>
                        <?php foreach ( $terms as $t ) printf( '<option value="%d">%s</option>', $t->term_id, esc_html($t->name) ); ?>
                    </select>
                </label>
            </p>

            <p>
                <label><input type="checkbox" name="want_coupon" value="1"> Do you want a coupon code when creating this recipe?</label>
            </p>

            <div id="wc_coupon_email_wrap" style="display:none;">
                <p><label>Your email for coupon (required if you requested coupon)<br><input type="email" name="coupon_email"></label></p>
            </div>

            <p><button type="submit">Create Collection</button></p>

            <div id="wc-collection-create-result"></div>
        </form>

        <script>
        (function(){
            const wrap = document.getElementById('wc_coupon_email_wrap');
            const checkbox = document.querySelector('#wc-collection-create-form input[name="want_coupon"]');
            if (checkbox) {
                checkbox.addEventListener('change', function(){
                    wrap.style.display = (this.checked ? 'block' : 'none');
                });
            }
        })();
        </script>
        <?php

        return ob_get_clean();
    }

    /* ----------------------------
       Front-end create handler (AJAX)
       ---------------------------- */
    public function handle_frontend_create() {
        check_ajax_referer( 'wc_collections_frontend', 'nonce' );

        $title = sanitize_text_field( $_POST['collection_title'] ?? '' );
        $content = wp_kses_post( $_POST['collection_content'] ?? '' );
        $product_ids = isset( $_POST['collection_products'] ) ? array_map('intval', (array) $_POST['collection_products']) : array();
        $product_ids = array_values( array_unique( array_filter( $product_ids ) ) );
        $cat = isset( $_POST['collection_category'] ) ? intval( $_POST['collection_category'] ) : 0;
        $want_coupon = isset( $_POST['want_coupon'] ) && $_POST['want_coupon'] ? true : false;
        $coupon_email = isset( $_POST['coupon_email'] ) ? sanitize_email( $_POST['coupon_email'] ) : '';

        if ( empty( $title ) ) {
            wp_send_json_error( 'Title required' );
        }
        if ( count( $product_ids ) < 2 ) {
            wp_send_json_error( 'You must attach at least 2 products.' );
        }
        if ( $want_coupon && empty( $coupon_email ) ) {
            wp_send_json_error( 'Email required for coupon' );
        }

        // Create collection as pending if user not logged in; if logged in author is current user
        $post_author = get_current_user_id() ? get_current_user_id() : 0;
        $post_status = is_user_logged_in() ? 'publish' : 'pending'; // optionally allow guest published; safer to pending
        $new = wp_insert_post( array(
            'post_type' => 'collection',
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => $post_status,
            'post_author' => $post_author,
        ) );

        if ( is_wp_error( $new ) ) {
            wp_send_json_error( 'Could not create collection.' );
        }

        // Attach products
        update_post_meta( $new, '_collection_product_ids', $product_ids );

        // Set taxonomy
        if ( $cat ) wp_set_post_terms( $new, array($cat), 'collection_category', false );

        // If coupon requested -> generate coupon & send via Mailgun
        $coupon_code = '';
        if ( $want_coupon ) {
            // create coupon (50 SEK off or 10% — configurable)
            $settings = get_option( $this->option_key, array() );
            $amount = $settings['coupon_amount'] ?? '10';
            $type = $settings['coupon_type'] ?? 'percent'; // percent or fixed_cart
            $coupon_code = $this->create_coupon_for_email( $amount, $type, $coupon_email );
            if ( is_wp_error( $coupon_code ) ) {
                // continue but inform user
                $coupon_error = $coupon_code->get_error_message();
            } else {
                // Send via Mailgun if configured
                $mailgun_sent = $this->send_coupon_mailgun( $coupon_email, $coupon_code, $title );
                if ( is_wp_error( $mailgun_sent ) ) {
                    $mailgun_error = $mailgun_sent->get_error_message();
                }
            }
        }

        // Trigger a dataLayer push via response; frontend will push GTM event
        $resp = array(
            'success' => true,
            'post_id' => $new,
            'coupon_code' => $coupon_code,
            'coupon_error' => $coupon_error ?? '',
            'mailgun_error' => $mailgun_error ?? '',
            'status' => $post_status,
        );

        // return success
        wp_send_json_success( $resp );
    }

    /* ----------------------------
       Coupon creation (WooCommerce)
       ---------------------------- */
    public function create_coupon_for_email( $amount, $type, $email ) {
        if ( ! class_exists('WC_Coupon') ) return new WP_Error( 'no_wc', 'WooCommerce is required for coupons.' );

        $code = 'COLL-' . strtoupper( wp_generate_password(8, false, false) );
        $coupon = array(
            'post_title' => $code,
            'post_content' => '',
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type' => 'shop_coupon'
        );
        $coupon_id = wp_insert_post( $coupon );

        if ( ! $coupon_id ) return new WP_Error( 'coupon_fail', 'Could not create coupon.' );

        update_post_meta( $coupon_id, 'discount_type', $type ); // percent or fixed_cart
        update_post_meta( $coupon_id, 'coupon_amount', $amount );
        update_post_meta( $coupon_id, 'individual_use', 'no' );
        update_post_meta( $coupon_id, 'product_ids', '' );
        update_post_meta( $coupon_id, 'exclude_product_ids', '' );
        update_post_meta( $coupon_id, 'usage_limit', 1 );
        update_post_meta( $coupon_id, 'usage_limit_per_user', 1 );
        update_post_meta( $coupon_id, 'limit_usage_to_x_items', '' );
        update_post_meta( $coupon_id, 'free_shipping', 'no' );

        // store intended recipient (for our record)
        update_post_meta( $coupon_id, '_coupon_recipient_email', $email );

        return $code;
    }

    /* ----------------------------
       Mailgun send (simple)
       Requires: mailgun_api_key and mailgun_domain in settings
       ---------------------------- */
    public function send_coupon_mailgun( $to_email, $coupon_code, $collection_title ) {
        $settings = get_option( $this->option_key, array() );
        $api_key = $settings['mailgun_api_key'] ?? '';
        $domain = $settings['mailgun_domain'] ?? '';
        $from = $settings['mailgun_from'] ?? 'no-reply@' . $_SERVER['HTTP_HOST'];

        if ( empty( $api_key ) || empty( $domain ) ) {
            return new WP_Error( 'mailgun_not_configured', 'Mailgun keys not configured in plugin settings.' );
        }

        $subject = "Your coupon for creating '{$collection_title}'";
        $body = "Thanks for creating the recipe \"{$collection_title}\". Use coupon code: {$coupon_code} at checkout.";

        $endpoint = "https://api.mailgun.net/v3/{$domain}/messages";
        $response = wp_remote_post( $endpoint, array(
            'timeout' => 20,
            'body' => array(
                'from' => $from,
                'to' => $to_email,
                'subject' => $subject,
                'text' => $body,
            ),
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ),
            ),
        ) );

        if ( is_wp_error( $response ) ) return $response;
        if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
            return new WP_Error( 'mailgun_error', 'Mailgun returned error: ' . wp_remote_retrieve_response_message( $response ) );
        }
        return true;
    }

    /* ----------------------------
       Admin settings
       ---------------------------- */
    public function add_admin_menu() {
        add_options_page( 'WC Collections', 'WC Collections', 'manage_options', 'wc-collections-settings', [ $this, 'settings_page' ] );
    }

    public function register_settings() {
        register_setting( $this->option_key, $this->option_key );
    }

    public function settings_page() {
        $settings = get_option( $this->option_key, array() );
        ?>
        <div class="wrap">
            <h1>WC Collections Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( $this->option_key ); ?>
                <?php do_settings_sections( $this->option_key ); ?>

                <h2>Mailgun</h2>
                <table class="form-table">
                    <tr><th>API Key</th><td><input type="text" name="<?php echo $this->option_key; ?>[mailgun_api_key]" value="<?php echo esc_attr( $settings['mailgun_api_key'] ?? '' ); ?>" style="width:40%"></td></tr>
                    <tr><th>Domain (e.g. mg.example.com)</th><td><input type="text" name="<?php echo $this->option_key; ?>[mailgun_domain]" value="<?php echo esc_attr( $settings['mailgun_domain'] ?? '' ); ?>" style="width:40%"></td></tr>
                    <tr><th>From address</th><td><input type="text" name="<?php echo $this->option_key; ?>[mailgun_from]" value="<?php echo esc_attr( $settings['mailgun_from'] ?? 'no-reply@' . $_SERVER['HTTP_HOST'] ); ?>" style="width:40%"></td></tr>
                </table>

                <h2>Coupon defaults</h2>
                <table class="form-table">
                    <tr><th>Coupon Type</th>
                        <td>
                            <select name="<?php echo $this->option_key; ?>[coupon_type]">
                                <option value="percent" <?php selected( $settings['coupon_type'] ?? 'percent', 'percent' ); ?>>Percent</option>
                                <option value="fixed_cart" <?php selected( $settings['coupon_type'] ?? '', 'fixed_cart' ); ?>>Fixed amount</option>
                            </select>
                        </td></tr>
                    <tr><th>Coupon Amount</th>
                        <td><input type="text" name="<?php echo $this->option_key; ?>[coupon_amount]" value="<?php echo esc_attr( $settings['coupon_amount'] ?? '10' ); ?>"></td></tr>
                </table>

                <h2>Google Tag Manager</h2>
                <table class="form-table">
                    <tr><th>GTM Container ID</th><td><input type="text" name="<?php echo $this->option_key; ?>[gtm_id]" value="<?php echo esc_attr( $settings['gtm_id'] ?? '' ); ?>" placeholder="GTM-XXXX"></td></tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /* ----------------------------
       Single template fallback
       ---------------------------- */
    public function maybe_load_single_template( $single ) {
        global $post;
        if ( $post && $post->post_type === 'collection' ) {
            $theme_template = locate_template( array( 'single-collection.php' ) );
            if ( $theme_template ) return $theme_template;
            return plugin_dir_path(__FILE__) . 'templates/single-collection.php';
        }
        return $single;
    }

    /* ----------------------------
       REST product ids callback
       ---------------------------- */
    public function rest_get_product_ids( $object, $field_name, $request ) {
        $ids = get_post_meta( $object['id'], '_collection_product_ids', true );
        if ( ! is_array( $ids ) ) return array();
        return $ids;
    }

    /* ----------------------------
       Purchase all handler: adds product IDs to cart and redirects to cart
       Accepts POST: collection_id, nonce
       ---------------------------- */
    public function purchase_all_handler() {
        if ( ! isset( $_POST['collection_id'] ) ) {
            wp_redirect( wc_get_cart_url() ); exit;
        }
        $collection_id = intval( $_POST['collection_id'] );
        if ( ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'wc_collections_purchase' ) ) {
            wp_die( 'Invalid nonce' );
        }

        $product_ids = get_post_meta( $collection_id, '_collection_product_ids', true );
        if ( ! is_array( $product_ids ) ) $product_ids = array();

        if ( empty( $product_ids ) ) {
            wp_redirect( wc_get_cart_url() ); exit;
        }

        // Clear cart optionally or not; here we add to existing cart
        foreach ( $product_ids as $pid ) {
            // if it's a simple product: add to cart
            WC()->cart->add_to_cart( $pid );
        }

        wp_redirect( wc_get_cart_url() ); exit;
    }

    public function ajax_admin_search_products() {
    check_ajax_referer( 'wc_collections_admin', 'nonce' );
    $q = sanitize_text_field( $_GET['q'] ?? '' );
    $args = array(
        'post_type' => 'product',
        's' => $q,
        'posts_per_page' => 20,
    );
    $query = new WP_Query( $args );
    $items = array();
    foreach ( $query->posts as $p ) {
        $items[] = array('id' => $p->ID, 'text' => $p->post_title );
    }
    wp_send_json_success( array('items' => $items) );
}

    /* ----------------------------
       Front-end product search (AJAX)
       ---------------------------- */

    public function ajax_front_search_products() {
        $q = sanitize_text_field( $_GET['q'] ?? '' );
        $args = array(
            'post_type' => 'product',
            's' => $q,
            'posts_per_page' => 20,
        );
        $query = new WP_Query( $args );
        $items = array();
        foreach ( $query->posts as $p ) {
            $items[] = array('id' => $p->ID, 'text' => $p->post_title );
        }
        wp_send_json_success( array('items' => $items) );
    }


}

WC_Collections_Plugin::instance();
