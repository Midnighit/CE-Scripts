<?php
$stime = microtime(true);

echo "Creating new ruins, applying damage and purging them depending on inactivity...\n";

// load some commonly used functions
require 'CE_functions.php';

// check if db is found at given path
if(!file_exists(CEDB_PATH . DB_FILE)) exit("No database found, skipping script\n");

// define constants and set ini variables	
ini_set('max_execution_time', 600);

// Open the sqlite3 db
$db = new SQLite3(CEDB_PATH . DB_FILE);

//---------------------- Execute some preparatory operations --------------------//

// Create a SQL compatibel string from the owner whitelist
$whitelist = implode(",", OWNER_WLST);

// Give all thespians within New Vilayet territory back to their respective owners
$queries[] = "UPDATE buildings SET owner_id = 12 WHERE object_id IN (SELECT id FROM actor_position, buildings WHERE id = object_id AND (x BETWEEN -81000 AND -64000) AND (y BETWEEN 103000 AND 111000) AND class = '/Game/Mods/Pippi/Pippi_Mob.Pippi_Mob_C' AND owner_id <> 12)";

// Remove characters that have been inactive for more than the number of days defined in LONG_INACTIVE from the db
$result = $db->exec("CREATE TEMPORARY TABLE removed_chars AS SELECT id FROM characters WHERE (strftime('%s','now')) - lastTimeOnline > " . LONG_INACTIVE . " * 86400 AND id > 20 AND id NOT IN (" . $whitelist . ")");
$result = $db->exec("DELETE FROM characters WHERE id IN (SELECT id FROM removed_chars)");
if($result) $changes = $db->changes();

// Remove rows linked to removed characters from character_stats, item_inventory, item_properties and properties
$queries[] = "DELETE FROM actor_position WHERE id IN (SELECT id FROM removed_chars)";
$queries[] = "DELETE FROM character_stats WHERE char_id IN (SELECT id FROM removed_chars)";
$queries[] = "DELETE FROM item_inventory WHERE owner_id IN (SELECT id FROM removed_chars)";
$queries[] = "DELETE FROM item_properties WHERE owner_id IN (SELECT id FROM removed_chars)";
$queries[] = "DELETE FROM properties WHERE object_id IN (SELECT id FROM removed_chars)";
while(count($queries) > 0) $db->exec(array_shift($queries));
if($changes) echo "Removing " . $changes . " long time inactive characters from the db...\n";

// Remove now empty clans from the db
$result = $db->exec("DELETE FROM guilds WHERE guildId NOT IN (SELECT DISTINCT guild FROM characters WHERE guild NOT NULL) AND guildId > 20 AND guildId NOT IN (" . $whitelist . ")");
if($result) $changes = $db->changes();
if($changes) echo "Removing " . $changes . " empty clans from the db...\n";

// Remove events older than EVENT_LOG_HOLD_BACK from the game_events table
$result = $db->exec("DELETE FROM game_events WHERE worldTime < strftime('%s', 'now', '-" . EVENT_LOG_HOLD_BACK . " days')");
if($result) $changes = $db->changes();
if($changes) echo "Removing " . $changes . " event log lines from the db...\n";

// Remove thrall and pet feeding pots 
$queries[] = "DELETE FROM buildable_health WHERE object_id IN (SELECT id FROM actor_position WHERE class LIKE '%FeedingContainer%')";
$queries[] = "DELETE FROM buildings WHERE object_id IN (SELECT id FROM actor_position WHERE class LIKE '%FeedingContainer%')";
$queries[] = "DELETE FROM destruction_history WHERE object_id IN (SELECT id FROM actor_position WHERE class LIKE '%FeedingContainer%')";
$queries[] = "DELETE FROM properties WHERE object_id IN (SELECT id FROM actor_position WHERE class LIKE '%FeedingContainer%')";
$queries[] = "DELETE FROM actor_position WHERE class LIKE '%FeedingContainer%'";

//---------------------------- Compiling Information ----------------------------//

// save all guild and character names before some of them are renamed to Ruins
updateOwnercache($db);
// check for objects that have no owners and add a current timestamp to those that are new
updateNoOwnerObjectscache($db);
// save all thrall/pet objectIDs and their ownerIDs
updateThrallcache($db);

// Create a table containing all clans with inactive members and the number of those inactive members
$queries[] = "CREATE TEMPORARY TABLE clanmembers_inactive AS SELECT count(*) as num_members_inactive, guild as guild_id FROM characters WHERE lastTimeOnline < strftime('%s', 'now', '-" . INACTIVITY . " days') AND guild_id NOT NULL GROUP BY guild_id";
// Create a table containing all clans and the number of members
$queries[] = "CREATE TEMPORARY TABLE clanmembers_all AS SELECT count(*) as num_members_all, guild as guild_id FROM characters WHERE guild_id NOT NULL GROUP BY guild_id";
// Compare the two tables above and create a new table containing containing only those rows where the number of members match (e.g. all members are inactive)
$queries[] = "CREATE TEMPORARY TABLE objects_owned_by_clans_inactive AS SELECT object_id, name, owner_id FROM buildings, guilds WHERE guildId = owner_id AND owner_id IN ( SELECT clanmembers_all.guild_id AS guild_id FROM clanmembers_all INNER JOIN clanmembers_inactive ON clanmembers_all.guild_id = clanmembers_inactive.guild_id WHERE num_members_inactive = num_members_all)";
// Create a table containing all inactive characters that have no clan
$queries[] = "CREATE TEMPORARY TABLE objects_owned_by_characters_inactive AS SELECT object_id, char_name as name, owner_id FROM buildings, characters WHERE owner_id = id AND lastTimeOnline < strftime('%s','now', '-" . INACTIVITY . " days')";
// Combine the inactive character and clan tables to one table with all inactive objetcs to be deleted
$queries[] = "CREATE TEMPORARY TABLE objects_owned_by_inactive AS SELECT object_id, owner_id, name, x, y, z FROM objects_owned_by_characters_inactive, actor_position WHERE object_id = id UNION SELECT object_id, owner_id, name, x, y, z FROM objects_owned_by_clans_inactive, actor_position WHERE object_id = id";
// Create a table containing all objects that have no owner
$queries[] = "CREATE TEMPORARY TABLE objects_no_owner AS SELECT object_id, owner_id, x, y, z FROM buildings, actor_position WHERE object_id = id AND owner_id > 20 AND owner_id NOT IN ( SELECT id FROM characters ) AND owner_id NOT IN ( SELECT guildId FROM guilds )"; // UNION SELECT object_id, owner_id, x, y, z FROM buildings, actor_position, guilds WHERE id = object_id AND owner_id = guildId AND owner_id NOT IN (SELECT guild FROM characters WHERE guild NOT NULL)";
// Create a table containing all objects that belong to active owners
$queries[] = "CREATE TEMPORARY TABLE objects_owned_by_active AS SELECT object_id, owner_id, x, y, z FROM actor_position, buildings WHERE id = object_id AND owner_id NOT IN ( SELECT owner_id FROM objects_owned_by_inactive ) AND owner_id NOT IN ( SELECT owner_id FROM buildings WHERE owner_id NOT IN ( SELECT id FROM characters ) AND owner_id NOT IN ( SELECT guildId FROM guilds ) )";
while(count($queries) > 0) $db->exec(array_shift($queries));

// create an array with all owners and tag them as guilds or single players
$sql = "SELECT guildId FROM guilds";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_NUM)) $isGuild[$row[0]] = true;

$sql = "SELECT id FROM characters WHERE guild IS NULL";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_NUM)) $isGuild[$row[0]] = false;

if(DAMAGE > 0)
{				
	// Create an array with all objects and their instances that need to have damage applied to them
	$sql = "SELECT objects_owned_by_inactive.object_id, owner_id, instance_id, health_id, health_percentage FROM objects_owned_by_inactive, buildable_health WHERE objects_owned_by_inactive.object_id = buildable_health.object_id ORDER BY objects_owned_by_inactive.object_id";
	$result = $db->query($sql);
	while($row = $result->fetchArray(SQLITE3_NUM)) if(!in_array($row[1], OWNER_WLST)) $toBeDamagedByOwner[] = ['objectID' => $row[0], 'ownerID' => $row[1], 'instanceID' => $row[2], 'healthID' => $row[3], 'healthPercentage' => $row[4]];

	$sql = "SELECT objects_no_owner.object_id, owner_id, instance_id, health_id, health_percentage FROM objects_no_owner, buildable_health WHERE objects_no_owner.object_id = buildable_health.object_id ORDER BY objects_no_owner.object_id";
	$result = $db->query($sql);
	while($row = $result->fetchArray(SQLITE3_NUM)) if(!in_array($row[1], OWNER_WLST)) $toBeDamagedByObject[] = ['objectID' => $row[0], 'ownerID' => $row[1], 'instanceID' => $row[2], 'healthID' => $row[3], 'healthPercentage' => $row[4]];
}

// Create an array with all inactive owners and the number of days that they have been inactive
$sql = "SELECT owner_id, ((strftime('%s','now') - lastTimeOnline) / 86400) - " . INACTIVITY . " AS daysInactive FROM objects_owned_by_inactive, characters WHERE guild = owner_id GROUP BY owner_id ORDER BY daysInactive";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_NUM)) $daysInactive[$row[0]] = $row[1];

$sql = "SELECT owner_id, ((strftime('%s','now') - lastTimeOnline) / 86400) - " . INACTIVITY . " AS daysInactive FROM objects_owned_by_inactive, characters WHERE id = owner_id GROUP BY owner_id ORDER BY daysInactive";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_NUM)) $daysInactive[$row[0]] = $row[1];

// Create a string with all inactive owners
$inactive_owners = '';
if(isset($daysInactive))
{
	foreach($daysInactive as $key => $days) $inactive_owners .= $key . ',';
	$inactive_owners = substr($inactive_owners, 0, -1);
}

// Create an array with all objects that already are ruins
$sql = "SELECT object_id, owner_id, x, y, z FROM actor_position, buildings, characters WHERE actor_position.id = object_id AND characters.id = owner_id AND char_name = 'Ruins' ORDER BY object_id";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_NUM)) $ruins[] = ['objectID' => $row[0], 'ownerID' => $row[1], 'x' => $row[2], 'y' => $row[3], 'z' => $row[4]];

$sql = "SELECT object_id, owner_id, x, y, z FROM actor_position, buildings, guilds WHERE actor_position.id = object_id AND guildId = owner_id AND name = 'Ruins' ORDER BY object_id";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_NUM)) $ruins[] = ['objectID' => $row[0], 'ownerID' => $row[1], 'x' => $row[2], 'y' => $row[3], 'z' => $row[4]];

// Create an array with all owners that will be returned to their original name
$sql = "SELECT guildId FROM guilds WHERE name = 'Ruins' AND guildId > 20 AND (guildId NOT IN (SELECT owner_id FROM buildings) OR guildId NOT IN (" . $inactive_owners . ") OR guildId IN (" . $whitelist . "))";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_NUM)) $renameToOriginal[$row[0]] = $ownercache[$row[0]];
$sql = "SELECT id FROM characters WHERE char_name = 'Ruins' AND (id NOT IN (SELECT owner_id FROM buildings) OR id NOT IN (" . $inactive_owners . ") OR id IN (" . $whitelist . "))";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_NUM)) $renameToOriginal[$row[0]] = $ownercache[$row[0]];


// Create an array with all owners that need to be renamed to ruins
if(isset($daysInactive))
{
	$sql = "SELECT DISTINCT owner_id FROM objects_owned_by_inactive WHERE name <> 'Ruins'";
	$result = $db->query($sql);
	while($row = $result->fetchArray(SQLITE3_NUM)) if($daysInactive[$row[0]] < PURGE && !in_array($row[0], OWNER_WLST)) $renameToRuins[$row[0]] = "Ruins";
}

// Create an array with all objects that have no owner and are not already in the dedicated Ruins clan.
$sql = "SELECT object_id, owner_id, x, y, z FROM objects_no_owner WHERE owner_id > 20";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_NUM)) if(!in_array($row[1], OWNER_WLST)) $moveToRuinsGuild[] = ['objectID' => $row[0], 'ownerID' => $row[1], 'x' => $row[2], 'y' => $row[3], 'z' => $row[4]];
$now = time();

// Create an array with all objects that have no owners and the number of days that they have were ownerless.
foreach($noownerobjcache as $k => $v) $daysObjInactive[$k] = floor(($now - $v) / 86000);

// Create an array with all objects that will be purged
if(isset($daysInactive))
{
	$sql = "SELECT object_id, owner_id, x, y, z FROM objects_owned_by_inactive";
	$result = $db->query($sql);
	while($row = $result->fetchArray(SQLITE3_NUM)) if(($daysInactive[$row[1]] >= PURGE || $daysInactive[$row[1]] * DAMAGE >= 1) && !in_array($row[1], OWNER_WLST)) $toBePurged[] = ['objectID' => $row[0], 'ownerID' => $row[1], 'x' => $row[2], 'y' => $row[3], 'z' => $row[4]];
}

if(isset($daysObjInactive))
{
	$sql = "SELECT object_id, owner_id, x, y, z FROM buildings, actor_position WHERE id = object_id AND owner_id = 11";
	$result = $db->query($sql);
	while($row = $result->fetchArray(SQLITE3_NUM)) if($daysObjInactive[$row[0]] >= PURGE || $daysObjInactive[$row[0]] * DAMAGE >= 1) $toBePurged[] = ['objectID' => $row[0], 'ownerID' => $row[1], 'x' => $row[2], 'y' => $row[3], 'z' => $row[4]];
}
	
// Create an array with all thralls
$sql = "SELECT id, x, y, z FROM actor_position WHERE class LIKE '/Game/Characters/NPCs/Humanoid/%HumanoidNPC%' ORDER BY id";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_NUM)) $objectsThrall[] = ['ID' => $row[0], 'x' => $row[1], 'y' => $row[2], 'z' => $row[3]];

// Calculate the distance between every ruin object that will be removed to every thrall and create an array of all those closer than PURGE_DIST.
if(isset($toBePurged)) foreach($toBePurged as $obj) foreach($objectsThrall as $thrall)
{
	$distance = sqrt(pow(($obj['x'] - $thrall['x']), 2) + pow(($obj['y'] - $thrall['y']), 2) + pow(($obj['z'] - $thrall['z']), 2));
	if($distance < PURGE_DIST && !isset($removeThralls[$thrall['ID']])) $removeThralls[$thrall['ID']] = true;
}

//---------------------------- Manipulating the Database ----------------------------//

// Delete all references to the thrall object IDs calculated from the actor_position and properties tables.
if(isset($removeThralls))
{
	foreach($removeThralls as $id => $junk)
	{
		$queries[] = "DELETE FROM actor_position WHERE id = ". $id;
		$queries[] = "DELETE FROM properties WHERE object_id = ". $id;
	}
	echo "Removing " . count($queries) . " thralls from actor_position belonging to ruins that will be purged...\n";
	while(count($queries) > 0) $db->exec(array_shift($queries));
}

// Delete all the ruins from the tables destruction_history, properties, buildable_health, building_instances, actor_position and buildings
if(isset($toBePurged))
{
	foreach($toBePurged as $obj)
	{
		$queries[] = "DELETE FROM destruction_history WHERE object_id = " . $obj['objectID'];
		$queries[] = "DELETE FROM properties WHERE object_id = " . $obj['objectID'];
		$queries[] = "DELETE FROM buildable_health WHERE object_id = " . $obj['objectID'];
		$queries[] = "DELETE FROM building_instances WHERE object_id = " . $obj['objectID'];
		$queries[] = "DELETE FROM actor_position WHERE id = " . $obj['objectID'];
		$queries[] = "DELETE FROM buildings WHERE object_id = " . $obj['objectID'];
	}
	echo "Removing " . count($toBePurged) . " ruin objects from destruction_history, properties, buildable_health, building_instances, actor_position and buildings...\n";
	while(count($queries) > 0) $db->exec(array_shift($queries));
}

// Rename all characters/guilds whose objects are being purged back to their original names
if(isset($renameToOriginal))
{
	foreach($renameToOriginal as $k => $v)
	{
		if(isset($isGuild[$k]) && $isGuild[$k]) $queries[] = 'UPDATE guilds SET name = "' . $v . '" WHERE guildId = ' . $k;
		else $queries[] = 'UPDATE characters SET char_name = "' . $v . '" WHERE id = ' . $k;
	}
	if(count($queries) > 0)
	{
		echo "Renaming " . count($queries) . " characters and clans to their original names...\n";
		while(count($queries) > 0) $db->exec(array_shift($queries));
	}
}

// Rename all characters/guilds that have been inactive for more than INACTIVITY days, that still have objects and their original name to Ruins
if(isset($renameToRuins))
{
	foreach($renameToRuins as $k => $v)
	{
		if($isGuild[$k]) $queries[] = "UPDATE guilds SET name = 'Ruins' WHERE guildId = " . $k;
		else $queries[] = "UPDATE characters SET char_name = 'Ruins' WHERE id = " . $k;
	}
	if(count($queries) > 0)
	{
		echo "Renaming " . count($queries) . " characters and clans to Ruins...\n";
		while(count($queries) > 0) $db->exec(array_shift($queries));
	}
}

// Damage ruins depending on how long they've been inactive
if(isset($toBeDamagedByOwner)) foreach($toBeDamagedByOwner as $k => $v) if($v['healthPercentage'] > (1.00000001 - DAMAGE * $daysInactive[$v['ownerID']])) $queries[] = "UPDATE buildable_health SET health_percentage = " . (1 - DAMAGE * $daysInactive[$v['ownerID']]) . " WHERE object_id = " . $v['objectID'] . " AND instance_id = " . $v['instanceID'] . " AND health_id = " . $v['healthID'];
if(isset($toBeDamagedByObject)) foreach($toBeDamagedByObject as $k => $v) if($v['healthPercentage'] > (1.00000001 - DAMAGE * $daysObjInactive[$v['objectID']])) $queries[] = "UPDATE buildable_health SET health_percentage = " . (1 - DAMAGE * $daysObjInactive[$v['ownerID']]) . " WHERE object_id = " . $v['objectID'] . " AND instance_id = " . $v['instanceID'] . " AND health_id = " . $v['healthID'];
if(count($queries) > 0)
{
	echo "Damaging " . count($queries) . " objects and object instances that are part of ruins...\n";
	while(count($queries) > 0) $db->exec(array_shift($queries));
}

// Create dedicated Ruins Guild with reserved guildId = 11 if it doesn't exist already and there is at least one no-owner object that needs to be moved
if(isset($moveToRuinsGuild))
{
	$result = $db->query("SELECT EXISTS(SELECT 1 FROM guilds WHERE guildId = 11 AND name = 'Ruins')");
	$exists = $result->fetchArray(SQLITE3_NUM)[0];
	if(!$exists) $db->exec("INSERT INTO guilds (guildId, name, messageOfTheDay, owner, nameLastChangedBy, motdLastChangedBy) VALUES (11, 'Ruins', '', '', -1, -1)");

	// Assign all non-reserved no owner objects to the Ruins guild
	foreach($moveToRuinsGuild as $v) $queries[] = "UPDATE buildings SET owner_id = 11 WHERE owner_id = " . $v['ownerID'];
	echo "Assigning " . count($queries) . " no owner objects to the dedicated Ruins clan...\n";
	while(count($queries) > 0) $db->exec(array_shift($queries));
}

// Run some general performance increasing maintenance commands on the db
// Close and reopen the db to ensure that the previous statements have been fully processed.
$db->close();
$db = new SQLite3(CEDB_PATH . DB_FILE);
$queries[] = "VACUUM";
$queries[] = "ANALYZE";
while(count($queries) > 0) $db->exec(array_shift($queries));
$db->close();

$etime = microtime(true);
$diff = $etime - $stime;
echo "\nDone!\nRequired time: ".round($diff, 3)." sec.\n";
?>