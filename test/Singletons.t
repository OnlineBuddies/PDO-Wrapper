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
$t = new mh_test(16);

define( "DSN", "mysql:dbname=test" );
define( "USER", "root" );
define( "PASS", null );

function trace($msg) {
    global $t;
    $t->diag($msg);
}

class Test_PDO extends OLB_PDO {
    public $warnings = array();
    function try_clearSingleton() {
        $this->clearSingleton();
    }
    function try_makeSingleton() {
        $this->makeSingleton();
    }
    function logWarning($msg) {
        global $t;
        $t->diag($msg);
        $this->warnings[] = $msg;
    }
}
$dbh = Test_PDO::getInstance(DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ));
$t->ok( $dbh instanceOf OLB_PDO, "getInstance on a subclass will still get OLB_PDO if you don't specify the class");

$dbh = Test_PDO::getInstance(DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ), 'Test_PDO');
$t->ok( $dbh instanceOf Test_PDO, "getInstance constructs a new OLB_PDO object");

$dbh2 = Test_PDO::getInstance(DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ), 'Test_PDO');
$t->ok( $dbh === $dbh2, "Multiple calls to getInstance with the same args result in the same object");

$t->try_test("We can remove a database handle from the singleton pool");
try {
    $dbh->try_clearSingleton();
    $t->pass();
}
catch (Exception $e) {
    $t->except_fail($e);
}

$t->try_test("We can make the database handle a singleton again");
try {
    $dbh->try_makeSingleton();
    $t->pass();
}
catch (Exception $e) {
    $t->except_fail($e);
}

$dbh2 = Test_PDO::getInstance(DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ), 'Test_PDO');
$t->ok( $dbh === $dbh2, "Remaking the singleton means that a later call to getInstance returns the original object" );

$dbh->try_clearSingleton();
$dbh2 = Test_PDO::getInstance(DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ), 'Test_PDO');
$t->ok( $dbh !== $dbh2, "getInstance creates a new object rather then returning the one removed from the pool" );

$dbh->try_makeSingleton();
$t->is( count($dbh->warnings), 1, "Trying to register a singleton when a replacement has been made triggers a warning");

$dbh3 = Test_PDO::getInstance(DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ), 'Test_PDO');
$t->ok( $dbh2 === $dbh3, "Later instances are identical, despite failed attempts to put the first instance back in the pool" );


// Fetch a non-singleton
$dbh = new Test_PDO( DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ));

$t->try_test("clearSingleton on a non-singleton throws an exception");
try {
    $dbh->try_clearSingleton();
    $t->fail();
}
catch (Exception $e) {
    $t->pass();
}

$t->try_test("makeSingleton on a non-singleton throws an exception");
try {
    $dbh->try_makeSingleton();
    $t->fail();
}
catch (Exception $e) {
    $t->pass();
}

$dbh = OLB_PDO::getInstance(DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ));

$sth = $dbh->query("SELECT 1 AS result");
$row = $sth->fetch();
$sth->closeCursor();
$t->is( $row["result"], 1, "Singleton object worked ok" );

$dbh->disconnect();

$t->try_test("commit on a disconnected handle throws an exception");
try {
    $dbh->commit();
    $t->fail();
}
catch (PDOException $e) {
    $t->pass();
}
$t->try_test("rollBack on a disconnected handle throws an exception");
try {
    $dbh->rollBack();
    $t->fail();
}
catch (PDOException $e) {
    $t->pass();
}

$t->try_test("beginTransaction on a singleton throws an exception");
try {
    $dbh->beginTransaction();
    $t->fail();
}
catch (PDOException $e) {
    $t->pass();
}

$t->try_test("setAttribute on a singleton throws an acception");
try {
    $dbh->setAttribute(PDO::ATTR_AUTOCOMMIT,TRUE);
    $t->fail();
}
catch (PDOException $e) {
    $t->pass();
}
