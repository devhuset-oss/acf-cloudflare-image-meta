<?php
/*
 * Plugin Name: ACF Cloudflare Image Meta
 * Description: Automatically generates responsive Cloudflare image variants in ACF fields
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Version: 2.0.1
 * Author: Devhuset AS
 * Author URI: https://devhuset.no
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: acf-cloudflare-image-meta
 *
 * @package acf-cloudflare-image-meta
 */

defined('ABSPATH') || exit;

define('ACF_CLOUDFLARE_IMAGE_VERSION', '2.0.1');
define('ACF_CLOUDFLARE_IMAGE_DIR', plugin_dir_path(__FILE__));
define('ACF_CLOUDFLARE_IMAGE_URL', plugin_dir_url(__FILE__));
define('ACF_CLOUDFLARE_IMAGE_BASENAME', plugin_basename(__FILE__));

add_action('admin_init', function () {
    if (!class_exists('ACF')) {
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e('ACF Cloudflare Image Meta requires Advanced Custom Fields to be installed and activated.', 'acf-cloudflare-image-meta'); ?>
                </p>
            </div>
            <?php
        });
    }
});

if (file_exists(ACF_CLOUDFLARE_IMAGE_DIR . 'update.php')) {
    require ACF_CLOUDFLARE_IMAGE_DIR . 'update.php';
}

function register_cloudflare_image_field()
{
    if (!function_exists('acf_get_path')) {
        return;
    }

    require_once(acf_get_path('includes/fields/class-acf-field.php'));

    class ACF_Field_Cloudflare_Image extends acf_field
    {
        private $cloudflare_variants = [
            'thumbnail' => [
                'preset' => 'thumbnail',
                'width' => 150,
                'height' => 150
            ],
            'xs' => [
                'preset' => 'xs',
                'width' => 320,
                'height' => 320
            ],
            'sm' => [
                'preset' => 'sm',
                'width' => 480,
                'height' => 480
            ],
            'md' => [
                'preset' => 'md',
                'width' => 768,
                'height' => 768
            ],
            'lg' => [
                'preset' => 'lg',
                'width' => 1024,
                'height' => 1024
            ],
            'xl' => [
                'preset' => 'xl',
                'width' => 1440,
                'height' => 1440
            ],
            'public' => [
                'preset' => 'public',
                'width' => 2048,
                'height' => 2048
            ]
        ];

        public function __construct()
        {
            $this->name = 'cloudflare_image';
            $this->label = __('Cloudflare Image', 'acf-cloudflare-image-meta');
            $this->category = 'content';
            $this->defaults = array(
                'url' => '',
                'alt' => '',
            );

            add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
            parent::__construct();
        }

        public function validate_value($valid, $value, $field, $input)
        {
            if (!empty($value['url'])) {
                if (!preg_match('/^https:\/\/imagedelivery\.net\/[\w\-]+\/[\w\-]+\//', $value['url'])) {
                    return __('Please enter a valid Cloudflare Images URL', 'acf-cloudflare-image-meta');
                }
            }
            return $valid;
        }

        public function format_value($value, $post_id, $field)
        {
            if (empty($value['url'])) {
                return $value;
            }

            $url_parts = parse_url($value['url']);
            $path_parts = explode('/', trim($url_parts['path'], '/'));

            if (count($path_parts) < 2) {
                return $value;
            }

            $account_hash = $path_parts[0];
            $image_id = $path_parts[1];

            $value['variants'] = [];
            foreach ($this->cloudflare_variants as $size => $config) {
                $variant_url = "https://imagedelivery.net/{$account_hash}/{$image_id}/{$config['preset']}";

                $value['variants'][$size] = [
                    'url' => $variant_url,
                    'width' => $config['width'],
                    'height' => $config['height']
                ];
            }

            return $value;
        }

        public function input_admin_enqueue_scripts()
        {
            $this->enqueue_scripts();
        }

        public function enqueue_scripts()
        {
            wp_enqueue_style(
                'acf-cloudflare-image',
                ACF_CLOUDFLARE_IMAGE_URL . 'assets/css/admin.css',
                [],
                ACF_CLOUDFLARE_IMAGE_VERSION
            );
        }

        public function render_field($field)
        {
            $value = isset($field['value']) ? $field['value'] : [];

            $preview_url = '';
            if (!empty($value['url'])) {
                $formatted = $this->format_value($value, null, $field);
                $preview_url = $formatted['variants']['thumbnail']['url'] ?? $value['url'];
            }
            ?>
            <div class="acf-cloudflare-image-wrapper">
                <div class="acf-input-wrap">
                    <label><?php esc_html_e('Image URL', 'acf-cloudflare-image-meta'); ?></label>
                    <input type="url" name="<?php echo esc_attr($field['name']); ?>[url]"
                        value="<?php echo esc_url($value['url'] ?? ''); ?>" class="cloudflare-url-input" />
                    <?php if ($preview_url): ?>
                        <div class="cloudflare-image-preview">
                            <img src="<?php echo esc_url($preview_url); ?>" alt="<?php echo esc_attr($value['alt'] ?? ''); ?>"
                                class="preview-image" style="max-width: 150px; height: auto;" />
                        </div>
                    <?php endif; ?>
                </div>

                <div class="acf-input-wrap">
                    <label><?php esc_html_e('Alt Text', 'acf-cloudflare-image-meta'); ?></label>
                    <input type="text" name="<?php echo esc_attr($field['name']); ?>[alt]"
                        value="<?php echo esc_attr($value['alt'] ?? ''); ?>" />
                </div>
            </div>
            <?php
        }

        public function render_field_settings($field)
        {
            acf_render_field_setting($field, [
                'label' => __('Instructions', 'acf-cloudflare-image-meta'),
                'instructions' => __('Paste a Cloudflare Images URL. Available variants: xs, sm, md, lg, xl, thumbnail, public', 'acf-cloudflare-image-meta'),
                'type' => 'message',
                'name' => 'instructions'
            ]);
        }
    }

    acf_register_field_type(new ACF_Field_Cloudflare_Image());
}

add_action('acf/include_field_types', 'register_cloudflare_image_field', 5);