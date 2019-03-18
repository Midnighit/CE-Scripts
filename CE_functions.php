<?php

require 'config.php';

// Converts a given timestamp to a human readable date in a given timezone
function convertTZ($timestamp, &$timezone, &$datetime)
{
	$datetime->settimestamp($timestamp);					// set the date object to the timestamp
	$diff = $timezone->getOffset($datetime);				// calculate the current offset between the given timezone and the one set on the computer running
	return date('d-M-Y H:i', ($timestamp - $diff));			// return the formatted YYYY-MM-DD (ISO 8601) date and time HH:MM:SS string
}

// Extract a className from the CEDB
function getClassName($input)
{
	$class_path = preg_split('*[/\.]*', $input);
	return array_pop($class_path);
}

// Calcualte the average of the values within an array
function array_avrg($array, $precision = 0)
{ 
	$count = count($array); 
	$sum = array_sum($array); 
	if($precision === false) return $sum / $count;
	else return round($sum / $count, $precision);
} 

// Calcualte the median of the values within an array
function array_median($array, $precision = 0)
{
	rsort($array);
	$count = count($array);
	if(($count % 2) == 0)
	{
		$middleLow = floor($count / 2) - 1;
		$middleUp = ceil($count / 2);
		$total = ($array[$middleLow] + $array[$middleUp]) / 2;
	}
	else
	{
		$middle = floor($count / 2);
		$total = $array[$middle];
	}
	if($precision === false) return $total;
	else return round($total, $precision);
} 

// Function to order tables organized in rows instead of columns
function array_orderby()
{
	$args = func_get_args();
	$data = array_shift($args);
	foreach ($args as $n => $field) {
		if (is_string($field)) {
			$tmp = array();
			foreach ($data as $key => $row)
				$tmp[$key] = $row[$field];
			$args[$n] = $tmp;
			}
	}
	$args[] = &$data;
	call_user_func_array('array_multisort', $args);
	return array_pop($args);
}

// Get the timestamp of the character that was the last to be online on the server
function getLastOnlineTimestamp($db)
{
	$sql = 'SELECT lastTimeOnline FROM characters ORDER BY lastTimeOnline DESC LIMIT 1';
	$result = $db->query($sql);
	$row = $result->fetchArray(SQLITE3_ASSOC);
	$result->finalize();
	return $row['lastTimeOnline'];
}

// function used for array_walk in getTilescount
function roundTiles(&$num)
{
	$num = round($num, 0);
}

// generates an array of owners and the amount of tiles they own
function getTilescount($db, $key = 0, $bMult = 1, $pMult = 1)
{
	// If the key is owner_id initialize the array with zeroes for every owner of any building tiles or placeables at all
	if($key == BY_OWNER)
	{
		$sql = 'SELECT owner_id FROM buildings';
		$result = $db->query($sql);
		while($row = $result->fetchArray(SQLITE3_ASSOC)) $tiles[$row['owner_id']] = 0;
		$result->finalize();
	}
		
	// Add root-objects and those attached to it to the tiles array and store the root objects in a separate array to exclude them from the placables array
	$sql = 'SELECT buildings.object_id, owner_id, count(*) AS tiles FROM actor_position, buildings, building_instances WHERE buildings.object_id = id AND building_instances.object_id = id GROUP BY buildings.object_id';
	$result = $db->query($sql);
	while($row = $result->fetchArray(SQLITE3_ASSOC))
	{
		if($key == BY_OWNER) $tiles[$row['owner_id']] += $row['tiles'] * $bMult;
		elseif($key == BY_OBJECT) $tiles[$row['object_id']] = $row['tiles'] * $bMult;
		$root_objects[$row['object_id']] = true;
	}
	$result->finalize();

	// Add all the placables objects excluding all the root-objects already added above
	$sql = 'SELECT buildings.object_id, owner_id FROM actor_position, buildings WHERE buildings.object_id = id';
	$result = $db->query($sql);
	while($row = $result->fetchArray(SQLITE3_ASSOC)) if((!isset($root_objects[$row['object_id']]) || !$root_objects[$row['object_id']]))
	{
		if($key == BY_OWNER) $tiles[$row['owner_id']] += $pMult;
		elseif($key == BY_OBJECT) $tiles[$row['object_id']] = $pMult;
	}
	$result->finalize();
	
	array_walk($tiles, 'roundTiles');
	return $tiles;
}

// Count the members of guilds satisfying the filter conditions. Solo players count as one member
function getMembers($db, $filter = 0, $time = false)
{
	if(!$time) $time = time();
	$guild = "SELECT guild as guild_id, count(*) as members FROM characters WHERE guild_id NOT NULL";
	$char = 'SELECT id as char_id FROM characters WHERE guild IS NULL';
	$onlyChars = $onlyGuilds = false;
	
	if($filter >= NOGUILD)
	{
		$filter -= NOGUILD;
		$onlyChars = true;
	}
	if($filter >= GUILD)
	{
		$filter -= GUILD;
		$onlyGuilds = true;
	}
	if($filter >= NOBUILDINGS)
	{
		$filter -= NOBUILDINGS;
		$guild .= " AND guild_id NOT IN (SELECT owner_id FROM buildings)";
		$char .= " AND char_id NOT IN (SELECT owner_id FROM buildings)";
	}
	if($filter >= BUILDINGS)
	{ 
		$filter -= BUILDINGS;
		$guild .= " AND guild_id IN (SELECT owner_id FROM buildings)";
		$char .= " AND char_id IN (SELECT owner_id FROM buildings)";
	}
	if($filter >= INACTIVE)
	{
		$filter -= INACTIVE;
		$guild .= " AND " . $time . " - lastTimeOnline > " . INACTIVITY . " * 86400";
		$char .= " AND " . $time . " - lastTimeOnline > " . INACTIVITY . " * 86400";
	}
	if($filter >= ACTIVE)
	{
		$filter -= ACTIVE;
		$guild .= " AND " . $time . " - lastTimeOnline <= " . INACTIVITY . " * 86400";
		$char .= " AND " . $time . " - lastTimeOnline <= " . INACTIVITY . " * 86400";
	}
	
	
	$guild .= " GROUP BY guild_id";
	$result = $db->query($guild);
	while($row = $result->fetchArray(SQLITE3_ASSOC)) if((!$onlyChars || $row['members'] == 1) && (!$onlyGuilds || $row['members'] > 1)) $members[$row['guild_id']] = $row['members'];
	$result->finalize();

	if(!$onlyGuilds)
	{
		$result = $db->query($char);
		while($row = $result->fetchArray(SQLITE3_ASSOC)) $members[$row['char_id']] = 1;
		$result->finalize();
	}
	
	if(isset($members)) return $members;
	else return [];
}

// Takes an array with CE objects with (at least) an owner and coordinates each. Removes all entries that are closer than min_distance to each other
// Parameters: Array to be processed and the COLUMN INDEX for the owner, x, y and z coordinates as well as the minimum distance they need to have between each other for them to be kept
function consolidate($arr, $object, $owner, $tiles, $x, $y, $z, $min_dist)
{
	// Loop is terminated when every array row has been processed
	while(isset($arr) && count($arr) > 0)
	{
		// Get the first row of the array and store it in cmp
		$cmp = array_shift($arr);
		// If there's already at least a single row in the return array, compare all of its rows with the current cmp row
		if(isset($out))
		{
			// Let's start with the assumption that the new row is being kept
			$keep = true;
			// Iterate through the return array and compare owner and distance
			foreach($out as $k => $v)
			{
				// if the return array contains a row that is both owned by the same owner and closer than the max_dist to it, keep is set to false, the tiles of the object are added to the one in that row and the loop interrupted
				if($v[$owner] == $cmp[$owner] && sqrt(pow(($v[$x] - $cmp[$x]), 2) + pow(($v[$y] - $cmp[$y]), 2) + pow(($v[$z] - $cmp[$z]), 2)) < $min_dist)
				{
					$out[$k][$tiles] += $cmp[$tiles];
					$keep = false;
					break;
				}
			}
			// If the loop wasnt broken and keep is still true, add cmp to out.
			if($keep)
			{
				// Now check arr for more entries that are owned by the same owner and too close to cmp, add their tiles to the row and remove them from arr
				foreach($arr as $k => $v) if($v[$owner] == $cmp[$owner] && sqrt(pow(($v[$x] - $cmp[$x]), 2) + pow(($v[$y] - $cmp[$y]), 2) + pow(($v[$z] - $cmp[$z]), 2)) < $min_dist)
				{
					$cmp[$tiles] += $v[$tiles];
					unset($arr[$k]);
				}
				$out[] = $cmp;
			}
		}
		// out was still empty, so cmp is automatically added as its first element
		else $out[] = $cmp;
	}
	// once the whole array has been processed return out to the caller.
	return $out;
}

// Creates and/or updates a file noownerobjcache.list to store which objects have been detected as having no owners when.
function updateNoOwnerObjectscache(&$db)
{
	global $noownerobjcache;
	// if an noownerobjcache has been created, read it otherwise initialize the reserved owner IDs
	if(file_exists('noownerobjcache.list')) include 'noownerobjcache.list';
	else $noownerobjcache = [];

	// get all objectIDs whose owner is listed in neither characters nor guilds table and those already in the reserved no owner clan.
	$sql =
		"SELECT object_id
		FROM buildings
		WHERE (owner_id NOT IN ( SELECT id FROM characters ) AND owner_id NOT IN ( SELECT guildId FROM guilds )) OR owner_id = " . RUINS_CLAN_ID;
	$result = $db->query($sql);
	// add current timestamp for new entries 
	$now = time();
	while($row = $result->fetchArray(SQLITE3_NUM)) $allNoOwnerObj[$row[0]] = $now;
	$result->finalize();
	
	// purge objects from the cache that either have an owner or don't exist anymore (e.g. everything that's not in the objects array)
	foreach($noownerobjcache as $k => $v) if(!isset($allNoOwnerObj[$k])) unset($noownerobjcache[$k]);
	
	// add new objects that don't have an owner or belong to the no owner ruins guild to the cache
	foreach($allNoOwnerObj as $k => $v) if(!isset($noownerobjcache[$k])) $noownerobjcache[$k] = $v;

	// write back the cached object timestamps
	$handle = fopen('./noownerobjcache.list', 'w+');
	$contents = '<?php'.PHP_EOL;
	foreach($noownerobjcache as $k => $v) $contents .= '$noownerobjcache["' . $k . '"] = ' . $v . ';'.PHP_EOL;
	$contents .= '?>';
	fwrite($handle, $contents);
}

// Creates and/or updates a file ownercache.list to store all the ownerID <=> name relations
function updateOwnercache(&$db)
{
	global $ownercache;
	// if an ownercache has been created, read it otherwise initialize the reserved owner IDs
	if(file_exists('ownercache.list')) include 'ownercache.list';
	else list($ownercache[0], $ownercache[1], $ownercache[2]) = ['Game assets', 'Whitelisted', 'Ruins'];

	// Get all characters IDs and their names from the current characters table
	$sql =
		"SELECT id AS owner_id, char_name AS name
		FROM characters";
	$result = $db->query($sql);
	// Overwrite or add all characters found to $owners array. Ruins are not written into $ownercache to preserve their original names.
	while($row = $result->fetchArray(SQLITE3_ASSOC)) if($row['name'] != "Ruins") $ownercache[$row['owner_id']] = $row['name'];
	$result->finalize();

	// Get all guild IDs and their names from the current guilds table
	$sql =
		"SELECT guildId AS owner_id, name
		FROM guilds";
	$result = $db->query($sql);
	// Overwrite or add all guilds found to $owners array. Ruins are not written into $ownercache to preserve their original names.
	while($row = $result->fetchArray(SQLITE3_ASSOC)) if($row['name'] != "Ruins") $ownercache[$row['owner_id']] = $row['name'];
	$result->finalize();

	// write back the cached owner names
	$handle = fopen('./ownercache.list', 'w+');
	$contents = '<?php'.PHP_EOL;
	foreach($ownercache as $ownerId => $ownerName) $contents .= '$ownercache["'.$ownerId.'"] = "'.$ownerName.'";'.PHP_EOL;
	$contents .= '?>';
	fwrite($handle, $contents);
}

// Creates and/or updates a file thrallcache.list to store all the objectID <=> ownerID relations
function updateThrallcache(&$db)
{
	global $thrallcache;
	// if a thrallcache has been created, read it
	if(file_exists('thrallcache.list')) include 'thrallcache.list';

	// Get all thralls/pets and their owner_ids from the game_events table
	$result = $db->query("SELECT DISTINCT objectid, ownerid, ownerguildid, ownerName, ownerGuildName FROM game_events WHERE eventtype = 89");

	while($row = $result->fetchArray(SQLITE3_NUM))
	{
		if($row[1] > 0) $thrallcache[$row[0]] = $row[1];
		elseif($row[2] > 2) $thrallcache[$row[0]] = $row[2];
	}

	// write back the cached owner names
	$handle = fopen('./thrallcache.list', 'w+');
	$contents = '<?php'.PHP_EOL;
	if(isset($thrallchache)) foreach($thrallcache as $objectId => $ownerId) $contents .= '$thrallcache["'.$objectId.'"] = "'.$ownerId.'";'.PHP_EOL;
	$contents .= '?>';
	fwrite($handle, $contents);
}

// function to convert the Steamd64ID to the Steam username
function getSteamName($steamid, &$steamcache, $timeout = 1)
{
	$steamid = (string)$steamid;
	if(strlen($steamid) == 18) $steamid = substr($steamid, 0, -1);
	if(isset($steamcache[$steamid]) && ($steamcache[$steamid]!='')) return $steamcache[$steamid];
	else
	{
		$url = 'http://steamcommunity.com/profiles/'.$steamid.'/?xml=1';
		$opts = array('http' => array('timeout' => (int)$timeout));
		$context = stream_context_create($opts);
		$data = file_get_contents($url, false, $context);
		if($data)
		{
			$start = strrpos($data, '<steamID><![CDATA[')+18;
			if(!$start) return '';
			$length = strpos($data, ']]></steamID>', $start)-$start;
			if($length==0)
			{
				$url = 'https://steamcommunity.com/profiles/'.$steamid;
				$data = file_get_contents($url, false, $context);
				if($data)
				{
					$start = strrpos($data, '<title>Steam Community ::')+26;
					$length = strpos($data, '</title>', $start)-$start;
					$steamcache[$steamid] = substr($data, $start, $length);
					return $steamcache[$steamid];
				}
				else $steamcache[$steamid] = 'couldn\'t access!';
			}
			else $steamcache[$steamid] = substr($data, $start, $length);
			return $steamcache[$steamid];
		}
		else return '';
	}
}
?>