<?php

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Paysafe_Payment',
    __DIR__
);

if (!class_exists('\Paysafe\Request')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . 'SDK/paysafe.php';
}
