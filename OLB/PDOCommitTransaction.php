<?php
/**
 * Copyright © 2012 Online Buddies, Inc. - All Rights Reserved
 *
 * @package OLB::PDO
 * @author bturner@online-buddies.com
 */

/**
 * Exceptions inheriting from this class will not trigger a rollback when
 * thrown from inside an execTransaction block.
 */
interface OLB_PDOCommitTransaction { }
