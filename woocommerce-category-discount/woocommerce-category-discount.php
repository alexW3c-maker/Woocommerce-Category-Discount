<?php
/*
Plugin Name: Woocommerce Category Discount
Description: Плагин для предоставления скидок на определенные категории товаров и добавления бесплатных продуктов в корзину.
Version: 1.0
Author: alexW3c_maker
*/

if (!defined('ABSPATH')) {
    exit; // Защита от прямого доступа к файлу
}

if (!class_exists('WooCommerce_Category_Discount')) {
    class WooCommerce_Category_Discount
    {
        public function __construct()
        {
            add_action('admin_menu', array($this, 'register_admin_page'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('woocommerce_before_cart', array($this, 'display_free_products'));
            add_action('woocommerce_add_to_cart', array($this, 'add_free_product_to_cart'), 10, 6);
            add_filter('woocommerce_before_calculate_totals', array($this, 'set_free_product_price_zero'), 10, 1);
            add_filter('woocommerce_cart_item_quantity', array($this, 'remove_quantity_input_for_free_product'), 10, 3);
            add_action('woocommerce_cart_updated', array($this, 'remove_free_product_if_needed'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
            add_action('wp_ajax_add_free_product', array($this, 'ajax_add_free_product'));
            add_action('wp_ajax_nopriv_add_free_product', array($this, 'ajax_add_free_product'));
        }

        public function enqueue_scripts()
        {
            wp_enqueue_script('category-discount', plugins_url('category-discount.js', __FILE__), array('jquery'), '1.0', true);
            wp_enqueue_style('category-discount', plugins_url('category-discount.css', __FILE__), array(), '1.0', 'all');
            wp_localize_script('category-discount', 'category_discount_ajax_object', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('add_free_product_nonce'),
            ));
        }

        public function register_admin_page()
        {
            add_submenu_page('woocommerce', 'Category Discount', 'Category Discount', 'manage_options', 'category-discount', array($this, 'admin_page_callback'));
        }

        public function register_settings()
        {
            register_setting('category_discount_group', 'discount_category');
            register_setting('category_discount_group', 'discount_threshold');
            register_setting('category_discount_group', 'free_product_category');
        }

        public function admin_page_callback()
        {
            ?>
            <div class="wrap">
                <h1>Category Discount</h1>
                <form method="post" action="options.php">
                    <?php settings_fields('category_discount_group'); ?>
                    <?php do_settings_sections('category_discount_group'); ?>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Discount Category</th>
                            <td>
                                <?php wp_dropdown_categories(array('taxonomy' => 'product_cat', 'hide_empty' => 0, 'name' => 'discount_category', 'selected' => get_option('discount_category'))); ?>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Discount Threshold</th>
                            <td><input type="number" name="discount_threshold" value="<?php echo esc_attr(get_option('discount_threshold')); ?>" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Free Product Category</th>
                            <td>
                                <?php wp_dropdown_categories(array('taxonomy' => 'product_cat', 'hide_empty' => 0, 'name' => 'free_product_category', 'selected' => get_option('free_product_category'))); ?>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </div>
            <?php
        }

        public function display_free_products()
        {
            $discount_category_id = get_option('discount_category');
            $discount_threshold = get_option('discount_threshold');
            $free_product_category_id = get_option('free_product_category');

            $cart = WC()->cart;
            $cart_items = $cart->get_cart();

            $discount_category_count = 0;
            $has_free_product = false;
            foreach ($cart_items as $cart_item_key => $cart_item) {
                $product_id = $cart_item['product_id'];

                if (has_term($discount_category_id, 'product_cat', $product_id)) {
                    $discount_category_count += $cart_item['quantity'];
                }

                if (has_term($free_product_category_id, 'product_cat', $product_id)) {
                    $has_free_product = true;
                    $cart->cart_contents[$cart_item_key]['data']->set_price(0);
                    $cart->set_session();
                }
            }

            if ($discount_category_count >= $discount_threshold && !$has_free_product) {
                $args = array(
                    'post_type' => 'product',
                    'posts_per_page' => -1,
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'product_cat',
                            'field' => 'term_id',
                            'terms' => $free_product_category_id,
                        ),
                    ),
                );
                $free_products = get_posts($args);

                echo '<div class="woocommerce">';
                echo '<div class="woocommerce-notices-wrapper">';
                echo '<div class="woocommerce-message">';
                echo '<strong>Вы можете выбрать один бесплатный продукт из списка:</strong>';
                echo '<form method="post" action="">';
                echo '<div class="woocommerce-form-coupon-toggle">';
                echo '<div style="margin: 15px;">';

                // Добавьте эти строки для создания скрытых полей input
                echo '<input type="hidden" id="discount_category_count" value="' . $discount_category_count . '">';
                echo '<input type="hidden" id="discount_threshold" value="' . $discount_threshold . '">';

                echo '<select name="add_free_product" id="add_free_product" class="select wc-enhanced-select">';
                echo '<option value="">Выберите продукт</option>';
                foreach ($free_products as $free_product) {
                    $product = wc_get_product($free_product->ID);
                    echo '<option value="' . $product->get_id() . '">' . $product->get_title() . '</option>';
                }
                echo '</select>';
                echo '<input type="button" value="Добавить в корзину" id="add_free_product_button" class="button" />';
                echo '</div>';
                echo '</div>';
                echo '</form>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
            }
        }

        public function set_free_product_price_zero($cart_object)
        {
            $free_product_category_id = get_option('free_product_category');

            foreach ($cart_object->get_cart() as $cart_item_key => $cart_item) {
                $product_id = $cart_item['product_id'];

                if (has_term($free_product_category_id, 'product_cat', $product_id)) {
                    $cart_item['data']->set_price(0);
                }
            }
        }

        public function remove_quantity_input_for_free_product($product_quantity, $cart_item_key, $cart_item)
        {
            $free_product_category_id = get_option('free_product_category');
            $product_id = $cart_item['product_id'];

            if (has_term($free_product_category_id, 'product_cat', $product_id)) {
                return sprintf('1 <input type="hidden" name="cart[%s][qty]" value="1" />', $cart_item_key);
            } else {
                return $product_quantity;
            }
        }

        public function add_free_product_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
        {
            $free_product_category_id = get_option('free_product_category');

            if (has_term($free_product_category_id, 'product_cat', $product_id)) {
                $cart = WC()->cart;
                if (isset($cart->cart_contents[$cart_item_key])) {
                    $cart->cart_contents[$cart_item_key]['data']->set_price(0);
                    $cart->cart_contents[$cart_item_key]['quantity'] = 1;
                    $cart->set_session();
                }
            }
        }

        public function remove_free_product_if_needed()
        {
            $discount_category_id = get_option('discount_category');
            $discount_threshold = get_option('discount_threshold');
            $free_product_category_id = get_option('free_product_category');

            $cart = WC()->cart;
            $cart_items = $cart->get_cart();

            $discount_category_count = 0;
            $free_product_cart_item_key = null;
            foreach ($cart_items as $cart_item_key => $cart_item) {
                $product_id = $cart_item['product_id'];

                if (has_term($discount_category_id, 'product_cat', $product_id)) {
                    $discount_category_count += $cart_item['quantity'];
                }

                if (has_term($free_product_category_id, 'product_cat', $product_id)) {
                    $free_product_cart_item_key = $cart_item_key;
                }
            }

            if ($discount_category_count < $discount_threshold && $free_product_cart_item_key !== null) {
                $cart->remove_cart_item($free_product_cart_item_key);
            }
        }

        public function ajax_add_free_product()
        {
            //check_ajax_referer('add_free_product_nonce', 'nonce'); 

            $product_id = intval($_POST['product_id']);

            if (!empty($product_id)) {
                $cart = WC()->cart;
                $cart->add_to_cart($product_id, 1);
                $cart->set_session();

                wp_send_json_success('Product added to cart.');
            } else {
                error_log("Invalid product ID: " . $product_id);
                wp_send_json_error('Invalid product ID.');
            }
        }
    }
}

function woocommerce_category_discount()
{
    return new WooCommerce_Category_Discount();
}

// Инициализация плагина
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('plugins_loaded', 'woocommerce_category_discount');
}