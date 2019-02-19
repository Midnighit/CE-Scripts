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
		<title>Update list of buildings with no owners</title>
		<link rel='stylesheet' href='../css/formate.css'>
		<link rel='icon' href='../ConanSandbox_0000.ico'>
	</head>
	<body>
		<header>
			<h1>Update list of buildings with no owners</h1>
		</header>
		<a href='tilespermember.php'>Update tiles per member list</a></br>
		<a href='characterlist.php'>Update character list</a></br>
		<a href='inactives.php'>Update list of inactive clans/characters and ruins</a></br>
		<a href='no-owner.php'>Update list of buildings with no owners</a></br>
		<a href='processLog.php'>Update the logs</a></br>
		<pre>
<?php
}			
else echo 'Updating the no owners sheet...' . $lb;

// load some commonly used functions
require 'CE_functions.php';

// check if db is found at given path
if(!file_exists(CEDB_PATH . 'game.db')) exit('No database found, skipping script' . $lb);

// Get the Google API client and construct the service object and set the spreadsheetId.
require 'google_sheets_client.php';
$client = G_getClient();
$service = new Google_Service_Sheets($client);
$spreadsheetId = ADMIN_SPREADSHEET_ID;
$sheetId = NO_OWNER_SHEET_ID;

// Do some timezone shenanigans to get the offset for the real unix timestamp
$tz = new datetimezone('Etc/GMT');							// where the server is located
$dt = new datetime('now', new datetimezone('Etc/GMT'));		// instanciate an date object to work with
date_default_timezone_set('Etc/GMT');						// use GMT for all future outputs

// Open the SQLite3 db and places the values in a sheets conform array
$db=new SQLite3(CEDB_PATH . 'game.db');

// Read the first row of the ordered characters table to get the db age
$lastUpdate = 'Database Date: '.convertTZ(getLastOnlineTimestamp($db), $tz, $dt).' GMT';

// Read in and update the ownercache
updateOwnercache($db);
// Read the amount of tiles per owner into the tiles array
$tiles = getTilescount($db, BY_OBJECT);

// Get all the object_ids that have no corresponding character or guild ID in their respective tables
$sql = "SELECT buildings.object_id AS object_id, buildings.owner_id AS owner_id, x, y, z FROM buildings INNER JOIN actor_position ON buildings.object_id = actor_position.id WHERE buildings.owner_id NOT IN (SELECT characters.id FROM characters) AND buildings.owner_id NOT IN (SELECT guilds.guildId FROM guilds) ORDER BY owner_id";
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_ASSOC)) $noOwners[$row['object_id']] = ['Owner' => $row['owner_id'], 'x' => $row['x'], 'y' => $row['y'], 'z' => $row['z']];
$result->finalize();
// Close the db
unset($db);

if(isset($noOwners))
{
	// try to retrieve the original owner name from the cache
	foreach($noOwners as $objectId => $value) (isset($ownercache[$value['Owner']]) ? $noOwners[$objectId]['Name'] = $ownercache[$value['Owner']] : $noOwners[$objectId]['Name'] = 'Unknown');
	// assemble the values array
	foreach($noOwners as $objectId => $value) $values[] = [$objectId, $value['Name'], $value['Owner'], $tiles[$objectId], round($value['x'], 0), round($value['y'], 0), round($value['z'], 0)];
	$values = consolidate($values, 0, 2, 3, 4, 5, 6, MIN_DIST);
	// Sort the arrays by owner and then by location
	$values = array_orderby($values, '2', '3', SORT_DESC, '4', '5');
	// Replace the single coordinates with one Location string
	foreach($values as $k => $v)
	{
		$values[$k][4] = 'TeleportPlayer ' . $v[4] . ' ' . $v[5] . ' ' . $v[6];
		unset($values[$k][5]);
		unset($values[$k][6]);
	}
}
else $values[] = ['No no owner objects found!', '', '', '', ''];

// Add the headlines at the top of the table after it has been sorted
array_unshift($values, ['Object ID', 'Original owner Names', 'Owner ID', 'Tiles', 'Location']);
array_unshift($values, ['Last Upload: '.date('d-M-Y H:i').' GMT', '', $lastUpdate]);

// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$range = 'No owner objects!A1:E'.count($values);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => $valueInputOption];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 2, 'firstData' => 3, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 5];

// Build the requests array
G_changeFormat($sheetId, $requests, 1, $rows['firstData'], 3, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat($sheetId, $requests, 4, $rows['firstData'], 4, $rows['lastData'], 'RIGHT', 'NUMBER', '#,##0');
G_changeFormat($sheetId, $requests, 5, $rows['firstData'], 5, $rows['lastData'], 'LEFT', 'TEXT');
G_deleteGroup($sheetId, $requests, $rows['firstData'], $rows['lastData']);
G_unhideCells($sheetId, $requests, $rows['firstData'], $rows['lastData']);
G_addGroupByColumn($sheetId, $requests, $values, 2, $rows['firstData'], $rows['lastData']);
G_setFilterRequest($sheetId, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);
G_setGridSize($sheetId, $requests, $columns['last'], $rows['last'], 2);

// Update the spreadsheet
$service->spreadsheets_values->update($spreadsheetId, $range, $valueRange, $params);
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);

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