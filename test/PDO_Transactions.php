#!/usr/bin/env php
<?
/**
 * Copyright © 2011 Online Buddies, Inc. - All Rights Reserved
 *
 * @package OLB::PDO
 * @author bturner@online-buddies.com
 */

@include dirname(__FILE__)."/../build/test.php"; // Under OLBSL
@include dirname(__FILE__)."/../../bootstrap/unit.php"; // In MHBangV4
@include_once "OLB/PDO.php";
@include_once SF_ROOT."/OLB/PDO.php";

$t = new mh_test(5);

define( "HOST", "dev-maindb.dev.manhunt.net" );
define( "DBNAME", "test" );
define( "DSN", "mysql:host=".HOST.";dbname=".DBNAME );
define( "USER", "build" );
define( "PASS", "lettherebedata" );

class Test_PDO extends OLB_PDO {
    public function logRetry( $connects, $retries, $str ) {
        global $t;
        $t->diag("MySQL connection #".$connects.", retry #".$retries.": $str");
    }
}

$suffix = getmypid();

$dbh = Test_PDO::getInstance( DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ));
$dbh2 = new Test_PDO( DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ));
$init = array(
    "SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE",
    "SET net_read_timeout=70",
    );
foreach ($init as $sql) {
    $dbh->exec($sql);
    $dbh2->exec($sql);
}

$dbh->exec("DROP TABLE IF EXISTS pdo_test$suffix");
$dbh->exec("CREATE TABLE pdo_test$suffix ( id int auto_increment primary key, foo varchar(50) not null ) ENGINE=InnoDB");
$dbh->exec("INSERT INTO pdo_test$suffix SET foo='test this'");

// Test that successful transactions work
function test1_do($dbh) {
    global $suffix;
    $sth = $dbh->prepare("SELECT MAX(id) FROM pdo_test$suffix");
    $sth->execute();
    $row = $sth->fetch();
    $sth = $dbh->prepare("INSERT INTO pdo_test$suffix SET foo=?");
    $sth->execute(array( "Test #".$row[0]." added"));
}
function test1_rollback($dbh) {
    global $t;
    $t->diag("test1 rollback");
}

$dbh->execTransaction( "test1_do", "test1_rollback" );

$sth = $dbh->prepare("SELECT * FROM pdo_test$suffix WHERE id=2");
$sth->execute();
$row = $sth->fetch();
$t->is( $row['foo'], "Test #1 added", "Transaction completed");


// Test that invalid transactions get rolled back
$t->try_test("invalid transaction rolled back");
function test2_do($dbh) {
    global $suffix;
    $sth = $dbh->prepare("SELECT MAX(id) FROM pdo_test$suffix");
    $sth->execute();
    $row = $sth->fetch();
    $sth = $dbh->prepare("INSEERT INTO pdo_test$suffix SET foo=?");
    $sth->execute(array( "Test #".$row[0]." added"));
    $t->fail();
}
function test2_rollback($dbh) {
    global $t;
    $t->pass();
}

try {
    $dbh->execTransaction( "test2_do", "test2_rollback" );
    $t->fail("Invalid transaction threw exception");
}
catch (PDOException $e) {
    $t->pass("Invalid transaction threw exception");
}

// Test that deadlock exceptions results in retries-- we do this by
// artificially throwing an exception, as we can't easily do this in the
// current development environment.
$tried = 0;
function test3_do($dbh) {
    global $suffix;
    $sth = $dbh->prepare("SELECT MAX(id) FROM pdo_test$suffix");
    $sth->execute();
    $row = $sth->fetch();
    global $tried;
    if ( ! $tried ++ ) {
        throw new PDOException("SQLSTATE[0000]: 1213 Deadlock found when trying to get lock; try restarting transaction");
    }
    global $t;
    $t->pass("No rollback outside of the first pass");
}
function test3_rollback($dbh) {
    global $t;
    global $tried;
    if ( $tried == 1) {
        $t->diag("Rolling back due to deadlock on first pass");
    }
    else {
        $t->fail("No rollback outside of the first pass");
    }
}

$t->try_test("'Deadlocking' transaction");
try {
    $dbh->execTransaction( "test3_do", "test3_rollback" );
    $t->pass();
}
catch (Exception $e) {
    $t->except_fail($e);
}


/* These don't work due to connection timeouts that we don't seem to be able to override.
 
$dbh->exec("DROP TABLE IF EXISTS deadlock_maker$suffix");
$dbh->exec("CREATE TABLE deadlock_maker$suffix (a INT PRIMARY KEY) ENGINE=InnoDB");
$dbh->exec("INSERT INTO deadlock_maker$suffix (a) VALUES (1), (2)");

function testXX_do($dbh) {
    global $suffix;
    $dbh->query("SELECT * FROM deadlock_maker$suffix WHERE a=1")->fetchAll();
    try {
        $dbh->exec("UPDATE deadlock_maker$suffix SET a=1 WHERE a <> 1");
    }
    catch (PDOException $e) {
        if ( strpos($e->getMessage(), "1062 Duplicate entry") === FALSE ) {
            throw $e;
        }
    }
}
function testXX_rollback($dbh) {
    global $t;
    $t->diag("test1 rollback");
}

$dbh2->beginTransaction();
$dbh2->query("SELECT * FROM deadlock_maker$suffix WHERE a=2")->fetchAll();
try {
    $dbh2->exec("UPDATE deadlock_maker$suffix SET a=2 WHERE a <> 2");
}
catch (PDOException $e) {
    if ( strpos($e->getMessage(), "1062 Duplicate entry") === FALSE ) {
        throw $e;
    }
    $t->diag("Duplicate on update, that's fine.");
}
$dbh->execTransaction( "testXX_do", "testXX_rollback" );
if ( $dbh2->inTransaction() ) {
    $dbh2->query("SELECT 1")->fetchAll();
    $dbh2->commit();
}

$dbh->exec("DROP TABLE IF EXISTS deadlock_maker$suffix");
*/


$dbh->exec("DROP TABLE IF EXISTS pdo_test$suffix");

