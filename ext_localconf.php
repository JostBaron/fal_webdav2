<?php
if (!defined('TYPO3_MODE')) {
    die ('Access denied.');
}

/** @var \TYPO3\CMS\Core\Resource\Driver\DriverRegistry $driverRegistry */
$driverRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
    \TYPO3\CMS\Core\Resource\Driver\DriverRegistry::class
);
$driverRegistry->registerDriverClass(
    \Jbaron\FalWebdav\Driver\WebdavDriver::class,
    \Jbaron\FalWebdav\Driver\WebdavDriver::DRIVER_KEY,
    'WebDAV driver for FAL',
    'FILE:EXT:fal_webdav2/Configuration/FlexForm/DriverFlexForm.xml'
);

