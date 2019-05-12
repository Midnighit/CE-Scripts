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


foreach($ism_table as $num => $line)
{
  // add headline for the sheet but ignore it for the JSON file
  if($num == 0)
  {
    $values[] = ['Item', 'TemplateID', 'Modify', 'Stat', 'StatID', 'Amount'];
    continue;
  }
  // create JSON file for import
  if(count($line) == 6) list($item, $templateId, $mod, $stat, $statId, $amount) = $line;
  else
  {
    list($item, $templateId, $mod) = $line;
    $stat = $statId = $amount = '';
  }
  $ism_json[$num]['RowName'] = $templateId;
  !isset($itbl_list[$templateId]) ? $name = $item : $name = $itbl_list[$templateId];
  if($mod == 'add')
  {
    if(is_numeric($statId)) $stat = STAT_INT[$statId];
    elseif($statId = array_search($stat, STAT_INT));
    else continue;
    $ism_json[$num - 1]['Modifications'][0]['IsFloatStatModification'] = false;
    $ism_json[$num - 1]['Modifications'][0]['OperatorID'] = 'Add';
    $ism_json[$num - 1]['Modifications'][0]['StatID'] = $statId;
    $ism_json[$num - 1]['Modifications'][0]['ModificationValue'] = $amount;

    // create values array to upload to re-upload google sheet
    $values[] = [$name, $templateId, $mod, $stat, $statId, $amount];
  }
  else
  {
    $ism_json[$num - 1]['Modifications'] = array();
    $values[] = [$name, $templateId, $mod, '', '', ''];
  }
}

echo "Writing ItemStatModification.json..." . PHP_EOL;
$json = json_encode($ism_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$handle = fopen(OUT_FILE, 'w+');
fwrite($handle, $json);

echo "Uploading changes to google sheet..." . PHP_EOL;
// Set parameters for the spreadsheet update
$valueInputOption = 'USER_ENTERED';
$range = 'Item Stat Modification!A1:F'.count($values);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => $valueInputOption];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 1, 'firstData' => 2, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 6];

// Build the requests array
G_setGridSize(ISM_SHEET_ID, $requests, $columns['last'], $rows['last'], 1);
G_changeFormat(ISM_SHEET_ID, $requests, 1, $rows['firstData'], 5, $rows['lastData'], 'LEFT', 'TEXT');
G_changeFormat(ISM_SHEET_ID, $requests, 6, $rows['firstData'], 6, $rows['lastData'], 'RIGHT', 'NUMBER');
G_setFilterRequest(ISM_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);

// Update the spreadsheet
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate( ADMIN_SPREADSHEET_ID, $batchUpdateRequest);
$service->spreadsheets_values->update( ADMIN_SPREADSHEET_ID, $range, $valueRange, $params);
unset($values);

echo "done!\n";

$etime = microtime(true);
$diff = $etime - $stime;
echo PHP_EOL . "Required time: ".round($diff,3)." sec.";
?>
