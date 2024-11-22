<?php
/*
 * Plugin Name: ACF Cloudflare Image Meta
 * Description: Automatically fetches and stores metadata for Cloudflare images in ACF fields
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Version: 1.0.0
 * Author: Devhuset AS
 * Author URI: https://devhuset.no
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: acf-cloudflare-image-meta
 *
 * @package acf-cloudflare-image-meta
 */

defined('ABSPATH') || exit;

define('ACF_CLOUDFLARE_IMAGE_VERSION', '1.0.0');
define('ACF_CLOUDFLARE_IMAGE_DIR', plugin_dir_path(__FILE__));
define('ACF_CLOUDFLARE_IMAGE_URL', plugin_dir_url(__FILE__));
define('ACF_CLOUDFLARE_IMAGE_BASENAME', plugin_basename(__FILE__));

// Check ACF dependency
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

// Include updates file if it exists
if (file_exists(ACF_CLOUDFLARE_IMAGE_DIR . 'update.php')) {
    require ACF_CLOUDFLARE_IMAGE_DIR . 'update.php';
}

class ACF_Field_Cloudflare_Image extends acf_field
{
    private $cache_group = 'cloudflare_image_meta';
    private $cache_expiration = DAY_IN_SECONDS;

    public function __construct()
    {
        $this->name = 'cloudflare_image';
        $this->label = __('Cloudflare Image', 'acf-cloudflare-image-meta');
        $this->category = 'content';

        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        parent::__construct();
    }

    public function enqueue_scripts()
    {
        wp_enqueue_style(
            'acf-cloudflare-image',
            ACF_CLOUDFLARE_IMAGE_URL . 'assets/css/admin.css',
            [],
            ACF_CLOUDFLARE_IMAGE_VERSION
        );

        wp_enqueue_script(
            'acf-cloudflare-image',
            ACF_CLOUDFLARE_IMAGE_URL . 'assets/js/admin.js',
            ['jquery'],
            ACF_CLOUDFLARE_IMAGE_VERSION,
            true
        );

        wp_localize_script('acf-cloudflare-image', 'acfCloudflareImage', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('acf_cloudflare_image'),
        ]);
    }

    public function render_field($field)
    {
        $value = isset($field['value']) ? $field['value'] : [];
        ?>
        <div class="acf-cloudflare-image-wrapper">
            <div class="acf-input-wrap">
                <label><?php esc_html_e('Image URL', 'acf-cloudflare-image-meta'); ?></label>
                <input type="url" name="<?php echo esc_attr($field['name']); ?>[url]"
                    value="<?php echo esc_url($value['url'] ?? ''); ?>" class="cloudflare-url-input" />
                <div class="cloudflare-image-preview">
                    <?php if (!empty($value['url'])): ?>
                        <img src="<?php echo esc_url($value['url']); ?>" alt="<?php echo esc_attr($value['alt'] ?? ''); ?>"
                            class="preview-image" />
                    <?php endif; ?>
                </div>
            </div>

            <div class="acf-input-wrap">
                <label><?php esc_html_e('Alt Text', 'acf-cloudflare-image-meta'); ?></label>
                <input type="text" name="<?php echo esc_attr($field['name']); ?>[alt]"
                    value="<?php echo esc_attr($value['alt'] ?? ''); ?>" />
            </div>

            <div class="image-metadata">
                <?php if (!empty($value['metadata'])): ?>
                    <p><?php esc_html_e('Width:', 'acf-cloudflare-image-meta'); ?>
                        <?php echo esc_html($value['metadata']['width'] ?? ''); ?>
                    </p>
                    <p><?php esc_html_e('Height:', 'acf-cloudflare-image-meta'); ?>
                        <?php echo esc_html($value['metadata']['height'] ?? ''); ?>
                    </p>
                    <p><?php esc_html_e('Last Updated:', 'acf-cloudflare-image-meta'); ?>
                        <?php echo esc_html(date_i18n(get_option('date_format'), $value['metadata']['updated'] ?? time())); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function update_value($value, $post_id, $field)
    {
        if (empty($value['url']) || !wp_http_validate_url($value['url']) || !strpos($value['url'], 'imagedelivery.net')) {
            return $value;
        }

        // Check cache first
        $cache_key = md5($value['url']);
        $cached_data = wp_cache_get($cache_key, $this->cache_group);

        if ($cached_data !== false) {
            $value['metadata'] = $cached_data;
            return $value;
        }

        $image_data = $this->get_image_dimensions($value['url']);
        if ($image_data) {
            $metadata = [
                'width' => $image_data['width'],
                'height' => $image_data['height'],
                'updated' => time()
            ];

            $value['metadata'] = $metadata;
            wp_cache_set($cache_key, $metadata, $this->cache_group, $this->cache_expiration);
        } else {
            $this->log_error('Failed to get image dimensions for URL: ' . $value['url']);
        }

        return $value;
    }

    private function get_image_dimensions($url)
    {
        $temp_file = download_url($url);

        if (is_wp_error($temp_file)) {
            $this->log_error('Failed to download image: ' . $temp_file->get_error_message());
            return false;
        }

        $size = @getimagesize($temp_file);
        unlink($temp_file);

        if ($size === false) {
            $this->log_error('Failed to get image size for downloaded file');
            return false;
        }

        return [
            'width' => $size[0],
            'height' => $size[1]
        ];
    }

    private function log_error($message)
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[ACF Cloudflare Image Meta] ' . $message);
        }

        // Store error in transient for admin notice
        $errors = get_transient('acf_cloudflare_image_errors') ?: [];
        $errors[] = [
            'message' => $message,
            'time' => time()
        ];
        set_transient('acf_cloudflare_image_errors', array_slice($errors, -5), HOUR_IN_SECONDS);
    }
}

// Register field
add_action('acf/include_field_types', function () {
    new ACF_Field_Cloudflare_Image();
});

// Display admin notices for errors
add_action('admin_notices', function () {
    $errors = get_transient('acf_cloudflare_image_errors');
    if (!empty($errors)) {
        foreach ($errors as $error) {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php echo esc_html(sprintf(
                    __('ACF Cloudflare Image Meta Error (%s): %s', 'acf-cloudflare-image-meta'),
                    date_i18n(get_option('date_format'), $error['time']),
                    $error['message']
                )); ?></p>
            </div>
            <?php
        }
        delete_transient('acf_cloudflare_image_errors');
    }
});