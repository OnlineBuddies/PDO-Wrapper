<?php
/**
 * Copyright © 2013 Online Buddies, Inc. - All Rights Reserved
 *
 * @package OLB::PDO
 * @author bturner@online-buddies.com
 */

/**
 * Exceptions inheriting from this class will trigger a transaction retry,
 * much like a deadlock would.
 */
interface OLB_PDORetryTransaction { }
