<?php
$stime = microtime(true);
/******************************************
* Functions, global variables & constants *
******************************************/

const MAX_STACK_SIZE = 1000000;
const MAX_PRODUCER = 1000;
const PRODUCER_ID = array('13012', '18002', '18010', '18011', '18012', '18020', '18021', '18022', '80995', '80991', '80992', '80993', '80994', '80995');
const MODIFIED_VANILLA_ID = array('51396');

/*********************
* Table preparations *
*********************/

echo 'Updating stackable items in ItemTable.json... ';

/// items Table:
// complile all the relevant information into the items table
// Key: ItemId / Value: Array with MaxStackSize
$json = file_get_contents('./db/ItemTable.json');
$terpo = file_get_contents('./db/TERPO-items.json');
$result_json = json_decode($json, true);
$result_terpo = json_decode($terpo, true);
$result = array_merge($result_json, $result_terpo);
$new_row = 0;
$counted = [];
foreach($result as $row => $value)
{
	// Vanilla items that were changed have to be specifically added here
	if(in_array($value['RowName'], MODIFIED_VANILLA_ID))
	{
		// ignore the vanilla version of the item (the one that shows up first)
		if(!isset($counted[$value['RowName']])) $counted[$value['RowName']] = true;
		// add the changed version that originates from TERPO-items.json
		else $out_incr[$new_row++] = $result[$row];
	}
	// Only increase stacksize for all items with a MaxStackSize > 1
	elseif($value['MaxStackSize'] > 1)
	{
		$out_incr[$new_row] = $result[$row];
		if(in_array($value['RowName'], PRODUCER_ID)) $out_incr[$new_row++]['MaxStackSize'] = MAX_PRODUCER;
		else $out_incr[$new_row++]['MaxStackSize'] = MAX_STACK_SIZE;
	}
	// TERPO items need to be merged even when their stacksize isn't changed
	elseif(strlen($value['RowName']) == 8 && substr($value['RowName'], 0, 4) == '8469') $out_incr[$new_row++] = $result[$row];
}
echo "done!\n";

echo "Writing changes to ItemTable.UpdatedStackSize.json...\n";
$json = json_encode($out_incr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$handle = fopen('./db/ItemTable.UpdatedStackSize.json', 'w+');
fwrite($handle, $json);
echo "done!\n";

$etime = microtime(true);
$diff = $etime - $stime;
echo "\nRequired time: ".round($diff,3)." sec.";
?>
