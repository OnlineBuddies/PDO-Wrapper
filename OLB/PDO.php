<?php
/**
 * Copyright © 2012 Online Buddies, Inc. - All Rights Reserved
 *
 * @package OLB::PDO
 * @author bturner@online-buddies.com
 */
require_once( dirname(__FILE__)."/PDO/STH.php" );
require_once( dirname(__FILE__)."/PDOCommitTransaction.php" );
require_once( dirname(__FILE__)."/PDORetryTransaction.php" );

/**
 * A wrapper for PDO that adds connection retries, singletons and safer transactions
 *
 * It extends PDO, which at first seems natural until you see that we
 * override every one of PDO's methods and actually store a plain PDO object
 * and call methods on that.  We have to do this in order to allow reconnecting which
 * involves throwing away the old PDO object and making a new one.
 * The reasons we still extend PDO at all are:
 *     It lets us inherit all of its constants.
 *     It allows our wrapper to be used anywhere something has a class
 *     constraint on PDO.
 */
class OLB_PDO extends PDO {

    /// The name of the statement handle class to use, default OLB_PDO_STH
    const STH_CLASS       = -1000;

    /// The number of times to retry a failure
    const RETRIES         = -1001;

    /// The milisecond multiple to backoff retries with
    const RETRY_BACKOFF   = -1002;

    /// The jitter factor to introduce in the backoff
    const RETRY_JITTER    = -1003;

    /// Trace calls
    const TRACE           = -1004;

    /// Retry deadlocks resulting from normal execute commands
    const RETRY_DEADLOCKS = -1005;

    /// Sleep after the nth retry (default 0)
    const RETRY_SLEEP_AFTER = -1006;

    /// Run on connect/reconnect
    const INIT_COMMAND     = -1007;

    /// Default connnection attributes:
    ///    Throw exceptions on errors and autocommit statements
    ///    Note that we can mix and match our own constant
    ///    as PDO constants
    protected function connect_attrs() {
        return array(
            self::STH_CLASS               => 'OLB_PDO_STH',
            self::RETRIES                 => 5,
            self::RETRY_BACKOFF           => 400, // ms
            self::RETRY_JITTER            => 0.50, // * RETRY_BACKOFF * rand(1.0)
            self::RETRY_SLEEP_AFTER       => 0,
            self::TRACE                   => FALSE,
            self::RETRY_DEADLOCKS         => FALSE,
            self::ATTR_ERRMODE            => self::ERRMODE_EXCEPTION,
            self::ATTR_AUTOCOMMIT         => TRUE,
            self::INIT_COMMAND            => 'SET CHARACTER SET UTF8',
            );
    }

    private $params;
    private $opts = array();
    private $attrs = array();
    private $dbh;
    private $is_singleton = FALSE;
    private $trans_depth = 0;

    /**
     * A utility method to split OLB_PDO options from PDO connection attributes.
     *
     * @param array $attrs_and_opts
     * @param array &$opts
     * @param array &$attrs
     */
    private function _load_opts(array $attrs_and_opts, array &$opts, array &$attrs) {
        foreach ($attrs_and_opts as $k=>$v) {
            if ( $k < 0 ) {
                if ( is_null($v) ) {
                    unset($opts[$k]);
                }
                else {
                    $opts[$k] = $v;
                }
            }
            else {
                if ( is_null($v) ) {
                    unset($attrs[$k]);
                }
                else {
                    $attrs[$k] = $v;
                }
            }
        }
    }

    /**
     * Creates a new PDO wrapper and connect to it.
     * Parameters are the same as PDO
     * @param string $dsn
     * @param string $username
     * @param string $password
     * @param array $attrs
     */
    public function __construct( $dsn, $username=null, $password=null, array $attrs=null ) {
        assert( 'is_string($dsn)' );
        assert( '! isset($username) or is_string($username)' );
        assert( '! isset($password) or is_string($password)' );

        $conn_attrs = array();

        // Load default connection attributes
        $this->_load_opts($this->connect_attrs(), $this->opts, $conn_attrs );

        // Override those with any passed in
        if ( isset($attrs) ) {
            $this->_load_opts($attrs, $this->opts, $conn_attrs );
        }

        assert( 'count($conn_attrs) > 0' );

        // Parameters needed by the PDO constructor
        $this->params = array(
            'dsn'       => $dsn,
            'username'  => $username,
            'password'  => $password,
            'conn_attrs'=> $conn_attrs,
            );
        $this->connect();
    }

    /// Store the instances of our singleton
    static private $instances;

    private $instance_id;

    /**
     * Fetches the singleton PDO wrapper.  The parameters are the same
     * as to PDO, and one singleton will be made for each unique set of arguments.
     * @param string $dsn
     * @param string $username (optional)
     * @param string $password (optional)
     * @param array $attrs (optional)
     * @param string $class (optional)
     * @returns OLB_PDO
     */
    public static function getInstance( $dsn, $username=null, $password=null, array $attrs=null, $class=null ) {
        if ( !isset($class) ) {
            $class = __CLASS__;
        }

        // We key our singletons by all of the connect arguments
        $params = func_get_args();
        $instance_id = hash( 'sha256', json_encode($params) );

        // If our singleton exists already, fetch that...
        if ( isset(self::$instances[$instance_id]) ) {
            $dbh = self::$instances[$instance_id];
        }
        // Otherwise make a new one
        if ( !isset($dbh) ) {
            $dbh = new $class( $dsn, $username, $password, $attrs );
            self::$instances[$instance_id] = $dbh;
            $dbh->is_singleton = TRUE;
            $dbh->instance_id = $instance_id;
        }

        return $dbh;
    }

    /**
     * Removes a singleton from the cache and unflags it.  We maintain the
     * instance id so that we can reinstate the singleton later easily.
     *
     * This allows you to use operations that are not usually allowed for
     * singletons.
     */
    protected function clearSingleton() {
        if ( !isset($this->instance_id) ) {
            throw new PDOException("Can't clear singleton bit on a non-singleton database handle");
        }
        $this->is_singleton = FALSE;
        unset(self::$instances[$this->instance_id]);
    }

    /**
     * Adds a previously removed singleton back into the singleton cache.
     * This undoes what clearSingleton does.  If you try to restore a
     * database handle but discover that in the mean time another copy with
     * the same instance_id was created then a warning is logged and we let
     * this database handle fall out of scope.
     */
    protected function makeSingleton() {
        if ( !isset($this->instance_id) ) {
            throw new PDOException("Can't set singleton bit on a non-singleton database handle");
        }
        if ( isset(self::$instances[$this->instance_id]) ) {
            $this->logWarning( "Failed to set database handle, ".$this->params['dsn'].", as singleton as another one was already created" );
        }
        else {
            $this->is_singleton = TRUE;
            self::$instances[$this->instance_id] = $this;
        }
    }

    private $connects = 0;

    /**
     * Sleep preceding a retry.
     *
     * @param $tries Number of tries so far
     */
    public function retrySleep($tries) {
        if ( $tries > $this->getAttribute( self::RETRY_SLEEP_AFTER ) ) {
            $backoff = $this->getAttribute( self::RETRY_BACKOFF );
            $jitter = $this->getAttribute( self::RETRY_JITTER );
            $base_delay = $tries * $backoff;
            $random_jitter = mt_rand(0,$backoff*$jitter);
            usleep( ($base_delay + $random_jitter) * 1000 ); // msec to μsec
        }
    }

    /**
     * (Re)connects to the PDO database handle we wrap.
     *
     * @param Exception $error (optional)
     */
    public function connect(Exception $error=null) {
        // First, disconnect from the database
        $this->disconnect();

        $maxRetries = $this->getAttribute( self::RETRIES );

        $this->connects ++;

        for ( $tries=0; $tries < $maxRetries; ++$tries ) {

            // Log and sleep on second and later attempts
            if ( $tries and isset($error) ) {
                $this->logRetry( $this->connects, $tries, $error->getMessage() );
            }
            if ( $tries ) {
                $this->retrySleep($tries);
            }

            // Try to create a new database handle and set any non-connection attributes
            try {
                $this->dbh = new PDO(
                    $this->params['dsn'],
                    $this->params['username'],
                    $this->params['password'],
                    $this->params['conn_attrs'] );

                foreach ($this->attrs as $k=>$v) {
                    $this->dbh->setAttribute( $k, $v );
                }
                if ( @ $initCmd = $this->opts[self::INIT_COMMAND] ) {
                    foreach ((array)$initCmd as $sql) {
                        $this->dbh->exec($sql);
                    }
                }
                return;
            }
            catch (PDOException $e) {
                $error = $e;
            }
        }
        // If we got this far that means we ran out of retry attempts so we
        // throw an error
        if ( is_object($error) ) {
            throw $error;
        }
        else {
            throw new PDOException("Error while connecting to database it looks like RETRIES ($maxRetries) may not have been set.");
        }
    }

    /**
     * Disconnect-- the only way to do this in PHP is to unset the DBH object.
     * Normally this is only called by connect()
     */
    public function disconnect() {
        unset($this->dbh);
        $this->trans_depth = 0;
    }

    /**
     * Constructs a new statement handle wrapper
     * @param string $sql
     * @param array $opts driver options
     * @returns OLB_PDO_STH
     */
    public function prepare($sql, $opts=array()) {
        assert('is_string($sql)');
        assert('strlen($sql) > 0');
        if ( !isset($this->dbh) ) { $this->connect(); } // Reconnect if we were explicitly disconnected

        $class = $this->getAttribute(self::STH_CLASS);
        assert('isset($class)');
        assert('is_subclass_of($class,"OLB_PDO_STH") or $class=="OLB_PDO_STH"');

        /// @todo For 5.3: Change to $class::newFromPrepare($this,$sql,$opts);
        return call_user_func( array($class,"newFromPrepare"), $this, $sql, $opts, $class );
    }

    /**
     * Constructs a new statement handle wrapper using query, which immediately
     * prepares and executes the sql.  The returned statement handle is ready to
     * be fetched against.
     * @param string $statement
     * @param int $fetchMode_sem If this is set then it and any further
     * arguments are (conceptually) passed to setFetchMode.
     * @returns OLB_PDO_STH
     */
    public function query( $statement, $fetchMode_sem=null ) {
        assert('is_string($statement)');
        assert('strlen($statement) > 0');
        if ( !isset($this->dbh) ) { $this->connect(); } // Reconnect if we were explicitly disconnected

        $this->traceTimerStart();

        $class = $this->getAttribute(self::STH_CLASS);

        $args = func_get_args();

        // Fetchmode is a flag followed by a variable number of additional parameters
        if ( isset($fetchMode_sem) ) {
            $fetchMode = array_slice( $args, 0);
        }
        else {
            $fetchMode = null;
        }

        /// @todo For 5.3: Change to $class::newFromQuery($this,$statement,$fetchMode);
        $result = call_user_func( array($class,"newFromQuery"), $this, $statement, $fetchMode, $class );
        $this->traceCall("query",$args);
        return $result;
    }

    /**
     * @param Exception $e
     * @returns bool True if the exception represents a deadlock
     */
    public function _is_deadlock(Exception $e) {
        $msg = $e->getMessage();
        if ( $e instanceOf PDOException AND strpos( $msg, " 1213 " ) !== FALSE ) {
            return TRUE;
        }
        else {
            return FALSE;
        }
    }

    /**
     * @param Exception $e
     * @returns Boolean True if the database error in $e is retryable
     */
    public function _retryable(Exception $e) {
        $msg = $e->getMessage();
        if ( strpos( $msg, " 2006 " ) !== FALSE or # MySQL server has gone away
             strpos( $msg, " 2013 " ) !== FALSE or # MySQL server has gone away
             strpos( $msg, " 1053 " ) !== FALSE or # Server shutdown
             strpos( $msg, " 1317 " ) !== FALSE ) { # Query execution was interrupted
            return TRUE;
        }
        else {
            return FALSE;
        }
    }

    /**
     * Throw an exception if a query fails with a non-retryable error.  We wrap this so
     * that our subclasses can optionally add more logging. (Times we wish we had AOP support.)
     * @param PDOException $e The exception object itself
     * @param string $query Information about the query (eg, sql, bind params, etc)
     */
    public function queryException(PDOException $e, $query=null ) {
        throw $e;
    }

    /**
     * Execute a single SQL statement and return success or failure
     * @param string $statement
     * @returns int
     */
    public function exec($statement) {
        assert('is_string($statement)');
        assert('strlen($statement) > 0');
        if ( !isset($this->dbh) ) { $this->connect(); } // Reconnect if we were explicitly disconnected

        $this->traceTimerStart();

        $args = func_get_args();

        try {
            $result = $this->dbh->exec($statement);
            $this->traceCall("exec",$args);
            return $result;
        }
        catch (PDOException $e) {
            // If we got a MySQL has gone away error ...
            if ( $this->_retryable($e) ) {

                // If we were in a transaction, explicitly disconnect so that further activies
                // will trigger a reconnect and throw an exception.
                if ( $this->inTransaction() ) {
                    $this->disconnect();
                    $this->queryException($e);
                }
                // Otherwise reconnect
                else {
                    $this->connect($e);
                    $result = $this->dbh->exec($statement);
                    $this->traceCall("exec",$args);
                    return $result;
                }
            }
            else {
                $this->queryException($e);
            }
        }
    }

    /**
     * This is used by the statement handle wrapper to construct the actual
     * PDO statement handle.
     * @param string $sql
     * @param array $opts
     * @returns PDOStatement
     */
    public function _pdo_prepare($sql, array $opts=array()) {
        assert('is_string($sql)');
        assert('strlen($sql) > 0');
        if ( !isset($this->dbh) ) { $this->connect(); } // Reconnect if we were explicitly disconnected
        return $this->dbh->prepare( $sql, $opts );
    }

    /**
     * This is used by the statement handle wrapper to construct
     * a PDO statement handle via the query method.
     * @param string $sql
     * @param int $fetchMode If set then it and any additional arguments are
     * passed to query.
     * @returns PDOStatement
     */
    public function _pdo_query($sql,$fetchMode=null) {
        assert( 'is_string($sql)');
        assert( 'strlen($sql)>0' );
        if ( !isset($this->dbh) ) { $this->connect(); } // Reconnect if we were explicitly disconnected

        // $fetchMode is a collection of a variable number of arguments so this is
        // unfortunately the only way to pass them on to query, without having something mind blowingly ugly
        // like: switch (count($fetchMode)) { case 1: ...; case 2: ...; case 3: ...; }
        if ( isset($fetchMode) ) {
            assert( 'is_array($fetchMode)' );
            $args = array($sql) + $fetchMode;
            return call_user_func_array( array($this->dbh,"query"), $args );
        }
        else {
            return $this->dbh->query($sql);
        }
    }

    /**
     * Start a database transaction and execute $do.  If $do throws an
     * exception then $rollback will be executed.  If the exception thrown
     * is a deadlock then everything will be retried up to $maxRetries
     * times.  Once $do succeeds then $dbh->commit is called.  Otherwise,
     * the final exception is rethrown.
     *
     * @param Callable $do
     * @param Callable $rollback (optional)
     * @param integer $maxRetries
     * @returns The return value of the $do call
     */
    public function execTransaction( $do, $rollback=null, $maxRetries=null ) {
        assert( 'is_callable($do)');
        assert('!isset($rollback) or is_callable($rollback)');
        if ( ! isset($maxRetries ) ) {
            $maxRetries = $this->getAttribute( self::RETRIES );
        }
        assert( 'is_int($maxRetries)');
        assert( '$maxRetries > 0');
        assert( '$maxRetries < 100');

        $traceArgs = array( "...", isset($rollback) ? "..." : null, $maxRetries );

        $single = $this->is_singleton;
        if ( $single ) {
            $this->clearSingleton();
            array_unshift( $traceArgs, ":single" );
        }

        $return = null;
        $tries = 0;
        while ( 1 ) {
            $tries ++;
            try {
                $this->beginTransaction();
                $return = call_user_func( $do, $this );
                $this->commit();
                break;
            }
            catch (Exception $e) {
                if ( $e instanceOf PDOException or $e instanceOf OLB_PDORetryTransaction ) {
                    try {
                        $this->rollBack();
                    } catch (Exception $re) {}

                    if (isset($rollback)) {
                        call_user_func( $rollback, $this, $e, $tries, $maxRetries );
                    }
                    // Out of retries, rethrow the exception
                    if ( $tries > $maxRetries ) {
                        if ( $single ) { $this->makeSingleton(); }
                        array_unshift( $traceArgs, "x$tries" );
                        $this->traceCall( "execTransaction", $traceArgs, $return, false );
                        throw $e;
                    }
                    // If this is the usual kind of retryable exception, reconnect and retry
                    else if ( $this->_retryable($e) ) {
                        $this->connect($e);
                        $this->retrySleep($tries);
                    }
                    // If this is NOT a deadlock then we treat this like a generic exception
                    else if ( ! $this->_is_deadlock($e) and ! $e instanceOf OLB_PDORetryTransaction ) {
                        $this->do_after_last_rollback( $e, $tries, $single, $traceArgs );
                    }
                    // Otherwise it was a deadlock, sleep and retry
                    else {
                        $this->retrySleep($tries);
                    }
                }
                else if ( $e instanceOf OLB_PDOCommitTransaction ) {
                    $this->commit();
                    if ( $single ) { $this->makeSingleton(); }
                    array_unshift( $traceArgs, "x$tries", ":commitException=".get_class($e).": ".$e->getMessage() );
                    $this->traceCall( "execTransaction", $traceArgs, $return, false );
                    throw $e;
                }
                else {
                    try {
                        $this->rollBack();
                    } catch (Exception $re) {
                        $this->disconnect();
                    }

                    if (isset($rollback)) {
                        call_user_func( $rollback, $this, $e, $tries, $maxRetries );
                    }
                    $this->do_after_last_rollback( $e, $tries, $single, $traceArgs );
                }
            }
        }
        if ( $single ) { $this->makeSingleton(); }
        array_unshift( $traceArgs, "x$tries" );
        $this->traceCall( "execTransaction", $traceArgs, $return, false );
        return $return;
    }

    protected function do_after_last_rollback( $e, $tries, $single, $traceArgs ) {
        if ( $single ) { $this->makeSingleton(); }
        array_unshift( $traceArgs, "x$tries", ":rollbackException=".get_class($e).": ".$e->getMessage() );
        $this->traceCall( "execTransaction", $traceArgs, null, false );
        throw $e;
    }

    /**
     * @returns bool
     */
    public function beginTransaction() {
        if ( !isset($this->dbh) ) { $this->connect(); } // Reconnect if we were explicitly disconnected

        // We don't allow transactions on singletons, as it could produce weird hard to track down bugs.
        if ( $this->is_singleton ) {
            throw new PDOException("Can't begin a transaction on a singleton database handle");
        }

        try {
            if ( $this->trans_depth ) {
                $result = $this->exec("SAVEPOINT nested{$this->trans_depth}");
                $result = ($result !== FALSE);
            }
            else {
                $result = $this->dbh->beginTransaction();
            }
        }
        catch (PDOException $e) {
            if ( $this->_retryable($e) ) {
                $this->connect($e);
                $result = $this->dbh->beginTransaction();
            }
            else {
                $this->queryException($e);
            }
        }

        if ( $result ) {
            $this->trans_depth ++;
            $this->traceCall("beginTransaction",array(),$this->trans_depth);
            return $this->trans_depth;
        }
        else {
            $this->traceCall("beginTransaction",array(),FALSE);
            return FALSE;
        }
    }

    /**
     * @returns bool
     */
    public function commit() {
        if ( !isset($this->dbh) ) {
            throw new PDOException("Can't commit on a disconnected database handle.");
        }

        $this->traceCall("commit({$this->trans_depth})");
        if ( -- $this->trans_depth ) {
            return $this->exec("RELEASE nested{$this->trans_depth}");
        }
        else {
            return $this->dbh->commit();
        }
    }

    /**
     * @returns bool
     */
    public function rollBack() {
        if ( !isset($this->dbh) ) {
            throw new PDOException("Can't rollback on a disconnected database handle.");
        }

        $this->traceCall("rollBack({$this->trans_depth})");
        if ( -- $this->trans_depth ) {
            return $this->exec("ROLLBACK TO nested{$this->trans_depth}");
        }
        else {
            return $this->dbh->rollBack();
        }
    }

    /**
     * Does not call the actual inTransaction method as it's documented to only work
     * with PostgreSQL.  Instead, it tracks the beginTransaction, commit and rollBack
     * methods.
     * @returns bool
     */
    public function inTransaction() {
        return $this->trans_depth;
    }

    /**
     * Change a connection attribute at run time.  This is not allowed on singletons.
     * @param int $attribute
     * @param mixed $value
     * @returns bool
     */
    public function setAttribute($attribute,$value) {
        assert('is_int($attribute)');
        if ( $this->is_singleton ) {
            throw new PDOException( "Can't set an attribute on a singleton" );
        }

        // Our attribute constants are all negative, theirs are all positive
        if ( $attribute < 0 ) {
            $this->opts[$attribute] = $value;
        }
        else {
            if ( !isset($this->dbh) ) { $this->connect(); } // Reconnect if we were explicitly disconnected
            $this->attrs[$attribute] = $value;
            return $this->dbh->setAttribute($attribute,$value);
        }
    }

    /**
     * @param int $attribute
     * @returns mixed
     */
    public function getAttribute($attribute) {
        assert('is_int($attribute)');
        // Our attribute constants are all negative, theirs are all positive
        if ( $attribute < 0 ) {
            if ( isset($this->opts[$attribute]) ) {
                return $this->opts[$attribute];
            }
            else {
                return null;
            }
        }
        else {
            if ( !isset($this->dbh) ) { $this->connect(); } // Reconnect if we were explicitly disconnected
            return $this->dbh->getAttribute($attribute);
        }
    }

    /**
     * @param string $string
     * @param int $parameter_type (default: PDO::PARAM_STR)
     * @returns string
     */
    public function quote( $string, $parameter_type = self::PARAM_STR ) {
        return $this->dbh->quote( $string, $parameter_type );
    }

    /**
     * @returns mixed
     */
    public function errorCode() {
        return $this->dbh->errorCode();
    }

    /**
     * @returns array
     */
    public function errorInfo() {
        return $this->dbh->errorInfo();
    }

    /**
     * @param string $name (default: NULL)
     * @returns string
     */
    public function lastInsertId($name=null) {
        return $this->dbh->lastInsertId();
    }

    /**
     * Log a reconnection attempt.
     *
     * @param integer $connects
     * @param integer $retries
     * @param string $str
     */
    public function logRetry( $connects, $retries, $str ) {
        assert('is_int($connects)');
        assert('is_int($retries)');
        assert('is_string($str)');
        $this->logWarning("MySQL connection #".$connects.", retry #".$retries.": $str");
    }

    /**
     * Log a warning
     *
     * @param string $msg
     */
    public function logWarning( $msg ) {
        assert('is_string($msg)');
        error_log( $msg );
    }

    private $startTime = 0;

    /**
     * Log database calls if tracing is enabled
     * @param string $func Name of the function being called
     * @param array $args Arguments to the function being called
     * @param string $return Return value of the function being called
     */
    public function traceCall( $func, array $args = array(), $return = NULL, $useTimer = true ) {
        assert('is_string($func)');
        if ( $this->canTrace() ) {
            $out = $func . "(";
            $out .= implode(", ", array_map( "json_encode", $args ) );
            $out .= ")";
            if ( isset( $return ) ) {
                $out .= " = $return";
            }
            if ( $useTimer and $this->startTime ) {
                $out .= " in ".floor( (microtime(TRUE)-$this->startTime) * 1000 ) . " ms";
                $this->startTime = 0;
            }
            $this->logTrace( $out );
        }
    }

    /**
     * Start a timer, the next traceCall will report the amount of time
     * passed since it was started.
     */
    public function traceTimerStart() {
        if ( $this->canTrace() ) {
            $this->startTime = microtime(TRUE);
        }
    }

    /**
     * Actualy prints log line from a trace
     * @param string $str The line to print
     */
    public function logTrace( $str ) {
        assert('is_string($str)');
        if ( is_callable($this->opts[self::TRACE]) ) {
            call_user_func( $this->opts[self::TRACE], $str );
        }
        else {
            $this->logWarning("TRACE: $str");
        }
    }

    /**
     * Returns true if tracing is enabled
     * @returns bool
     */
    public function canTrace() {
        return isset($this->opts[self::TRACE]) and $this->opts[self::TRACE];
    }

}
