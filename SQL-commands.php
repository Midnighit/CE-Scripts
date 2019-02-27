<?php

// load some commonly used functions
require 'CE_functions.php';

// check if db is found at given path
if(!file_exists(CEDB_PATH . 'game.db')) exit("No database found, skipping script\n");

/************************************ ADD QUERIES HERE ************************************/


// CREATE DUMMY GUILD:
// Example: INSERT INTO guilds (guildID, name, owner) VALUES (<UNUSED GUILDID>, '<GUILD NAME>', 0)
// $queries[] = "INSERT INTO guilds (guildID, name, owner) VALUES (2, 'Ruins', 0)";

// GRANT CLAIMS:
// Example: UPDATE buildings SET owner_id = <NEW OWNER ID> WHERE owner_id = <PREVIOUS OWNER ID>
// $queries[] = "UPDATE buildings SET owner_id = 994992 WHERE owner_id = 2069";

// GRANT CLAIMS BELONGING TO NO OWNER:
// Example: UPDATE buildings SET owner_id = <NEW OWNER ID> WHERE owner_id = 2 AND object_id IN ( SELECT id FROM actor_position WHERE (x BETWEEN <LOW X> AND <HIGH X>) AND (y BETWEEN <LOW Y> AND <HIGH Y>) )
// $queries[] = "UPDATE buildings SET owner_id = 4 WHERE owner_id = 713170 AND object_id IN ( SELECT id FROM actor_position WHERE (x BETWEEN -120000 AND -100000) AND (y BETWEEN -65000 AND -45000) )";

// CHANGE A CHARACTER TIMESTAMP FOR THE RUINS SCRIPT
// $queries[] = "UPDATE characters SET lastTimeOnline = strftime('%s','now') WHERE id = 527209";

// SWITCH ACCOUNTS FOR CHARACTERS:
// Example: UPDATE characters SET playerId = '<STEAM64ID>' WHERE id = <CHARACTER ID>
// $queries[] = "UPDATE characters SET playerId = '76561198023983194' WHERE id = 664367";
// $queries[] = "UPDATE characters SET playerId = '76561198855528142' WHERE id = 775190";

// RENAME A GUILD
// Example: UPDATE guilds SET name = '<NEW NAME>' WHERE guildId = <GUILD ID>
// $queries[] = "UPDATE guilds SET name = 'Spillway' WHERE guildId = 3";

// Remove items/emotes from everyone in the game
// $queries[] = "DELETE FROM item_inventory WHERE template_id=105"; // 105 = sitting emote

// REMOVE ALL OBJECTS BELONGING TO A SPECIFIC OWNER
/*
$queries[] = "CREATE TEMPORARY table objects_remove AS SELECT object_id FROM buildings WHERE owner_id = 50685";
$queries[] = "DELETE FROM destruction_history WHERE object_id IN (SELECT object_id FROM objects_remove)";
$queries[] = "DELETE FROM properties WHERE object_id IN (SELECT object_id FROM objects_remove)";
$queries[] = "DELETE FROM buildable_health WHERE object_id IN (SELECT object_id FROM objects_remove)";
$queries[] = "DELETE FROM building_instances WHERE object_id IN (SELECT object_id FROM objects_remove)";
$queries[] = "DELETE FROM buildings WHERE object_id IN (SELECT object_id FROM objects_remove)";
$queries[] = "DELETE FROM actor_position WHERE id IN (SELECT object_id FROM objects_remove)";
*/

// REMOVE ALL UNLOCK PLUS ITEMS FROM THE DB
/*
$queries[] = "DELETE FROM destruction_history WHERE object_id IN (SELECT id FROM actor_position WHERE class LIKE '%UnlockPlus%')";
$queries[] = "DELETE FROM properties WHERE object_id IN (SELECT id FROM actor_position WHERE class LIKE '%UnlockPlus%') OR name LIKE '%UnlockPlus%'";
$queries[] = "DELETE FROM buildable_health WHERE object_id IN (SELECT id FROM actor_position WHERE class LIKE '%UnlockPlus%')";
$queries[] = "DELETE FROM building_instances WHERE object_id IN (SELECT id FROM actor_position WHERE class LIKE '%UnlockPlus%')";
$queries[] = "DELETE FROM buildings WHERE object_id IN (SELECT id FROM actor_position WHERE class LIKE '%UnlockPlus%')";
$queries[] = "DELETE FROM actor_position WHERE id IN (SELECT id FROM actor_position WHERE class LIKE '%UnlockPlus%')";
$queries[] = "DELETE FROM item_inventory WHERE template_id = 376580852";
*/

// REMOVE ALL BUILDINGS BELONGING TO A GIVEN OWNER FROM THE CURRENT DB AND THEN RESTORE THEM FROM OLDER DB
// HOWTO:
// Copy an older version of the db that still contains the buildings into the saved folder as backup.db.
// Enter the owner_id for the owner whose buildings should be restored to the older db in the two temporary table queries.

/*
$queries[] = "ATTACH DATABASE '" . CEDB_PATH . "backup.db' AS bak";

// UNCOMMENT ONLY ONE:

// THE TWO LINES DIRECTLY BELOW THIS TO RESTORE A WHOLE CLANS BUILDING...
// $queries[] = "CREATE TEMPORARY table objects_remove AS SELECT object_id FROM buildings WHERE owner_id = 493575";
// $queries[] = "CREATE TEMPORARY table objects_restore AS SELECT object_id FROM bak.buildings WHERE owner_id = 493575";

// ...OR THE LINES BELOW THIS TO RESTORE ALL BUILDINGS BELONGING TO A SPECIFIC CLAN AND LOCATED IN A SPECIFIC AREA
// $queries[] = "CREATE TEMPORARY table objects_remove AS SELECT class, object_id FROM buildings, actor_position WHERE owner_id = 493575 AND object_id = id AND (x BETWEEN 186506 AND 187563) AND (y BETWEEN 128779 AND 130007) AND z > -19830";
// $queries[] = "CREATE TEMPORARY table objects_restore AS SELECT class, object_id FROM bak.buildings, bak.actor_position WHERE owner_id = 493575 AND object_id = id AND (x BETWEEN 186506 AND 187563) AND (y BETWEEN 128779 AND 130007) AND z > -19830";

$queries[] = "DELETE FROM destruction_history WHERE object_id IN (SELECT object_id FROM objects_remove)";
$queries[] = "DELETE FROM properties WHERE object_id IN (SELECT object_id FROM objects_remove)";
$queries[] = "DELETE FROM buildable_health WHERE object_id IN (SELECT object_id FROM objects_remove)";
$queries[] = "DELETE FROM building_instances WHERE object_id IN (SELECT object_id FROM objects_remove)";
$queries[] = "DELETE FROM buildings WHERE object_id IN (SELECT object_id FROM objects_remove)";
$queries[] = "DELETE FROM actor_position WHERE id IN (SELECT object_id FROM objects_remove)";

$queries[] = "INSERT INTO destruction_history SELECT * FROM bak.destruction_history WHERE object_id IN (SELECT object_id FROM objects_restore)";
$queries[] = "INSERT INTO properties SELECT * FROM bak.properties WHERE object_id IN (SELECT object_id FROM objects_restore)";
$queries[] = "INSERT INTO buildable_health SELECT * FROM bak.buildable_health WHERE object_id IN (SELECT object_id FROM objects_restore)";
$queries[] = "INSERT INTO building_instances SELECT * FROM bak.building_instances WHERE object_id IN (SELECT object_id FROM objects_restore)";
$queries[] = "INSERT INTO buildings SELECT * FROM bak.buildings WHERE object_id IN (SELECT object_id FROM objects_restore)";
$queries[] = "INSERT INTO actor_position SELECT * FROM bak.actor_position WHERE id IN (SELECT object_id FROM objects_restore)";
$queries[] = "INSERT INTO item_inventory SELECT * FROM bak.item_inventory WHERE owner_id IN (SELECT object_id FROM objects_restore)";
$queries[] = "INSERT INTO item_properties SELECT * FROM bak.item_properties WHERE owner_id IN (SELECT object_id FROM objects_restore)";
*/

/******************************************************************************************/

if(isset($queries) && count($queries) > 0)
{
	$stime = microtime(true);
	echo "Run some SQL scripts\n";

	// Open the SQLite3 db and places the values in a sheets conform array
	$db = new SQLite3(CEDB_PATH . 'game.db');

	// execute queries.
	while(count($queries) > 0) $db->exec(array_shift($queries));

	$db->close();

	$etime = microtime(true);
	$diff = $etime - $stime;
	echo "\nDone!\nRequired time: ".round($diff, 3)." sec.\n";
}
?>