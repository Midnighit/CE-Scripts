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
		<title>Update the logs</title>
		<link rel='stylesheet' href='../css/formate.css'>
		<link rel='icon' href='../ConanSandbox_0000.ico'>
	</head>
	<body>
		<header>
			<h1>Update the logs</h1>
		</header>
		<a href='tilespermember.php'>Update tiles per member list</a></br>
		<a href='characterlist.php'>Update character list</a></br>
		<a href='inactives.php'>Update list of inactive clans/characters and ruins</a></br>
		<a href='processLog.php'>Update the logs</a></br>
		<pre>
<?php
}
else echo 'updating the logs...' . $lb;

// load some commonly used functions
require 'CE_functions.php';

// check if db is found at given path
if(!file_exists(CEDB_PATH . DB_FILE)) exit('No database found, skipping script' . $lb);

function getName($input)
{
	preg_match('/,\s"([^"]+)"\)/', stripcslashes($input), $result);
	return $result[1];
}

// define constants and set ini variables
ini_set("memory_limit","1000M");
ini_set('max_execution_time', 300);
date_default_timezone_set('Etc/GMT');
const HOLD_BACK_TIME = 7;

$dir = scandir(CEDB_PATH . 'Logs/');
$queue = array();
if(file_exists('steamcache.list')) include 'steamcache.list';

foreach($dir as $filename) if(preg_match('/ConanSandbox[\w\d\-.]*\.log$/', $filename))
{
	$log = file(CEDB_PATH . 'Logs/' . $filename);
	echo "Processing ".$filename." please wait..." . $lb;

	foreach($log as $num => &$line)
	{
		// Example Chat
		// Vanilla => [2018.07.16-13.33.23:827][ 80]ChatWindow: Character Kimifae said: "We are at war with them, as are many in these lands. But they killed our Blacksmith, a revered role of our tribe."
		// Pippi   => [2019.12.20-17.59.39:226][Pippi]PippiChat: Birger said in channel [Global]: Wtf
		// Pippi   => [2019.12.20-17.48.59:412][Pippi]PippiChat: Broga said in channel [The Golden Falls]: Heyo
		// Pippi   => [2019.12.20-17.53.00:048][Pippi]PippiChat: Aera said in channel [Tarcus:Aera]: this char has
		if(substr($line, 32, 10) == "PippiChat:")
		{
			$date = substr($line, 1, 4) . '-' . substr($line, 6, 2) . '-' . substr($line, 9, 2);	// YYYY-MM-DD (ISO 8601)
			$time = substr($line, 12, 2) . ':' . substr($line, 15, 2) . ':' . substr($line, 18, 2);	// HH:MM:SS
			$timestamp = strtotime($date.' '.$time);
			$name = substr($line, 43, strpos($line, " said in channel", 43) - 43);
			$channel = substr($line, strpos($line, " said in channel [") + 18, strpos($line, "]: ") - (strpos($line, " said in channel [") + 18));
			$chat = preg_replace(['/(["])/', '/(^[=])/', "/([;\n\r])/"], ["'", ' ='], substr($line, strpos($line, "]: ") + 3));
			$chatlog[$timestamp] = ['Date' => $date, 'Time' => $time, 'Name' => $name, 'Channel' => $channel, 'Chat' => $chat];
		}
		// Example Login
		// [2018.07.16-14.44.39:823][550]LogNet: AddClientConnection: Added client connection: [UNetConnection] RemoteAddr: 99.10.207.47:59233, Name: SteamNetConnection_15, Driver: GameNetDriver SteamNetDriver_0, IsServer: YES, PC: NULL, Owner: NULL
		// [2018.07.16-14.44.39:923][553]LogNet: Login request: /Game/Maps/Startup?SteamAuthTicket=140<longstring>E9?Password=<password>?Ping=67?Name=Geneticdork?dw_user_id=76561198028718660?mod_list=30f11921313d9cd304987f84d023b9cd userId: 76561198028718660
		elseif(substr($line, 30, 28) == "LogNet: AddClientConnection:")
		{
			$date = substr($line, 1, 4) . '-' . substr($line, 6, 2) . '-' . substr($line, 9, 2);	// YYYY-MM-DD (ISO 8601)
			$time = substr($line, 12, 2) . ':' . substr($line, 15, 2) . ':' . substr($line, 18, 2);	// HH:MM:SS
			$timestamp = strtotime($date.' '.$time);
			preg_match('/RemoteAddr:\s(?<IP>\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})*/', $line, $match);
			$IPAddr = $match['IP'];
			array_push($queue, ['IP' => $IPAddr, 'Last Login' => date('d-M-Y H:i:s', $timestamp), 'timestamp' => $timestamp]);
		}
		elseif(substr($line, 30, 22) == "LogNet: Login request:")
		{
			$date = substr($line, 1, 4) . '-' . substr($line, 6, 2) . '-' . substr($line, 9, 2);	// YYYY-MM-DD (ISO 8601)
			$time = substr($line, 12, 2) . ':' . substr($line, 15, 2) . ':' . substr($line, 18, 2);	// HH:MM:SS
			$timestamp = strtotime($date . ' ' . $time);
			if(count($queue) > 0 && ($data = array_shift($queue))['timestamp'] >= $timestamp - 1)
			{
				$matches = preg_split('/\?/', $line);
				foreach($matches as $match)
				{
					if(substr($match, 0, 5) == 'Name=') $name = preg_replace(["/([;'])/", '/(["])/'], ['', "'"], substr($match, 5));
					elseif(substr($match, 0, 11) == 'dw_user_id=') $steamID = substr($match, 11);
				}
				// if cached Steam names don't exist or have changed, update them in the cache
				if(!isset($steamcache[$steamID]) || (isset($steamcache[$steamID]) && $steamcache[$steamID] != $name)) $steamcache[$steamID] = $name;
				$IPLog[$timestamp]['Steam Name'] = $name;
				$IPLog[$timestamp]['Steam ID'] = $steamID;
				$IPLog[$timestamp]['IP'] = $data['IP'];
				$IPLog[$timestamp]['Last Login'] = $data['Last Login'];
			}
		}
	}
}
unset($log);

foreach($dir as $filename) if(preg_match('/ServerCommandLog[\w\d\-.]*\.log$/', $filename))
{
	$log = file(CEDB_PATH . 'Logs/' . $filename);
	echo "Processing ".$filename." please wait..." . $lb;

	foreach($log as $num => &$line)
	{
		// Example SpawnItem
		// [2018.09.01-14.31.15:844][841]Player Randel used command: SpawnItem 52006 1 (player is admin)
		preg_match('/Player (\w+) used command: SpawnItem ([\-]?\d+) (\d+)/', $line, $matches);
		if($matches)
		{
			$date = substr($line, 1, 4) . '-' . substr($line, 6, 2) . '-' . substr($line, 9, 2);	// YYYY-MM-DD (ISO 8601)
			$timestamp = strtotime($date);
			list($junk, $name, $itemID, $amount) = $matches;
			if(!isset($commandLog[$timestamp][$name][$itemID]))
			{
				$commandLog[$timestamp][$name][$itemID]['Amount'] = $amount;
				$commandLog[$timestamp][$name][$itemID]['Date'] = $date;
				if(isset($item[$itemID])) $commandLog[$timestamp][$name][$itemID]['ItemName'] = $item[$itemID];
				elseif($itemID < 0) $commandLog[$timestamp][$name][$itemID]['ItemName'] = 'Pippi item';
				elseif($itemID < 10000) $commandLog[$timestamp][$name][$itemID]['ItemName'] = 'DLC Item';
				elseif($itemID > 92000) $commandLog[$timestamp][$name][$itemID]['ItemName'] = 'Mod Item';
				else $commandLog[$timestamp][$name][$itemID]['ItemName'] = 'Unknown';
			}
			else $commandLog[$timestamp][$name][$itemID]['Amount'] += $amount;
		}
	}
}

// write back any changes that might have occured to the cache file.
$handle = fopen('./steamcache.list', 'w+');
$contents = '<?php'.PHP_EOL;
foreach($steamcache as $steamId => $steamName) $contents .= '$steamcache["'.$steamId.'"] = "'.$steamName.'";'.PHP_EOL;
$contents .= '?>';
fwrite($handle, $contents);
fclose($handle);

// Open the SQLite3 db and places the values in a sheets conform array
$db = new SQLite3(CEDB_PATH . DB_FILE);

// Read the characters table to determine the names of the characters belonging to the steam IDs
$sql = 'SELECT playerId, char_name FROM characters';
$result = $db->query($sql);
while($row = $result->fetchArray(SQLITE3_ASSOC)) $characters[$row['playerId']] = $row['char_name'];
$result->finalize();
unset($db);

if(isset($IPLog)) foreach($IPLog as $timestamp => $value)
{
	if(isset($characters[$value['Steam ID']])) $IPLog[$timestamp]['Character Name'] = $characters[$value['Steam ID']];
	else $IPLog[$timestamp]['Character Name'] = 'N/A';
}

// Get the Google API client and construct the service object and set the spreadsheetId.
require 'google_sheets_client.php';
$client = G_getClient();
$service = new Google_Service_Sheets($client);

// Read the chatlog that's already uploaded
$response = $service->spreadsheets_values->get( LOGS_SPREADSHEET_ID, 'Chat Log!A3:E');
if($response->values) foreach($response->values as $key => $value)
{
	if(!isset($value[4])) $value[4] = '';
	$chatlog[strtotime($value[0] . ' ' . $value[1])] = ['Date' => $value[0], 'Time' => $value[1], 'Name' => $value[2], 'Channel' => $value[3], 'Chat' => $value[4]];
}

$values[] = ['Last Upload: '.date('d-M-Y H:i').' GMT', '', '', 'Hold back time: ' . HOLD_BACK_TIME];
$values[] = ['Date', 'Time', 'Name', 'Channel', 'Chat message'];

if(isset($chatlog))
{
	ksort($chatlog);
	// chat entries older than the HOLD_BACK_TIME will not be taken over into the values array
	$tooOld = time() - HOLD_BACK_TIME * 24 * 60 * 60;
	foreach($chatlog as $line => $value)
	{
		$timestamp = strtotime($value['Date'] . ' ' . $value['Time']);
		if($timestamp > $tooOld) $values[] = [date('d-M-Y', $timestamp), $value['Time'], $value['Name'], $value['Channel'], $value['Chat']];
	}

	// Set parameters for the spreadsheet update
	$valueInputOption = 'USER_ENTERED';
	$range = 'Chat Log!A1:E'.count($values);
	$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
	$params = ['valueInputOption' => $valueInputOption];
	$rows = ['firstHeadline' => 1, 'lastHeadline' => 2, 'firstData' => 3, 'lastData' => count($values), 'last' => count($values)];
	$columns = ['first' => 1, 'last' => 5];

	// Build the requests array
	G_setGridSize(CHAT_LOG_SHEET_ID, $requests, $columns['last'], $rows['last'], 2);
	G_changeFormat(CHAT_LOG_SHEET_ID, $requests, 3, $rows['firstData'], 4, $rows['lastData'], 'LEFT', 'TEXT');
	G_setFilterRequest(CHAT_LOG_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);

	// Update the spreadsheet
	$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
	$response = $service->spreadsheets->batchUpdate( LOGS_SPREADSHEET_ID, $batchUpdateRequest);
	$service->spreadsheets_values->update( LOGS_SPREADSHEET_ID, $range, $valueRange, $params);
	unset($values);
}

// Read the IPlog that's already uploaded
$response = $service->spreadsheets_values->get( LOGS_SPREADSHEET_ID, 'IP Log!A3:E');
if($response->values) foreach($response->values as $key => $value)
{
	$names = explode(' / ', $value[1]);
	$steamIDs = explode(' / ', $value[2]);
	$steamNames = explode(' / ', $value[3]);
	foreach($steamIDs as $key => $steamID)
	{
		$timestamp = strtotime($value[4]);
		$index = $timestamp + $key;
		$IPLog[$index] = ['IP' => $value[0], 'Character Name' => $names[$key], 'Steam ID' => $steamID, 'Steam Name' => $steamNames[$key], 'Last Login' => $value[4]];
	}
}

if(isset($IPList))
{
	ksort($IPLog);

	// Find duplicate IPs with different Steam IDs
	foreach($IPLog as $value)
	{
		// IP hasn't been added to IPList yet.
		if(!isset($IPList[$value['IP']]))
		{
			$IPList[$value['IP']]['Character Name'][0] = $value['Character Name'];
			$IPList[$value['IP']]['Steam ID'][0] = $value['Steam ID'];
			$IPList[$value['IP']]['Steam Name'][0] = $value['Steam Name'];
			$IPList[$value['IP']]['Last Login'] = $value['Last Login'];
		}
		// IP has been added but character is yet unknown.
		elseif(!in_array($value['Steam ID'], $IPList[$value['IP']]['Steam ID']))
		{
			$IPList[$value['IP']]['Character Name'][] = $value['Character Name'];
			$IPList[$value['IP']]['Steam ID'][] = $value['Steam ID'];
			$IPList[$value['IP']]['Steam Name'][] = $value['Steam Name'];
			if(strtotime($value['Last Login']) > strtotime($IPList[$value['IP']]['Last Login']));
		}
		elseif(strtotime($value['Last Login']) > strtotime($IPList[$value['IP']]['Last Login']));
	}

	foreach($IPList as $key => $value)
	{
		$IPList[$key]['Character Name'] = implode(' / ', $value['Character Name']);
		$IPList[$key]['Steam ID'] = implode(' / ', $value['Steam ID']);
		$IPList[$key]['Steam Name'] = implode(' / ', $value['Steam Name']);
	}

	$values[] = ['Last Upload: '.date('d-M-Y H:i').' GMT', '', '', '', ''];
	$values[] = ['IP Address', 'Character Name', 'Steam ID', 'Steam Name', 'Last Login (GMT)'];
	foreach($IPList as $key => $value) $values[] = [$key, $value['Character Name'], $value['Steam ID'], $value['Steam Name'], $value['Last Login']];

	// Set parameters for the spreadsheet update
	$valueInputOption = 'USER_ENTERED';
	$range = 'IP Log!A1:E'.count($values);
	$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
	$params = ['valueInputOption' => $valueInputOption];
	$rows = ['firstHeadline' => 1, 'lastHeadline' => 2, 'firstData' => 3, 'lastData' => count($values), 'last' => count($values)];
	$columns = ['first' => 1, 'last' => 5];

	// Build the requests array
	G_setGridSize(IP_LOG_SHEET_ID, $requests, $columns['last'], $rows['last'], 2);
	G_setFilterRequest(IP_LOG_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);
	G_changeFormat(IP_LOG_SHEET_ID, $requests, 1, $rows['firstData'], 4, $rows['lastData'], 'LEFT', 'TEXT');
	G_changeFormat(IP_LOG_SHEET_ID, $requests, 5, $rows['firstData'], 5, $rows['lastData'], 'RIGHT', 'DATE_TIME', 'dd-mmm-yyyy hh:mm:ss');

	// Update the spreadsheet
	$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
	$response = $service->spreadsheets->batchUpdate( LOGS_SPREADSHEET_ID, $batchUpdateRequest);
	$service->spreadsheets_values->update( LOGS_SPREADSHEET_ID, $range, $valueRange, $params);
	unset($values);
}

// Read the CommandLog that's already uploaded
$response = $service->spreadsheets_values->get( LOGS_SPREADSHEET_ID, 'Command Log!A3:E');
if($response->values) foreach($response->values as $key => $value)
{
	if(!isset($value[4])) $value[4] = "Unknown";
	$commandLog[strtotime($value[0])][$value[1]][$value[2]]['Amount'] = $value[4];
	$commandLog[strtotime($value[0])][$value[1]][$value[2]]['ItemName'] = $value[3];
	$commandLog[strtotime($value[0])][$value[1]][$value[2]]['Date'] = $value[0];
}

$values[] = ['Last Upload: '.date('d-M-Y H:i').' GMT', '', '', 'Hold back time: ' . HOLD_BACK_TIME];
$values[] = ['Date', 'Account', 'Item ID', 'Item Name', 'Amount'];

if(isset($commandLog))
{
	ksort($commandLog);
	// log entries older than the HOLD_BACK_TIME will not be taken over into the values array
	$tooOld = time() - HOLD_BACK_TIME * 24 * 60 * 60;
	// for each day
	foreach($commandLog as $timestamp => $names)
	{
		// check if the date isn't older than what HOLD_BACK_TIME dictates and then for each name
		if($timestamp > $tooOld) foreach($names as $name => $itemIDs)
		{
			// and for each of the itemIDs assign the values
			foreach($itemIDs as $itemID => $value) $values[] = [$value['Date'], $name, $itemID, $value['ItemName'], $value['Amount']];
		}
	}

	// Set parameters for the spreadsheet update
	$valueInputOption = 'USER_ENTERED';
	$range = 'Command Log!A1:E'.count($values);
	$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
	$params = ['valueInputOption' => $valueInputOption];
	$rows = ['firstHeadline' => 1, 'lastHeadline' => 2, 'firstData' => 3, 'lastData' => count($values), 'last' => count($values)];
	$columns = ['first' => 1, 'last' => 5];

	// Build the requests array
	G_setGridSize(COMMAND_LOG_ID, $requests, $columns['last'], $rows['last'], 2);
	G_setFilterRequest(COMMAND_LOG_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);
	G_changeFormat(COMMAND_LOG_ID, $requests, 1, $rows['firstData'], 1, $rows['lastData'], 'RIGHT', 'DATE_TIME', 'dd-mmm-yyyy');
	G_changeFormat(COMMAND_LOG_ID, $requests, 2, $rows['firstData'], 4, $rows['lastData'], 'LEFT', 'TEXT');
	G_changeFormat(COMMAND_LOG_ID, $requests, 5, $rows['firstData'], 5, $rows['lastData'], 'LEFT', 'NUMBER');

	// Update the spreadsheet
	$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
	$response = $service->spreadsheets->batchUpdate( LOGS_SPREADSHEET_ID, $batchUpdateRequest);
	$service->spreadsheets_values->update( LOGS_SPREADSHEET_ID, $range, $valueRange, $params);
}

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
