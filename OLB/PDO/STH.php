<?php
/**
 * Copyright © 2011 Online Buddies, Inc. - All Rights Reserved
 *
 * @package OLB
 * @author bturner@online-buddies.com
 */
/**
 * OLB PDO statement handle wrapper
 */
class OLB_PDO_STH implements Iterator {

    private $dbh;
    private $sql;
    private $sth;
    private $bindValues = array();
    private $bindParams = array();
    private $bindColumns = array();
    private $attrs = array();
    private $fetchMode;
    
    /**
     * These should be created by calling prepare on an OLB_PDO object.
     *
     * @param OLB_PDO $dbh
     * @param string $sql
     * @param array $attrs
     */
    private function __construct( OLB_PDO $dbh, $sql, array $attrs=array() ) {
        $this->dbh = $dbh;
        $this->sql = $sql;
        $this->attrs = $attrs;
    }
    
    /**
     * Create a new statement handle using PDO's prepare method
     *
     * @param OLB_PDO $dbh
     * @param string $sql
     * @param array $attrs
     * @param $class The class name of the object to create-- this would be
     * our classname, except that this is necessary to allow you to
     * construct an inherited PDO_STH class.
     * @returns OLB_PDO_STH | FALSE
     */
    public static function newFromPrepare( OLB_PDO $dbh, $sql, array $attrs=array(), $class ) {
        $obj = new $class( $dbh, $sql, $attrs );
        if ( $obj->prepare() ) {
            return $obj;
        }
        else {
            return FALSE;
        }
    }
    
    /**
     * Create a new statement handle using PDO's query method
     *
     * @param OLB_PDO $dbh
     * @param string $sql
     * @param array $fetchMode
     * @param $class The class name of the object to create-- this would be
     * our classname, except that this is necessary to allow you to
     * construct an inherited PDO_STH class.
     * @returns OLB_PDO_STH | FALSE
     */
    public static function newFromQuery( OLB_PDO $dbh, $sql, $fetchMode, $class) {
        $obj = new $class( $dbh, $sql );
        if ( $obj->query($fetchMode) ) {
            return $obj;
        }
        else {
            return FALSE;
        }
    }
    
    /**
     * Magic to wrap all other methods.
     *
     * @param string $name
     * @param array $args
     */ 
    public function __call($name,array $args) {
        try {
            // We use is_callable instead of method_exists as it coexists with __call better.
            if ( is_callable(array($this->sth,$name)) ) {
                return call_user_func_array(array($this->sth,$name), $args);
            }
            else {
                throw new Exception( "Class '".get_class($this)."' does not have a method '$name'" );
            }
        }
        catch (Exception $e) {
            if ( isset($this->sth) ) {
                $this->sth->closeCursor();
            }
            throw $e;
        }
    }

    private $rowSets = 0;
    private $row;
    private $rowNum = -1;

    /// Used for Iterator support
    public function rewind() {
        if ( $this->rowSets ) {
            $this->nextRowset();
            $this->rowNum = -1;
        }
        $this->rowSets++;
        $this->next();
        return;
    }
    /// Used for Iterator support
    public function current() {
        return $this->row;
    }
    /// Used for Iterator support
    public function key() {
        return $this->rowNum;
    }
    /// Used for Iterator support
    public function next() {
        $this->row = $this->sth->fetch();
        $this->rowNum++;
    }
    /// Used for Iterator support
    public function valid() {
        return $this->row !== FALSE;
    }
    
    
    /**
     * @param $mode
     * @param mixed $p1
     * @param mixed $p2
     * @param mixed $p3
     * @returns bool
     */
    public function setFetchMode($mode,$p1=null,$p2=null,$p3=null) {
        $this->fetchMode = func_get_args();
        return call_user_func_array(array($this->sth,'setFetchMode'), $this->fetchMode);
    }
    
    /**
     * @param mixed $parameter
     * @param mixed $value
     * @param int $data_type
     * @returns bool
     */
    public function bindValue($parameter, $value, $data_type=PDO::PARAM_STR) {
        $args = func_get_args();
        // Indent to group with the paired prepare
        $this->dbh->traceCall("        bindValue", $args);
        $this->bindValues[$parameter] = $args;
        return call_user_func_array(array($this->sth,'bindValue'), $this->bindValues[$parameter]);
    }
    
    /**
     * @param mixed $parameter
     * @param mixed &$variable
     * @param int $data_type
     * @param int $length
     * @param mixed $driver_options
     * @returns bool
     */
    public function bindParam($parameter, &$variable, $data_type=PDO::PARAM_STR, $length=null, $driver_options=null ) {
        $params = func_get_args();
        // Indent to group with the paired prepare
        $this->dbh->traceCall("        bindParam", $params);
        $params[1] =& $variable;
        $this->bindParams[$parameter] =& $params;
        if ( $data_type & PDO::PARAM_INPUT_OUTPUT ) {
            $this->dbh->queryException(new PDOException("We do not currently support INOUT or OUT variables on stored procedures"));
        }
        return call_user_func_array(array($this->sth,'bindParam'), $params );
    }

    /**
     * @param mixed $column
     * @param mixed &$param
     * @param int $type
     * @param int $maxLen
     * @param mixed $driverdata
     * @returns bool
     */
    public function bindColumn($column, &$param, $type=null, $maxLen=null, $driverdata=null) {
        $params = func_get_args();
        // Indent to group with the paired prepare
        $this->dbh->traceCall("        bindColumn", $params);

        // The second parameter into our list by reference
        $params[1] =& $param;
        
        // And store the whole bundle by reference
        $this->bindColumns[$column] =& $params;
        
        return call_user_func_array(array($this->sth,'bindColumn'), $params );
        
    }

    /**
     * @param int $fetchMode
     * @returns bool
     */
    protected function query($fetchMode) {
        unset($this->sth);

        try {
            $this->sth = $this->dbh->_pdo_query($this->sql,$fetchMode);
        }
        catch (PDOException $e) {
            // If we got a MySQL has gone away error ...
            if ( $this->dbh->_retryable($e) ) {

                // If we were in a transaction, explicitly disconnect so that further activies
                // will trigger a reconnect and throw an exception.
                if ( $this->dbh->inTransaction() ) {
                    $this->dbh->disconnect();
                    $this->dbh->queryException($e,$this->dump());
                }
                // Otherwise reconnect
                else {
                    $this->dbh->connect($e);
                    $this->sth = $this->dbh->_pdo_query($this->sql,$fetchMode);
                }
            }
            else {
                $this->dbh->queryException($e,$this->dump());
            }
        }
        return $this->sth !== FALSE;
    }
    
    /**
     * Regenerate the underlying statement handle object and ensures that
     * attributes, fetch mode and bind values are restored.
     * This would normally be used after a reconnect.
     */
    protected function prepare() {
        unset($this->sth);

        $this->dbh->traceCall("prepare",array($this->sql));
        
        try {
            $this->sth = $this->dbh->_pdo_prepare($this->sql, $this->attrs );
        }
        catch (PDOException $e) {
            // If we got a MySQL has gone away error ...
            if ( $this->dbh->_retryable($e) ) {

                // If we were in a transaction, explicitly disconnect so that further activies
                // will trigger a reconnect and throw an exception.
                if ( $this->dbh->inTransaction() ) {
                    $this->dbh->disconnect();
                    $this->dbh->queryException($e,$this->dump());
                }
                // Otherwise reconnect
                else {
                    $this->dbh->connect($e);
                    $this->sth = $this->dbh->_pdo_prepare($this->sql, $this->attrs );
                }
            }
            else {
                $this->dbh->queryException($e,$this->dump());
            }
        }
        
        // If we got out of there without an STH then we throw an exception.
        // Generally this will only happen if exceptions are disabled.
        if ( $this->sth === FALSE ) {
            return FALSE;
        }
        
        // If all went well, we reapply all of our settings.
        if ( isset($this->fetchMode) ) {
            call_user_func_array(array($this,'setFetchMode'), $this->fetchMode);
        }
        foreach ($this->bindValues as $k => $v) {
            call_user_func_array(array($this,'bindValue'), $v);
        }
        foreach ($this->bindParams as $k => &$v) {
            call_user_func_array(array($this,'bindParam'), $v);
        }
        foreach ($this->bindColumns as $k => &$v) {
            call_user_func_array(array($this,'bindColumn'), $v);
        }
        foreach ($this->attrs as $k=>$v) {
            $this->setAttribute( $k, $v );
        }
        return TRUE;
    }
    
    /**
     * Return everything what we know about this query, for debugging.
     * @param array $bind (optional) If bind is passed in, it is used
     * logged as additional bind params passed in at execute time.
     * @returns string
     */
    protected function dump($bind = null) {
        $out = "DB QUERY - SQL: " . $this->sql . "\n";
        if ( isset($bind) ) {
            $out .= "DB QUERY - BOUND: ". implode(", ", array_map( "json_encode", $bind ) ) . "\n";
        }
        foreach ($this->bindValues as $k => $v) {
            $out .= "DB QUERY - bindValue" . "(";
            $out .= implode(", ", array_map( "json_encode", $v ) );
            $out .= ")\n";
        }
        foreach ($this->bindParams as $k => &$v) {
            $out .= "DB QUERY - bindParam" . "(";
            $out .= implode(", ", array_map( "json_encode", $v ) );
            $out .= ")\n";
        }
        foreach ($this->bindColumns as $k => &$v) {
            $out .= "DB QUERY - bindColumn" . "(";
            $out .= implode(", ", array_map( "json_encode", $v ) );
            $out .= ")\n";
        }
        foreach ($this->attrs as $k=>$v) {
            $out .= "DB QUERY - ATTRIBUTE: $k = $v\n";
        }
        if ( isset($this->fetchMode) ) {
            $out .= "DB QUERY - FETCHMODE: ";
            $out .= implode(", ", array_map( "json_encode", $this->fetchMode ) );
        }
        return $out;
    }

    /**
     * This wraps PDO's execute method in code that will retry the MySQL has
     * gone away error.
     * @param array $bind (optional)
     * @param array $attrs (optional) You can set the RETRY_DEADLOCKS and RETRIES options
     * on a per query basis by passing them in here.
     * @returns bool
     * @throws PDOException
     */
    public function execute($bind = null, $attrs = null) {


        if ( ! isset($attrs) ) {
            $attrs = array();
        }
        $retry_deadlocks = isset($attrs[ OLB_PDO::RETRY_DEADLOCKS ]) ? $attrs[ OLB_PDO::RETRY_DEADLOCKS ] : $this->dbh->getAttribute( OLB_PDO::RETRY_DEADLOCKS );
        $total_tries = isset($attrs[ OLB_PDO::RETRIES ]) ? $attrs[ OLB_PDO::RETRIES ] : $this->dbh->getAttribute( OLB_PDO::RETRIES );

        // Clear our tracking variables for iterator mode
        $this->rowSets = 0;
        unset($this->row);
        $this->rowNum = -1;

        $args = func_get_args();


        for ( $tries = 1 ; $tries <= $total_tries ; ++$tries ) {
            $this->dbh->traceTimerStart();

            try {
                $result = $this->sth->execute($bind);
                $this->dbh->traceCall("execute",$args);
                return $result;
            }
            catch (PDOException $e) {
                $this->dbh->traceCall("execute",$args);
                if ( isset( $this->sth ) ) {
                    $this->sth->closeCursor();
                }
                // If we got a MySQL has gone away error ...
                if ( $this->dbh->_retryable($e) ) {
                    // If we were in a transaction, explicitly disconnect so that further activies
                    // will trigger a reconnect and throw an exception.
                    if ( $this->dbh->inTransaction() ) {
                        $this->dbh->disconnect();
                        $this->dbh->queryException($e,$this->dump($bind));
                    }
                    // Otherwise reconnect
                    else {
                        $this->dbh->connect($e);
                        $this->prepare();
                        // And we don't have to do anything to retry this,
                        // just fall through to the loop.
                    }
                }
                // If we AREN'T in a transaction AND we get a deadlock error
                // that means we called a proc that ran in a transaction.  As
                // the transaction is entirely in the proc, we can safely retry
                // it.
                else if ( ! $this->dbh->inTransaction() and $retry_deadlocks and $this->dbh->_is_deadlock($e) ) {
                    // And we don't have to do anything to retry this, just
                    // fall through to the loop.
                }
                else {
                    // If we're already in a transaction and this is a
                    // deadlock that's normal-- we just rethrow without
                    // logging.
                    if ( $this->dbh->_is_deadlock($e) and $this->dbh->inTransaction() ) {
                        throw $e;
                    }
                    else {
                        $this->dbh->queryException($e,$this->dump($bind));
                    }
                }
            }
            catch (Exception $e) {
                $this->dbh->traceCall("execute",$args);
                $this->sth->closeCursor();
                throw $e;
            }
            
            $this->dbh->retrySleep( $tries );
        }
        $this->dbh->queryException($e,$this->dump($bind));
    }
    
    /**
     * @param int $attribute
     * @param mixed $value
     * @returns bool
     */
    public function setAttribute($attribute,$value) {
        $this->attrs[$attribute] = $value;
        return $this->sth->setAttribute($attribute,$value);
    }

    /**
     * @param int $attribute
     * @returns mixed
     */
    public function getAttribute($attribute) {
        return $this->sth->getAttribute($attribute);
    }
    
}
