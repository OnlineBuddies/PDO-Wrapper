#!/usr/bin/env php
<?
/**
 * Copyright © 2011 Online Buddies, Inc. - All Rights Reserved
 *
 * @package OLB::PDO
 * @author bturner@online-buddies.com
 */

include dirname(__FILE__)."/../build/mh_test.php";
require_once "OLB/PDO.php";

global $t;
$t = new mh_test(4);

define( "DSN", "mysql:dbname=test" );
define( "USER", "root" );
define( "PASS", null );

$dbh = new OLB_PDO( DSN, USER, PASS );

$t->ok( $dbh instanceOf OLB_PDO, "Basic constructor, no arguments" );

$dbh = new OLB_PDO( DSN, USER, PASS, array(
    OLB_PDO::RETRY_JITTER => null,
    PDO::ATTR_ERRMODE => null,
    ));

$t->ok( $dbh instanceOf OLB_PDO, "Constructor with clear of opt and attr defaults" );

$dbh = new OLB_PDO( DSN, USER, PASS, array(
    OLB_PDO::RETRY_JITTER => 1.0,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_WARNING,
    ));
$t->ok( $dbh instanceOf OLB_PDO, "Constructor setting opt and attr values" );

$t->try_test( "Constructing with no RETRIES throws an exception" );
try {
    $dbh = new OLB_PDO( DSN, USER, PASS, array( OLB_PDO::RETRIES => null ) );
    $t->fail();
}
catch (PDOException $e) {
    $t->pass();
}
