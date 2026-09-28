<?php

/** Minimal WP_Error stub for unit tier; mirrors plugin's required surface. */
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /**
         * @var array<int,string>
         */
        private $messages = [];

        /**
         * @var mixed
         */
        private $data;

        /**
         * @var string
         */
        private $code = '';

        public function __construct($code = '', $message = '', $data = null)
        {
            $this->code = (string) $code;

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
         * The first error message, or '' when there is none (mirrors core's default-code lookup).
         */
        public function get_error_message(): string
        {
            return $this->messages[0] ?? '';
        }

        public function get_error_code(): string
        {
            return $this->code;
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
