<?php

/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/**
 * This is the package.xml generator for Emogrifier
 *
 * PHP version 5
 *
 * LICENSE: The MIT License (MIT)
 *
 * @package   Emogrifier
 * @author    Pelago <info@pelagodesign.com>
 * @author    Hafiz Ismail
 * @author    Nick Burka <nick@silverorange.com>
 * @copyright 2008-2013 Pelago Design
 * @copyright 2013 silverorange
 * @license   http://opensource.org/licenses/mit-license.php The MIT License (MIT)
 */

require_once 'PEAR/PackageFileManager2.php';
PEAR::setErrorHandling(PEAR_ERROR_DIE);

$apiVersion     = '1.0';
$apiState       = 'beta';

$releaseVersion = '1.0';
$releaseState   = 'beta';

$package = new PEAR_PackageFileManager2();

$package->setOptions(
    array(
        'filelistgenerator' => 'file',
        'simpleoutput'      => true,
        'baseinstalldir'    => '/',
        'packagedirectory'  => './',
        'dir_roles'         => array(
            'Emogrifier'    => 'php'
        ),
        'exceptions'        => array(
            'LICENSE.txt'   => 'doc',
            'CHANGELOG.txt' => 'doc',
            'README.md'     => 'doc'
        ),
        'ignore'            => array(
            'composer.json',
            'package.php',
            '*.tgz'
        )
    )
);

$package->setPackage('Emogrifier');
$package->setSummary('Turns CSS into inline styles');
$package->setDescription('Emogrifier automagically transmogrifies your HTML '.
    'by parsing your CSS and inserting your CSS definitions into '.
    'tags within your HTML based on your CSS selectors.');

$package->setChannel('pear.silverorange.com');
$package->setPackageType('php');
$package->setLicense('MIT', 'http://opensource.org/licenses/mit-license.php');
$package->setNotes('First release!');

$package->setReleaseVersion($releaseVersion);
$package->setReleaseStability($releaseState);
$package->setAPIVersion($apiVersion);
$package->setAPIStability($apiState);

$package->addMaintainer(
    'lead',
    'nick',
    'Nick Burka',
    'nick@silverorange.com'
);

$package->setPhpDep('5.2.1');
$package->addExtensionDep('required', 'libxml');
$package->addExtensionDep('required', 'mbstring');
$package->setPearinstallerDep('1.4.0');

$package->generateContents();

if (   isset($_GET['make'])
    || (isset($_SERVER['argv']) && @$_SERVER['argv'][1] == 'make')
) {
    $package->writePackageFile();
} else {
    $package->debugPackageFile();
}

?>
