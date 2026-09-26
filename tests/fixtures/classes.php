<?php

declare(strict_types=1);

/**
 * BillMySales for WooCommerce.
 *
 * Copyright (c) 2026 BillMySales <https://www.billmysales.com>
 * Licensed under the GNU Affero General Public License v3.0 or later.
 * See LICENSE file for more details.
 */

// Minimal WordPress and WooCommerce classes the plugin uses, for the unit
// tests (mocked with Mockery; functions are mocked with Brain Monkey).
// PHPStan uses the real stubs instead (the file is excluded there).

namespace {
    /**
     * Double of WordPress' REST request.
     */
    class WP_REST_Request
    {
        /**
         * HTTP method.
         *
         * @var string
         */
        private string $method;

        /**
         * Route.
         *
         * @var string
         */
        private string $route;

        /**
         * A request for a route.
         *
         * @param string $method HTTP method.
         * @param string $route  Route.
         */
        public function __construct(string $method, string $route)
        {
            $this->method = $method;
            $this->route = $route;
        }

        /**
         * HTTP method.
         *
         * @return string
         */
        public function get_method(): string
        {
            return $this->method;
        }

        /**
         * Route.
         *
         * @return string
         */
        public function get_route(): string
        {
            return $this->route;
        }
    }

    /**
     * Double of WordPress' error.
     */
    class WP_Error
    {
        /**
         * Error message.
         *
         * @var string
         */
        private string $message;

        /**
         * An error.
         *
         * @param string $code    Error code.
         * @param string $message Error message.
         */
        public function __construct(string $code = '', string $message = '')
        {
            $this->message = $message;
        }

        /**
         * Error message.
         *
         * @return string
         */
        public function get_error_message(): string
        {
            return $this->message;
        }
    }

    /**
     * Double of WooCommerce's order (the methods the plugin calls).
     */
    class WC_Order
    {
        /**
         * Order id.
         *
         * @return int
         */
        public function get_id(): int
        {
            return 0;
        }

        /**
         * Status, without the "wc-" prefix.
         *
         * @return string
         */
        public function get_status(): string
        {
            return '';
        }

        /**
         * A meta value.
         *
         * @param string $key Meta key.
         * @return mixed
         */
        public function get_meta(string $key)
        {
            return '';
        }

        /**
         * Sets a meta value.
         *
         * @param string $key   Meta key.
         * @param mixed  $value Value.
         * @return void
         */
        public function update_meta_data(string $key, $value): void
        {
        }

        /**
         * Adds a note.
         *
         * @param string $note Note.
         * @return int Note id.
         */
        public function add_order_note(string $note): int
        {
            return 0;
        }
    }
}

namespace Automattic\WooCommerce\Utilities {
    /**
     * Double of WooCommerce's features utility.
     */
    class FeaturesUtil
    {
        /**
         * Fires a hook the tests expect (Brain Monkey's expectDone).
         *
         * @param string $feature  Feature.
         * @param string $file     Plugin file.
         * @param bool   $positive Compatible or not.
         * @return void
         */
        public static function declare_compatibility(string $feature, string $file, bool $positive): void
        {
            do_action('test_features_util_declare_compatibility', $feature, $file, $positive);
        }
    }
}
