<?php
/**
 * PHPUnit bootstrap: no WordPress boot required, WP_Mock stands in for every core function/hook.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

WP_Mock::bootstrap();

// WP_Mock doesn't ship WP_Error; code under test only constructs and type-checks it.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(public $code = '', public $message = '', public $data = '')
        {
        }
    }
}
