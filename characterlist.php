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
		<title>Update character list</title>
		<link rel='stylesheet' href='../css/formate.css'>
		<link rel='icon' href='../ConanSandbox_0000.ico'>
	</head>
	<body>
		<header>
			<h1>Update character list</h1>
		</header>
		<a href='tilespermember.php'>Update tiles per member list</a></br>
		<a href='characterlist.php'>Update character list</a></br>
		<a href='inactives.php'>Update list of inactive clans/characters and ruins</a></br>
		<a href='processLog.php'>Update the logs</a></br>
		<pre>
<?php
}
else echo 'Updating the characterlist sheet...' . $lb;


// load some commonly used functions
require 'CE_functions.php';

// check if db is found at given path
if(!file_exists(CEDB_PATH)) exit('No database found, skipping script' . $lb);

// set ini variables	
if(file_exists('steamcache.list')) include_once 'steamcache.list';
else ini_set('max_execution_time', 300);

// Get the Google API client and construct the service object and set the spreadsheetId.
require 'google_sheets_client.php';
$client = G_getClient();
$service = new Google_Service_Sheets($client);

// Do some timezone shenanigans to get the offset for the real unix timestamp
$tz = new datetimezone('Etc/GMT');							// where the server is located
$dt = new datetime('now', new datetimezone('Etc/GMT'));		// instanciate an date object to work with
date_default_timezone_set('Etc/GMT');						// use GMT for all future outputs

// Open the SQLite3 db and places the values in a sheets conform array
$db = new SQLite3(CEDB_PATH);

// save all guild and character names for future reference
updateOwnercache($db);

// Read the guilds table into the guilds array
$sql =
	'SELECT guildId, name
	FROM guilds';
$result=$db->query($sql);
while($row = $result->fetchArray(SQLITE3_ASSOC)) $guilds[$row['guildId']]=$row['name'];
$result->finalize();

// Read the characters table directly into values adding guild and steamID info as we go
$sql =
	'SELECT id, char_name, guild, level, playerId, lastTimeOnline
	FROM characters
	ORDER BY lastTimeOnline DESC';
$result=$db->query($sql);
$values[] = ['Last Upload: '.date('d-M-Y H:i').' GMT', '', '']; // add the upload date and database age to the headline
$values[] = ['Character Names', 'Character ID', 'Guild Names', 'Guild ID', 'lvl', 'Steam Name', 'Steam ID', 'Slot', 'Last Login (GMT)'];
while($row = $result->fetchArray(SQLITE3_ASSOC))
{
	if($row['guild'] == NULL)
	{
		$guildId = '';
		$guild = '';
	}
	else
	{
		$guildId = $row['guild'];
		$guild = $ownercache[$row['guild']];
	}
	if(count($values) == 2) $values[0][2] = $lastUpdate = 'Database Date: '.convertTZ($row['lastTimeOnline'], $tz, $dt).' GMT';
	if(strlen($row['playerId']) == 17) $slot = 'active';
	elseif(strlen($row['playerId']) == 18)
	{
		$slot = substr($row['playerId'], -1);
		$row['playerId'] = substr($row['playerId'], 0, -1);
	}
	$values[] = [$ownercache[$row['id']], $row['id'], $guild, $guildId, $row['level'], getSteamName($row['playerId'], $steamcache), $row['playerId'], $slot, convertTZ($row['lastTimeOnline'], $tz, $dt)];
}
$result->finalize();
unset($db);

// if no additional lines were added after the headlines, create a dummy line
if(count($values) == 2) $values[] = ['No characters found!', '', '', '', '', '', '', '', ''];

// cache the learned steamIds in a local file
$handle = fopen('./steamcache.list', 'w+');
$contents = '<?php'.PHP_EOL;
foreach($steamcache as $steamId => $steamName) $contents .= '$steamcache["'.$steamId.'"] = "'.$steamName.'";'.PHP_EOL;
$contents .= '?>';
fwrite($handle, $contents);
fclose($handle);

// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$range = 'Characters!A1:I'.count($values);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => $valueInputOption];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 2, 'firstData' => 3, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 9];

// Build the requests array
G_changeFormat(CHARACTERLIST_SHEET_ID, $requests, 1, $rows['firstData'], 4, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(CHARACTERLIST_SHEET_ID, $requests, 5, $rows['firstData'], 5, $rows['lastData'], 'RIGHT', 'NUMBER');
G_changeFormat(CHARACTERLIST_SHEET_ID, $requests, 6, $rows['firstData'], 8, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(CHARACTERLIST_SHEET_ID, $requests, 9, $rows['firstData'], 9, $rows['lastData'], 'RIGHT', 'DATE_TIME', 'dd-mmm-yyyy hh:mm');
G_setFilterRequest(CHARACTERLIST_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);
G_setGridSize(CHARACTERLIST_SHEET_ID, $requests, $columns['last'], $rows['last'], 2);

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