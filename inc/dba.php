<?php

/**
 *	Provides abstraction for DBA functions and implents a swDba class using Sqlite3
 *  
 *  File also sets default $swDbaHandler to 'sqlite3'. You can change than in site/configuration.php.
 * 
 */


define("CRLF", "\r\n");
define("LF", "\n");
define("TAB", "\t");

/**
 *  Opens a database and returns it
 * 
 *  @param $file path to file
 *  @param $mode rdt, wdt, c...
 *  @param $handler 'db', 'db4', 'sqlite3'
 */

function swDbaOpen($file, $mode, $handler)
{
	global $swDbaHandler;
	
	if ($swDbaHandler == 'sqlite3')
	{
		try
		{
			return new swDba($file,$mode);
		}
		catch (swDbaError $err)
		{
			echotime('db busy');
			$err->notify();
			return;
		}
	}
	elseif ($swDbaHandler == 'persistance')
	{
		$db = new swPersistanceDba;
		$db->persistance = $file;
		$db->open();
		return $db;
	}
	
	return @dba_open($file,$mode,$swDbaHandler);
}

/**
 *  Sets the cursor to the first record, returns false if not possible
 * 
 *  @param $db
 */

function swDbaFirstKey($db)
{
	global $swDbaHandler;
	if ($swDbaHandler == 'sqlite3') return $db->firstkey();
	elseif ($swDbaHandler == 'persistance')
	{
		 reset($db->dict);
		 return key($db->dict);
	}
		
	return @dba_firstkey($db);
}

/**
 *  Sets the cursor to the next record, returns false if not possible
 * 
 *  @param $db
 */

function swDbaNextKey($db)
{
	global $swDbaHandler;
	if ($swDbaHandler == 'sqlite3') return $db->nextkey();
	elseif ($swDbaHandler == 'persistance')
	{
		 next($db->dict);
		 return key($db->dict);
	}

	return @dba_nextkey($db);
}

/**
 *  Returns true if a key exists in the database.
 * 
 *  @param $key
 *  @param $db
 */

function swDbaExists($key,$db)
{
	global $swDbaHandler;
	if ($swDbaHandler == 'sqlite3') return $db->exists($key);
	elseif ($swDbaHandler == 'persistance')
	{
		if ($db)	return array_key_exists($key, $db->dict);
		else 		return false;
	}

	return @dba_exists($key,$db);
}

/**
 *  Returns a value for a given key. Throws an exception if the key does not exists
 * 
 *  @param $key
 *  @param $db
 */

function swDbaFetch($key,$db)
{
	global $swDbaHandler;
	if ($swDbaHandler == 'sqlite3')
	{
		try
		{
			return $db->fetch($key);
		}
		catch (swDbaError $err)
		{
			$err->notify();			
			return false;
		}
	}
	elseif ($swDbaHandler == 'persistance')
	{
		 return @$db->dict[$key];
	}	
	
	return @dba_fetch($key,$db);
}

/**
 *  Sets a value for a given key.
 * 
 *  @param $key
 *  @param $value
 *  @param $db
 */

function swDbaReplace($key,$value,$db)
{
	global $swDbaHandler;
	if ($swDbaHandler == 'sqlite3')
	{
		try
		{
			return $db->replace($key,$value);
		}
		catch (swDbaError $err)
		{
			$err->notify();
			return false;
		}
	}
	elseif ($swDbaHandler == 'persistance')
	{
		 $db->dict[$key] = $value;
	}	
		
	return @dba_replace($key,$value,$db);
}

/**
 *  Saves the database to disks.
 *  
 *  Before sync, new rows live only in the journal.
 *  @param $db
 */

function swDbaSync($db)
{
	global $swDbaHandler;
	if ($swDbaHandler == 'sqlite3')
	{
		try
		{
			return $db->sync();
		}
		catch (swDbaError $err)
		{
			$err->notify();
			return false;
		}
	}
	elseif ($swDbaHandler == 'persistance')
	{
		 $db->save();
		 return true;
	}	
	return @dba_sync($db);
}

/**
 *  Deletes a value for a given key. Throws an exception if the key does not exists
 * 
 *  @param $key
 *  @param $db
 */


function swDbaDelete($key,$db)
{
	global $swDbaHandler;
	if ($swDbaHandler == 'sqlite3')
	{
		try
		{
			return $db->delete($key);
		}
		catch (swDbaError $err)
		{
			$err->notify();
			return false;
		}
	}
	elseif ($swDbaHandler == 'persistance')
	{
		 unset($db->dict[$key]);
		 return;
	}	
	return @dba_delete($key,$db);
}

/**
 *  Closes the database, syncs first if neede.
 * 
 *  @param $db
 */

function swDbaClose($db)
{
	global $swDbaHandler;
	if ($swDbaHandler == 'sqlite3')
	{
		try
		{
			return $db->close();
		}
		catch (swDbaError $err)
		{
			$err->notify();
			return false;
		}

	}
	elseif ($swDbaHandler == 'persistance')
	{
		 $db->save();
		 return true;
	}	
		
	return @dba_close($db);
}

/**
 *  Returns the number of keys
 * 
 *  @param $db
 */

function swDbaCount($db)
{
	global $swDbaHandler;
	if ($swDbaHandler == 'sqlite3')
		return $db->count();
		
	elseif ($swDbaHandler == 'persistance')
	{
		 return count($db->dict);
	}	

	
	return 0; // there is no native function
}


/**
 * Provides a class to to use Sqlite3 as a key-value database (DBA).
 * 
 * Don't confuse $db var of this class with the global $db object.
 *
 * $path holds the file path of the Sqlite3 database.
 * $db holds the Sqlite3 database.
 * $rows holds the relation (allowing holding status firstkey-nextkey
 * $journal holds modifications that are not synched
 */

class swDba
{
	var $path;
	var $db;
	var $rows;
	var $journal;
	
	function __construct($path)
	{
		global $swRoot; 
		$this->db = new SQLite3($path);
		$this->path = str_replace($swRoot,'',$path);
		if (! $this->db)
		{
			throw new swDbaError('swDba create table error '.$this->db->lastErrorMsg().' path'.$path);
		}
		
		
		if (!$this->db->busyTimeout(5000))  // sql tries to connect during 5000ms
		{
			throw new swDbaError('swdba is busy');
		}
			
		$this->db->exec('PRAGMA journal_mode = WAL'); 
		$this->db->exec('PRAGMA synchronous=NORMAL');
		
		if (!$this->db->exec('CREATE TABLE IF NOT EXISTS kv (k text unique, v text)'))
		{
			throw new swDbaError('swDba create table error '.$this->db->lastErrorMsg());
		}
		$this->journal = array();
		
		
	}
	
		
	function firstKey()
	{	
		
		$this->sync();
		
		$statement = $this->db->prepare('SELECT k FROM kv ORDER BY k');
		$this->rows = $statement->execute();

		if ($this->db->lastErrorCode())
		{
			throw new swDbaError('swDba firstKey error '.$this->db->lastErrorMsg());
		}
		
		if ($r = $this->rows->fetchArray(SQLITE3_ASSOC))
		{
			return $r['k'];
		}
		else
		{
			return false;
		}
			
	}
	
	function nextKey()
	{
		
		if (!$this->rows)
		{
			throw new swDbaError('swDba nextKey without firstKey error');
		}
		
		if ($r = $this->rows->fetchArray(SQLITE3_ASSOC))
		{
			return $r['k'];
		}
		else
			return false;
		
	}
	
	function exists($key)
	{
		if (isset($this->journal[$key])) 
		{
			if ($this->journal[$key] === false) return false; else return true;
		} 
		
		$statement = $this->db->prepare('SELECT count(k) as c FROM kv WHERE k = :key');
		$statement->bindValue(':key', $key);
		$result = $statement->execute();
		
		if ($this->db->lastErrorCode())
		{
			$this->db->exec('VACUUM');
			throw new swDbaError('swDba exists error '.$this->db->lastErrorMsg());
		}
		else
		{
			$row = $result->fetchArray();
		}
		
		if ($row['c']) return true;
		else return false;
		
	}
	
	function fetch($key)
	{
		if (isset($this->journal[$key]))
		{
			return $this->journal[$key];
		} 
		
		$statement = $this->db->prepare('SELECT v FROM kv WHERE k = :key');
		$statement->bindValue(':key', $key);
		$result = $statement->execute();
		
		if ($this->db->lastErrorCode())
		{
			$this->db->exec('VACUUM');
			throw new swDbaError('swDba fetch error '.$this->db->lastErrorMsg());
		}
		else
		{
			$row = $result->fetchArray();
		}
		return $row['v'];
	}

	function delete($key)
	{
		$this->journal[$key] = false;
	 	
	}
	
	function replace($key, $value)
	{
	 	$test = $this->fetch($key);
	 	if ($test == $value) return;
	 	
	 	$this->journal[$key] = $value;
	 	
		if (count($this->journal) >= 1000) $this->sync();
		
		return true;
	}
	
	function sync()
	{
		if (!count($this->journal)) return;
		
		echotime('sync '.count($this->journal));
		
		$lines = array();
		$lines[] = "PRAGMA synchronous=OFF; ";
		
		foreach($this->journal as $k=>$v)
		{
			$k = $this->db->escapeString($k);
			$v = $this->db->escapeString($v);
			if ($v === FALSE)
				$lines[]= "DELETE FROM kv WHERE k = '$k' ;"; // use double quote because in sql it is single quote
			else
				$lines[]= "REPLACE INTO kv (k,v) VALUES ('$k','$v');";			
		}
		$q = join(PHP_EOL,$lines);
		$lines[] = "PRAGMA synchronous=FULL; ";
		
		$this->db->exec($q);

		if ($this->db->lastErrorCode())
		{
			$this->db->exec('VACUUM');
			throw new swDbaError('swDba sync error '.$this->db->lastErrorMsg());
		}		
		$this->journal = array();
		
	}
	
	function close()
	{
		$this->sync();
	}
	
	function __destruct()
	{
		$this->sync();
		
		if (rand(0, 100) > 90 || true) $this->db->exec('PRAGMA optimize');
		
		$this->db->close();
	}	
	
	function listDatabases()
	{
		// list all open DB not implemented, needs a global array
	}
	
	function count()
	{
		$statement = $this->db->prepare('SELECT count(k) as c FROM kv');
		$result = $statement->execute();
		
		if ($this->db->lastErrorCode())
		{
			throw new swDbaError('swdba fetch error '.$this->db->lastErrorMsg());
		}
		
		$row = $result->fetchArray();
		
		return $row['c'];
	}
	
	
		
}


/**
 *  Last resort class if neither Sqlite nor dba functions are present
 */


class swPersistanceDba extends swPersistance
{
	var $dict = array();
}


/**
 *  Holds an error class.
 */

class swDbaError extends Exception
{
	function notify()
	{
		global $username;
		global $name;
		global $action;
		global $query;
		global $lang;
		$error = $this->getMessage();
		$message = $this->getFile().' '.$this->getLine();
		
		swLog($username,$name,$action,$query,$lang,'','',$error,'',$message,'');
	}
}


