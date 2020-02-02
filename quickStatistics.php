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

// load some commonly used functions
require 'CE_functions.php';

// check if db is found at given path
if(!file_exists(CEDB_PATH . DB_FILE))
{
	log_line('No database found, skipping script', false, true, false);
	exit('No database found, skipping script' . $lb);
}

// Get the Google API client and construct the service object and set the spreadsheet- and sheetId.
require 'google_sheets_client.php';
$client = G_getClient();
$service = new Google_Service_Sheets($client);

// Do some timezone shenanigans to get the offset for the real unix timestamp
$tz = new datetimezone('Etc/GMT');							// where the server is located
$dt = new datetime('now', new datetimezone('Etc/GMT'));		// instanciate an date object to work with
date_default_timezone_set('Etc/GMT');						// use GMT for all future outputs

// Open the SQLite3 db and places the values in a sheets conform array
$db = new SQLite3(CEDB_PATH . DB_FILE);

// Read in and update the ownercache
updateOwnercache($db);

// Get the last time a player has been online and use that information to determine the db age
$lastUpdate = 'Database Date: '.convertTZ(getLastOnlineTimestamp($db), $tz, $dt).' GMT';
$now = date('d-M-Y H:i', time());
$log = log_line('Updating the tiles per member sheet as of ' . $now . '...');

// Read the amount of tiles per owner into the tiles array
$tiles = getTilescount($db, BY_OWNER, BUILDING_TILE_MULT, PLACEBALE_TILE_MULT);
// Get all the characters with buildings and the number of them within a guild
$active = ACTIVE * ((ALLOWANCE_INCLUDES_INACTIVES + 1) % 2);
$members = getMembers($db, BUILDINGS + $active);

// Create the values array
$whitelist = implode(',', OWNER_WLST);
$sql = 'SELECT DISTINCT owner_id FROM buildings WHERE owner_id NOT IN (' . $whitelist . ')';
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_ASSOC)) if(isset($members[$row['owner_id']])) $values[] = [
		$ownercache[$row['owner_id']],
		$members[$row['owner_id']],
		$tiles[$row['owner_id']],
		round($tiles[$row['owner_id']] / $members[$row['owner_id']]),
		ALLOWANCE_BASE + ($members[$row['owner_id']] - 1) * ALLOWANCE_CLAN];
$result->finalize();

// Order the remaining values by tiles per member then owner then number of tiles and finally coordinates
if(isset($values)) $values = array_orderby($values, '2', SORT_DESC, '3', SORT_DESC, '1');
else $values[] = ['No buildings found!', '', '', '', ''];

// Add the headlines at the top of the table after it has been sorted
array_unshift($values, ['Owner Names', 'Members', 'Tiles', 'Tiles per member', 'Allowance']);
array_unshift($values, ['Last Upload: '.date('d-M-Y H:i').' GMT', '', $lastUpdate]);

// Define some special rows and columns
$rows = ['firstHeadline' => 1, 'lastHeadline' => 2, 'firstData' => 3, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 5];

// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$range = 'Tiles!A1:E'.count($values);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => $valueInputOption];

// Build the requests array
G_unmergeCells(TPM_PLAYERS_SHEET_ID, $requests, 1, $rows['firstData'], 2, $rows['lastData']);
G_setFilterRequest(TPM_PLAYERS_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);
G_setGridSize(TPM_PLAYERS_SHEET_ID, $requests, $columns['last'], $rows['last'], 2);
G_changeFormat(TPM_PLAYERS_SHEET_ID, $requests, 1, $rows['firstData'], 1, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(TPM_PLAYERS_SHEET_ID, $requests, 2, $rows['firstData'], 5, $rows['lastData'], 'CENTER', 'NUMBER', '#,##0');

// Update the spreadsheet
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate(PLAYER_SPREADSHEET_ID, $batchUpdateRequest);
$service->spreadsheets_values->update(PLAYER_SPREADSHEET_ID, $range, $valueRange, $params);
unset($values);

//---------------------------- Activity Statistics ---------------------------//

$log = log_line("Updating the activity statistics sheet...", $log);

const FRAME = 75;
const HOLD_BACK_TIME = 5;

// get current time
$now = time();

// Read the statistics already uploaded
$response = $service->spreadsheets_values->get(PLAYER_SPREADSHEET_ID, 'Activity Statistics!A2:B');
if($response->values) foreach($response->values as $key => $value)
{
	$statsTS = strtotime($value[0]);
	$statistics[$statsTS] = $value;
}

$value[0] = date('D d-M-Y H:i', $now);

// Determine all the characters that have been online (add 15 seconds gap to exclude players who were online when the server shuts down)
$result=$db->query("SELECT id, char_name FROM characters WHERE " . $now . " - lastTimeOnline < " . FRAME);
while($row = $result->fetchArray(SQLITE3_ASSOC)) $charsOnlineLastTenMinutes[$row['id']] = $row['char_name'];
$result->finalize();
if(isset($charsOnlineLastTenMinutes)) $value[1] = count($charsOnlineLastTenMinutes);
else $value[1] = 0;

$db->close();

// Append the new set of statistics and sort it by timestamp
$statistics[$now] = $value;
ksort($statistics);

// Form the values array to pass it over to the google sheets API
$values[0] = ['Date', '#Chars logged in'];

// statistics entries older than the HOLD_BACK_TIME will not be taken over into the values array
$tooOld = $now - HOLD_BACK_TIME * 24 * 60 * 60;
foreach($statistics as $k => $v)
{
	$timestamp = strtotime($v[0]);
	if($timestamp > $tooOld) $values[] = $v;
}

// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$range = 'Activity Statistics!A1:B4321';
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => $valueInputOption];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 1, 'firstData' => 2, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 2];

// Build the requests array
G_setGridSize(ACTIVITY_STATISTICS_SHEET_ID, $requests, $columns['last'], HOLD_BACK_TIME * 144 + 1, 1, true);
G_changeFormat(ACTIVITY_STATISTICS_SHEET_ID, $requests, 1, $rows['firstData'], 1, $rows['lastData'], 'LEFT', 'DATE_TIME', 'ddd dd-mmm-yyyy hh:mm');
G_changeFormat(ACTIVITY_STATISTICS_SHEET_ID, $requests, 2, $rows['firstData'], $columns['last'], $rows['lastData'], 'CENTER', 'NUMBER', '#,##0');

// Update the spreadsheet
$service->spreadsheets_values->update(PLAYER_SPREADSHEET_ID, $range, $valueRange, $params);
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate(PLAYER_SPREADSHEET_ID, $batchUpdateRequest);

//------------------------- Check for restart freeze -------------------------//

$log = log_line("Check for restart freeze...", $log);

$last_edit = filemtime(CEDB_PATH . 'Logs/ConanSandbox.log');
// if more than 4.5 minutes have passed since the last update, check the log file
$freeze = false;
if($now - $last_edit > 270)
{
	// read the last 4 lines of the logfile
	$lines = getLastLines(CEDB_PATH . 'Logs/ConanSandbox.log', 4);
	// if any of the lines differ, we assume that the game didn't freeze
	$freeze = true;
	foreach($lines as $key => $line) if(substr($line, 30) != FREEZE_LOG_LINES[$key]) $freeze = false;
	if($freeze) exec('taskkill /F /FI "WINDOWTITLE eq Conan Exiles"');
}
if($freeze) $log = log_line("Freeze detected (" . ($now - $last_edit) . " seconds since last change to logfile), killing process now...", $log);
else $log = log_line("No freeze detected (" . ($now - $last_edit) . " seconds since last change to logfile).", $log);

$etime = microtime(true);
$diff = $etime - $stime;
echo "Done!" . $lb;
log_line("Required time: ".round($diff, 3)." sec.", $log, true);
?>
