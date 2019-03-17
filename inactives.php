<?php
$stime = microtime(true);

if(isset($_SERVER['REMOTE_ADDR']))
{
	$html = true;
	$lb = "<br>";
}
else
{
	$html = false;
	$lb = "\n";
}
if($html)
{
?>
<!doctype html>
<html>
	<head>
		<meta charset='utf-8'>
		<title>Update list of inactive clans/characters and ruins</title>
		<link rel='stylesheet' href='../css/formate.css'>
		<link rel='icon' href='../ConanSandbox_0000.ico'>
	</head>
	<body>
		<header>
			<h1>Update list of inactive clans/characters and ruins</h1>
		</header>
		<a href='tilespermember.php'>Update tiles per member list</a></br>
		<a href='characterlist.php'>Update character list</a></br>
		<a href='inactives.php'>Update list of inactive clans/characters and ruins</a></br>
		<a href='no-owner.php'>Update list of buildings with no owners</a></br>
		<a href='processLog.php'>Update the logs</a></br>
		<pre>
<?php
}		
else echo 'Updating the inactives characters/clans and ruins sheets...' . $lb;

// load some commonly used functions
require 'CE_functions.php';

// check if db is found at given path
if(!file_exists(CEDB_PATH . 'backup-2019.03.17.db')) exit('No database found, skipping script' . $lb);

// Get the Google API client and construct the service object and set the spreadsheetId.
require 'google_sheets_client.php';
$client = G_getClient();
$service = new Google_Service_Sheets($client);

// Do some timezone shenanigans to get the offset for the real unix timestamp
$tz = new datetimezone('Etc/GMT');							// where the server is located
$dt = new datetime('now', new datetimezone('Etc/GMT'));		// instanciate an date object to work with
date_default_timezone_set('Etc/GMT');						// use GMT for all future outputs

// Open the SQLite3 db and places the values in a sheets conform array
$db = new SQLite3(CEDB_PATH . 'backup-2019.03.17.db');

// Read in and update the ownercache
updateOwnercache($db);
// get the last time a player has been online and use that information to determine the db age
$lastUpdate = 'Database Date: '.convertTZ(getLastOnlineTimestamp($db), $tz, $dt).' GMT';
// Read the amount of tiles per owner into the tiles array
$tiles = getTilescount($db, BY_OBJECT);
// Get all active characters or guilds with active members
$active_members = getMembers($db, ACTIVE);
// Get all the inactive characters with buildings and the number of them within a guild
$inactive_members = getMembers($db, INACTIVE+BUILDINGS);

/*********************** Fill the Inactive clans/characters sheet ***********************/

// Create a temporary table with all ruins owned by guilds
$sql = "CREATE TEMPORARY TABLE ruins_owned_by_guilds AS SELECT owner_id, object_id, x, y, z	FROM guilds, buildings, actor_position WHERE name = 'Ruins'	AND actor_position.id = object_id AND guildId = owner_id";
$db->exec($sql);

// Create another temporary table with all ruins owned by characters
$sql = "CREATE TEMPORARY TABLE ruins_owned_by_characters AS SELECT owner_id, object_id, x, y, z	FROM characters, buildings, actor_position WHERE char_name = 'Ruins' AND actor_position.id = object_id AND characters.id = owner_id";
$db->exec($sql);

// combine both tables to get one with all ruins
$sql = "CREATE TEMPORARY TABLE ruins AS SELECT * FROM ruins_owned_by_guilds UNION SELECT * FROM ruins_owned_by_characters";
$db->exec($sql);

$sql = 'SELECT buildings.object_id, owner_id, x, y, z FROM actor_position, buildings WHERE buildings.object_id = id AND owner_id NOT IN (SELECT owner_id FROM ruins)';
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_ASSOC)) if(!isset($active_members[$row['owner_id']]) && isset($inactive_members[$row['owner_id']])) $values[] = [$row['object_id'], $ownercache[$row['owner_id']], $row['owner_id'], $tiles[$row['object_id']], round($row['x'], 0), round($row['y'], 0), round($row['z'], 0)];

if(isset($values))
{
	// consolidate the values to only those with a minimum date('d-M-Y H:i to each other within the same guild
	$values = consolidate($values, 0, 2, 3, 4, 5, 6, MIN_DIST);

	// Sort the arrays by size
	$values = array_orderby($values, '2', '3', SORT_DESC, '4', '5');
	
	// Replace the single coordinates with one Location string
	foreach($values as $k => $v)
	{
		$values[$k][4] = 'TeleportPlayer ' . $v[4] . ' ' . $v[5] . ' ' . $v[6];
		unset($values[$k][5]);
		unset($values[$k][6]);
	}
}
else $values[] = ['No inactive characters or clans found!', '', '', '', ''];

// Add the headlines at the top of the table after it has been sorted
array_unshift($values, ['Object ID', 'Inactive Owner Names', 'Owner ID', 'Tiles', 'Location (Inactivity threshold set to: ' . INACTIVITY . ' days)']);
array_unshift($values, ['Last Upload: '.date('d-M-Y H:i').' GMT', '', $lastUpdate]);

// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$range = 'Inactive owners!A1:E'.count($values);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => $valueInputOption];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 2, 'firstData' => 3, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 5];

// Build the requests array
G_setGridSize(INACTIVES_SHEET_ID, $requests, $columns['last'], $rows['last'], 2);
G_changeFormat(INACTIVES_SHEET_ID, $requests, 1, $rows['firstData'], 3, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(INACTIVES_SHEET_ID, $requests, 4, $rows['firstData'], 4, $rows['lastData'], 'RIGHT', 'NUMBER', '#,##0');
G_changeFormat(INACTIVES_SHEET_ID, $requests, 5, $rows['firstData'], 5, $rows['lastData'], 'LEFT', 'TEXT');
G_deleteGroup(INACTIVES_SHEET_ID, $requests, $rows['firstData'], $rows['lastData']);
G_unhideCells(INACTIVES_SHEET_ID, $requests, $rows['firstData'], $rows['lastData']);
G_addGroupByColumn(INACTIVES_SHEET_ID, $requests, $values, 2, $rows['firstData'], $rows['lastData']);
G_setFilterRequest(INACTIVES_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);

// Update the spreadsheet
$service->spreadsheets_values->update(ADMIN_SPREADSHEET_ID, $range, $valueRange, $params);
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate(ADMIN_SPREADSHEET_ID, $batchUpdateRequest);

/*********************** Fill the Ruins sheet ***********************/

// remove the values from the Inactive clan/characters sheet
unset($values);

// get all the ruins and assemble a new values array from it.
$sql = "SELECT * FROM ruins ORDER BY owner_id";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_ASSOC)) $values[] = [$row['object_id'], $ownercache[$row['owner_id']], $row['owner_id'], $tiles[$row['object_id']], round($row['x'], 0), round($row['y'], 0), round($row['z'], 0)];

if(isset($values))
{
	// Consolidate the list
	$values = consolidate($values, 0, 2, 3, 4, 5, 6, MIN_DIST);
	// Sort the arrays by size
	$values = array_orderby($values, '2', '3', SORT_DESC, '4', '5');
	// Add the headlines at the top of the table after it has been sorted
	// Replace the single coordinates with one Location string
	foreach($values as $k => $v)
	{
		$values[$k][4] = 'TeleportPlayer ' . $v[4] . ' ' . $v[5] . ' ' . $v[6];
		unset($values[$k][5]);
		unset($values[$k][6]);
	}
}
else $values[] = ['No ruins found!', '', '', '', ''];

array_unshift($values, ['Object ID', 'Original owner', 'Owner ID', 'Tiles', 'Location (Inactivity threshold set to: ' . INACTIVITY . ' days)']);
array_unshift($values, ['Last Upload: '.date('d-M-Y H:i').' GMT', '', '', $lastUpdate]);

// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$sheetId = RUINS_SHEET_ID;
$range = 'Ruins!A1:E'.count($values);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => $valueInputOption];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 2, 'firstData' => 3, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 5];

// Build the requests array
G_changeFormat(RUINS_SHEET_ID, $requests, 1, $rows['firstData'], 3, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(RUINS_SHEET_ID, $requests, 4, $rows['firstData'], 4, $rows['lastData'], 'RIGHT', 'NUMBER', '#,##0');
G_changeFormat(RUINS_SHEET_ID, $requests, 5, $rows['firstData'], 5, $rows['lastData'], 'LEFT', 'TEXT');
G_deleteGroup(RUINS_SHEET_ID, $requests, $rows['firstData'], $rows['lastData']);
G_unhideCells(RUINS_SHEET_ID, $requests, $rows['firstData'], $rows['lastData']);
G_addGroupByColumn(RUINS_SHEET_ID, $requests, $values, 2, $rows['firstData'], $rows['lastData']);
G_setFilterRequest(RUINS_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);
G_setGridSize(RUINS_SHEET_ID, $requests, $columns['last'], $rows['last'], 2);

// Update the spreadsheet
$service->spreadsheets_values->update(ADMIN_SPREADSHEET_ID, $range, $valueRange, $params);
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate(ADMIN_SPREADSHEET_ID, $batchUpdateRequest);

// Close the db
unset($db);

$etime = microtime(true);
$diff = $etime - $stime;
echo "Done!" . $lb . "Required time: ".round($diff, 3)." sec." . $lb;
if($html)
{
?>
    </pre>
	</body>
</html>
<?php
}
?>