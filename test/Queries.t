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
$t = new mh_test(29);

define( "DSN", "mysql:dbname=test" );
define( "USER", "root" );
define( "PASS", null );

global $suffix;
$suffix = getmypid();

function trace($msg) {
    global $t;
    $t->diag($msg);
}

$dbh = new OLB_PDO( DSN, USER, PASS, array(OLB_PDO::TRACE=>'trace',PDO::ATTR_ERRMODE=>PDO::ERRMODE_SILENT) );

$sth = $dbh->query("SELECT 1 AS result");
$t->ok( $sth instanceOf OLB_PDO_STH or $sth instanceOf PDOStatement, "Query returns a statement handle" );
$sth->bindColumn( 1, $result );
$sth->fetch();
$t->is( $result, 1, "Regular object worked ok" );

$sth = $dbh->prepare("SELECT 1 AS result");
$sth->execute();
$row = $sth->fetch();
$t->is( $row["result"], 1, "Prepare->Execute work ok" );

$dbh->setAttribute( PDO::ATTR_EMULATE_PREPARES, FALSE );
$dbh->setAttribute( PDO::MYSQL_ATTR_DIRECT_QUERY, FALSE );
$t->is_false( $dbh->prepare("ABCDEF"), "Invalid prepares return false");

$t->is_false( $dbh->exec("ABCDEF"), "Invalid exec queries return false ");
$t->is_false( $dbh->query("ABCDEF"), "Invalid queries return false" );


$dbh->setAttribute( PDO::ATTR_EMULATE_PREPARES, TRUE );
$sth = $dbh->prepare("ABCDEF");
$t->is_false( $sth->execute(), "Invalid executes return false" );

$dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

$dbh->setAttribute( PDO::ATTR_EMULATE_PREPARES, FALSE );

$t->try_test("Non-emulated prepare with invalid SQL throws an exception");
try {
    $sth = $dbh->prepare("ABCDEF");
    $t->fail();
}
catch (PDOException $e) {
    $t->pass();
}


$dbh->setAttribute( PDO::ATTR_EMULATE_PREPARES, TRUE );

$t->try_test("Exec with invalid SQL throws an exception");
try {
    $dbh->exec("ABCDEF");
    $t->fail();
}
catch (PDOException $e) {
    $t->pass();
}

$t->try_test("Query with invalid SQL throws an exception");
try {
    $dbh->query("ABCDEF");
    $t->fail();
}
catch (PDOException $e) {
    $t->pass();
}

$dbh->exec("DROP TABLE IF EXISTS pdo_test$suffix");
$dbh->exec("CREATE TABLE pdo_test$suffix ( id int auto_increment primary key, foo varchar(50) not null ) ENGINE=InnoDB");

$t->is( OLB_PDO::getAvailableDrivers(), PDO::getAvailableDrivers(), "getAvailableDrivers is a pass-through");

$t->ok( $dbh->exec("DROP TABLE IF EXISTS abc123") !== FALSE, "Valid exec queries are ok" );

$sth = $dbh->prepare("SELECT ? AS result");
$sth->execute(array(2));
$row = $sth->fetch();
$t->is( $row["result"], 2, "Query with passed in bind params work" );

$sth = $dbh->prepare("SELECT :foo AS result");
$sth->bindValue( "foo", 5 );
$sth->execute();
$row = $sth->fetch();
$t->is( $row["result"], 5, "bindValue works as expected" );

$sth = $dbh->prepare("SELECT :foo AS result");
$foo = "abc";
$sth->bindParam( "foo", $foo );
$sth->execute();
$row = $sth->fetch();
$t->is( $row["result"], "abc", "readonly bindParam works as expected" );

$sth = $dbh->prepare("SELECT :foo AS r1, :foobar AS r2");
$sth->bindValue( "foo", 5 );
$sth->bindValue( "foobar", 7 );
$sth->execute();
$row = $sth->fetch();
$t->is( $row["r1"], 5, "Binding handles substrings right, test 1" );

$sth = $dbh->prepare("SELECT :foobar AS r1, :foo AS r2");
$sth->bindValue( "foo", 5 );
$sth->bindValue( "foobar", 7 );
$sth->execute();
$row = $sth->fetch();
$t->is( $row["r1"], 7, "Binding handles substrings right, test 2" );

$sth = $dbh->prepare("SELECT :foo AS r1, :foobar AS r2");
$sth->bindValue( "foobar", 7 );
$sth->bindValue( "foo", 5 );
$sth->execute();
$row = $sth->fetch();
$t->is( $row["r1"], 5, "Binding handles substrings right, test 3" );

$dbh->exec("DROP PROCEDURE IF EXISTS test_bind$suffix");
$dbh->exec("
CREATE PROCEDURE test_bind$suffix( INOUT bar INTEGER )
BEGIN
    SELECT bar * 2 INTO bar;
END");
$t->try_test("Bind param with OUT or INOUT vars is unsupported");
try {
    $sth = $dbh->prepare("CALL test_bind$suffix(?)");
    $bar = 23;
    $sth->bindParam(1, $bar, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 11 );
    $sth->execute();
    $sth->closeCursor();
    $t->fail();
}
catch (Exception $e) {
    $t->pass();
}

$dbh->exec("DROP PROCEDURE IF EXISTS test_proc$suffix");
$dbh->exec("
CREATE PROCEDURE test_proc$suffix()
BEGIN
    SELECT id,foo FROM pdo_test$suffix;
    SELECT count(*) as count FROM pdo_test$suffix;
END");
$dbh->exec("INSERT INTO pdo_test$suffix SET foo='test this'");
function test_proc($dbh) {
    global $suffix;
    $sth = $dbh->prepare("CALL test_proc$suffix()");
    $sth->execute();
    return $sth->fetch();
}
$row = test_proc($dbh);
$t->is( $row['foo'], 'test this', "Fetch from proc works" );
$row2 = test_proc($dbh);
$t->is( $row2['foo'], 'test this', "Fetch from proc -again- works");
$sth = $dbh->prepare("SELECT id,foo FROM pdo_test$suffix");
$sth->execute();
$row = $sth->fetchAll();
$t->is( $row[0]['foo'], 'test this', "Select after fetch from proc works without closeCursor" );

$sth = $dbh->prepare("CALL test_proc$suffix()");
$sth->execute();
// Fetch the first recordset
foreach ($sth as $row) {
    $t->is( $row['foo'], 'test this', "Fetched our one row" );
}
// Fetch the second recordset
foreach ($sth as $row) {
    $t->is( $row['count'], 1, "Fetched the count from the second recordset" );
}

$sth = $dbh->prepare("SHOW DATABASES");
$sth->execute();
$num = 0;
$match = array( "information_schema", "mysql", "test" );
foreach ($sth as $i=>$row) {
    if ( in_array( $row[0], $match ) ) {
        $t->diag( "Fetched: $row[0]" );
        ++ $num;
    }
}
$t->is( $num, 3, "Found all of our diagnostic databases using iterator form" );

$t->is( $dbh->quote("a'bc"), "'a\\'bc'", "We can call PDO methods that are proxied via __call" );

$sth = $dbh->prepare("SELECT 10 as result");
$sth->setFetchMode( PDO::FETCH_OBJ );
$sth->execute();
$t->is( $sth->rowCount(), 1, "We can call PDOStatement methods that are proxied via __call" );
$row = $sth->fetch();
$t->is( $row->result, 10, "setFetchMode works correctly" );
    
$dbh->prepare("SELECT 1");
$t->try_test("invalid method calls are rethrown");
try {
    $sth->abcdef();
    $t->fail();
}
catch (Exception $e) {
    $t->pass();
}
$dbh->exec("DROP TABLE IF EXISTS pdo_test$suffix");
$dbh->exec("DROP PROCEDURE IF EXISTS test_bind$suffix");
$dbh->exec("DROP PROCEDURE IF EXISTS test_proc$suffix");
