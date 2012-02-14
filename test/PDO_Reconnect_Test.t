#!/usr/bin/env php
<?
/**
 * Copyright © 2011 Online Buddies, Inc. - All Rights Reserved
 *
 * @package OLB::PDO
 * @author bturner@online-buddies.com
 */

@include dirname(__FILE__)."/../build/test.php"; // Under OLBSL
require_once "OLB/PDO.php";

$t = new mh_test(7);

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

$dbh = new Test_PDO( DSN, USER, PASS, array( OLB_PDO::TRACE => 'trace', PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ));


$t->is_true( ping($dbh), "Connected to the database" );

kill_process($dbh);

$t->is_true( ping($dbh), "Reconnected to the database ok" );

$t->diag("Error mode: ".$dbh->getAttribute(PDO::ATTR_ERRMODE));

$dbh->disconnect();

$t->is_true( ping($dbh), "Reconnected to the database ok after regular disconnect" );

kill_process($dbh);

$sth = @$dbh->prepare("SELECT 2");
@$sth->execute();
$t->is( get_value($sth), 2, "Recovered from disconnect prior to prepare" );


$sth = $dbh->prepare("SELECT 3");
kill_process($dbh);
@$sth->execute();
$t->is( get_value($sth), 3, "Recovered from disconnect prior to execute" );

// Here we muck with reflection in order to fiddle with the stored reconnect parameters, so that
// we can force that to fail.
$class = new ReflectionClass("OLB_PDO");
$prop = $class->getProperty("params");
if ( is_callable(array($prop,"setAccessible")) ) {
    $prop->setAccessible(TRUE);
    $params = $prop->getValue( $dbh );
    $params['password'] = "alskdjflkdjf";
    $prop->setValue( $dbh, $params );

    kill_process($dbh);
    $t->try_test( "Without an invalid password, we can't reconnect" );
    try {
        ping($dbh);
        $t->fail();
        $t->skip();
    }
    catch (PDOException $e) {
        $t->pass();
        $t->like( $e->getMessage(), "/Access denied for user/", "We got an error message too" );
    }
    catch (Exception $e) {
        $t->except_fail($e);
        $t->skip();
    }
}
else {
    $t->skip("Can't test failing reconnects without PHP 5.3",2);
}


function ping($dbh) {
    $sth = @$dbh->query("SELECT 1");
    return get_value($sth) == 1;
}

function kill_process($dbh) {
    $id = get_value( $dbh->query("SELECT connection_id()") );
    $kdbh = new Test_PDO( DSN, USER, PASS );
    return $kdbh->exec( "KILL $id" );
}

function get_value($sth) {
    $row = @$sth->fetch(PDO::FETCH_NUM);
    return $row[0];
}

