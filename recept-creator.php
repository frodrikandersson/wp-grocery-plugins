<?php
/*
Plugin Name: Recept Creator
Description: Frontend recipe creation + optional 5% coupon email (creates page /create-recept).
Version: 0.1
Author: Fredrik
*/

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('wp_head','rc_head_marker');
function rc_head_marker() {
    echo "<!-- RC_PLUGIN_ACTIVE -->\n";
}

register_activation_hook(__FILE__,'rc_activation');
function rc_activation() {
    if (! get_page_by_path('create-recept') ) {
        wp_insert_post(array(
            'post_title'   => 'Create Recept',
            'post_name'    => 'create-recept',
            'post_content' => '[rc_create_recept_form]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ));
    }
}

add_action('init','rc_register_shortcodes');
function rc_register_shortcodes() {
    add_shortcode('rc_create_recept_form','rc_create_recept_form_shortcode');
}

function rc_create_recept_form_shortcode() {
    if (! class_exists('WooCommerce') ) {
        return '<p>WooCommerce must be active for the create-recept form to work.</p>';
    }

    // Get products for selection
    $products = wc_get_products(array(
        'limit'  => -1,
        'status' => 'publish',
        'category' => array('produkter') // change if needed
    ));

    // Get child categories of 'recept'
    $parent_cat = get_term_by('slug', 'recept', 'product_cat');
    $child_cats = $parent_cat ? get_terms(array(
        'taxonomy'   => 'product_cat',
        'parent'     => $parent_cat->term_id,
        'hide_empty' => false,
    )) : array();

    ob_start();
    ?>
    <div class="rc-form-wrap">
        <h2>Create a Recipe (Recept)</h2>
        <form id="rcCreateReceptForm" enctype="multipart/form-data">

            <p><label>Title<br>
                <input type="text" name="title" required>
            </label></p>

            <p><label>Description<br>
                <textarea name="description"></textarea>
            </label></p>

            <p><label>Short Description<br>
                <textarea name="short_description"></textarea>
            </label></p>

            <p>
                <label>Select Existing Category or Create New<br>
                    <select name="category" id="rc_category_select">
                        <option value="">-- Select a category --</option>
                        <?php foreach($child_cats as $cat): ?>
                            <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p>
                <label>Or enter new subcategory<br>
                    <input type="text" name="new_category" placeholder="New category name">
                </label>
            </p>


            <p><label>Featured Image<br>
                <input type="file" name="featured_image">
            </label></p>

            <p><label>Gallery Images<br>
                <input type="file" name="gallery_images[]" multiple>
            </label></p>

            <div class="grouped-products">
                <p>Select at least 2 products for this recipe:</p>
                <div class="grouped-grid">
                    <?php foreach($products as $p): ?>
                        <?php 
                            $categories = wp_get_post_terms($p->get_id(), 'product_cat', array('fields'=>'names'));
                            $cat_list = implode(', ', $categories);
                        ?>
                        <div class="grouped-card">
                            <input type="checkbox" name="grouped_products[]" value="<?php echo esc_attr($p->get_id()); ?>" style="display:none;">
                            <div class="card-inner">
                                <?php echo wp_get_attachment_image($p->get_image_id(),'medium'); ?>
                                <div class="card-title"><?php echo esc_html($p->get_name()); ?></div>
                                <div class="card-cats"><?php echo esc_html($cat_list); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <input type="hidden" name="discount" id="rc_discount" value="">
            <input type="hidden" name="email" id="rc_email" value="">
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('rc_create_recept'); ?>">

            <p><button type="submit">Create Recipe</button></p>
        </form>
    </div>

    <script>
    (function(){
        try {
            document.getElementById('rc_discount').value = localStorage.getItem('receptDiscount') || '0';
            document.getElementById('rc_email').value = localStorage.getItem('receptEmail') || '';
        } catch(e){}
    })();
    </script>
    <?php
    return ob_get_clean();
}



add_action('wp_enqueue_scripts','rc_enqueue_scripts');
function rc_enqueue_scripts() {
    wp_register_script('rc-frontend', plugin_dir_url(__FILE__) . 'rc-frontend.js', array('jquery'), '0.1', true);
    wp_register_style('rc-frontend', plugin_dir_url(__FILE__) . 'css/rc-frontend.css', array(), '0.1');
    wp_enqueue_script('rc-frontend');
    wp_enqueue_style('rc-frontend');

    wp_localize_script('rc-frontend', 'RCSettings', array(
        'ajax_url'    => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('rc_create_recept'),
        'wpUserEmail' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
        'create_page' => site_url('/create-recept'),
        'recept_page' => site_url('/product-category/recept'),
    ));
}

add_action('woocommerce_before_shop_loop','rc_output_create_button', 5);
function rc_output_create_button() {
    if ( function_exists('is_product_category') && is_product_category('recept') ) {
        echo '<div class="rc-create-wrapper" style="margin:20px 0;"><button class="createButton">Do you want to create a recipe and get 5% off your next purchase? Click here!</button></div>';
    }
}

add_filter('the_content','rc_maybe_add_button_to_content');
function rc_maybe_add_button_to_content($content) {
    if ( function_exists('is_product_category') && is_product_category('recept') && is_main_query() ) {
        $button = '<div class="rc-create-wrapper" style="margin:20px 0;"><button class="createButton">Do you want to create a recipe and get 5% off your next purchase? Click here!</button></div>';
        return $button . $content;
    }
    return $content;
}

/* AJAX handler: create grouped product, images, gallery, optionally create coupon + send email */
add_action('wp_ajax_rc_create_recept','rc_handle_create_recept');
add_action('wp_ajax_nopriv_rc_create_recept','rc_handle_create_recept');
function rc_handle_create_recept() {
    check_ajax_referer('rc_create_recept','nonce');

    if ( ! class_exists('WooCommerce') ) wp_send_json_error(array('message'=>'WooCommerce not active.'));

    $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
    if ( empty($title) ) wp_send_json_error(array('message'=>'Title is required.'));

    $description = isset($_POST['description']) ? wp_kses_post($_POST['description']) : '';
    $short_description = isset($_POST['short_description']) ? wp_kses_post($_POST['short_description']) : '';
    $grouped = isset($_POST['grouped_products']) ? array_map('intval', (array) $_POST['grouped_products']) : array();
    $discount = isset($_POST['discount']) ? intval($_POST['discount']) : 0;
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

    // ================= Handle parent + child categories =================
    $category_ids = array();
    $parent_term = get_term_by('slug','recept','product_cat');
    if($parent_term){
        $category_ids[] = $parent_term->term_id; // always include parent
    }

    $new_cat_name = isset($_POST['new_category']) ? sanitize_text_field($_POST['new_category']) : '';
    $selected_cat = isset($_POST['category']) ? intval($_POST['category']) : 0;

    if($new_cat_name && $parent_term){
        $existing_cats = get_terms(array(
            'taxonomy'   => 'product_cat',
            'parent'     => $parent_term->term_id,
            'hide_empty' => false,
            'name'       => $new_cat_name,
        ));

        if(!empty($existing_cats) && !is_wp_error($existing_cats)){
            $child_id = $existing_cats[0]->term_id;
        } else {
            $new_term = wp_insert_term(
                $new_cat_name,
                'product_cat',
                array('parent' => $parent_term->term_id)
            );
            if(!is_wp_error($new_term)){
                $child_id = $new_term['term_id'];
            }
        }

        if(!empty($child_id)){
            $category_ids[] = $child_id;
        }
    } elseif($selected_cat) {
        $category_ids[] = $selected_cat;
    }


    // ================= Create grouped product =================
    $product = new WC_Product_Grouped();
    $product->set_name($title);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description($description);
    $product->set_short_description($short_description);

    if(!empty($category_ids)){
        $product->set_category_ids($category_ids);
    }

    $product_id = $product->save();

    if (!empty($grouped)) {
        $product->set_children($grouped);
        $product->save();
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    if ( isset($_FILES['featured_image']) && ! empty($_FILES['featured_image']['name']) ) {
        $attach_id = media_handle_upload('featured_image', 0);
        if (! is_wp_error($attach_id) ) {
            $product->set_image_id($attach_id);
            $product->save();
        }
    }

    $gallery_ids = array();
    if ( isset($_FILES['gallery_images']) && ! empty($_FILES['gallery_images']['name'][0]) ) {
        $files = $_FILES['gallery_images'];
        $count = count($files['name']);
        for ($i=0; $i<$count; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $file_array = array(
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            );
            $backup = $_FILES;
            $_FILES = array('gallery_single' => $file_array);
            $attach_id = media_handle_upload('gallery_single', 0);
            $_FILES = $backup;
            if (! is_wp_error($attach_id)) $gallery_ids[] = $attach_id;
        }
        if (! empty($gallery_ids)) {
            $product->set_gallery_image_ids($gallery_ids);
            $product->save();
        }
    }

    $coupon_code = '';
    if ($discount && ! empty($email)) {
        $coupon_code = '5OFF-' . strtoupper(wp_generate_password(6,false,false));
        $coupon = new WC_Coupon();
        $coupon->set_code($coupon_code);
        $coupon->set_discount_type('percent');
        $coupon->set_amount(5);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_email_restrictions(array($email));
        $coupon->save();

        $subject = 'Your 5% discount';
        $message = "Thanks — use this coupon code for 5% off: $coupon_code";
        wp_mail($email, $subject, $message);
    }

    wp_send_json_success(array('message'=>'Recipe created','product_id'=>$product_id,'coupon'=>$coupon_code));
}
