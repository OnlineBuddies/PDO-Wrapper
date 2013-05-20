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
$t = new mh_test(15);

function trace($msg) {
    global $t;
    $t->diag($msg);
}


define( "HOST", "localhost" );
define( "DBNAME", "test" );
define( "DSN", "mysql:host=".HOST.";dbname=".DBNAME );
define( "USER", "root" );
define( "PASS", null );

class Test_PDO extends OLB_PDO {
    public function logRetry( $connects, $retries, $str ) {
        global $t;
        $t->diag("MySQL connection #".$connects.", retry #".$retries.": $str");
    }
}

global $suffix;
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
$id = 1;

$dbh2->beginTransaction();

$dbh2->exec("INSERT INTO pdo_test$suffix SET foo='test this'");

$sth = $dbh2->query("SELECT COUNT(*) as count FROM pdo_test$suffix", PDO::FETCH_OBJ );
$obj = $sth->fetch();
$t->is( $obj->count, 1, "Transactions: Insert added a row" );

$dbh2->rollBack();

$sth = $dbh2->query("SELECT COUNT(*) as count FROM pdo_test$suffix", PDO::FETCH_OBJ );
$obj = $sth->fetch();
$t->is( $obj->count, 0, "Transactions: Rollback removed the row" );

$dbh2->beginTransaction();

$dbh2->exec("INSERT INTO pdo_test$suffix SET foo='out'");

$sth = $dbh2->query("SELECT COUNT(*) as count FROM pdo_test$suffix", PDO::FETCH_OBJ );
$obj = $sth->fetch();
$t->is( $obj->count, 1, "Transactions: Insert added another row" );

$dbh2->commit();

$sth = $dbh2->query("SELECT COUNT(*) as count FROM pdo_test$suffix", PDO::FETCH_OBJ );
$obj = $sth->fetch();
$t->is( $obj->count, 1, "Transactions: And its still there after our commit" );


$dbh->exec("DELETE FROM pdo_test$suffix");
$dbh->exec("INSERT INTO pdo_test$suffix SET foo='test this'");


// Test that successful transactions work
function test1_do($dbh) {
    global $t;
    $t->diag("Running transaction...");
    global $suffix;
    $sth = $dbh->prepare("SELECT MAX(id) FROM pdo_test$suffix");
    $sth->execute();
    $row = $sth->fetch();
    $sth = $dbh->prepare("INSERT INTO pdo_test$suffix SET foo=?");
    $sth->execute(array( "Test #".$row[0]." added"));
    global $id;
    $id = $dbh->lastInsertId();
}
function test1_rollback($dbh) {
    global $t;
    $t->diag("test1 rollback");
}

$dbh->execTransaction( "test1_do", "test1_rollback" );

$sth = $dbh->prepare("SELECT * FROM pdo_test$suffix WHERE id=?");
$sth->execute(array( $id ));
$row = $sth->fetch();
$t->like( $row['foo'], "/Test #\d+ added/", "Transaction completed");

// Test nested 
function nest1_do($dbh) {
    global $t;
    $t->diag("Running transaction...");
    global $suffix;
    $sth = $dbh->prepare("SELECT MAX(id) FROM pdo_test$suffix");
    $sth->execute();
    $row = $sth->fetch();
    $testNum = ($row[0]) + 1;
    $sth = $dbh->prepare("INSERT INTO pdo_test$suffix SET foo=?");
    $sth->execute(array( "Test #$testNum added"));
    global $id;
    $id = $dbh->lastInsertId();
    try {
        $dbh->execTransaction( "nest2_do", "nest2_rollback" );
    }
    catch (Exception $e) {
        $t->diag("Sub exception ".$e->getMessage());
    }
}
function nest1_rollback($dbh) {
    global $t;
    $t->diag("nest1 rollback");
}
function nest2_do($dbh) {
    global $t;
    $t->diag("Running transaction...");
    global $suffix;
    $sth = $dbh->prepare("SELECT MAX(id) FROM pdo_test$suffix");
    $sth->execute();
    $row = $sth->fetch();
    $testNum = ($row[0]) + 1;
    $sth = $dbh->prepare("INSERT INTO pdo_test$suffix SET foo=?");
    $sth->execute(array( "Test #$testNum added"));
    throw new Exception("BOOM");
}
function nest2_rollback($dbh) {
    global $t;
    $t->diag("nest2 rollback");
}

$dbh->execTransaction( "nest1_do", "nest1_rollback" );

$sth = $dbh->prepare("SELECT * FROM pdo_test$suffix WHERE id=?");
$sth->execute(array( $id ));
$row = $sth->fetch();
$t->like( $row['foo'], "/Test #\d+ added/", "Transaction completed");

class TestException extends Exception implements OLB_PDOCommitTransaction {}

// Test that throwing a PDOCommiTransaction exception doesn't rollback
// anything.
function test_throw_do($dbh) {
    global $t;
    $t->diag("Running transaction...");
    global $suffix;
    $sth = $dbh->prepare("SELECT MAX(id) FROM pdo_test$suffix");
    $sth->execute();
    $row = $sth->fetch();
    $sth = $dbh->prepare("INSERT INTO pdo_test$suffix SET foo=?");
    $sth->execute(array( "Test #".$row[0]." added"));
    global $id;
    $id = $dbh->lastInsertId();
    throw new TestException();
}
function test_throw_rollback($dbh) {
    global $t;
    $t->diag("test1 rollback");
}

$t->try_test("Trying transaction with commit safe exception");
try {
    $dbh->execTransaction( "test_throw_do", "test_throw_rollback" );
    $t->fail();
    $t->diag("Didn't see an exception thrown at all");
}
catch (TestException $e) {
    $t->pass();
}
catch (Exception $e) {
    $t->fail();
    $t->diag("Expected a TestException, get a ".class_name($e));
}

$sth = $dbh->prepare("SELECT * FROM pdo_test$suffix WHERE id=?");
$sth->execute(array( $id ));
$row = $sth->fetch();
$t->like( $row['foo'], "/Test #\d+ added/", "Transaction completed");





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
global $tried;
$tried = 0;
function test3_do($dbh) {
    global $t;
    $t->diag("Running transaction...");
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

$tried = 0;
// Make the retry counter fail out
function test4_do($dbh) {
    global $t;
    $t->diag("Running transaction...");
    global $tried;
    $tried ++;
    kill_process($dbh);
    ping($dbh);
}
function test4_rollback($dbh) {
    global $t;
    $t->diag("Rolling back");
}

$t->try_test("Transaction fail retry timeout");
try {
    $dbh->execTransaction( "test4_do", "test4_rollback" );
    $t->fail();
}
catch (PDOException $e) {
    $t->pass();
}
$t->is( $tried, 6, "transaction retries exhausted all retries" );


// Make the retry counter fail out
function test5_do($dbh) {
    throw new Exception("BOOM");
}
function test5_rollback($dbh) {
    global $t;
    $t->diag("Rolling back");
}

$t->try_test("Transaction fail retry timeout");
try {
    $dbh->execTransaction( "test5_do", "test5_rollback" );
    $t->fail();
}
catch (Exception $e) {
    $t->pass();
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

 
 
function kill_process($dbh) {
    $id = get_value( $dbh->query("SELECT connection_id()") );
    $kdbh = new Test_PDO( DSN, USER, PASS );
    return $kdbh->exec( "KILL $id" );
}

function get_value($sth) {
    list($value) = @$sth->fetch(PDO::FETCH_NUM);
    return $value;
}

function ping($dbh) {
    $sth = @$dbh->query("SELECT 1");
    return get_value($sth) == 1;
}

