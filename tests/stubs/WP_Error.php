<?php

/**
 * Minimal WP_Error stub for the unit tier (real WP is only loaded in the integration tier).
 * Mirrors the surface MailEventLogger relies on: get_error_data, get_error_messages, add.
 */
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<int,string> */
        private $messages = [];

        /** @var mixed */
        private $data;

        public function __construct($code = '', $message = '', $data = null)
        {
            if ($message !== '') {
                $this->messages[] = $message;
            }

            $this->data = $data;
        }

        public function add($code, $message = '', $data = null): void
        {
            if ($message !== '') {
                $this->messages[] = $message;
            }

            if ($data !== null) {
                $this->data = $data;
            }
        }

        /**
         * @return array<int,string>
         */
        public function get_error_messages(): array
        {
            return $this->messages;
        }

        /**
         * @return mixed
         */
        public function get_error_data()
        {
            return $this->data;
        }
    }
}
