<?php
/**
 * PHPUnit bootstrap: no WordPress boot required, WP_Mock stands in for every core function/hook.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

WP_Mock::bootstrap();
