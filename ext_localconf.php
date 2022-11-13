<?php
if (!defined('TYPO3_MODE')) {
    die ('Access denied.');
}

$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers'][\Jbaron\FalWebdav\Driver\WebdavDriver::DRIVER_KEY] = [
    'class' => \Jbaron\FalWebdav\Driver\WebdavDriver::class,
    'shortName' => \Jbaron\FalWebdav\Driver\WebdavDriver::DRIVER_KEY,
    'label' => 'WebDAV driver for FAL',
    'flexFormDS' => 'FILE:EXT:fal_webdav2/Configuration/FlexForm/DriverFlexForm.xml',
];
