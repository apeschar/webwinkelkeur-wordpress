<?php
namespace Valued\WordPress;

use ReflectionClass;

abstract class BasePlugin {
    protected static $instances = [];

    public $admin;

    public $frontend;

    public $woocommerce;

    /** @return string */
    abstract public function getSlug();

    /** @return string */
    abstract public function getName();

    /** @return string */
    abstract public function getMainDomain();

    /** @return string */
    abstract public function getDashboardDomain();

    public static function getInstance() {
        if (!isset(self::$instances[static::class])) {
            self::$instances[static::class] = new static();
        }
        return self::$instances[static::class];
    }

    public function init() {
        register_activation_hook($this->getPluginFile(), [$this, 'activatePlugin']);
        add_action('plugins_loaded', [$this, 'loadTranslations']);

        if (is_admin()) {
            $this->admin = new Admin($this);
        } else {
            $this->frontend = new Frontend($this);
        }

        $this->woocommerce = new WooCommerce($this);
    }

    public function activatePlugin() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta('
            CREATE TABLE `' . $this->getInviteErrorsTable() . '` (
                `id` int NOT NULL AUTO_INCREMENT,
                `url` varchar(255) NOT NULL,
                `response` text NOT NULL,
                `time` bigint NOT NULL,
                `reported` boolean NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `time` (`time`),
                KEY `reported` (`reported`)
            )
        ');
    }

    public function loadTranslations() {
        load_plugin_textdomain(
            'webwinkelkeur',
            false,
            "{$this->getSlug()}/common/languages/"
        );
    }

    /**
     * @param string $name
     * @return string
     */
    public function getOptionName($name) {
        return "{$this->getSlug()}_{$name}";
    }

    /** @return string */
    public function getInviteErrorsTable() {
        return $GLOBALS['wpdb']->prefix . $this->getSlug() . '_invite_error';
    }

    /**
     * @param string $__template
     * @param array $__scope
     * @return string
     **/
    public function render($__template, array $__scope) {
        extract($__scope);
        ob_start();
        require __DIR__ . '/../templates/' . $__template . '.php';
        return ob_get_clean();
    }

    public function getPluginFile() {
        $reflect = new ReflectionClass($this);
        return dirname(dirname($reflect->getFilename())) . '/' . $this->getSlug() . '.php';
    }

    public function isWoocommerceActivated(): bool {
        return class_exists('woocommerce');
    }

    public function getActiveGtinPlugin() {
        return GtinHandler::getActivePlugin();
    }

    public function getProductMetaKeys(): array {
        global $wpdb;
        $meta_keys = $wpdb->get_col("
            SELECT DISTINCT(pm.meta_key)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE
                p.post_type = 'product'
                AND pm.meta_key <> ''
                AND pm.meta_value <> ''
        ");
        return array_map(
            function ($value) {
                $meta_value = substr($this->getMetaValue($value), 0, 15);
                return [
                    'type' => 'meta_key',
                    'name' => $value,
                    'example_value' => $meta_value,
                ];
            },
            $meta_keys
        );
    }

    public function getKeysPerSuggestion(string $selected_key, bool $suggested = false) {
        $options = [];
        foreach ($this->getProductKeys() as $key) {
            if ($key['suggested'] != $suggested) {
                continue;
            }
            $options[] = '<option value="' . $key['option_value'] . '" ' . ($key['option_value'] == $selected_key ? 'selected' : '') . '>' . $key['label'] . '</option>';
        }
        return $options;
    }

    public function getProductKeys() {
        $custom_keys = array_merge($this->getProductMetaKeys(), $this->getCustomAttributes());
        return array_map(function ($value) {
            return [
                'option_value' => $value['type'] . htmlentities($value['name']),
                'label' => htmlentities($value['name']) . ' (e.g. ' . $value['example_value'] . ')',
                'suggested' => $this->isSuggested($value['example_value']),
            ];
        },
            $custom_keys
        );
    }

    private function getMetaValue($meta_key) {
        global $wpdb;
        $sql = "
            SELECT meta.meta_value
            FROM {$wpdb->postmeta} meta
            WHERE meta.meta_key = '{$meta_key}'
            AND meta.meta_value <> ''
            LIMIT 1;
        ";
        return $wpdb->get_var($sql);
    }
    private function getCustomAttributes(): array {
        global $wpdb;
        $custom_attributes = [];
        $sql = "
            SELECT meta.meta_id, meta.meta_key as name, meta.meta_value 
            FROM {$wpdb->postmeta} meta
            JOIN {$wpdb->posts} posts
            ON meta.post_id = posts.id 
            WHERE posts.post_type = 'product' 
            AND meta.meta_key='_product_attributes';";

        $data = $wpdb->get_results($sql);
        foreach ($data as $value) {
            $product_attr = unserialize($value->meta_value);
            if (!is_array($product_attr)) {
                continue;
            }
            foreach ($product_attr as $arr_value) {
                if (
                    !$this->isUniqueAttribute($custom_attributes, $arr_value['name']) ||
                    empty($arr_value['value'])
                ) {
                    continue;
                }
                $custom_attributes[] = [
                    'type' => 'custom_attribute',
                    'name' => $arr_value['name'],
                    'example_value' => $arr_value['value'],
                ];
            }
        }
        return $custom_attributes;
    }

    private function isUniqueAttribute(array $array, string $value): bool {
        foreach ($array as $item) {
            if (isset($item['name']) && $item['name'] == $value) {
                return false;
            }
        }
        return true;
    }

    private function isSuggested($value): bool {
        return preg_match('/^\d{8}(?:\d{4,6})?$/', $value);
    }
}
