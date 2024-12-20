<?php
/**
 * Plugin update checker
 *
 * @package acf-cloudflare-image-meta
 */

if (!class_exists('acf_cloudflare_update_checker')) {
    class acf_cloudflare_update_checker
    {
        public $plugin_slug;
        public $version;
        public $cache_key;
        public $cache_allowed;

        public function __construct()
        {
            if (!defined('ACF_CLOUDFLARE_IMAGE_BASENAME') || !defined('ACF_CLOUDFLARE_IMAGE_VERSION')) {
                return;
            }

            $this->plugin_slug = ACF_CLOUDFLARE_IMAGE_BASENAME;
            $this->version = ACF_CLOUDFLARE_IMAGE_VERSION;
            $this->cache_key = 'acf_cloudflare_upd';
            $this->cache_allowed = false;

            add_filter('plugins_api', array($this, 'info'), 20, 3);
            add_filter('site_transient_update_plugins', array($this, 'update'));
            add_action('upgrader_process_complete', array($this, 'purge'), 10, 2);
        }

        public function request()
        {
            $remote = get_transient($this->cache_key);

            if (false === $remote || !$this->cache_allowed) {
                $remote = wp_remote_get(
                    'https://plugins.devhuset.dev/acf-cloudflare-image-meta/info.json',
                    array(
                        'timeout' => 10,
                        'headers' => array(
                            'Accept' => 'application/json'
                        )
                    )
                );

                if (
                    is_wp_error($remote)
                    || 200 !== wp_remote_retrieve_response_code($remote)
                    || empty(wp_remote_retrieve_body($remote))
                ) {
                    return false;
                }

                set_transient($this->cache_key, $remote, HOUR_IN_SECONDS / 2);
            }

            $remote = json_decode(wp_remote_retrieve_body($remote));

            return $remote;
        }

        public function info($res, $action, $args)
        {
            if ('plugin_information' !== $action) {
                return $res;
            }

            if ($this->plugin_slug !== $args->slug) {
                return $res;
            }

            $remote = $this->request();

            if (!$remote) {
                return $res;
            }

            $res = new stdClass();

            $res->name = $remote->name;
            $res->slug = $remote->slug;
            $res->version = $remote->version;
            $res->tested = $remote->tested;
            $res->requires = $remote->requires;
            $res->author = $remote->author;
            $res->download_link = $remote->download_url;
            $res->trunk = $remote->download_url;
            $res->requires_php = $remote->requires_php;
            $res->last_updated = $remote->last_updated;

            $res->sections = array(
                'description' => $remote->sections->description,
                'installation' => $remote->sections->installation,
                'changelog' => $remote->sections->changelog
            );

            if (!empty($remote->banners)) {
                $res->banners = array(
                    'low' => $remote->banners->low,
                    'high' => $remote->banners->high
                );
            }

            return $res;
        }

        public function update($transient)
        {
            if (empty($transient->checked)) {
                return $transient;
            }

            $remote = $this->request();

            if (
                $remote
                && version_compare($this->version, $remote->version, '<')
                && version_compare($remote->requires, get_bloginfo('version'), '<=')
                && version_compare(PHP_VERSION, $remote->requires_php, '>=')
            ) {
                $res = new stdClass();
                $res->slug = $this->plugin_slug;
                $res->plugin = $this->plugin_slug;
                $res->new_version = $remote->version;
                $res->tested = $remote->tested;
                $res->package = $remote->download_url;

                $transient->response[$res->plugin] = $res;
            }

            return $transient;
        }

        public function purge($upgrader, $options)
        {
            if (
                $this->cache_allowed
                && 'update' === $options['action']
                && 'plugin' === $options['type']
            ) {
                delete_transient($this->cache_key);
            }
        }
    }

    new acf_cloudflare_update_checker();
}