<?php
$stime = microtime(true);
/******************************************
* Functions, global variables & constants *
******************************************/

require 'config.php';
const IN_ITBL = './db/ItemTable.json';
const OUT_FILE = './db/ItemStatModification.json';
const STAT_INT = array('HealthMax', 'HealthCurrent', 'FeatPointsUsed', 'FeatPointsTotal', 'Level', 'StaminaCurrent', 'StaminaMax', 'ConsciousnessCurrent', 'ConsciousnessMax', 'ThrallID', 'ThrallTier', 'Gender', 'KnockbackDefense', 'Faction', 'Vitality', 'Grit', 'Encumbrance', 'Strength', 'Accuracy', 'Agility', 'Survival', 'Resilience', 'InventorySpace');
const STAT_FLOAT = array('ThrallCraftingSpeed', 'ThrallCraftingCost', 'ThrallCraftingFuel', 'ThrallEntertainyterPotency', 'DamageModifierMelee', 'DamageModifierRanged', 'NaturalArmor', 'ThrallCorruptionCleansePotency', 'ThrallCorruptionCleanseLimit', 'Armor', 'EncumbranceWEeight', 'KilledXPModifier', 'CurrentEncumbrance', 'TemperatureModification', 'PenisScaleModifier');

/*************************
* Read from google sheet *
*************************/

// remove all non-alphanumeric characters
function norm($input)
{
  return preg_replace("/([^\w])/", '', $input);
}

// extract recipe name from Recipestable
function getName($input, $normalize = FALSE)
{
	preg_match('/,\s"([^"]+)"\)/', stripcslashes($input), $result);
	if($normalize) norm($result[1]);
	return $result[1];
}

// Get the Google API client and construct the service object and set the spreadsheet- and sheetId.
require 'google_sheets_client.php';
$client = G_getClient();
$service = new Google_Service_Sheets($client);

echo "Reading in ItemsTable for templateID translation..." . PHP_EOL;
// read file into string for json_decode
$itbl_str = file_get_contents(IN_ITBL);
$itbl_json = json_decode($itbl_str, true);
foreach ($itbl_json as $key => $value) $itbl_list[$value['RowName']] = getName($value['Name']);

$response = $service->spreadsheets_values->get(ADMIN_SPREADSHEET_ID, 'Item Stat Modification!A1:F');
if($response->values) foreach($response->values as $key => $value) $ism_table[] = $value;

echo "Processing Item Stat Modifications..." . PHP_EOL;

// create JSON file for import and values array for upload
foreach($ism_table as $num => $line)
{
  // add headline for the sheet but ignore it for the JSON file
  if($num == 0)
  {
    $values_admin[] = ['Item', 'TemplateID', 'Modify', 'Stat', 'StatID', 'Amount'];
    $values_player[] = ['Item', 'Stat', 'Amount'];
    continue;
  }
  // lines with remove as modification only have the first three data cells
  if(count($line) == 6) list($item, $templateId, $mod, $stat, $statId, $amount) = $line;
  else
  {
    list($item, $templateId, $mod) = $line;
    $stat = $statId = $amount = '';
  }
  // check if templateId has already been added
  if(!isset($has_been_added[$templateId]))
  {
    // remember that it has been added now
    $has_been_added[$templateId] = $index = $num - 1;
    $ism_json[$index]['RowName'] = $templateId;
  }
  else $index = $has_been_added[$templateId];
  !isset($itbl_list[$templateId]) ? $name = $item : $name = $itbl_list[$templateId];
  if($mod == 'add')
  {
    if(is_numeric($statId)) $stat = STAT_INT[$statId];
    elseif($statId = array_search($stat, STAT_INT));
    else continue;
    // if there already is at least one modification, append the new one
    isset($ism_json[$index]['Modifications']) ? $num_mods = count($ism_json[$index]['Modifications']) : $num_mods = 0;
    $ism_json[$index]['Modifications'][$num_mods]['IsFloatStatModification'] = false;
    $ism_json[$index]['Modifications'][$num_mods]['OperatorID'] = 'Add';
    $ism_json[$index]['Modifications'][$num_mods]['StatID'] = $statId;
    $ism_json[$index]['Modifications'][$num_mods]['ModificationValue'] = $amount;

    // create values array to upload to re-upload google sheet
    $values_admin[] = [$name, $templateId, $mod, $stat, $statId, $amount];
    $values_player[] = [$name, $stat, $amount];
  }
  else
  {
    $ism_json[$index]['Modifications'] = array();
    $values_admin[] = [$name, $templateId, $mod, '', '', ''];
  }
}

echo "Writing ItemStatModification.json..." . PHP_EOL;
$json = json_encode($ism_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$handle = fopen(OUT_FILE, 'w+');
fwrite($handle, $json);

echo "Uploading changes to google admin sheet..." . PHP_EOL;
// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$range = 'Item Stat Modification!A1:F'.count($values_admin);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values_admin]);
$params = ['valueInputOption' => $valueInputOption];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 1, 'firstData' => 2, 'lastData' => count($values_admin), 'last' => count($values_admin)];
$columns = ['first' => 1, 'last' => 6];

// Build the requests array
G_setGridSize(ISM_ADMIN_SHEET_ID, $requests, $columns['last'], $rows['last'], 1);
G_changeFormat(ISM_ADMIN_SHEET_ID, $requests, 1, $rows['firstData'], 1, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(ISM_ADMIN_SHEET_ID, $requests, 2, $rows['firstData'], 2, $rows['lastData'], 'RIGHT', 'NUMBER');
G_changeFormat(ISM_ADMIN_SHEET_ID, $requests, 3, $rows['firstData'], 4, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(ISM_ADMIN_SHEET_ID, $requests, 5, $rows['firstData'], 6, $rows['lastData'], 'RIGHT', 'NUMBER');
G_setFilterRequest(ISM_ADMIN_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);

// Update the spreadsheet
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate( ADMIN_SPREADSHEET_ID, $batchUpdateRequest);
$service->spreadsheets_values->update( ADMIN_SPREADSHEET_ID, $range, $valueRange, $params);
unset($values_admin);
unset($requests);

echo "done!\n";

echo "Uploading changes to google player sheet..." . PHP_EOL;
// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$range = 'Attribute Bonuses!A1:C'.count($values_player);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values_player]);
$params = ['valueInputOption' => $valueInputOption];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 1, 'firstData' => 2, 'lastData' => count($values_player), 'last' => count($values_player)];
$columns = ['first' => 1, 'last' => 3];

// Build the requests array
G_setGridSize(ISM_PLAYER_SHEET_ID, $requests, $columns['last'], $rows['last'], 1);
G_changeFormat(ISM_PLAYER_SHEET_ID, $requests, 1, $rows['firstData'], 1, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(ISM_PLAYER_SHEET_ID, $requests, 2, $rows['firstData'], 2, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(ISM_PLAYER_SHEET_ID, $requests, 3, $rows['firstData'], 3, $rows['lastData'], 'RIGHT', 'NUMBER');
G_setFilterRequest(ISM_PLAYER_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);

// Update the spreadsheet
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate( PLAYER_SPREADSHEET_ID, $batchUpdateRequest);
$service->spreadsheets_values->update( PLAYER_SPREADSHEET_ID, $range, $valueRange, $params);
unset($values_player);

echo "done!\n";

$etime = microtime(true);
$diff = $etime - $stime;
echo PHP_EOL . "Required time: ".round($diff,3)." sec.";
?>
