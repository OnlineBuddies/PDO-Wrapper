#!/usr/bin/env php
<?
/**
 * Copyright © 2011 Online Buddies, Inc. - All Rights Reserved
 *
 * @package OLB::PDO
 * @author bturner@online-buddies.com
 */

include dirname(__FILE__)."/../build/test.php";
require_once "OLB/PDO.php";

global $t;
$t = new mh_test(34);

define( "DSN", "mysql:dbname=test" );
define( "USER", "root" );
define( "PASS", null );

function trace($msg) {
    global $t;
    $t->diag($msg);
}

// Separate functions here to get separate scopes for our variables
fresh();
function fresh() {
    global $t;
    $dbh = new OLB_PDO( DSN, USER, PASS, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT, 
        OLB_PDO::TRACE    => 'trace' ));

    $sth = $dbh->query("SELECT 1 AS result");
    $t->ok( $sth instanceOf OLB_PDO_STH or $sth instanceOf PDOStatement, "Query returns a statement handle" );
    $sth->bindColumn( 1, $result );
    $sth->fetch();
    $t->is( $result, 1, "Regular object worked ok" );
    
    $sth = $dbh->prepare("SELECT 1 AS result");
    $sth->execute();
    $row = $sth->fetch();
    $t->is( $row["result"], 1, "Prepare->Execute work ok" );

    $t->is_false( $dbh->exec("ABCDEF"), "Invalid exec queries return false ");
    $t->is_false( $dbh->query("ABCDEF"), "Invalid queries return false" );
    $sth = $dbh->prepare("ABCDEF");
    $t->is_false( $sth->execute(), "Invalid executes return false" );

    $dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

    $dbh->exec("DROP TABLE IF EXISTS pdo_test");
    $dbh->exec("CREATE TABLE pdo_test ( id int auto_increment primary key, foo varchar(50) not null ) ENGINE=InnoDB");

    $dbh->beginTransaction();

    $dbh->exec("INSERT INTO pdo_test SET foo='test this'");

    $sth = $dbh->query("SELECT COUNT(*) as count FROM pdo_test", PDO::FETCH_OBJ );
    $obj = $sth->fetch();
    $t->is( $obj->count, 1, "Transactions: Insert added a row" );

    $dbh->rollBack();

    $sth = $dbh->query("SELECT COUNT(*) as count FROM pdo_test", PDO::FETCH_OBJ );
    $obj = $sth->fetch();
    $t->is( $obj->count, 0, "Transactions: Rollback removed the row" );

    $dbh->beginTransaction();

    $dbh->exec("INSERT INTO pdo_test SET foo='out'");

    $sth = $dbh->query("SELECT COUNT(*) as count FROM pdo_test", PDO::FETCH_OBJ );
    $obj = $sth->fetch();
    $t->is( $obj->count, 1, "Transactions: Insert added another row" );

    $dbh->commit();

    $sth = $dbh->query("SELECT COUNT(*) as count FROM pdo_test", PDO::FETCH_OBJ );
    $obj = $sth->fetch();
    $t->is( $obj->count, 1, "Transactions: And its still there after our commit" );
    
}

singleton();
function singleton() {
    global $t;

    $dbh = OLB_PDO::getInstance(DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ));

    $sth = $dbh->query("SELECT 1 AS result");
    $row = $sth->fetch();
    $sth->closeCursor();
    $t->is( $row["result"], 1, "Singleton object worked ok" );

//--- Call out to singleton2 here
    singleton2($dbh);

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

    $t->is( $dbh->getAttribute(PDO::ATTR_AUTOCOMMIT), TRUE, "getAttribute returns ATTR_AUTOCOMMIT ok");

#    $t->is( $dbh->getAttribute(OLB_PDO::STH_CLASS), "OLB_PDO_STH", "getAttribute returns STH_CLASS ok");

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
    $sth->bindValue( "foo", $foo );
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
    
    $dbh->exec("DROP PROCEDURE IF EXISTS test_bind");
    $dbh->exec("
CREATE PROCEDURE test_bind( INOUT bar INTEGER )
BEGIN
    SELECT bar * 2 INTO bar;
END");
    $t->try_test("Bind param with OUT or INOUT vars is unsupported");
    try {
        $sth = $dbh->prepare("CALL test_bind(?)");
        $bar = 23;
        $sth->bindParam(1, $bar, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 11 );
        $sth->execute();
        $sth->closeCursor();
        $t->fail();
    }
    catch (Exception $e) {
        $t->pass();
    }
    
    $dbh->exec("DROP PROCEDURE IF EXISTS test_proc");
    $dbh->exec("
CREATE PROCEDURE test_proc()
BEGIN
    SELECT id,foo FROM pdo_test;
    SELECT count(*) FROM pdo_test;
END");
    $dbh->exec("INSERT INTO pdo_test SET foo='test this'");
    function test_proc($dbh) {
        $sth = $dbh->prepare("CALL test_proc()");
        $sth->execute();
        return $sth->fetch();
    }
    $row = test_proc($dbh);
    $t->is( $row['foo'], 'out', "Fetch from proc works" );
    $row2 = test_proc($dbh);
    $t->is( $row2['foo'], 'out', "Fetch from proc -again- works");
    $sth = $dbh->prepare("SELECT id,foo FROM pdo_test");
    $sth->execute();
    $row = $sth->fetchAll();
    $t->is( $row[0]['foo'], 'out', "Select after fetch from proc works without closeCursor" );
    $dbh->exec("DROP PROCEDURE IF EXISTS test_proc");
    
    $sth = $dbh->prepare("SHOW DATABASES");
    $sth->execute();
    $num = 0;
    $match = array( "information_schema", "mysql", "test" );
    foreach ($sth as $row) {
        if ( in_array( $row[0], $match ) ) {
            $t->diag( "Fetched: $row[0]" );
            ++ $num;
        }
    }
    $t->is( $num, 3, "Found all of our diagnostic databases" );
    
    $t->is( $dbh->quote("a'bc"), "'a\\'bc'", "We can call PDO methods that are proxied via __call" );
    
    $sth = $dbh->prepare("SELECT 10 as result");
    $sth->setFetchMode( PDO::FETCH_OBJ );
    $sth->execute();
    $t->is( $sth->rowCount(), 1, "We can call PDOStatement methods that are proxied via __call" );
    $row = $sth->fetch();
    $t->is( $row->result, 10, "setFetchMode works correctly" );
    

}


function singleton2($sdbh) {
    global $t;
    $sdbh2 = OLB_PDO::getInstance(DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ));

    $t->is_true( $sdbh === $sdbh2, "Both calls to getInstance returned the same object" );

    $sdbh2 = OLB_PDO::getInstance(DSN . ";", USER, PASS, array( OLB_PDO::TRACE => 'trace' ));

    $t->is_true( $sdbh !== $sdbh2, "A different DSN results in a different singleton" );
    $sdbh2->disconnect();

    $t->try_test("commit on a disconnected handle throws an exception");
    try {
        $sdbh2->commit();
        $t->fail();
    }
    catch (PDOException $e) {
        $t->pass();
    }
    $t->try_test("rollBack on a disconnected handle throws an exception");
    try {
        $sdbh2->rollBack();
        $t->fail();
    }
    catch (PDOException $e) {
        $t->pass();
    }


}

cleanup();
function cleanup() {
    $dbh = OLB_PDO::getInstance(DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace' ));
    $dbh->exec("DROP TABLE IF EXISTS pdo_test");
    $dbh->exec("DROP PROCEDURE IF EXISTS test_bind");
    $dbh->exec("DROP PROCEDURE IF EXISTS test_proc");
}