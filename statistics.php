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
echo 'Updating the statistics sheet...' . $lb;

// load some commonly used functions
require 'CE_functions.php';

// check if db is found at given path
if(!file_exists(CEDB_PATH . DB_FILE)) exit('No database found, skipping script' . $lb);

// set ini variables	
if(file_exists('steamcache.list')) include_once 'steamcache.list';
else ini_set('max_execution_time', 300);

// Get the Google API client and construct the service object and set the spreadsheetId.
require 'google_sheets_client.php';
$client = G_getClient();
$service = new Google_Service_Sheets($client);
$spreadsheetId = PLAYER_SPREADSHEET_ID;
$sheetId = STATISTICS_SHEET_ID;

// Do some timezone shenanigans to get the offset for the real unix timestamp
$tz = new datetimezone('Etc/GMT');							// where the server is located
$dt = new datetime('now', new datetimezone('Etc/GMT'));		// instanciate an date object to work with
date_default_timezone_set('Etc/GMT');						// use GMT for all future outputs

// create some constants that are only relevant within this script
const ONE_HOUR = 1*60*60;
const TWENTY_HOURS = 20*60*60;
const TWENTYFOUR_HOURS = 24*60*60;

// Open the SQLite3 db and places the values in a sheets conform array
$db = new SQLite3(CEDB_PATH . DB_FILE);

// Read in and update the ownercache
updateOwnercache($db);
// save all thrall/pet objectIDs and their ownerIDs
updateThrallcache($db);
// get the last time a player has been online and use that information to determine the db age
$lastUpdateTS = getLastOnlineTimestamp($db);

// Read the statistics already uploaded
$redundant = false;
$response = $service->spreadsheets_values->get($spreadsheetId, 'Statistics!A2:N');
if($response->values) foreach($response->values as $key => $value)
{
	$statsTS = strtotime($value[0]);
	if(($lastUpdateTS < ($statsTS + ONE_HOUR)) && ($lastUpdateTS > ($statsTS - ONE_HOUR)))
	{
		$redundant = date('D d-M-Y H:i', $statsTS);
		break;
	}
	$statistics[$statsTS] = $value;
}

if($redundant)
{
	echo "Another set of statistics with a timestamp within one hour of this one is already in the statistics. Stopping execution of the script now!<br>";
	echo "This DBs date: " . date('D d-M-Y H:i', $lastUpdateTS) . " / Date of the set already available: " . $redundant;
	exit;
}

$value[0] = date('D d-M-Y H:i', $lastUpdateTS);

// Read the amount of placeables per owner into the placeables array
$placeables = getTilescount($db, BY_OWNER, 0, 1);
$numPlaceables = array_sum($placeables);
// Read the amount of building tiles per owner into the buildingTiles array
$buildingTiles = getTilescount($db, BY_OWNER, 1, 0);
$numBuildingTiles = array_sum($buildingTiles);

$value[1] = $numAllTiles = $numPlaceables + $numBuildingTiles;
$value[2] = $numPlaceables;
$value[3] = $numBuildingTiles;

// Get all active characters in guilds
$activeCharsInGuilds = getMembers($db, ACTIVE + GUILD, $lastUpdateTS);
$numActiveCharsInGuilds = array_sum($activeCharsInGuilds);
$numActiveGuilds = count($activeCharsInGuilds);
// Get all active characters that are not in guilds
$activeCharsNotInGuilds = getMembers($db, ACTIVE + NOGUILD, $lastUpdateTS);
$numActiveCharsNotInGuilds = array_sum($activeCharsNotInGuilds);
$activeChars = array_merge($activeCharsInGuilds, $activeCharsNotInGuilds);
$numActiveChars = $numActiveCharsInGuilds + $numActiveCharsNotInGuilds;
// Read all building tiles regardless of owner
$allTiles = getTilescount($db);
$numAllTiles = array_sum($allTiles);
// Determine the which tiles belong to active characters and guilds
foreach($activeCharsNotInGuilds as $k => $v) if(isset($allTiles[$k])) $activeCharsNotInGuildTiles[$k] = $allTiles[$k];
$numActiveCharsNotInGuildTiles = array_sum($activeCharsNotInGuildTiles);
foreach($activeCharsInGuilds as $k => $v) if(isset($allTiles[$k])) $activeCharsInGuildTiles[$k] = $allTiles[$k];
$numActiveCharsInGuildTiles = array_sum($activeCharsInGuildTiles);

$value[4] = round($numActiveCharsNotInGuildTiles / $numActiveChars);
$value[5] = round(array_median($activeCharsNotInGuildTiles));
$value[6] = round($numActiveCharsInGuildTiles / $numActiveGuilds);
$value[7] = round(array_median($activeCharsInGuildTiles));

// Get all the inactive characters and the number of them within a guild
$inactiveChars = getMembers($db, INACTIVE, $lastUpdateTS);
$numInactiveChars = array_sum($inactiveChars);
$numAllChars = $numActiveChars + $numInactiveChars;

$value[8] = $numAllChars;
$value[9] = $numActiveChars;
$value[10] = $numInactiveChars;

// Determine all the characters that have been online 
$result=$db->query("SELECT id, char_name FROM characters WHERE " . $lastUpdateTS . " - lastTimeOnline <= " . TWENTYFOUR_HOURS);
while($row = $result->fetchArray(SQLITE3_ASSOC)) $charsOnlineLastTwentyFourHours[$row['id']] = $row['char_name'];
$result->finalize();
$numCharsOnlineLastTwentyFourHours = count($charsOnlineLastTwentyFourHours);

$value[11] = $numCharsOnlineLastTwentyFourHours;

// Read the chatlog from another spreadsheet
$numChatLines = 0;
$response = $service->spreadsheets_values->get('1RV8yjYGvD0jynDSi0PHpo3Y_31zHl83cxPmvhqlyDnk', 'Chat Log!A3:B');
foreach($response->values as $k => $v) if((strtotime($v[0]) > ($lastUpdateTS - TWENTYFOUR_HOURS)) && (strtotime($v[0]) <= $lastUpdateTS)) $numChatLines++;

$value[12] = $numChatLines;

// Create an array with all characters and clans named ruins
$result = $db->query("SELECT guildId FROM guilds WHERE name = 'Ruins' AND guildId <> " . RUINS_CLAN_ID);
while($row = $result->fetchArray(SQLITE3_ASSOC)) $ruins[$row['guildId']] = true;
$result->finalize();
$result = $db->query("SELECT id FROM characters WHERE char_name = 'Ruins'");
while($row = $result->fetchArray(SQLITE3_ASSOC)) $ruins[$row['id']] = true;
$result->finalize();
$result = $db->query("SELECT DISTINCT owner_id FROM buildings WHERE owner_id = " . RUINS_CLAN_ID);
while($row = $result->fetchArray(SQLITE3_ASSOC)) $ruins[$row['owner_id']] = true;
$result->finalize();
if(empty($ruins)) $numRuins = 0; else $numRuins = count($ruins);

$value[13] = $numRuins;

// Append the new set of statistics and sort it by timestamp
$statistics[$lastUpdateTS] = $value;
ksort($statistics);

// Form the values array to pass it over to the google sheets API
$values[0] = ['Date', 'Total #tiles', '#Placeables', '#Buildingtiles', 'Average #tiles per active char', 'Median #tiles per active char', 'Average #tiles per active clan', 'Median #tiles per active clan', 'Total #chars on the server', '#Active chars on the server', '#Inactive chars on the server', '#Chars logged in within 24h', '#Chatlines within 24h', '#Ruins'];
foreach($statistics as $k => $v) $values[] = $v;

// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$range = 'Statistics!A1:N1000';
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => $valueInputOption];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 1, 'firstData' => 2, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 14];

// Build the requests array
G_setGridSize($sheetId, $requests, $columns['last'], 1000, 1, true);
G_changeFormat($sheetId, $requests, 1, $rows['firstData'], 1, $rows['lastData'], 'LEFT', 'DATE_TIME', 'ddd dd-mmm-yyyy hh:mm');
G_changeFormat($sheetId, $requests, 2, $rows['firstData'], $columns['last'], $rows['lastData'], 'CENTER', 'NUMBER', '#,##0');

// Update the spreadsheet
$service->spreadsheets_values->update($spreadsheetId, $range, $valueRange, $params);
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdateRequest);

$etime = microtime(true);
$diff = $etime - $stime;
echo "Done!" . $lb . "Required time: ".round($diff, 3)." sec." . $lb;
?>