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
		<title>Update tiles per member list</title>
		<link rel='stylesheet' href='../css/formate.css'>
		<link rel='icon' href='../ConanSandbox_0000.ico'>
	</head>
	<body>
		<header>
			<h1>Update tiles per member list</h1>
		</header>
		<a href='tilespermember.php'>Update tiles per member list</a></br>
		<a href='characterlist.php'>Update character list</a></br>
		<a href='inactives.php'>Update list of inactive clans/characters and ruins</a></br>
		<a href='processLog.php'>Update the logs</a></br>
		<pre>
<?php
}
else echo 'Updating the tiles per member sheet...' . $lb;

// load some commonly used functions
require 'CE_functions.php';

// check if db is found at given path
if(!file_exists(CEDB_PATH)) exit('No database found, skipping script' . $lb);

// Get the Google API client and construct the service object and set the spreadsheet- and sheetId.
require 'google_sheets_client.php';
$client = G_getClient();
$service = new Google_Service_Sheets($client);

// Do some timezone shenanigans to get the offset for the real unix timestamp
$tz = new datetimezone('Etc/GMT');							// where the server is located
$dt = new datetime('now', new datetimezone('Etc/GMT'));		// instanciate an date object to work with
date_default_timezone_set('Etc/GMT');						// use GMT for all future outputs

// Open the SQLite3 db and places the values in a sheets conform array
$db = new SQLite3(CEDB_PATH);

// Read in and update the ownercache
updateOwnercache($db);
// Get the last time a player has been online and use that information to determine the db age
$lastUpdate = 'Database Date: '.convertTZ(getLastOnlineTimestamp($db), $tz, $dt).' GMT';
// Read the amount of tiles per owner into another tiles array
$tilesOwn = getTilescount($db, BY_OWNER);
// Read the amount of tiles per object into another tiles array
$tilesObj = getTilescount($db, BY_OBJECT);
// Get all the active characters with buildings and the number of them within a guild
$members = getMembers($db, ACTIVE+BUILDINGS);

// Create the values array
$sql = 'SELECT object_id, owner_id, class, x, y, z FROM buildings, actor_position WHERE object_id = id';
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_ASSOC)) if(isset($members[$row['owner_id']])) $values[] = [$row['object_id'], $ownercache[$row['owner_id']], $row['owner_id'], $tilesObj[$row['object_id']], $members[$row['owner_id']], round($tilesOwn[$row['owner_id']] / $members[$row['owner_id']], 0), getClassName($row['class']), round($row['x'], 0), round($row['y'], 0), round($row['z'], 0)];
$result->finalize();

if(isset($values))
{
	// consolidate the values to only those with a minimum distance to each other within the same guild
	$values = consolidate($values, 0, 2, 3, 7, 8, 9, MIN_DIST);

	// Order the remaining values by tiles per member then owner then number of tiles and finally coordinates
	$values = array_orderby($values, '5', SORT_DESC, '2', '3', SORT_DESC, '7', '8');

	// Replace the single coordinates with one Location string
	foreach($values as $k => $v)
	{
		$values[$k][7] = 'TeleportPlayer ' . $v[7] . ' ' . $v[8] . ' ' . $v[9];
		unset($values[$k][8]);
		unset($values[$k][9]);
	}

	unset($db);
}
else $values[] = ['No owner objects found!', '', '', '', '', '', '', ''];

// Add the headlines at the top of the table after it has been sorted
array_unshift($values, ['Object ID', 'Owner Names', 'Owner ID', 'Tiles', 'Members', 'Tiles per member', 'Item Class', 'Location']);
array_unshift($values, ['Last Upload: '.date('d-M-Y H:i').' GMT', '', $lastUpdate]);

// Define some special rows and columns
$rows = ['firstHeadline' => 1, 'lastHeadline' => 2, 'firstData' => 3, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 8];

// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$range = 'Tiles per member!A1:H'.count($values);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => $valueInputOption];

// Build the requests array
G_unmergeCells(TILES_PER_MEMBER_SHEET_ID, $requests, 1, $rows['firstData'], 2, $rows['lastData']);
G_changeFormat(TILES_PER_MEMBER_SHEET_ID, $requests, 1, $rows['firstData'], 3, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(TILES_PER_MEMBER_SHEET_ID, $requests, 4, $rows['firstData'], 6, $rows['lastData'], 'RIGHT', 'NUMBER', '#,##0');
G_changeFormat(TILES_PER_MEMBER_SHEET_ID, $requests, 7, $rows['firstData'], 8, $rows['lastData'], 'LEFT', 'TEXT');
G_deleteGroup(TILES_PER_MEMBER_SHEET_ID, $requests, $rows['firstData'], $rows['lastData']);
G_unhideCells(TILES_PER_MEMBER_SHEET_ID, $requests, $rows['firstData'], $rows['lastData']);
G_addGroupByColumn(TILES_PER_MEMBER_SHEET_ID, $requests, $values, 2, $rows['firstData'], $rows['lastData']);
G_setFilterRequest(TILES_PER_MEMBER_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);
G_setGridSize(TILES_PER_MEMBER_SHEET_ID, $requests, $columns['last'], $rows['last'], 2);

// Update the spreadsheet
$service->spreadsheets_values->update(ADMIN_SPREADSHEET_ID, $range, $valueRange, $params);
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate(ADMIN_SPREADSHEET_ID, $batchUpdateRequest);

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
